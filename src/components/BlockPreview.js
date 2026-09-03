/**
 * Block Preview Component.
 * Renders interactive visual preview and code inspector for the generated AI block.
 */

import { useState, useMemo, useEffect } from '@wordpress/element';
import { TabPanel, TextControl, ToggleControl, TextareaControl, Button } from '@wordpress/components';
import { interpolateTemplate, injectBlockStyles } from '../runtime/dynamic-block-factory';

export default function BlockPreview( { blockDef } ) {
	if ( ! blockDef ) {
		return null;
	}

	// Initialize local attribute state with block defaults.
	const [ previewAttrs, setPreviewAttrs ] = useState( () => {
		const initial = {};
		if ( blockDef.attributes ) {
			Object.keys( blockDef.attributes ).forEach( ( k ) => {
				initial[ k ] = blockDef.attributes[ k ]?.default ?? '';
			} );
		}
		return initial;
	} );

	// Inject styles into document when block definition updates.
	useEffect( () => {
		if ( blockDef.css && blockDef.name ) {
			injectBlockStyles( blockDef.name, blockDef.css );
		}
	}, [ blockDef ] );

	// Interpolate HTML template with current preview attributes.
	const renderedHtml = useMemo( () => {
		return interpolateTemplate( blockDef.render_html, previewAttrs );
	}, [ blockDef.render_html, previewAttrs ] );

	const editFields = blockDef.edit_fields || [];

	const tabs = [
		{
			name: 'visual',
			title: '✨ Live Preview',
			className: 'ai-preview-tab-visual',
		},
		{
			name: 'attributes',
			title: '⚙️ Attributes & Controls',
			className: 'ai-preview-tab-attributes',
		},
		{
			name: 'code',
			title: '💻 Generated Code',
			className: 'ai-preview-tab-code',
		},
	];

	return (
		<div className="ai-block-preview-card">
			<div className="ai-block-preview-header">
				<div className="ai-block-preview-meta">
					<span className="ai-block-preview-icon">✨</span>
					<div>
						<h3 className="ai-block-preview-title">{ blockDef.title || 'AI Custom Block' }</h3>
						<span className="ai-block-preview-slug">{ blockDef.name }</span>
					</div>
				</div>
				<p className="ai-block-preview-desc">{ blockDef.description }</p>
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
									className={ `ai-custom-block ai-block-${ ( blockDef.name || '' ).replace( 'ai-block/', '' ) }` }
									dangerouslySetInnerHTML={ { __html: renderedHtml } }
								/>
							</div>
						);
					}

					if ( tab.name === 'attributes' ) {
						return (
							<div className="ai-block-preview-attributes-panel">
								<p className="ai-preview-hint">
									Test how your block responds to attribute changes:
								</p>
								<div className="ai-preview-fields-grid">
									{ editFields.map( ( field ) => {
										const val = previewAttrs[ field.name ];
										if ( field.type === 'toggle' || field.type === 'boolean' ) {
											return (
												<ToggleControl
													key={ field.name }
													label={ field.label || field.name }
													checked={ Boolean( val ) }
													onChange={ ( checked ) =>
														setPreviewAttrs( ( prev ) => ( { ...prev, [ field.name ]: checked } ) )
													}
												/>
											);
										}
										if ( field.type === 'textarea' ) {
											return (
												<TextareaControl
													key={ field.name }
													label={ field.label || field.name }
													value={ val || '' }
													rows={ 3 }
													onChange={ ( newVal ) =>
														setPreviewAttrs( ( prev ) => ( { ...prev, [ field.name ]: newVal } ) )
													}
												/>
											);
										}
										return (
											<TextControl
												key={ field.name }
												label={ field.label || field.name }
												value={ val || '' }
												onChange={ ( newVal ) =>
													setPreviewAttrs( ( prev ) => ( { ...prev, [ field.name ]: newVal } ) )
												}
											/>
										);
									} ) }
								</div>
							</div>
						);
					}

					if ( tab.name === 'code' ) {
						return (
							<div className="ai-block-preview-code-panel">
								<div className="ai-code-section">
									<h4>HTML Template</h4>
									<pre className="ai-code-block">{ blockDef.render_html }</pre>
								</div>
								{ blockDef.css && (
									<div className="ai-code-section">
										<h4>Scoped CSS</h4>
										<pre className="ai-code-block">{ blockDef.css }</pre>
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
