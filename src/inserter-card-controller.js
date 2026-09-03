/**
 * Inserter Card Controller.
 * Mounts the AIInserterCard at the top of Gutenberg's native Blocks tab in the Inserter.
 */

import { createElement, createRoot, render } from '@wordpress/element';
import AIInserterCard from './components/AIInserterCard';

const INSERTER_CARD_CONTAINER_ID =
	'ai-block-creator-inserter-featured-card-wrap';

let cardRoot = null;

/**
 * Watches for Gutenberg's Blocks Inserter panel and mounts the featured AI card.
 *
 * @param {Function} onOpenModal Callback to launch the modal with prompt options.
 * @return {Function} Cleanup function.
 */
export function watchForInserterCard( onOpenModal ) {
	const injectCard = () => {
		// Find Gutenberg's Block Inserter blocks panel.
		const blocksPanel = document.querySelector(
			'.block-editor-tabbed-sidebar__tabpanel .block-editor-inserter__panel-content, .block-editor-tabbed-sidebar__tabpanel .block-editor-inserter__quick-inserter, .block-editor-inserter__menu .block-editor-inserter__panel-content, .block-editor-inserter__results'
		);

		if ( ! blocksPanel ) {
			return;
		}

		let cardContainer = document.getElementById(
			INSERTER_CARD_CONTAINER_ID
		);

		if ( ! cardContainer ) {
			cardContainer = document.createElement( 'div' );
			cardContainer.id = INSERTER_CARD_CONTAINER_ID;
			cardContainer.className = 'ai-inserter-featured-card-wrapper';

			// Insert at the top of the blocks panel (before categories list).
			if ( blocksPanel.firstChild ) {
				blocksPanel.insertBefore(
					cardContainer,
					blocksPanel.firstChild
				);
			} else {
				blocksPanel.appendChild( cardContainer );
			}

			const element = createElement( AIInserterCard, {
				onOpenModal,
			} );

			if ( createRoot ) {
				cardRoot = createRoot( cardContainer );
				cardRoot.render( element );
			} else if ( render ) {
				render( element, cardContainer );
			}
		}
	};

	injectCard();

	const observer = new window.MutationObserver( injectCard );
	observer.observe( document.body, { childList: true, subtree: true } );

	return () => {
		observer.disconnect();
		if ( cardRoot && cardRoot.unmount ) {
			cardRoot.unmount();
			cardRoot = null;
		}
	};
}
