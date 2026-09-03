/**
 * AI Block Creator - Block Editor Entry Point.
 */

import { registerPlugin } from '@wordpress/plugins';
import { PluginMoreMenuItem } from '@wordpress/edit-post';
import { registerBlockType } from '@wordpress/blocks';
import { useState, useEffect } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { registerDynamicAiBlock } from './runtime/dynamic-block-factory';
import AIBlockCreatorModal from './components/AIBlockCreatorModal';
import './styles.scss';

// Boot-time registration of all stored AI Custom Blocks
const settings = window.aiBlockCreatorSettings || {};
if ( Array.isArray( settings.savedBlocks ) ) {
	settings.savedBlocks.forEach( ( blockDef ) => {
		try {
			registerDynamicAiBlock( blockDef );
		} catch ( e ) {
			console.error( 'Error initializing saved AI block:', blockDef.name, e );
		}
	} );
}

/**
 * Top Toolbar Sparkle Button & Main Controller Component.
 */
function AIBlockCreatorApp() {
	const [ isModalOpen, setIsModalOpen ] = useState( false );

	// Listen for custom open event triggered by slash command / placeholder block
	useEffect( () => {
		const handleOpenEvent = () => setIsModalOpen( true );
		window.addEventListener( 'open-ai-block-creator', handleOpenEvent );
		return () => window.removeEventListener( 'open-ai-block-creator', handleOpenEvent );
	}, [] );

	// Inject header button directly into the editor header toolbar if available
	useEffect( () => {
		const checkHeader = () => {
			const headerToolbar = document.querySelector(
				'.edit-post-header-toolbar, .editor-header__toolbar, .interface-interface-skeleton__header'
			);

			if ( headerToolbar && ! document.getElementById( 'ai-block-creator-header-btn' ) ) {
				const btnContainer = document.createElement( 'div' );
				btnContainer.id = 'ai-block-creator-header-btn';
				btnContainer.className = 'ai-header-btn-wrap';

				const btn = document.createElement( 'button' );
				btn.type = 'button';
				btn.className = 'components-button ai-sparkle-toolbar-btn has-text has-icon';
				btn.innerHTML = '<span class="ai-btn-icon">✨</span><span class="ai-btn-label">Create Block with AI</span>';
				btn.title = 'Speak, type, or screenshot a custom block into existence';
				btn.onclick = () => setIsModalOpen( true );

				btnContainer.appendChild( btn );
				headerToolbar.appendChild( btnContainer );
			}
		};

		const interval = setInterval( checkHeader, 500 );
		setTimeout( () => clearInterval( interval ), 5000 );
		return () => clearInterval( interval );
	}, [] );

	return (
		<>
			<PluginMoreMenuItem
				icon="star-filled"
				onClick={ () => setIsModalOpen( true ) }
			>
				AI Block Creator
			</PluginMoreMenuItem>

			<AIBlockCreatorModal
				isOpen={ isModalOpen }
				onClose={ () => setIsModalOpen( false ) }
				onBlockCreated={ ( blockDef ) => {
					console.log( 'AI Block created & registered:', blockDef.name );
				} }
			/>
		</>
	);
}

// Register Plugin in Gutenberg
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
	title: 'Create Block with AI',
	category: 'ai-blocks',
	icon: 'star-filled',
	description: 'Speak, type, or paste a screenshot to create a new custom block on the fly.',
	keywords: [ 'ai', 'generate', 'custom block', 'screenshot', 'voice', 'speak' ],
	supports: {
		inserter: true,
		html: false,
	},
	edit: function QuickEdit( { clientId } ) {
		const { removeBlock } = wp.data.dispatch( 'core/block-editor' );

		const handleOpen = () => {
			window.dispatchEvent( new CustomEvent( 'open-ai-block-creator' ) );
			// Remove the placeholder block once modal opens
			removeBlock( clientId );
		};

		return (
			<div className="ai-block-inserter-card">
				<div className="ai-inserter-content">
					<span className="ai-inserter-icon">✨</span>
					<div className="ai-inserter-text">
						<h4>AI Block Creator</h4>
						<p>Speak, type, or paste a screenshot to build your custom block right here.</p>
					</div>
				</div>
				<Button variant="primary" onClick={ handleOpen } className="ai-inserter-btn">
					✨ Open Creator
				</Button>
			</div>
		);
	},
	save: () => null,
} );
