/**
 * AI Inserter Card.
 * A featured action card rendered at the top of Gutenberg's native Blocks
 * inserter tab, allowing users to type prompts or click chips directly
 * from the standard "+" menu.
 */

import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const SUGGESTION_CHIPS = [
	{
		label: __( '⚡ Pricing Table', 'ai-block-creator' ),
		prompt: 'Create a modern, responsive 3-tier pricing comparison table with a highlighted popular plan, feature checklists, and CTA buttons.',
	},
	{
		label: __( '⭐ Testimonial', 'ai-block-creator' ),
		prompt: 'Create a customer testimonial card with 5 star ratings, customer avatar, quote, author name, role, and company logo placeholder.',
	},
	{
		label: __( '❓ FAQ Accordion', 'ai-block-creator' ),
		prompt: 'Create an interactive FAQ accordion with expandable question panels, smooth chevron indicator, and styled answer section.',
	},
	{
		label: __( '📊 Stats Banner', 'ai-block-creator' ),
		prompt: 'Create an impressive statistics / milestone banner with 4 metrics, large bold numbers, and subtitle labels on a dark gradient background.',
	},
	{
		label: __( '🚀 Feature Grid', 'ai-block-creator' ),
		prompt: 'Create a 3-column feature highlight card grid with colorful icon badges, feature titles, and brief descriptions.',
	},
];

export default function AIInserterCard( { onOpenModal } ) {
	const [ quickPrompt, setQuickPrompt ] = useState( '' );

	const handleLaunch = ( promptToUse ) => {
		const targetPrompt = promptToUse || quickPrompt;
		if ( onOpenModal ) {
			onOpenModal( { prompt: targetPrompt } );
		}
	};

	return (
		<div className="ai-inserter-featured-card">
			<div className="ai-inserter-featured-header">
				<div className="ai-inserter-featured-title">
					<span className="ai-sparkle">✨</span>
					<strong>
						{ __( 'Create with AI', 'ai-block-creator' ) }
					</strong>
				</div>
				<Button
					variant="tertiary"
					size="small"
					className="ai-inserter-featured-link"
					onClick={ () => handleLaunch() }
				>
					{ __( 'Open Modal ↗', 'ai-block-creator' ) }
				</Button>
			</div>

			<div className="ai-inserter-featured-input-wrap">
				<input
					type="text"
					className="ai-inserter-featured-input"
					placeholder={ __(
						'Describe a block to generate…',
						'ai-block-creator'
					) }
					value={ quickPrompt }
					onChange={ ( e ) => setQuickPrompt( e.target.value ) }
					onKeyDown={ ( e ) => {
						if ( e.key === 'Enter' && quickPrompt.trim() ) {
							e.preventDefault();
							handleLaunch();
						}
					} }
				/>
				<Button
					variant="primary"
					size="small"
					disabled={ ! quickPrompt.trim() }
					onClick={ () => handleLaunch() }
				>
					{ __( 'Create', 'ai-block-creator' ) }
				</Button>
			</div>

			<div className="ai-inserter-featured-chips">
				{ SUGGESTION_CHIPS.map( ( chip, idx ) => (
					<button
						key={ idx }
						type="button"
						className="ai-inserter-featured-chip"
						onClick={ () => handleLaunch( chip.prompt ) }
					>
						{ chip.label }
					</button>
				) ) }
			</div>
		</div>
	);
}
