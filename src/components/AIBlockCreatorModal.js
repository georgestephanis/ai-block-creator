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
} from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { createBlock } from '@wordpress/blocks';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import VoiceInput from './VoiceInput';
import ImageDropzone from './ImageDropzone';
import BlockPreview from './BlockPreview';
import { registerDynamicAiBlock } from '../runtime/dynamic-block-factory';
import { notifyLibraryUpdated } from './BlockLibrarySidebar';

const SUGGESTIONS = [
	{
		label: '⚡ Pricing Table (3 tiers)',
		prompt: 'Create a modern, responsive 3-tier pricing comparison table with a highlighted popular plan, feature checklists, and CTA buttons.',
	},
	{
		label: '⭐ Testimonial Card',
		prompt: 'Create a customer testimonial card with 5 star ratings, customer avatar, quote, author name, role, and company logo placeholder.',
	},
	{
		label: '❓ FAQ Accordion',
		prompt: 'Create an interactive FAQ accordion with expandable question panels, smooth chevron indicator, and styled answer section.',
	},
	{
		label: '👤 Speaker / Author Bio',
		prompt: 'Create a speaker bio card with circular profile image, bio paragraph, social media link badges, and topic tags.',
	},
	{
		label: '🚀 Feature Grid',
		prompt: 'Create a 3-column feature highlight card grid with colorful icon badges, feature titles, and brief descriptions.',
	},
	{
		label: '📊 Stats Counter Banner',
		prompt: 'Create an impressive statistics / milestone banner with 4 metrics, large bold numbers, and subtitle labels on a dark gradient background.',
	},
];

const INITIAL_STATE = {
	prompt: '',
	screenshot: null,
	error: null,
	currentBlock: null,
	conversation: [],
	saveSuccess: false,
};

export default function AIBlockCreatorModal( {
	isOpen,
	onClose,
	placeholderClientId,
	initialBlock,
	initialPrompt,
} ) {
	const [ prompt, setPrompt ] = useState( INITIAL_STATE.prompt );
	const [ screenshot, setScreenshot ] = useState( INITIAL_STATE.screenshot );
	const [ isGenerating, setIsGenerating ] = useState( false );
	const [ error, setError ] = useState( INITIAL_STATE.error );
	const [ currentBlock, setCurrentBlock ] = useState(
		INITIAL_STATE.currentBlock
	);
	const [ conversation, setConversation ] = useState(
		INITIAL_STATE.conversation
	);
	const [ isSaving, setIsSaving ] = useState( false );
	const [ saveSuccess, setSaveSuccess ] = useState(
		INITIAL_STATE.saveSuccess
	);

	const { insertBlocks, replaceBlocks, removeBlock } =
		useDispatch( 'core/block-editor' );

	// Seed the modal with a block loaded from the library (see
	// BlockLibrarySidebar's "Refine" action) or a prompt passed from the inserter.
	useEffect( () => {
		if ( isOpen ) {
			if ( initialPrompt ) {
				setPrompt( initialPrompt );
			}
			if ( initialBlock ) {
				setCurrentBlock( initialBlock );
				setConversation( [
					{
						role: 'assistant',
						content: sprintf(
							// translators: %s: block title.
							__(
								'Loaded "%s" from your library. Describe changes to refine it, or use the buttons below to reinsert or resave it as-is.',
								'ai-block-creator'
							),
							initialBlock.title || initialBlock.name
						),
					},
				] );
			}
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ isOpen ] );

	if ( ! isOpen ) {
		return null;
	}

	const settings = window.aiBlockCreatorSettings || {};
	const hasConnectedLlm = settings.hasConnectedLlm !== false;
	const supportsImageInput = Boolean( settings.supportsImageInput );
	const canManageLibrary = settings.canManageLibrary !== false;

	const resetState = () => {
		setPrompt( INITIAL_STATE.prompt );
		setScreenshot( INITIAL_STATE.screenshot );
		setError( INITIAL_STATE.error );
		setCurrentBlock( INITIAL_STATE.currentBlock );
		setConversation( INITIAL_STATE.conversation );
		setSaveSuccess( INITIAL_STATE.saveSuccess );
	};

	const handleClose = () => {
		if ( placeholderClientId ) {
			removeBlock( placeholderClientId );
		}
		resetState();
		onClose();
	};

	const handleVoiceTranscript = ( transcript ) => {
		setPrompt( ( prev ) =>
			prev ? `${ prev } ${ transcript }` : transcript
		);
	};

	const handleGenerate = async ( explicitPrompt = null ) => {
		const targetPrompt = explicitPrompt || prompt;
		if ( ! targetPrompt && ! screenshot ) {
			return;
		}

		setIsGenerating( true );
		setError( null );
		setSaveSuccess( false );

		const newConversation = [
			...conversation,
			{
				role: 'user',
				content:
					targetPrompt ||
					__( '[Screenshot uploaded]', 'ai-block-creator' ),
			},
		];
		setConversation( newConversation );

		try {
			const payload = {
				prompt: targetPrompt,
				history: newConversation,
			};

			if ( supportsImageInput && screenshot ) {
				payload.image = screenshot;
			}

			if ( currentBlock ) {
				payload.current_block = currentBlock;
			}

			const response = await apiFetch( {
				path: '/ai-block-creator/v1/generate',
				method: 'POST',
				data: payload,
			} );

			const block = response?.block || response;

			if ( block && block.name ) {
				registerDynamicAiBlock( block );
				setCurrentBlock( block );
				setConversation( ( prev ) => [
					...prev,
					{
						role: 'assistant',
						content: sprintf(
							// translators: %s: block title.
							__(
								'Created block "%s"! You can preview it on the right, refine it further, or insert it into your post.',
								'ai-block-creator'
							),
							block.title || block.name
						),
					},
				] );
				setPrompt( '' );
				setScreenshot( null );
			} else {
				throw new Error(
					__(
						'Invalid response from AI generator.',
						'ai-block-creator'
					)
				);
			}
		} catch ( err ) {
			const message =
				err?.message ||
				err?.data?.message ||
				__( 'Failed to generate block.', 'ai-block-creator' );
			setError( message );
			setConversation( ( prev ) => [
				...prev,
				{
					role: 'assistant',
					content: sprintf(
						// translators: %s: error message.
						__( 'Error: %s', 'ai-block-creator' ),
						message
					),
				},
			] );
		} finally {
			setIsGenerating( false );
		}
	};

	const handleSaveToLibrary = async () => {
		if ( ! currentBlock || isSaving ) {
			return;
		}
		setIsSaving( true );
		setError( null );

		try {
			const saved = await apiFetch( {
				path: '/ai-block-creator/v1/blocks',
				method: 'POST',
				data: currentBlock,
			} );

			registerDynamicAiBlock( saved );
			setCurrentBlock( saved );
			setSaveSuccess( true );
			notifyLibraryUpdated();
		} catch ( err ) {
			setError(
				err?.message ||
					err?.data?.message ||
					__( 'Failed to save block to library.', 'ai-block-creator' )
			);
		} finally {
			setIsSaving( false );
		}
	};

	const handleInsertIntoPost = () => {
		if ( ! currentBlock ) {
			return;
		}

		const blockName = currentBlock.name.startsWith( 'ai-block/' )
			? currentBlock.name
			: `ai-block/${ currentBlock.name }`;

		const newBlockInstance = createBlock(
			blockName,
			currentBlock.attributes || {}
		);

		if ( placeholderClientId ) {
			replaceBlocks( placeholderClientId, newBlockInstance );
		} else {
			insertBlocks( newBlockInstance );
		}

		onClose();
	};

	const generateButtonLabel = currentBlock
		? __( '✨ Refine Block', 'ai-block-creator' )
		: __( '✨ Generate Block', 'ai-block-creator' );

	return (
		<Modal
			title={
				<div className="ai-modal-header-title">
					<span className="ai-sparkle-badge">✨</span>
					<span>
						{ __( 'AI Block Creator', 'ai-block-creator' ) }
					</span>
					<span className="ai-modal-tagline">
						{ supportsImageInput
							? __(
									'Speak, type, or screenshot blocks into existence',
									'ai-block-creator'
							  )
							: __(
									'Speak or type blocks into existence',
									'ai-block-creator'
							  ) }
					</span>
				</div>
			}
			onRequestClose={ handleClose }
			className="ai-block-creator-modal"
			isFullScreen={ false }
		>
			<div className="ai-block-creator-container">
				{ ! hasConnectedLlm && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'No AI provider is currently configured for this site. Ask an administrator to connect one in Settings before generating blocks.',
							'ai-block-creator'
						) }
					</Notice>
				) }

				{ ! canManageLibrary && (
					<Notice status="info" isDismissible={ false }>
						{ __(
							'You can generate and preview blocks, but saving them to the library requires additional permissions. Ask an administrator to save this block for you.',
							'ai-block-creator'
						) }
					</Notice>
				) }

				{ error && (
					<Notice
						status="error"
						isDismissible={ false }
						className="ai-error-notice"
					>
						{ error }
					</Notice>
				) }

				{ saveSuccess && (
					<Notice
						status="success"
						isDismissible={ true }
						onDismiss={ () => setSaveSuccess( false ) }
					>
						{ __(
							'Custom block successfully saved to library! It is now available in your block inserter.',
							'ai-block-creator'
						) }
					</Notice>
				) }

				{ /* Conversation history view if multiple turns */ }
				{ conversation.length > 0 && (
					<div className="ai-conversation-thread">
						{ conversation.map( ( msg, idx ) => (
							<div
								key={ idx }
								className={ `ai-chat-bubble is-${ msg.role }` }
							>
								<span className="ai-chat-role">
									{ msg.role === 'user'
										? __( 'You', 'ai-block-creator' )
										: __(
												'AI Assistant',
												'ai-block-creator'
										  ) }
								</span>
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
								<span className="ai-section-label">
									{ __( 'Quick Ideas:', 'ai-block-creator' ) }
								</span>
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
											disabled={
												isGenerating ||
												! hasConnectedLlm
											}
										>
											{ item.label }
										</button>
									) ) }
								</div>
							</div>
						) }

						<div className="ai-input-card">
							<TextareaControl
								label={
									currentBlock
										? '💬 ' +
										  __(
												'Refine your block:',
												'ai-block-creator'
										  )
										: '📝 ' +
										  __(
												'Describe the block you want to create:',
												'ai-block-creator'
										  )
								}
								value={ prompt }
								onChange={ setPrompt }
								placeholder={
									currentBlock
										? __(
												'e.g. "Add a price badge", "Make the button blue with gradient", "Add dark background"…',
												'ai-block-creator'
										  )
										: __(
												'e.g. "Create a pricing comparison table with 3 plans and a featured badge"…',
												'ai-block-creator'
										  )
								}
								rows={ 4 }
								disabled={ isGenerating || ! hasConnectedLlm }
								className="ai-main-textarea"
							/>

							<div className="ai-input-actions-bar">
								<div className="ai-multimodal-tools">
									<VoiceInput
										onTranscript={ handleVoiceTranscript }
										disabled={
											isGenerating || ! hasConnectedLlm
										}
									/>
									{ supportsImageInput && (
										<ImageDropzone
											image={ screenshot }
											onImageChange={ setScreenshot }
											disabled={
												isGenerating ||
												! hasConnectedLlm
											}
										/>
									) }
								</div>

								<Button
									variant="primary"
									onClick={ () => handleGenerate() }
									disabled={
										isGenerating ||
										! hasConnectedLlm ||
										( ! prompt && ! screenshot )
									}
									className="ai-generate-submit-btn"
								>
									{ isGenerating ? (
										<>
											<Spinner />
											<span>
												{ __(
													'Generating…',
													'ai-block-creator'
												) }
											</span>
										</>
									) : (
										generateButtonLabel
									) }
								</Button>
							</div>
						</div>

						{ currentBlock && (
							<div className="ai-refine-tips">
								💡{ ' ' }
								<strong>
									{ __( 'Tip:', 'ai-block-creator' ) }
								</strong>{ ' ' }
								{ __(
									'Type follow-up instructions to refine styles, add attributes, or tweak layout without losing your current progress.',
									'ai-block-creator'
								) }
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
									<span className="ai-placeholder-icon">
										🎨
									</span>
									<h4>
										{ __(
											'Live Block Preview',
											'ai-block-creator'
										) }
									</h4>
									<p>
										{ supportsImageInput
											? __(
													'Type a description, dictate with your microphone, or paste a screenshot to watch your custom block come to life here.',
													'ai-block-creator'
											  )
											: __(
													'Type a description or dictate with your microphone to watch your custom block come to life here.',
													'ai-block-creator'
											  ) }
									</p>
								</div>
							</div>
						) }
					</div>
				</div>

				{ /* Modal Footer Actions */ }
				<div className="ai-modal-footer">
					<Button
						variant="tertiary"
						onClick={ handleClose }
						disabled={ isGenerating || isSaving }
					>
						{ __( 'Cancel', 'ai-block-creator' ) }
					</Button>

					{ currentBlock && (
						<div className="ai-footer-primary-actions">
							<Button
								variant="secondary"
								onClick={ handleSaveToLibrary }
								disabled={
									isGenerating ||
									isSaving ||
									! canManageLibrary
								}
							>
								{ isSaving ? (
									<Spinner />
								) : (
									__( 'Save to Library', 'ai-block-creator' )
								) }
							</Button>
							<Button
								variant="primary"
								onClick={ handleInsertIntoPost }
								disabled={
									isGenerating ||
									isSaving ||
									! canManageLibrary
								}
								className="ai-insert-btn"
							>
								{ isSaving ? (
									<Spinner />
								) : (
									'🚀 ' +
									__( 'Insert into Post', 'ai-block-creator' )
								) }
							</Button>
						</div>
					) }
				</div>
			</div>
		</Modal>
	);
}
