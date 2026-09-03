/**
 * Dynamic Block Factory.
 * Converts JSON block definitions into registered WordPress Gutenberg blocks.
 */

import { registerBlockType, getBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
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

/**
 * Interpolates attributes into HTML template for editor preview.
 *
 * @param {string} template HTML template string.
 * @param {Object} attributes Current block attributes.
 * @return {string}
 */
export function interpolateTemplate( template, attributes ) {
	if ( ! template ) {
		return '';
	}

	let html = template;

	// Process conditional blocks: {{#if isFeatured}}...{{/if}} or {{^if isFeatured}}...{{/if}}
	html = html.replace(
		/\{\{#if\s+([a-zA-Z0-9_-]+)\}\}([\s\S]*?)\{\{\/if\}\}/g,
		( match, key, content ) => {
			return attributes[ key ] ? content : '';
		}
	);

	html = html.replace(
		/\{\{\^if\s+([a-zA-Z0-9_-]+)\}\}([\s\S]*?)\{\{\/if\}\}/g,
		( match, key, content ) => {
			return ! attributes[ key ] ? content : '';
		}
	);

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
				.map( ( item ) => itemTemplate.replace( /\{\{item\}\}/g, item ) )
				.join( '' );
		}
	);

	// Replace variables: {{key}}
	html = html.replace( /\{\{([a-zA-Z0-9_-]+)\}\}/g, ( match, key ) => {
		const val = attributes[ key ];
		if ( typeof val === 'boolean' ) {
			return val ? '1' : '0';
		}
		if ( val === undefined || val === null ) {
			return '';
		}
		return String( val );
	} );

	// Raw variables: {{{key}}}
	html = html.replace( /\{\{\{([a-zA-Z0-9_-]+)\}\}\}/g, ( match, key ) => {
		const val = attributes[ key ];
		return val !== undefined && val !== null ? String( val ) : '';
	} );

	return html;
}

/**
 * Injects block scoped CSS into document head.
 *
 * @param {string} blockName Name of block.
 * @param {string} css CSS string.
 */
export function injectBlockStyles( blockName, css ) {
	if ( ! css ) {
		return;
	}
	const styleId = `ai-block-style-${ blockName.replace( /[^a-zA-Z0-9_-]/g, '-' ) }`;
	let styleEl = document.getElementById( styleId );
	if ( ! styleEl ) {
		styleEl = document.createElement( 'style' );
		styleEl.id = styleId;
		document.head.appendChild( styleEl );
	}
	styleEl.textContent = css;
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
			wp.blocks.unregisterBlockType( blockName );
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

	const editFields = Array.isArray( blockDef.edit_fields )
		? blockDef.edit_fields
		: Object.keys( attributes ).map( ( key ) => ( {
				name: key,
				label: key.replace( /([A-Z])/g, ' $1' ).replace( /^./, ( str ) => str.toUpperCase() ),
				type: attributes[ key ].type === 'boolean' ? 'toggle' : 'text',
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
			const { attributes: currentAttrs, setAttributes, className } = props;
			const blockProps = useBlockProps( {
				className: `ai-custom-block ai-block-${ blockName.replace( 'ai-block/', '' ) } ${ className || '' }`,
			} );

			// Render Inspector Controls for attributes
			const inspector = createElement(
				InspectorControls,
				null,
				createElement(
					PanelBody,
					{ title: `${ blockDef.title } Settings`, initialOpen: true },
					editFields.map( ( field ) => {
						const fieldKey = field.name;
						const fieldValue = currentAttrs[ fieldKey ] !== undefined
							? currentAttrs[ fieldKey ]
							: ( blockDef.attributes[ fieldKey ]?.default ?? '' );

						switch ( field.type ) {
							case 'toggle':
							case 'boolean':
								return createElement( ToggleControl, {
									key: fieldKey,
									label: field.label || fieldKey,
									checked: Boolean( fieldValue ),
									onChange: ( val ) => setAttributes( { [ fieldKey ]: val } ),
								} );

							case 'textarea':
								return createElement( TextareaControl, {
									key: fieldKey,
									label: field.label || fieldKey,
									value: fieldValue || '',
									rows: 4,
									onChange: ( val ) => setAttributes( { [ fieldKey ]: val } ),
								} );

							case 'color':
								return createElement(
									'div',
									{ key: fieldKey, className: 'ai-block-color-field' },
									createElement( 'label', { className: 'components-base-control__label' }, field.label || fieldKey ),
									createElement( ColorPalette, {
										value: fieldValue || '#3b82f6',
										onChange: ( val ) => setAttributes( { [ fieldKey ]: val || '' } ),
									} )
								);

							case 'number':
								return createElement( RangeControl, {
									key: fieldKey,
									label: field.label || fieldKey,
									value: Number( fieldValue ) || 0,
									min: field.min !== undefined ? field.min : 0,
									max: field.max !== undefined ? field.max : 100,
									onChange: ( val ) => setAttributes( { [ fieldKey ]: val } ),
								} );

							case 'select':
								return createElement( SelectControl, {
									key: fieldKey,
									label: field.label || fieldKey,
									value: fieldValue,
									options: field.options || [],
									onChange: ( val ) => setAttributes( { [ fieldKey ]: val } ),
								} );

							case 'text':
							case 'url':
							default:
								return createElement( TextControl, {
									key: fieldKey,
									label: field.label || fieldKey,
									value: fieldValue || '',
									onChange: ( val ) => setAttributes( { [ fieldKey ]: val } ),
								} );
						}
					} )
				)
			);

			// Render preview HTML
			const renderedHtml = useMemo( () => {
				return interpolateTemplate( blockDef.render_html, currentAttrs );
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
		console.error( 'Error registering dynamic AI block:', err );
		return null;
	}
}
