/**
 * AI Block Creator Main Modal & Conversational Interface.
 */

import { useState, useEffect } from '@wordpress/element';
import {
	Modal,
	Button,
	Spinner,
	Notice,
	TextareaControl,
	ButtonGroup,
} from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { createBlock } from '@wordpress/blocks';
import apiFetch from '@wordpress/api-fetch';
import VoiceInput from './VoiceInput';
import ImageDropzone from './ImageDropzone';
import BlockPreview from './BlockPreview';
import { registerDynamicAiBlock } from '../runtime/dynamic-block-factory';

const SUGGESTIONS = [
	{ label: '⚡ Pricing Table (3 tiers)', prompt: 'Create a modern, responsive 3-tier pricing comparison table with a highlighted popular plan, feature checklists, and CTA buttons.' },
	{ label: '⭐ Testimonial Card', prompt: 'Create a customer testimonial card with 5 star ratings, customer avatar, quote, author name, role, and company logo placeholder.' },
	{ label: '❓ FAQ Accordion', prompt: 'Create an interactive FAQ accordion with expandable question panels, smooth chevron indicator, and styled answer section.' },
	{ label: '👤 Speaker / Author Bio', prompt: 'Create a speaker bio card with circular profile image, bio paragraph, social media link badges, and topic tags.' },
	{ label: '🚀 Feature Grid', prompt: 'Create a 3-column feature highlight card grid with colorful icon badges, feature titles, and brief descriptions.' },
	{ label: '📊 Stats Counter Banner', prompt: 'Create an impressive statistics / milestone banner with 4 metrics, large bold numbers, and subtitle labels on a dark gradient background.' },
];

export default function AIBlockCreatorModal( { isOpen, onClose, onBlockCreated } ) {
	const [ prompt, setPrompt ] = useState( '' );
	const [ screenshot, setScreenshot ] = useState( null );
	const [ isGenerating, setIsGenerating ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ currentBlock, setCurrentBlock ] = useState( null );
	const [ conversation, setConversation ] = useState( [] );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ saveSuccess, setSaveSuccess ] = useState( false );

	const { insertBlocks } = useDispatch( 'core/block-editor' );

	if ( ! isOpen ) {
		return null;
	}

	const handleVoiceTranscript = ( transcript ) => {
		setPrompt( ( prev ) => ( prev ? `${ prev } ${ transcript }` : transcript ) );
	};

	const handleGenerate = async ( customPrompt ) => {
		const targetPrompt = customPrompt || prompt;
		if ( ! targetPrompt && ! screenshot ) {
			return;
		}

		setIsGenerating( true );
		setError( null );
		setSaveSuccess( false );

		const userMessage = {
			role: 'user',
			content: targetPrompt + ( screenshot ? ' [Attached screenshot]' : '' ),
			hasImage: Boolean( screenshot ),
		};

		setConversation( ( prev ) => [ ...prev, userMessage ] );

		try {
			const settings = window.aiBlockCreatorSettings || {};
			const response = await apiFetch( {
				path: '/ai-block-creator/v1/generate',
				method: 'POST',
				data: {
					prompt: targetPrompt,
					image: screenshot,
					history: conversation,
					current_block: currentBlock,
				},
				headers: {
					'X-WP-Nonce': settings.nonce || '',
				},
			} );

			if ( response && response.block ) {
				setCurrentBlock( response.block );
				setConversation( ( prev ) => [
					...prev,
					{
						role: 'assistant',
						content: `Generated custom block "${ response.block.title }".`,
						block: response.block,
					},
				] );
				// Clear prompt for next conversational refinement
				setPrompt( '' );
			} else {
				throw new Error( 'No block definition returned from server.' );
			}
		} catch ( err ) {
			console.error( 'AI Block Generation error:', err );
			setError( err.message || 'Failed to generate block. Please try again.' );
		} finally {
			setIsGenerating( false );
		}
	};

	const handleInsertIntoPost = async () => {
		if ( ! currentBlock ) {
			return;
		}

		setIsSaving( true );
		try {
			// Register in client-side Gutenberg runtime immediately
			registerDynamicAiBlock( currentBlock );

			// Persist block definition to server
			const settings = window.aiBlockCreatorSettings || {};
			await apiFetch( {
				path: '/ai-block-creator/v1/blocks',
				method: 'POST',
				data: {
					block_definition: currentBlock,
				},
				headers: {
					'X-WP-Nonce': settings.nonce || '',
				},
			} );

			// Prepare default attributes
			const initialAttrs = {};
			if ( currentBlock.attributes ) {
				Object.keys( currentBlock.attributes ).forEach( ( k ) => {
					initialAttrs[ k ] = currentBlock.attributes[ k ]?.default ?? '';
				} );
			}

			// Create and insert block instance into editor canvas
			const blockInstance = createBlock( currentBlock.name, initialAttrs );
			insertBlocks( blockInstance );

			if ( onBlockCreated ) {
				onBlockCreated( currentBlock );
			}

			onClose();
		} catch ( err ) {
			console.error( 'Failed to save/insert block:', err );
			setError( 'Failed to save block to server: ' + ( err.message || '' ) );
		} finally {
			setIsSaving( false );
		}
	};

	const handleSaveToLibrary = async () => {
		if ( ! currentBlock ) {
			return;
		}

		setIsSaving( true );
		try {
			registerDynamicAiBlock( currentBlock );

			const settings = window.aiBlockCreatorSettings || {};
			await apiFetch( {
				path: '/ai-block-creator/v1/blocks',
				method: 'POST',
				data: {
					block_definition: currentBlock,
				},
				headers: {
					'X-WP-Nonce': settings.nonce || '',
				},
			} );

			setSaveSuccess( true );
			if ( onBlockCreated ) {
				onBlockCreated( currentBlock );
			}
		} catch ( err ) {
			console.error( 'Failed to save block:', err );
			setError( 'Failed to save block: ' + ( err.message || '' ) );
		} finally {
			setIsSaving( false );
		}
	};

	return (
		<Modal
			title={
				<div className="ai-modal-header-title">
					<span className="ai-sparkle-badge">✨</span>
					<span>AI Block Creator</span>
					<span className="ai-modal-tagline">Speak, type, or screenshot blocks into existence</span>
				</div>
			}
			onRequestClose={ onClose }
			className="ai-block-creator-modal"
			isFullScreen={ false }
		>
			<div className="ai-block-creator-container">
				{ error && (
					<Notice status="error" isDismissible={ false } className="ai-error-notice">
						{ error }
					</Notice>
				) }

				{ saveSuccess && (
					<Notice status="success" isDismissible={ true } onDismiss={ () => setSaveSuccess( false ) }>
						Custom block successfully saved to library! It is now available in your block inserter.
					</Notice>
				) }

				{ /* Conversation history view if multiple turns */ }
				{ conversation.length > 0 && (
					<div className="ai-conversation-thread">
						{ conversation.map( ( msg, idx ) => (
							<div key={ idx } className={ `ai-chat-bubble is-${ msg.role }` }>
								<span className="ai-chat-role">{ msg.role === 'user' ? 'You' : 'AI Assistant' }</span>
								<p className="ai-chat-text">{ msg.content }</p>
							</div>
						) ) }
					</div>
				) }

				{ /* Main Content Area */ }
				<div className="ai-modal-body-grid">
					{ /* Left / Input Column */ }
					<div className="ai-prompt-column">
						{ ! currentBlock && (
							<div className="ai-suggestions-section">
								<span className="ai-section-label">Quick Ideas:</span>
								<div className="ai-suggestions-list">
									{ SUGGESTIONS.map( ( item, i ) => (
										<button
											key={ i }
											type="button"
											className="ai-suggestion-pill"
											onClick={ () => {
												setPrompt( item.prompt );
												handleGenerate( item.prompt );
											} }
											disabled={ isGenerating }
										>
											{ item.label }
										</button>
									) ) }
								</div>
							</div>
						) }

						<div className="ai-input-card">
							<TextareaControl
								label={ currentBlock ? '💬 Refine your block:' : '📝 Describe the block you want to create:' }
								value={ prompt }
								onChange={ setPrompt }
								placeholder={
									currentBlock
										? 'e.g. "Add a price badge", "Make the button blue with gradient", "Add dark background"...'
										: 'e.g. "Create a pricing comparison table with 3 plans and a featured badge"...'
								}
								rows={ 4 }
								disabled={ isGenerating }
								className="ai-main-textarea"
							/>

							<div className="ai-input-actions-bar">
								<div className="ai-multimodal-tools">
									<VoiceInput
										onTranscript={ handleVoiceTranscript }
										disabled={ isGenerating }
									/>
									<ImageDropzone
										image={ screenshot }
										onImageChange={ setScreenshot }
										disabled={ isGenerating }
									/>
								</div>

								<Button
									variant="primary"
									onClick={ () => handleGenerate() }
									disabled={ isGenerating || ( ! prompt && ! screenshot ) }
									className="ai-generate-submit-btn"
								>
									{ isGenerating ? (
										<>
											<Spinner />
											<span>Generating...</span>
										</>
									) : currentBlock ? (
										'✨ Refine Block'
									) : (
										'✨ Generate Block'
									)}
								</Button>
							</div>
						</div>

						{ currentBlock && (
							<div className="ai-refine-tips">
								💡 <strong>Tip:</strong> Type follow-up instructions to refine styles, add attributes, or tweak layout without losing your current progress.
							</div>
						) }
					</div>

					{ /* Right / Preview Column */ }
					<div className="ai-preview-column">
						{ currentBlock ? (
							<BlockPreview blockDef={ currentBlock } />
						) : (
							<div className="ai-preview-placeholder">
								<div className="ai-placeholder-content">
									<span className="ai-placeholder-icon">🎨</span>
									<h4>Live Block Preview</h4>
									<p>Type a description, dictate with your microphone, or paste a screenshot to watch your custom block come to life here.</p>
								</div>
							</div>
						) }
					</div>
				</div>

				{ /* Modal Footer Actions */ }
				<div className="ai-modal-footer">
					<Button variant="tertiary" onClick={ onClose } disabled={ isGenerating || isSaving }>
						Cancel
					</Button>

					{ currentBlock && (
						<div className="ai-footer-primary-actions">
							<Button
								variant="secondary"
								onClick={ handleSaveToLibrary }
								disabled={ isGenerating || isSaving }
							>
								{ isSaving ? <Spinner /> : 'Save to Library' }
							</Button>
							<Button
								variant="primary"
								onClick={ handleInsertIntoPost }
								disabled={ isGenerating || isSaving }
								className="ai-insert-btn"
							>
								{ isSaving ? <Spinner /> : '🚀 Insert into Post' }
							</Button>
						</div>
					) }
				</div>
			</div>
		</Modal>
	);
}
