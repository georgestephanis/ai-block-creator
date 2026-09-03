/**
 * AI Inserter Tab.
 * Integrates directly into Gutenberg's "+" Inserter Panel as a dedicated tab
 * alongside Blocks, Patterns, and Media.
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { Button, Spinner, Icon, Notice } from '@wordpress/components';
import { createBlock, getBlockType } from '@wordpress/blocks';
import { useDispatch } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import { starFilled, plus, pencil } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';
import VoiceInput from './VoiceInput';
import { LIBRARY_UPDATED_EVENT } from './BlockLibrarySidebar';

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
		label: __( '👤 Author / Speaker Bio', 'ai-block-creator' ),
		prompt: 'Create a speaker bio card with circular profile image, bio paragraph, social media link badges, and topic tags.',
	},
];

export default function AIInserterTab( { onOpenModal } ) {
	const [ quickPrompt, setQuickPrompt ] = useState( '' );
	const [ savedBlocks, setSavedBlocks ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ searchQuery, setSearchQuery ] = useState( '' );

	const { insertBlocks } = useDispatch( 'core/block-editor' );

	const loadBlocks = useCallback( async () => {
		setIsLoading( true );
		setError( '' );
		try {
			const res = await apiFetch( {
				path: '/ai-block-creator/v1/blocks',
				method: 'GET',
			} );
			setSavedBlocks( Array.isArray( res ) ? res : [] );
		} catch ( err ) {
			setError(
				err.message ||
					__( 'Failed to load saved AI blocks.', 'ai-block-creator' )
			);
		} finally {
			setIsLoading( false );
		}
	}, [] );

	useEffect( () => {
		loadBlocks();
		const handleUpdate = () => loadBlocks();
		window.addEventListener( LIBRARY_UPDATED_EVENT, handleUpdate );
		return () =>
			window.removeEventListener( LIBRARY_UPDATED_EVENT, handleUpdate );
	}, [ loadBlocks ] );

	const handleInsert = ( blockDef ) => {
		try {
			const blockName = blockDef.name;
			if ( ! getBlockType( blockName ) ) {
				onOpenModal( { block: blockDef } );
				return;
			}
			const newBlock = createBlock( blockName, {} );
			insertBlocks( newBlock );
		} catch ( err ) {
			setError(
				err.message ||
					__( 'Could not insert block.', 'ai-block-creator' )
			);
		}
	};

	const handleLaunchWithPrompt = ( promptToUse ) => {
		const targetPrompt = promptToUse || quickPrompt;
		onOpenModal( { prompt: targetPrompt } );
	};

	const handleVoiceTranscript = ( transcript ) => {
		setQuickPrompt( ( prev ) =>
			prev ? `${ prev } ${ transcript }` : transcript
		);
	};

	const filteredBlocks = savedBlocks.filter( ( block ) => {
		if ( ! searchQuery.trim() ) {
			return true;
		}
		const q = searchQuery.toLowerCase();
		return (
			( block.title || '' ).toLowerCase().includes( q ) ||
			( block.name || '' ).toLowerCase().includes( q ) ||
			( block.description || '' ).toLowerCase().includes( q )
		);
	} );

	const renderSavedBlocksContent = () => {
		if ( isLoading ) {
			return (
				<div className="ai-inserter-loading">
					<Spinner />
					<span>
						{ __( 'Loading AI blocks…', 'ai-block-creator' ) }
					</span>
				</div>
			);
		}

		if ( filteredBlocks.length === 0 ) {
			return (
				<div className="ai-inserter-empty-state">
					{ searchQuery ? (
						<p>
							{ sprintf(
								// translators: %s: search query
								__(
									'No AI blocks match "%s".',
									'ai-block-creator'
								),
								searchQuery
							) }
						</p>
					) : (
						<>
							<p>
								{ __(
									'No custom blocks created yet.',
									'ai-block-creator'
								) }
							</p>
							<Button
								variant="secondary"
								size="small"
								onClick={ () => onOpenModal() }
							>
								{ __(
									'Create Your First Block',
									'ai-block-creator'
								) }
							</Button>
						</>
					) }
				</div>
			);
		}

		return (
			<div className="ai-inserter-blocks-grid">
				{ filteredBlocks.map( ( block ) => (
					<div
						key={ block.id || block.name }
						className="ai-inserter-block-item"
					>
						<div
							className="ai-inserter-block-clickable"
							onClick={ () => handleInsert( block ) }
							role="button"
							tabIndex={ 0 }
							onKeyDown={ ( e ) => {
								if ( e.key === 'Enter' || e.key === ' ' ) {
									e.preventDefault();
									handleInsert( block );
								}
							} }
						>
							<span className="ai-inserter-block-icon">
								<Icon icon={ starFilled } />
							</span>
							<div className="ai-inserter-block-info">
								<strong className="ai-inserter-block-title">
									{ block.title || block.name }
								</strong>
								{ block.description && (
									<span className="ai-inserter-block-desc">
										{ block.description }
									</span>
								) }
							</div>
						</div>

						<div className="ai-inserter-block-actions">
							<Button
								variant="secondary"
								size="small"
								icon={ plus }
								label={ __(
									'Insert into post',
									'ai-block-creator'
								) }
								onClick={ () => handleInsert( block ) }
							/>
							<Button
								variant="tertiary"
								size="small"
								icon={ pencil }
								label={ __(
									'Refine with AI',
									'ai-block-creator'
								) }
								onClick={ () => onOpenModal( { block } ) }
							/>
						</div>
					</div>
				) ) }
			</div>
		);
	};

	return (
		<div className="ai-inserter-tab-panel">
			{ /* Quick Create Banner */ }
			<div className="ai-inserter-hero-card">
				<div className="ai-inserter-hero-header">
					<div className="ai-inserter-hero-title">
						<span className="ai-inserter-hero-sparkle">✨</span>
						<strong>
							{ __( 'Create with AI', 'ai-block-creator' ) }
						</strong>
					</div>
					<Button
						variant="primary"
						size="small"
						className="ai-inserter-open-modal-btn"
						onClick={ () => handleLaunchWithPrompt() }
					>
						{ __( 'Open Creator', 'ai-block-creator' ) }
					</Button>
				</div>

				<p className="ai-inserter-hero-desc">
					{ __(
						'Describe any custom block to generate and insert it in seconds.',
						'ai-block-creator'
					) }
				</p>

				<div className="ai-inserter-prompt-input-wrap">
					<input
						type="text"
						className="ai-inserter-prompt-input"
						placeholder={ __(
							'e.g. 3-tier pricing table, FAQ accordion…',
							'ai-block-creator'
						) }
						value={ quickPrompt }
						onChange={ ( e ) => setQuickPrompt( e.target.value ) }
						onKeyDown={ ( e ) => {
							if ( e.key === 'Enter' && quickPrompt.trim() ) {
								e.preventDefault();
								handleLaunchWithPrompt();
							}
						} }
					/>
					<VoiceInput onTranscript={ handleVoiceTranscript } />
					<Button
						variant="secondary"
						size="small"
						disabled={ ! quickPrompt.trim() }
						onClick={ () => handleLaunchWithPrompt() }
					>
						{ __( 'Generate', 'ai-block-creator' ) }
					</Button>
				</div>

				{ /* Suggestion Chips */ }
				<div className="ai-inserter-chips">
					{ SUGGESTION_CHIPS.map( ( chip, idx ) => (
						<button
							key={ idx }
							type="button"
							className="ai-inserter-chip"
							onClick={ () =>
								handleLaunchWithPrompt( chip.prompt )
							}
						>
							{ chip.label }
						</button>
					) ) }
				</div>
			</div>

			{ error && (
				<Notice
					status="error"
					isDismissible={ true }
					onRemove={ () => setError( '' ) }
				>
					{ error }
				</Notice>
			) }

			{ /* Saved Custom Blocks List */ }
			<div className="ai-inserter-saved-section">
				<div className="ai-inserter-saved-header">
					<h3>
						{ __( 'Your AI Custom Blocks', 'ai-block-creator' ) }
					</h3>
					{ savedBlocks.length > 3 && (
						<input
							type="search"
							className="ai-inserter-search-input"
							placeholder={ __(
								'Filter blocks…',
								'ai-block-creator'
							) }
							value={ searchQuery }
							onChange={ ( e ) =>
								setSearchQuery( e.target.value )
							}
						/>
					) }
				</div>

				{ renderSavedBlocksContent() }
			</div>
		</div>
	);
}
