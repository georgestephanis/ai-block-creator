/**
 * Block Library Sidebar.
 * Lists every saved AI custom block, and lets the user insert, refine, or
 * delete one without having to regenerate it from scratch.
 */

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
import { starFilled } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';

export const LIBRARY_UPDATED_EVENT = 'ai-block-creator-library-updated';

/**
 * Dispatches the event BlockLibrarySidebar listens for to refresh its list.
 * Call this after any successful save/delete that happens outside this
 * component (e.g. from AIBlockCreatorModal's persistBlock()).
 */
export function notifyLibraryUpdated() {
	window.dispatchEvent( new CustomEvent( LIBRARY_UPDATED_EVENT ) );
}

/**
 * A single row in the library list, with its own delete-confirmation state.
 *
 * @param {Object}   props
 * @param {Object}   props.block            Saved block definition.
 * @param {boolean}  props.canManageLibrary Whether the current user may delete.
 * @param {Function} props.onInsert         Called to insert the block into the post.
 * @param {Function} props.onRefine         Called to open the creator modal preloaded with this block.
 * @param {Function} props.onDeleted        Called with the block's id once deletion succeeds.
 * @return {Object} Element.
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
					// Already gone from the client-side registry; nothing to do.
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
					onClick={ () => onRefine( block ) }
				>
					{ __( 'Refine', 'ai-block-creator' ) }
				</Button>
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
							onClick={ () => setConfirmingDelete( true ) }
						>
							{ __( 'Delete', 'ai-block-creator' ) }
						</Button>
					) ) }
			</div>
		</div>
	);
}

export default function BlockLibrarySidebar( { onRefine } ) {
	const [ blocks, setBlocks ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( '' );

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

	return (
		<>
			<PluginSidebarMoreMenuItem target="ai-block-creator-library">
				{ __( 'AI Block Library', 'ai-block-creator' ) }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar
				name="ai-block-creator-library"
				title={ __( 'AI Block Library', 'ai-block-creator' ) }
				icon="star-filled"
			>
				<div className="ai-library-sidebar">
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
						</div>
					) }

					{ ! isLoading && blocks.length === 0 && ! error && (
						<p className="ai-library-empty">
							{ __(
								"You haven't saved any AI-generated blocks yet. Create one from the editor's More menu or the block inserter.",
								'ai-block-creator'
							) }
						</p>
					) }

					{ ! isLoading && blocks.length > 0 && (
						<p className="ai-library-count">
							{ sprintf(
								/* translators: %d: number of saved blocks. */
								__( '%d saved block(s).', 'ai-block-creator' ),
								blocks.length
							) }
						</p>
					) }

					<div className="ai-library-list">
						{ blocks.map( ( block ) => (
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
				</div>
			</PluginSidebar>
		</>
	);
}
