/**
 * Preview for definitions that aren't standalone blocks.
 *
 * A style, a variation and a pattern all have no render template of their own
 * — existing blocks still draw themselves — so there is nothing to interpolate
 * and show the way BlockPreview does for a custom block. What's useful instead
 * is what the author is actually about to get: which block it attaches to (or
 * which blocks it is made of), what it will be called, and the CSS, attribute
 * preset, or markup behind it.
 */

import { __, sprintf } from '@wordpress/i18n';
import {
	kindOf,
	KIND_BLOCK_STYLE,
	KIND_BLOCK_PATTERN,
} from '../runtime/dynamic-block-factory';
import { kindLabel, kindExplanation } from './kind-labels';

/**
 * @param {Object} props          Component props.
 * @param {Object} props.blockDef Style, variation or pattern definition.
 * @return {Object} Element.
 */
export default function DefinitionPreview( { blockDef } ) {
	const kind = kindOf( blockDef );
	const isStyle = kind === KIND_BLOCK_STYLE;
	const isPattern = kind === KIND_BLOCK_PATTERN;
	const isVariation = ! isStyle && ! isPattern;
	const attributeEntries = Object.entries( blockDef.attributes || {} );

	return (
		<div className="ai-block-preview-card ai-definition-preview">
			<div className="ai-block-preview-header">
				<div className="ai-block-preview-meta">
					<span className="ai-block-preview-icon">✨</span>
					<div>
						<h3 className="ai-block-preview-title">
							{ blockDef.label ||
								blockDef.title ||
								blockDef.name }
						</h3>
						<span className="ai-block-preview-slug">
							{ kindLabel( kind ) }
							{ blockDef.target_block
								? ` · ${ blockDef.target_block }`
								: '' }
						</span>
					</div>
				</div>
				{ blockDef.description && (
					<p className="ai-block-preview-desc">
						{ blockDef.description }
					</p>
				) }
			</div>

			<div className="ai-definition-preview-body">
				<p className="ai-preview-hint">
					{ kindExplanation( kind, blockDef.target_block ) }
				</p>

				{ isStyle && (
					<div className="ai-code-section">
						<h4>{ __( 'CSS class', 'ai-block-creator' ) }</h4>
						<pre className="ai-code-block">
							{ `.is-style-${ blockDef.name }` }
						</pre>
					</div>
				) }

				{ isVariation && attributeEntries.length > 0 && (
					<div className="ai-code-section">
						<h4>
							{ __( 'Preset attributes', 'ai-block-creator' ) }
						</h4>
						<ul className="ai-definition-attribute-list">
							{ attributeEntries.map( ( [ key, value ] ) => (
								<li key={ key }>
									<code>{ key }</code>
									{ ': ' }
									<span>
										{ typeof value === 'object'
											? JSON.stringify( value )
											: String( value ) }
									</span>
								</li>
							) ) }
						</ul>
					</div>
				) }

				{ isVariation &&
					Array.isArray( blockDef.inner_block_names ) &&
					blockDef.inner_block_names.length > 0 && (
						<div className="ai-code-section">
							<h4>
								{ __( 'Inner blocks', 'ai-block-creator' ) }
							</h4>
							<pre className="ai-code-block">
								{ blockDef.inner_block_names.join( '\n' ) }
							</pre>
						</div>
					) }

				{ isPattern && (
					<div className="ai-code-section">
						<h4>{ __( 'Block markup', 'ai-block-creator' ) }</h4>
						<pre className="ai-code-block">
							{ blockDef.content ||
								__(
									'This pattern is empty — describe the section you want.',
									'ai-block-creator'
								) }
						</pre>
					</div>
				) }

				{ isPattern &&
					Array.isArray( blockDef.keywords ) &&
					blockDef.keywords.length > 0 && (
						<div className="ai-code-section">
							<h4>
								{ __(
									'Inserter keywords',
									'ai-block-creator'
								) }
							</h4>
							<p className="ai-preview-hint">
								{ blockDef.keywords.join( ', ' ) }
							</p>
						</div>
					) }

				{ ! isPattern && blockDef.css && (
					<div className="ai-code-section">
						<h4>{ __( 'Scoped CSS', 'ai-block-creator' ) }</h4>
						<pre className="ai-code-block">{ blockDef.css }</pre>
					</div>
				) }

				{ isStyle && ! blockDef.css && (
					<p className="ai-preview-hint">
						{ __(
							'This style has no CSS yet — describe the look you want to add some.',
							'ai-block-creator'
						) }
					</p>
				) }

				{ isVariation && ! blockDef.css && (
					<p className="ai-preview-hint">
						{ sprintf(
							// translators: %s: target block name.
							__(
								'This variation needs no CSS of its own; it configures the %s block directly.',
								'ai-block-creator'
							),
							blockDef.target_block
						) }
					</p>
				) }
			</div>
		</div>
	);
}
