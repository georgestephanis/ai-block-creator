/**
 * Block Preview Component.
 * Renders interactive visual preview and code inspector for the generated AI block.
 */

import { useState, useMemo, useEffect } from '@wordpress/element';
import { TabPanel } from '@wordpress/components';
import { useSettings } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import {
	interpolateTemplate,
	injectBlockStyles,
	renderEditField,
	kindOf,
	KIND_CUSTOM_BLOCK,
} from '../runtime/dynamic-block-factory';
import DefinitionPreview from './DefinitionPreview';

/**
 * Builds the default attribute values for a block definition.
 *
 * @param {Object} blockDef Block definition.
 * @return {Object} Map of attribute name to default value.
 */
function defaultsFor( blockDef ) {
	const initial = {};
	if ( blockDef?.attributes ) {
		Object.keys( blockDef.attributes ).forEach( ( k ) => {
			initial[ k ] = blockDef.attributes[ k ]?.default ?? '';
		} );
	}
	return initial;
}

export default function BlockPreview( { blockDef } ) {
	const [ previewAttrs, setPreviewAttrs ] = useState( () =>
		defaultsFor( blockDef )
	);
	const [ themeColors ] = useSettings( 'color.palette' );

	// Reset local attribute state whenever a new/refined definition arrives
	// (e.g. after a refinement turn changes or renames attributes) — without
	// this, previously-set attributes that no longer exist linger and newly
	// added ones render with no value until the user touches every field.
	useEffect( () => {
		setPreviewAttrs( defaultsFor( blockDef ) );
	}, [ blockDef ] );

	// Inject styles into document when block definition updates.
	useEffect( () => {
		if ( blockDef?.css && blockDef?.name ) {
			injectBlockStyles( blockDef.name, blockDef.css );
		}
	}, [ blockDef?.css, blockDef?.name ] );

	// Interpolate HTML template with current preview attributes.
	const renderedHtml = useMemo( () => {
		return interpolateTemplate( blockDef?.render_html, previewAttrs );
	}, [ blockDef?.render_html, previewAttrs ] );

	if ( ! blockDef ) {
		return null;
	}

	// Styles, variations and patterns have no render template to interpolate;
	// they get their own summary view instead of the live-preview tabs below.
	if ( kindOf( blockDef ) !== KIND_CUSTOM_BLOCK ) {
		return <DefinitionPreview blockDef={ blockDef } />;
	}

	const editFields = blockDef.edit_fields || [];

	const tabs = [
		{
			name: 'visual',
			title: '✨ ' + __( 'Live Preview', 'ai-block-creator' ),
			className: 'ai-preview-tab-visual',
		},
		{
			name: 'attributes',
			title: '⚙️ ' + __( 'Attributes & Controls', 'ai-block-creator' ),
			className: 'ai-preview-tab-attributes',
		},
		{
			name: 'code',
			title: '💻 ' + __( 'Generated Code', 'ai-block-creator' ),
			className: 'ai-preview-tab-code',
		},
	];

	return (
		<div className="ai-block-preview-card">
			<div className="ai-block-preview-header">
				<div className="ai-block-preview-meta">
					<span className="ai-block-preview-icon">✨</span>
					<div>
						<h3 className="ai-block-preview-title">
							{ blockDef.title ||
								__( 'AI Custom Block', 'ai-block-creator' ) }
						</h3>
						<span className="ai-block-preview-slug">
							{ blockDef.name }
						</span>
					</div>
				</div>
				<p className="ai-block-preview-desc">
					{ blockDef.description }
				</p>
			</div>

			<TabPanel
				className="ai-block-preview-tabs"
				activeClass="is-active"
				tabs={ tabs }
			>
				{ ( tab ) => {
					if ( tab.name === 'visual' ) {
						return (
							<div className="ai-block-preview-visual-canvas">
								<div
									className={ `ai-custom-block ai-block-${ (
										blockDef.name || ''
									).replace( 'ai-block/', '' ) }` }
									dangerouslySetInnerHTML={ {
										__html: renderedHtml,
									} }
								/>
							</div>
						);
					}

					if ( tab.name === 'attributes' ) {
						return (
							<div className="ai-block-preview-attributes-panel">
								<p className="ai-preview-hint">
									{ __(
										'Test how your block responds to attribute changes:',
										'ai-block-creator'
									) }
								</p>
								<div className="ai-preview-fields-grid">
									{ editFields.map( ( field ) =>
										renderEditField(
											field,
											previewAttrs[ field.name ],
											( val ) =>
												setPreviewAttrs( ( prev ) => ( {
													...prev,
													[ field.name ]: val,
												} ) ),
											themeColors
										)
									) }
								</div>
							</div>
						);
					}

					if ( tab.name === 'code' ) {
						return (
							<div className="ai-block-preview-code-panel">
								<div className="ai-code-section">
									<h4>
										{ __(
											'HTML Template',
											'ai-block-creator'
										) }
									</h4>
									<pre className="ai-code-block">
										{ blockDef.render_html }
									</pre>
								</div>
								{ blockDef.css && (
									<div className="ai-code-section">
										<h4>
											{ __(
												'Scoped CSS',
												'ai-block-creator'
											) }
										</h4>
										<pre className="ai-code-block">
											{ blockDef.css }
										</pre>
									</div>
								) }
							</div>
						);
					}

					return null;
				} }
			</TabPanel>
		</div>
	);
}
