/**
 * Human-facing labels for the three kinds of thing this plugin can produce.
 *
 * Kept in one module so the modal, the preview, and the library sidebar all
 * describe a definition the same way.
 */

import { __, sprintf } from '@wordpress/i18n';
import {
	kindOf,
	KIND_CUSTOM_BLOCK,
	KIND_BLOCK_STYLE,
	KIND_BLOCK_VARIATION,
	KIND_BLOCK_PATTERN,
} from '../runtime/dynamic-block-factory';

/**
 * Short noun for a kind, e.g. for a badge.
 *
 * @param {string} kind One of the KIND_* values.
 * @return {string} Translated label.
 */
export function kindLabel( kind ) {
	switch ( kind ) {
		case KIND_BLOCK_STYLE:
			return __( 'Block style', 'ai-block-creator' );
		case KIND_BLOCK_VARIATION:
			return __( 'Block variation', 'ai-block-creator' );
		case KIND_BLOCK_PATTERN:
			return __( 'Block pattern', 'ai-block-creator' );
		default:
			return __( 'Custom block', 'ai-block-creator' );
	}
}

/**
 * One-line explanation of what a definition of this kind will actually do,
 * shown next to the planner's own rationale.
 *
 * @param {string} kind        One of the KIND_* values.
 * @param {string} targetBlock Target block name, for styles and variations.
 * @return {string} Translated sentence.
 */
export function kindExplanation( kind, targetBlock ) {
	switch ( kind ) {
		case KIND_BLOCK_STYLE:
			return sprintf(
				// translators: %s: target block name, e.g. core/quote.
				__(
					'Adds a new option to the Styles panel of the %s block. It changes appearance only — no new fields, no new markup.',
					'ai-block-creator'
				),
				targetBlock
			);
		case KIND_BLOCK_VARIATION:
			return sprintf(
				// translators: %s: target block name, e.g. core/columns.
				__(
					'Adds a ready-made preset of the %s block to the inserter, with its settings already filled in.',
					'ai-block-creator'
				),
				targetBlock
			);
		case KIND_BLOCK_PATTERN:
			return __(
				'Inserts a ready-made arrangement of ordinary blocks that you then edit like any other content. It joins the inserter\u2019s pattern list the next time the editor loads.',
				'ai-block-creator'
			);
		default:
			return __(
				'Creates a brand-new block with its own editable fields, added to the AI Custom Blocks category.',
				'ai-block-creator'
			);
	}
}

/**
 * The kinds an author can switch to, given the one currently chosen.
 *
 * @param {string} currentKind Kind currently in effect.
 * @return {Array<{kind: string, label: string}>} Alternatives.
 */
export function alternativeKinds( currentKind ) {
	return [
		KIND_CUSTOM_BLOCK,
		KIND_BLOCK_STYLE,
		KIND_BLOCK_VARIATION,
		KIND_BLOCK_PATTERN,
	]
		.filter( ( kind ) => kind !== currentKind )
		.map( ( kind ) => ( { kind, label: kindLabel( kind ) } ) );
}

/**
 * Convenience wrapper: the label for a whole definition object.
 *
 * @param {Object} def Definition.
 * @return {string} Translated label.
 */
export function definitionKindLabel( def ) {
	return kindLabel( kindOf( def ) );
}
