import { useState, useEffect, useCallback } from '@wordpress/element';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { Button, Spinner, Notice, Icon } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import {
	createBlock,
	unregisterBlockType,
	getBlockType,
} from '@wordpress/blocks';
import { __, sprintf } from '@wordpress/i18n';
import { starFilled, pencil, trash } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';
import VoiceInput from './VoiceInput';

export const LIBRARY_UPDATED_EVENT = 'ai-block-creator-library-updated';

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

/**
 * Dispatches the event BlockLibrarySidebar listens for to refresh its list.
 */
export function notifyLibraryUpdated() {
	window.dispatchEvent( new CustomEvent( LIBRARY_UPDATED_EVENT ) );
}

/**
 * A single row in the library list, with its own delete-confirmation state.
 *
 * @param {Object}   props                  Component props.
 * @param {Object}   props.block            Saved block definition.
 * @param {boolean}  props.canManageLibrary Whether the current user may delete.
 * @param {Function} props.onInsert         Called to insert the block into the post.
 * @param {Function} props.onRefine         Called to open the creator modal preloaded with this block.
 * @param {Function} props.onDeleted        Called with the block's id once deletion succeeds.
 */
function LibraryRow( {
	block,
	canManageLibrary,
	onInsert,
	onRefine,
	onDeleted,
} ) {
	const [ confirmingDelete, setConfirmingDelete ] = useState( false );
	const [ isDeleting, setIsDeleting ] = useState( false );
	const [ error, setError ] = useState( '' );

	const handleDelete = async () => {
		setIsDeleting( true );
		setError( '' );
		try {
			await apiFetch( {
				path: `/ai-block-creator/v1/blocks/${ block.id }`,
				method: 'DELETE',
			} );

			if ( getBlockType( block.name ) ) {
				try {
					unregisterBlockType( block.name );
				} catch ( e ) {
					// Already gone from registry.
				}
			}

			onDeleted( block.id );
		} catch ( err ) {
			setError(
				err.message ||
					__( 'Failed to delete block.', 'ai-block-creator' )
			);
			setIsDeleting( false );
			setConfirmingDelete( false );
		}
	};

	return (
		<div className="ai-library-row">
			<div className="ai-library-row-main">
				<span className="ai-library-row-icon">
					<Icon icon={ starFilled } />
				</span>
				<div className="ai-library-row-text">
					<strong className="ai-library-row-title">
						{ block.title || block.name }
					</strong>
					{ block.description && (
						<span className="ai-library-row-desc">
							{ block.description }
						</span>
					) }
				</div>
			</div>

			{ error && (
				<Notice
					status="error"
					isDismissible={ true }
					onRemove={ () => setError( '' ) }
					className="ai-library-row-error"
				>
					{ error }
				</Notice>
			) }

			<div className="ai-library-row-actions">
				<Button
					variant="secondary"
					size="small"
					onClick={ () => onInsert( block ) }
				>
					{ __( 'Insert', 'ai-block-creator' ) }
				</Button>
				<Button
					variant="tertiary"
					size="small"
					icon={ pencil }
					label={ __( 'Refine with AI', 'ai-block-creator' ) }
					onClick={ () => onRefine( block ) }
				/>
				{ canManageLibrary &&
					( confirmingDelete ? (
						<>
							<Button
								variant="tertiary"
								isDestructive
								size="small"
								isBusy={ isDeleting }
								disabled={ isDeleting }
								onClick={ handleDelete }
							>
								{ __( 'Confirm?', 'ai-block-creator' ) }
							</Button>
							<Button
								variant="tertiary"
								size="small"
								disabled={ isDeleting }
								onClick={ () => setConfirmingDelete( false ) }
							>
								{ __( 'Cancel', 'ai-block-creator' ) }
							</Button>
						</>
					) : (
						<Button
							variant="tertiary"
							isDestructive
							size="small"
							icon={ trash }
							label={ __( 'Delete block', 'ai-block-creator' ) }
							onClick={ () => setConfirmingDelete( true ) }
						/>
					) ) }
			</div>
		</div>
	);
}

export default function BlockLibrarySidebar( { onLaunchModal, onRefine } ) {
	const [ blocks, setBlocks ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ quickPrompt, setQuickPrompt ] = useState( '' );
	const [ searchQuery, setSearchQuery ] = useState( '' );

	const { insertBlocks } = useDispatch( 'core/block-editor' );

	const settings = window.aiBlockCreatorSettings || {};
	const canManageLibrary = settings.canManageLibrary !== false;

	const fetchBlocks = useCallback( async () => {
		setIsLoading( true );
		setError( '' );
		try {
			const response = await apiFetch( {
				path: '/ai-block-creator/v1/blocks',
				method: 'GET',
			} );
			setBlocks( Array.isArray( response ) ? response : [] );
		} catch ( err ) {
			setError(
				err.message ||
					__(
						'Failed to load your saved blocks.',
						'ai-block-creator'
					)
			);
		} finally {
			setIsLoading( false );
		}
	}, [] );

	useEffect( () => {
		fetchBlocks();
		window.addEventListener( LIBRARY_UPDATED_EVENT, fetchBlocks );
		return () =>
			window.removeEventListener( LIBRARY_UPDATED_EVENT, fetchBlocks );
	}, [ fetchBlocks ] );

	const handleInsert = ( block ) => {
		const initialAttrs = {};
		if ( block.attributes ) {
			Object.keys( block.attributes ).forEach( ( k ) => {
				initialAttrs[ k ] = block.attributes[ k ]?.default ?? '';
			} );
		}
		insertBlocks( createBlock( block.name, initialAttrs ) );
	};

	const handleDeleted = ( deletedId ) => {
		setBlocks( ( prev ) => prev.filter( ( b ) => b.id !== deletedId ) );
	};

	const handleLaunchWithPrompt = ( promptToUse ) => {
		const targetPrompt = promptToUse || quickPrompt;
		if ( onLaunchModal ) {
			onLaunchModal( { prompt: targetPrompt } );
		}
	};

	const handleVoiceTranscript = ( transcript ) => {
		setQuickPrompt( ( prev ) =>
			prev ? `${ prev } ${ transcript }` : transcript
		);
	};

	const filteredBlocks = blocks.filter( ( block ) => {
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

	return (
		<>
			<PluginSidebarMoreMenuItem target="ai-block-creator-sidebar">
				{ __( 'AI Block Creator', 'ai-block-creator' ) }
			</PluginSidebarMoreMenuItem>

			<PluginSidebar
				name="ai-block-creator-sidebar"
				title={ __( 'AI Block Creator', 'ai-block-creator' ) }
				icon="star-filled"
			>
				<div className="ai-library-sidebar">
					{ /* Quick Create Card */ }
					<div className="ai-sidebar-quick-card">
						<div className="ai-sidebar-quick-header">
							<div className="ai-sidebar-quick-title">
								<span className="ai-sparkle">✨</span>
								<strong>
									{ __(
										'Create with AI',
										'ai-block-creator'
									) }
								</strong>
							</div>
							<Button
								variant="primary"
								size="small"
								onClick={ () => handleLaunchWithPrompt() }
							>
								{ __( 'Open Modal', 'ai-block-creator' ) }
							</Button>
						</div>

						<p className="ai-sidebar-quick-desc">
							{ __(
								'Describe any custom block to generate and insert it.',
								'ai-block-creator'
							) }
						</p>

						<div className="ai-sidebar-prompt-wrap">
							<input
								type="text"
								className="ai-sidebar-prompt-input"
								placeholder={ __(
									'e.g. 3-tier pricing table…',
									'ai-block-creator'
								) }
								value={ quickPrompt }
								onChange={ ( e ) =>
									setQuickPrompt( e.target.value )
								}
								onKeyDown={ ( e ) => {
									if (
										e.key === 'Enter' &&
										quickPrompt.trim()
									) {
										e.preventDefault();
										handleLaunchWithPrompt();
									}
								} }
							/>
							<VoiceInput
								onTranscript={ handleVoiceTranscript }
							/>
							<Button
								variant="secondary"
								size="small"
								disabled={ ! quickPrompt.trim() }
								onClick={ () => handleLaunchWithPrompt() }
							>
								{ __( 'Go', 'ai-block-creator' ) }
							</Button>
						</div>

						{ /* Suggestion Chips */ }
						<div className="ai-sidebar-chips">
							{ SUGGESTION_CHIPS.map( ( chip, idx ) => (
								<button
									key={ idx }
									type="button"
									className="ai-sidebar-chip"
									onClick={ () =>
										handleLaunchWithPrompt( chip.prompt )
									}
								>
									{ chip.label }
								</button>
							) ) }
						</div>
					</div>

					{ /* Saved Blocks Section */ }
					<div className="ai-sidebar-library-header">
						<h3>{ __( 'Saved Blocks', 'ai-block-creator' ) }</h3>
						{ blocks.length > 3 && (
							<input
								type="search"
								className="ai-sidebar-search-input"
								placeholder={ __(
									'Filter…',
									'ai-block-creator'
								) }
								value={ searchQuery }
								onChange={ ( e ) =>
									setSearchQuery( e.target.value )
								}
							/>
						) }
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

					{ isLoading && (
						<div className="ai-library-loading">
							<Spinner />
							<span>
								{ __(
									'Loading saved blocks…',
									'ai-block-creator'
								) }
							</span>
						</div>
					) }

					{ ! isLoading && filteredBlocks.length === 0 && ! error && (
						<div className="ai-library-empty">
							<p>
								{ searchQuery
									? sprintf(
											// translators: %s: search query
											__(
												'No blocks match "%s".',
												'ai-block-creator'
											),
											searchQuery
									  )
									: __(
											"You haven't saved any AI-generated blocks yet.",
											'ai-block-creator'
									  ) }
							</p>
						</div>
					) }

					{ ! isLoading && filteredBlocks.length > 0 && (
						<div className="ai-library-list">
							{ filteredBlocks.map( ( block ) => (
								<LibraryRow
									key={ block.id }
									block={ block }
									canManageLibrary={ canManageLibrary }
									onInsert={ handleInsert }
									onRefine={ onRefine }
									onDeleted={ handleDeleted }
								/>
							) ) }
						</div>
					) }
				</div>
			</PluginSidebar>
		</>
	);
}
