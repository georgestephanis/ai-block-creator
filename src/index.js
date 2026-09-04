/**
 * AI Block Creator - Block Editor Entry Point.
 */

import { registerPlugin } from '@wordpress/plugins';
import { PluginMoreMenuItem } from '@wordpress/editor';
import { registerBlockType } from '@wordpress/blocks';
import { useState, useEffect } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import domReady from '@wordpress/dom-ready';
import {
	kindOf,
	registerAiDefinition,
	KIND_CUSTOM_BLOCK,
} from './runtime/dynamic-block-factory';
import AIBlockCreatorModal from './components/AIBlockCreatorModal';
import BlockLibrarySidebar from './components/BlockLibrarySidebar';
import './styles.scss';

const OPEN_EVENT = 'open-ai-block-creator';

// Boot-time registration of all stored AI definitions.
const settings = window.aiBlockCreatorSettings || {};

/**
 * Registers one stored definition, logging rather than throwing on failure so
 * a single malformed definition can't abort the whole boot loop.
 *
 * @param {Object} def Stored definition.
 */
function bootRegister( def ) {
	try {
		registerAiDefinition( def );
	} catch ( e ) {
		// A saved definition failing to register on load is a real problem
		// worth surfacing to the console.
		// eslint-disable-next-line no-console
		console.error( 'Error initializing saved AI definition:', def.name, e );
	}
}

if ( Array.isArray( settings.savedBlocks ) ) {
	const savedBlocks = settings.savedBlocks;

	// Custom blocks stand alone, so they can register the moment this module
	// runs. Styles and variations attach to a block someone else registered,
	// and getBlockType() has to already know about it — core's own blocks come
	// from wp-block-library, whose execution order relative to this script
	// isn't guaranteed — so those wait until the document is ready and every
	// editor script has run.
	savedBlocks
		.filter( ( def ) => kindOf( def ) === KIND_CUSTOM_BLOCK )
		.forEach( bootRegister );

	domReady( () => {
		savedBlocks
			.filter( ( def ) => kindOf( def ) !== KIND_CUSTOM_BLOCK )
			.forEach( bootRegister );
	} );
}

import { watchForInserterCard } from './inserter-card-controller';

/**
 * Main Controller Component.
 */
function AIBlockCreatorApp() {
	const [ isModalOpen, setIsModalOpen ] = useState( false );
	const [ placeholderClientId, setPlaceholderClientId ] = useState( null );
	const [ initialBlock, setInitialBlock ] = useState( null );
	const [ initialPrompt, setInitialPrompt ] = useState( '' );

	const hasConnectedLlm = settings.hasConnectedLlm !== false;

	const openModal = ( {
		clientId = null,
		block = null,
		prompt = '',
	} = {} ) => {
		setPlaceholderClientId( clientId );
		setInitialBlock( block );
		setInitialPrompt( prompt );
		setIsModalOpen( true );
	};

	// Listen for the open event dispatched by the slash-command placeholder
	// block, carrying the placeholder's clientId so the eventual insert can
	// replace it in place instead of appending at the end of the post.
	useEffect( () => {
		const handleOpenEvent = ( event ) => {
			openModal( {
				clientId: event.detail?.clientId ?? null,
				prompt: event.detail?.prompt ?? '',
			} );
		};
		window.addEventListener( OPEN_EVENT, handleOpenEvent );
		return () => window.removeEventListener( OPEN_EVENT, handleOpenEvent );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	// Mount the featured AI Create card in the native Block Inserter (only if LLM connected).
	useEffect( () => {
		if ( ! hasConnectedLlm ) {
			return;
		}
		return watchForInserterCard( ( opts ) => openModal( opts ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ hasConnectedLlm ] );

	// If no LLM is connected, do not display the sidebar, menu item, or modal trigger.
	if ( ! hasConnectedLlm ) {
		return null;
	}

	return (
		<>
			<PluginMoreMenuItem
				icon="star-filled"
				onClick={ () => openModal() }
			>
				{ __( 'AI Block Creator', 'ai-block-creator' ) }
			</PluginMoreMenuItem>

			<BlockLibrarySidebar
				onLaunchModal={ ( opts ) => openModal( opts ) }
				onRefine={ ( block ) => openModal( { block } ) }
			/>

			<AIBlockCreatorModal
				isOpen={ isModalOpen }
				placeholderClientId={ placeholderClientId }
				initialBlock={ initialBlock }
				initialPrompt={ initialPrompt }
				onClose={ () => {
					setIsModalOpen( false );
					setPlaceholderClientId( null );
					setInitialBlock( null );
					setInitialPrompt( '' );
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

const hasLlm = settings.hasConnectedLlm !== false;
const hasVision = Boolean( settings.supportsImageInput );

/**
 * Register a quick inserter block: "ai-block/generator"
 * When inserted via "/" search ("ai block", "custom block", "generator"), it opens the modal!
 */
registerBlockType( 'ai-block/generator', {
	apiVersion: 3,
	title: __( 'Create Block with AI', 'ai-block-creator' ),
	category: 'ai-blocks',
	icon: 'star-filled',
	description: hasVision
		? __(
				'Speak, type, or paste a screenshot to create a new custom block on the fly.',
				'ai-block-creator'
		  )
		: __(
				'Speak or type to create a new custom block on the fly.',
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
		inserter: hasLlm,
		html: false,
	},
	edit: function QuickEdit( { clientId } ) {
		const handleOpen = () => {
			window.dispatchEvent(
				new CustomEvent( OPEN_EVENT, { detail: { clientId } } )
			);
		};

		if ( ! hasLlm ) {
			return (
				<div className="ai-block-inserter-card">
					<div className="ai-inserter-content">
						<span className="ai-inserter-icon">⚠️</span>
						<div className="ai-inserter-text">
							<h4>
								{ __(
									'AI Provider Not Connected',
									'ai-block-creator'
								) }
							</h4>
							<p>
								{ __(
									'Please connect an LLM provider in Settings > Connectors to use AI Block Creator.',
									'ai-block-creator'
								) }
							</p>
						</div>
					</div>
					{ settings.connectorsUrl && (
						<Button
							variant="secondary"
							href={ settings.connectorsUrl }
							target="_blank"
						>
							{ __(
								'Configure Connectors ↗',
								'ai-block-creator'
							) }
						</Button>
					) }
				</div>
			);
		}

		return (
			<div className="ai-block-inserter-card">
				<div className="ai-inserter-content">
					<span className="ai-inserter-icon">✨</span>
					<div className="ai-inserter-text">
						<h4>
							{ __( 'AI Block Creator', 'ai-block-creator' ) }
						</h4>
						<p>
							{ hasVision
								? __(
										'Speak, type, or paste a screenshot to build your custom block right here.',
										'ai-block-creator'
								  )
								: __(
										'Speak or type to build your custom block right here.',
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
