/**
 * AI Block Creator - Block Editor Entry Point.
 */

import { registerPlugin } from '@wordpress/plugins';
import { PluginMoreMenuItem } from '@wordpress/editor';
import { registerBlockType } from '@wordpress/blocks';
import { useState, useEffect } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { registerDynamicAiBlock } from './runtime/dynamic-block-factory';
import AIBlockCreatorModal from './components/AIBlockCreatorModal';
import BlockLibrarySidebar from './components/BlockLibrarySidebar';
import './styles.scss';

const OPEN_EVENT = 'open-ai-block-creator';

// Boot-time registration of all stored AI Custom Blocks.
const settings = window.aiBlockCreatorSettings || {};
if ( Array.isArray( settings.savedBlocks ) ) {
	settings.savedBlocks.forEach( ( blockDef ) => {
		try {
			registerDynamicAiBlock( blockDef );
		} catch ( e ) {
			// A saved block failing to register on load is a real problem
			// worth surfacing to the console.
			// eslint-disable-next-line no-console
			console.error(
				'Error initializing saved AI block:',
				blockDef.name,
				e
			);
		}
	} );
}

/**
 * Injects (and keeps injected) a header toolbar button that opens the
 * creator modal. Uses a MutationObserver instead of polling so it survives
 * header re-renders (fullscreen toggle, switching to template-editing mode)
 * for the life of the editor session, not just an initial 5-second window.
 *
 * @param {Function} onOpen Called when the button is clicked.
 * @return {Function} Cleanup function.
 */
function watchForHeaderToolbar( onOpen ) {
	const BUTTON_ID = 'ai-block-creator-header-btn';

	const insertButton = () => {
		const headerToolbar = document.querySelector(
			'.edit-post-header-toolbar, .editor-header__toolbar, .interface-interface-skeleton__header'
		);

		if ( ! headerToolbar || document.getElementById( BUTTON_ID ) ) {
			return;
		}

		const btnContainer = document.createElement( 'div' );
		btnContainer.id = BUTTON_ID;
		btnContainer.className = 'ai-header-btn-wrap';

		const btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className =
			'components-button ai-sparkle-toolbar-btn has-text has-icon';
		btn.innerHTML =
			'<span class="ai-btn-icon">✨</span><span class="ai-btn-label">' +
			__( 'Create Block with AI', 'ai-block-creator' ) +
			'</span>';
		btn.title = __(
			'Speak, type, or screenshot a custom block into existence',
			'ai-block-creator'
		);
		btn.onclick = onOpen;

		btnContainer.appendChild( btn );
		headerToolbar.appendChild( btnContainer );
	};

	insertButton();

	const observer = new window.MutationObserver( insertButton );
	observer.observe( document.body, { childList: true, subtree: true } );

	return () => observer.disconnect();
}

/**
 * Top Toolbar Sparkle Button & Main Controller Component.
 */
function AIBlockCreatorApp() {
	const [ isModalOpen, setIsModalOpen ] = useState( false );
	const [ placeholderClientId, setPlaceholderClientId ] = useState( null );
	const [ initialBlock, setInitialBlock ] = useState( null );

	const openModal = ( { clientId = null, block = null } = {} ) => {
		setPlaceholderClientId( clientId );
		setInitialBlock( block );
		setIsModalOpen( true );
	};

	// Listen for the open event dispatched by the slash-command placeholder
	// block, carrying the placeholder's clientId so the eventual insert can
	// replace it in place instead of appending at the end of the post.
	useEffect( () => {
		const handleOpenEvent = ( event ) => {
			openModal( { clientId: event.detail?.clientId ?? null } );
		};
		window.addEventListener( OPEN_EVENT, handleOpenEvent );
		return () => window.removeEventListener( OPEN_EVENT, handleOpenEvent );
		// eslint-disable-next-line react-hooks/exhaustive-deps -- openModal
		// is redefined every render but doesn't close over anything that
		// changes in a way this listener needs to react to.
	}, [] );

	useEffect( () => {
		return watchForHeaderToolbar( () => openModal() );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	return (
		<>
			<PluginMoreMenuItem
				icon="star-filled"
				onClick={ () => openModal() }
			>
				{ __( 'AI Block Creator', 'ai-block-creator' ) }
			</PluginMoreMenuItem>

			<BlockLibrarySidebar
				onRefine={ ( block ) => openModal( { block } ) }
			/>

			<AIBlockCreatorModal
				isOpen={ isModalOpen }
				placeholderClientId={ placeholderClientId }
				initialBlock={ initialBlock }
				onClose={ () => {
					setIsModalOpen( false );
					setPlaceholderClientId( null );
					setInitialBlock( null );
				} }
			/>
		</>
	);
}

// Register Plugin in Gutenberg.
registerPlugin( 'ai-block-creator', {
	render: AIBlockCreatorApp,
	icon: 'star-filled',
} );

/**
 * Register a quick inserter block: "ai-block/generator"
 * When inserted via "/" search ("ai block", "custom block", "generator"), it opens the modal!
 */
registerBlockType( 'ai-block/generator', {
	apiVersion: 3,
	title: __( 'Create Block with AI', 'ai-block-creator' ),
	category: 'ai-blocks',
	icon: 'star-filled',
	description: __(
		'Speak, type, or paste a screenshot to create a new custom block on the fly.',
		'ai-block-creator'
	),
	keywords: [
		__( 'ai', 'ai-block-creator' ),
		__( 'generate', 'ai-block-creator' ),
		__( 'custom block', 'ai-block-creator' ),
		__( 'screenshot', 'ai-block-creator' ),
		__( 'voice', 'ai-block-creator' ),
		__( 'speak', 'ai-block-creator' ),
	],
	supports: {
		inserter: true,
		html: false,
	},
	edit: function QuickEdit( { clientId } ) {
		const handleOpen = () => {
			window.dispatchEvent(
				new CustomEvent( OPEN_EVENT, { detail: { clientId } } )
			);
			// The placeholder itself is removed/replaced once the modal
			// produces a real block (see AIBlockCreatorModal), not here —
			// removing it eagerly would lose the insertion point.
		};

		return (
			<div className="ai-block-inserter-card">
				<div className="ai-inserter-content">
					<span className="ai-inserter-icon">✨</span>
					<div className="ai-inserter-text">
						<h4>
							{ __( 'AI Block Creator', 'ai-block-creator' ) }
						</h4>
						<p>
							{ __(
								'Speak, type, or paste a screenshot to build your custom block right here.',
								'ai-block-creator'
							) }
						</p>
					</div>
				</div>
				<Button
					variant="primary"
					onClick={ handleOpen }
					className="ai-inserter-btn"
				>
					✨ { __( 'Open Creator', 'ai-block-creator' ) }
				</Button>
			</div>
		);
	},
	save: () => null,
} );
