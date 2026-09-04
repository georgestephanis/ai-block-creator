/**
 * Dynamic Block Factory.
 * Converts JSON block definitions into registered WordPress Gutenberg blocks.
 */

import {
	registerBlockType,
	unregisterBlockType,
	getBlockType,
	registerBlockStyle,
	unregisterBlockStyle,
	registerBlockVariation,
	unregisterBlockVariation,
	createBlock,
} from '@wordpress/blocks';
import {
	useBlockProps,
	InspectorControls,
	useSettings,
} from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	TextareaControl,
	ToggleControl,
	ColorPalette,
	RangeControl,
	SelectControl,
} from '@wordpress/components';
import { createElement, RawHTML, useMemo } from '@wordpress/element';
import { escapeHTML, escapeAttribute } from '@wordpress/escape-html';

/**
 * Coerces an attribute value into a display string, mirroring the PHP
 * renderer's AI_Block_Renderer::stringify().
 *
 * @param {*} val Attribute value.
 * @return {string} Display-safe string form of the value.
 */
function stringify( val ) {
	if ( typeof val === 'boolean' ) {
		return val ? '1' : '0';
	}
	if ( val === undefined || val === null ) {
		return '';
	}
	if ( Array.isArray( val ) ) {
		return val.map( String ).join( ', ' );
	}
	return String( val );
}

/**
 * Very small allowlist-based CSS value sanitizer for values landing inside a
 * `style="..."` attribute. Mirrors the intent of PHP's safecss_filter_attr():
 * block known XSS vectors without trying to be a full CSS parser.
 *
 * @param {string} value Raw CSS value.
 * @return {string} Sanitized CSS value.
 */
function sanitizeStyleValue( value ) {
	return String( value )
		.replace( /expression\s*\([^)]*\)/gi, '' )
		.replace( /javascript\s*:/gi, '' )
		.replace( /[<>"]/g, '' );
}

/**
 * Escapes a value destined for a URL attribute (href/src), blocking
 * javascript:/data: executable schemes while allowing normal URLs.
 *
 * @param {string} value Raw URL value.
 * @return {string} Escaped URL, or an empty string if the scheme is disallowed.
 */
function sanitizeUrlValue( value ) {
	const trimmed = String( value ).trim();
	if ( /^\s*(javascript|vbscript):/i.test( trimmed ) ) {
		return '';
	}
	return escapeAttribute( trimmed );
}

/**
 * Interpolates attributes into HTML template for editor preview, with
 * context-aware escaping matching the PHP renderer (AI_Block_Renderer).
 *
 * @param {string} template   HTML template string.
 * @param {Object} attributes Current block attributes.
 * @return {string} Interpolated HTML.
 */
export function interpolateTemplate( template, attributes ) {
	if ( ! template ) {
		return '';
	}

	let html = template;

	// Raw variables: {{{key}}}. MUST run before the {{key}} pass below, since
	// {{{x}}} contains a valid {{x}} match that would otherwise be consumed
	// first, leaving stray braces behind.
	html = html.replace( /\{\{\{([a-zA-Z0-9_-]+)\}\}\}/g, ( match, key ) => {
		const val = attributes[ key ];
		// The editor preview can't safely run PHP's wp_kses_post(); render
		// raw HTML values as escaped text here instead of executing them,
		// matching what a non-unfiltered_html author will see once the
		// server-side kses pass (AI_Block_Store) has stripped active markup.
		return val !== undefined && val !== null
			? escapeHTML( stringify( val ) )
			: '';
	} );

	// Process conditional blocks, supporting nesting of the same tag type via
	// a small stack-based scanner (a plain regex pairs the first {{#if}}
	// with the first {{/if}}, which breaks on nested conditionals).
	html = processConditionals( html, attributes, '#if', true );
	html = processConditionals( html, attributes, '^if', false );

	// Process list repeaters: {{#list features}}<li>{{item}}</li>{{/list}}
	html = html.replace(
		/\{\{#list\s+([a-zA-Z0-9_-]+)\}\}([\s\S]*?)\{\{\/list\}\}/g,
		( match, key, itemTemplate ) => {
			const val = attributes[ key ];
			let items = [];
			if ( Array.isArray( val ) ) {
				items = val;
			} else if ( typeof val === 'string' ) {
				items = val
					.split( '\n' )
					.map( ( s ) => s.trim() )
					.filter( Boolean );
			}
			return items
				.map( ( item ) => {
					const itemStr =
						typeof item === 'object'
							? JSON.stringify( item )
							: String( item );
					return itemTemplate.replace(
						/\{\{item\}\}/g,
						escapeHTML( itemStr )
					);
				} )
				.join( '' );
		}
	);

	// 1. Process style="..." attributes (safecss filtering on style values).
	html = html.replace( /style\s*=\s*"([^"]*)"/gi, ( match, styleContent ) => {
		const interpolated = styleContent.replace(
			/\{\{([a-zA-Z0-9_-]+)\}\}/g,
			( _, key ) => {
				const val = attributes[ key ];
				return val !== undefined && val !== null
					? sanitizeStyleValue( stringify( val ) )
					: '';
			}
		);
		return `style="${ escapeAttribute( interpolated ) }"`;
	} );

	// 2. Process href/src="..." attributes (URL escaping).
	html = html.replace(
		/(href|src)\s*=\s*"([^"]*)"/gi,
		( match, attrName, urlContent ) => {
			const interpolated = urlContent.replace(
				/\{\{([a-zA-Z0-9_-]+)\}\}/g,
				( _, key ) => {
					const val = attributes[ key ];
					return val !== undefined && val !== null
						? stringify( val )
						: '';
				}
			);
			return `${ attrName }="${ sanitizeUrlValue( interpolated ) }"`;
		}
	);

	// 3. Replace remaining generic variables: {{key}} in text/attribute context.
	html = html.replace( /\{\{([a-zA-Z0-9_-]+)\}\}/g, ( match, key ) => {
		const val = attributes[ key ];
		if ( val === undefined ) {
			return '';
		}
		return escapeHTML( stringify( val ) );
	} );

	return html;
}

/**
 * Stack-based processor for {{#if x}}...{{/if}} / {{^if x}}...{{/if}}
 * blocks, supporting arbitrary nesting of the same tag type. Mirrors
 * AI_Block_Renderer::process_conditionals() in PHP.
 *
 * @param {string}  template       Template containing the tag pairs.
 * @param {Object}  attributes     Attribute values.
 * @param {string}  openTag        '#if' or '^if'.
 * @param {boolean} showWhenTruthy True to keep inner content when the attribute is truthy (#if), false for ^if.
 * @return {string} Template with conditionals resolved.
 */
function processConditionals( template, attributes, openTag, showWhenTruthy ) {
	const openNeedle = `{{${ openTag } `;
	if ( ! template.includes( openNeedle ) ) {
		return template;
	}

	const openRe = new RegExp(
		`\\{\\{${ openTag.replace(
			/[.*+?^${}()|[\]\\]/g,
			'\\$&'
		) }\\s+([a-zA-Z0-9_-]+)\\}\\}`
	);
	const closeTag = '{{/if}}';

	let result = '';
	let cursor = 0;

	while ( cursor < template.length ) {
		const rest = template.slice( cursor );
		const match = rest.match( openRe );
		if ( ! match ) {
			result += rest;
			break;
		}

		const offset = cursor + match.index;
		const varName = match[ 1 ];
		const bodyStart = offset + match[ 0 ].length;

		result += template.slice( cursor, offset );

		let depth = 1;
		let search = bodyStart;
		let closePos = template.length;

		while ( depth > 0 ) {
			const nextOpen = template.indexOf( openNeedle, search );
			const nextClose = template.indexOf( closeTag, search );

			if ( nextClose === -1 ) {
				closePos = template.length;
				depth = 0;
				break;
			}

			if ( nextOpen !== -1 && nextOpen < nextClose ) {
				depth++;
				search = nextOpen + openNeedle.length;
			} else {
				depth--;
				search = nextClose + closeTag.length;
				if ( depth === 0 ) {
					closePos = nextClose;
				}
			}
		}

		const body = template.slice( bodyStart, closePos );
		const isTruthy = Boolean( attributes[ varName ] );

		if ( isTruthy === showWhenTruthy ) {
			result += processConditionals(
				body,
				attributes,
				openTag,
				showWhenTruthy
			);
		}

		cursor = closePos + closeTag.length;
	}

	return result;
}

/**
 * Injects block scoped CSS into the document head — both the admin's own
 * document, and (when present) the block editor canvas iframe's document,
 * since apiVersion 3 blocks render inside an iframe and a <style> appended
 * only to the top-level document never reaches them.
 *
 * @param {string} blockName Name of block.
 * @param {string} css       CSS string.
 */
export function injectBlockStyles( blockName, css ) {
	if ( ! css ) {
		return;
	}

	const styleId = `ai-block-style-${ blockName.replace(
		/[^a-zA-Z0-9_-]/g,
		'-'
	) }`;

	const targets = [ document ];
	const canvasIframe = document.querySelector(
		'iframe[name="editor-canvas"]'
	);
	if ( canvasIframe && canvasIframe.contentDocument ) {
		targets.push( canvasIframe.contentDocument );
	}

	targets.forEach( ( doc ) => {
		let styleEl = doc.getElementById( styleId );
		if ( ! styleEl ) {
			styleEl = doc.createElement( 'style' );
			styleEl.id = styleId;
			doc.head.appendChild( styleEl );
		}
		styleEl.textContent = css;
	} );
}

/**
 * Renders a single inspector control for an edit_fields entry. Shared
 * between the live block inspector and the creation-modal preview panel so
 * both offer the same control types.
 *
 * @param {Object}   field    Edit field definition ({ name, label, type, options? }).
 * @param {*}        value    Current value.
 * @param {Function} onChange Called with the new value.
 * @param {Array}    colors   Optional theme color palette for `color` fields.
 * @return {Object} Element.
 */
export function renderEditField( field, value, onChange, colors ) {
	const fieldKey = field.name;

	switch ( field.type ) {
		case 'toggle':
		case 'boolean':
			return createElement( ToggleControl, {
				key: fieldKey,
				label: field.label || fieldKey,
				checked: Boolean( value ),
				onChange,
			} );

		case 'textarea':
			return createElement( TextareaControl, {
				key: fieldKey,
				label: field.label || fieldKey,
				value: value || '',
				rows: 4,
				onChange,
			} );

		case 'color':
			return createElement(
				'div',
				{ key: fieldKey, className: 'ai-block-color-field' },
				createElement(
					'label',
					{ className: 'components-base-control__label' },
					field.label || fieldKey
				),
				createElement( ColorPalette, {
					colors: colors || undefined,
					value: value || '#3b82f6',
					onChange: ( val ) => onChange( val || '' ),
				} )
			);

		case 'number':
			return createElement( RangeControl, {
				key: fieldKey,
				label: field.label || fieldKey,
				value: Number( value ) || 0,
				min: field.min !== undefined ? field.min : 0,
				max: field.max !== undefined ? field.max : 100,
				onChange,
			} );

		case 'select':
			return createElement( SelectControl, {
				key: fieldKey,
				label: field.label || fieldKey,
				value,
				options: field.options || [],
				onChange,
			} );

		case 'text':
		case 'url':
		default:
			return createElement( TextControl, {
				key: fieldKey,
				label: field.label || fieldKey,
				value: value || '',
				onChange,
			} );
	}
}

/**
 * Registers a dynamic AI block definition in Gutenberg.
 *
 * @param {Object} blockDef Block definition JSON.
 * @return {Object|null} Registered block type or null.
 */
export function registerDynamicAiBlock( blockDef ) {
	if ( ! blockDef || ! blockDef.name ) {
		return null;
	}

	const blockName = blockDef.name.startsWith( 'ai-block/' )
		? blockDef.name
		: `ai-block/${ blockDef.name }`;

	// Inject CSS styles into editor.
	if ( blockDef.css ) {
		injectBlockStyles( blockName, blockDef.css );
	}

	// Unregister if previously registered to allow hot reloading / updating.
	if ( getBlockType( blockName ) ) {
		try {
			unregisterBlockType( blockName );
		} catch ( e ) {
			// Ignore if unregister fails.
		}
	}

	// Prepare attributes schema.
	const attributes = {};
	if ( blockDef.attributes && typeof blockDef.attributes === 'object' ) {
		Object.keys( blockDef.attributes ).forEach( ( key ) => {
			const attr = blockDef.attributes[ key ];
			attributes[ key ] = {
				type: attr.type || 'string',
				default: attr.default !== undefined ? attr.default : '',
			};
		} );
	}

	const editFields =
		Array.isArray( blockDef.edit_fields ) && blockDef.edit_fields.length
			? blockDef.edit_fields
			: Object.keys( attributes ).map( ( key ) => ( {
					name: key,
					label: key
						.replace( /([A-Z])/g, ' $1' )
						.replace( /^./, ( str ) => str.toUpperCase() ),
					type:
						attributes[ key ].type === 'boolean'
							? 'toggle'
							: 'text',
			  } ) );

	const blockConfig = {
		apiVersion: 3,
		title: blockDef.title || 'AI Custom Block',
		description: blockDef.description || 'Created with AI Block Creator',
		icon: blockDef.icon || 'star-filled',
		category: 'ai-blocks',
		attributes,
		supports: {
			html: false,
			anchor: true,
			align: [ 'wide', 'full' ],
			customClassName: true,
		},
		edit: function Edit( props ) {
			const {
				attributes: currentAttrs,
				setAttributes,
				className,
			} = props;
			const [ themeColors ] = useSettings( 'color.palette' );
			const blockProps = useBlockProps( {
				className: `ai-custom-block ai-block-${ blockName.replace(
					'ai-block/',
					''
				) } ${ className || '' }`,
			} );

			// Render Inspector Controls for attributes
			const inspector = createElement(
				InspectorControls,
				null,
				createElement(
					PanelBody,
					{
						title: `${ blockDef.title } Settings`,
						initialOpen: true,
					},
					editFields.map( ( field ) => {
						const fieldKey = field.name;
						const fieldValue =
							currentAttrs[ fieldKey ] !== undefined
								? currentAttrs[ fieldKey ]
								: blockDef.attributes?.[ fieldKey ]?.default ??
								  '';

						return renderEditField(
							field,
							fieldValue,
							( val ) => setAttributes( { [ fieldKey ]: val } ),
							themeColors
						);
					} )
				)
			);

			// Render preview HTML
			const renderedHtml = useMemo( () => {
				return interpolateTemplate(
					blockDef.render_html,
					currentAttrs
				);
			}, [ currentAttrs ] );

			return createElement(
				'div',
				blockProps,
				inspector,
				createElement( RawHTML, null, renderedHtml )
			);
		},
		save: () => null, // Dynamic rendering handled by PHP on frontend.
	};

	try {
		return registerBlockType( blockName, blockConfig );
	} catch ( err ) {
		// Surface block-registration failures (e.g. a malformed AI-generated
		// definition) to the console so they're debuggable, not silently lost.
		// eslint-disable-next-line no-console
		console.error( 'Error registering dynamic AI block:', err );
		return null;
	}
}

/**
 * The definition kinds this plugin can produce. Mirrors the KIND_* constants
 * on AI_Block_Store in PHP; the two lists must stay in sync.
 */
export const KIND_CUSTOM_BLOCK = 'custom_block';
export const KIND_BLOCK_STYLE = 'block_style';
export const KIND_BLOCK_VARIATION = 'block_variation';

/**
 * Reads a definition's kind, defaulting to a custom block.
 *
 * Definitions saved before kinds existed have no `kind` field at all, and are
 * all custom blocks — so an absent (or unrecognized) kind must resolve to
 * that, not be treated as an error.
 *
 * @param {Object} def Definition.
 * @return {string} One of the KIND_* values.
 */
export function kindOf( def ) {
	const kind = def?.kind;
	return [
		KIND_CUSTOM_BLOCK,
		KIND_BLOCK_STYLE,
		KIND_BLOCK_VARIATION,
	].includes( kind )
		? kind
		: KIND_CUSTOM_BLOCK;
}

/**
 * Builds the `registerBlockStyle()` argument for a style definition.
 *
 * Split out from the registration call itself so the shape can be unit-tested
 * without a real @wordpress/blocks registry.
 *
 * @param {Object} def Style definition.
 * @return {Object|null} { target, style } or null when the definition is unusable.
 */
export function buildStyleConfig( def ) {
	if ( ! def?.name || ! def?.target_block ) {
		return null;
	}

	return {
		target: def.target_block,
		style: {
			name: def.name,
			label: def.label || def.title || def.name,
		},
	};
}

/**
 * Builds the `registerBlockVariation()` argument for a variation definition.
 *
 * `inner_block_names` is a flat list of block names (see
 * AI_Block_Store::sanitize_inner_block_names()); Gutenberg's innerBlocks
 * template format is an array of `[ name, attributes, innerBlocks ]` tuples,
 * so each name becomes a single-element tuple here.
 *
 * @param {Object} def Variation definition.
 * @return {Object|null} { target, variation } or null when the definition is unusable.
 */
export function buildVariationConfig( def ) {
	if ( ! def?.name || ! def?.target_block ) {
		return null;
	}

	const variation = {
		name: def.name,
		title: def.title || def.name,
		description: def.description || '',
		icon: def.icon || 'star-filled',
		attributes: def.attributes || {},
		scope: [ 'inserter', 'transform' ],
	};

	if (
		Array.isArray( def.inner_block_names ) &&
		def.inner_block_names.length
	) {
		variation.innerBlocks = def.inner_block_names.map( ( name ) => [
			name,
		] );
	}

	return { target: def.target_block, variation };
}

/**
 * Registers a block style definition against its target block.
 *
 * @param {Object} def Style definition.
 * @return {boolean} Whether the style was registered.
 */
export function registerAiBlockStyle( def ) {
	const config = buildStyleConfig( def );
	if ( ! config || ! getBlockType( config.target ) ) {
		return false;
	}

	if ( def.css ) {
		injectBlockStyles( `style-${ def.name }`, def.css );
	}

	// Re-registering a style name throws; drop any previous registration first
	// so refining a style updates it in place instead of failing silently.
	try {
		unregisterBlockStyle( config.target, config.style.name );
	} catch ( e ) {
		// Not previously registered.
	}

	try {
		registerBlockStyle( config.target, config.style );
		return true;
	} catch ( err ) {
		// eslint-disable-next-line no-console
		console.error( 'Error registering AI block style:', err );
		return false;
	}
}

/**
 * Registers a block variation definition against its target block.
 *
 * @param {Object} def Variation definition.
 * @return {boolean} Whether the variation was registered.
 */
export function registerAiBlockVariation( def ) {
	const config = buildVariationConfig( def );
	if ( ! config || ! getBlockType( config.target ) ) {
		return false;
	}

	if ( def.css ) {
		injectBlockStyles( `variation-${ def.name }`, def.css );
	}

	try {
		unregisterBlockVariation( config.target, config.variation.name );
	} catch ( e ) {
		// Not previously registered.
	}

	try {
		registerBlockVariation( config.target, config.variation );
		return true;
	} catch ( err ) {
		// eslint-disable-next-line no-console
		console.error( 'Error registering AI block variation:', err );
		return false;
	}
}

/**
 * Registers any AI definition, dispatching on its kind.
 *
 * This is the entry point every caller should use; registerDynamicAiBlock()
 * remains exported for the custom-block path it has always handled.
 *
 * @param {Object} def Definition of any kind.
 * @return {boolean} Whether registration succeeded.
 */
export function registerAiDefinition( def ) {
	switch ( kindOf( def ) ) {
		case KIND_BLOCK_STYLE:
			return registerAiBlockStyle( def );
		case KIND_BLOCK_VARIATION:
			return registerAiBlockVariation( def );
		default:
			return Boolean( registerDynamicAiBlock( def ) );
	}
}

/**
 * Builds the editor block(s) that inserting a definition should produce.
 *
 * A custom block inserts itself, seeded with its attribute defaults. A style
 * inserts its *target* block carrying the style's class — there is nothing
 * else to insert, since a style is only ever a class on some other block. A
 * variation inserts its target block with the variation's preset attributes
 * and inner blocks.
 *
 * @param {Object} def Definition of any kind.
 * @return {Object|null} A block object from createBlock(), or null.
 */
export function createBlockFromDefinition( def ) {
	if ( ! def?.name ) {
		return null;
	}

	const kind = kindOf( def );

	if ( kind === KIND_BLOCK_STYLE ) {
		if ( ! def.target_block ) {
			return null;
		}
		return createBlock( def.target_block, {
			className: `is-style-${ def.name }`,
		} );
	}

	if ( kind === KIND_BLOCK_VARIATION ) {
		if ( ! def.target_block ) {
			return null;
		}
		const innerBlocks = Array.isArray( def.inner_block_names )
			? def.inner_block_names.map( ( name ) => createBlock( name ) )
			: [];
		return createBlock(
			def.target_block,
			{ ...( def.attributes || {} ) },
			innerBlocks
		);
	}

	const blockName = def.name.startsWith( 'ai-block/' )
		? def.name
		: `ai-block/${ def.name }`;

	const initialAttrs = {};
	Object.keys( def.attributes || {} ).forEach( ( key ) => {
		initialAttrs[ key ] = def.attributes[ key ]?.default ?? '';
	} );

	return createBlock( blockName, initialAttrs );
}
