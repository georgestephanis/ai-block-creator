/**
 * AI Inserter Card.
 * A featured action card rendered at the top of Gutenberg's native Blocks
 * inserter tab, allowing users to speak/type prompts or click chips directly
 * from the standard "+" menu.
 */

import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import VoiceInput from './VoiceInput';

const SUGGESTION_CHIPS = [
	{
		label: __( '⚡ Pricing Table', 'ai-block-creator' ),
		prompt: 'Create a modern, responsive 3-tier pricing comparison table with a highlighted popular plan, feature checklists, and CTA buttons.',
	},
	{
		label: __( '⭐ Testimonial Card', 'ai-block-creator' ),
		prompt: 'Create a customer testimonial card with 5 star ratings, customer avatar, quote, author name, role, and company logo placeholder.',
	},
	{
		label: __( '❓ FAQ Accordion', 'ai-block-creator' ),
		prompt: 'Create an interactive FAQ accordion with expandable question panels, smooth chevron indicator, and styled answer section.',
	},
	{
		label: __( '📊 Stats Counter', 'ai-block-creator' ),
		prompt: 'Create an impressive statistics / milestone banner with 4 metrics, large bold numbers, and subtitle labels on a dark gradient background.',
	},
	{
		label: __( '🚀 Feature Grid', 'ai-block-creator' ),
		prompt: 'Create a 3-column feature highlight card grid with colorful icon badges, feature titles, and brief descriptions.',
	},
	{
		label: __( '👤 Speaker Bio', 'ai-block-creator' ),
		prompt: 'Create a speaker bio card with circular profile image, bio paragraph, social media link badges, and topic tags.',
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

	const handleVoiceTranscript = ( transcript ) => {
		setQuickPrompt( ( prev ) =>
			prev ? `${ prev } ${ transcript }` : transcript
		);
	};

	return (
		<div className="ai-inserter-featured-card">
			<div className="ai-inserter-featured-header">
				<div className="ai-inserter-featured-title">
					<span className="ai-sparkle">✨</span>
					<strong>
						{ __( 'Create Block with AI', 'ai-block-creator' ) }
					</strong>
				</div>
				<Button
					variant="primary"
					size="small"
					className="ai-inserter-featured-btn"
					onClick={ () => handleLaunch() }
				>
					{ __( 'Open Creator', 'ai-block-creator' ) }
				</Button>
			</div>

			<p className="ai-inserter-featured-desc">
				{ __(
					'Describe any custom block to generate and insert it.',
					'ai-block-creator'
				) }
			</p>

			<div className="ai-inserter-featured-input-wrap">
				<input
					type="text"
					className="ai-inserter-featured-input"
					placeholder={ __(
						'e.g. 3-tier pricing table, FAQ accordion…',
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
				<VoiceInput onTranscript={ handleVoiceTranscript } />
				<Button
					variant="secondary"
					size="small"
					disabled={ ! quickPrompt.trim() }
					onClick={ () => handleLaunch() }
				>
					{ __( 'Generate', 'ai-block-creator' ) }
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
