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
		// Find Gutenberg's Block Inserter search container or panel.
		const searchContainer = document.querySelector(
			'.block-editor-tabbed-sidebar__tabpanel .block-editor-inserter__search, .block-editor-inserter__menu .block-editor-inserter__search, .block-editor-inserter__search'
		);

		const panelContent = document.querySelector(
			'.block-editor-tabbed-sidebar__tabpanel .block-editor-inserter__panel-content, .block-editor-inserter__menu .block-editor-inserter__panel-content, .block-editor-inserter__panel-content'
		);

		if ( ! searchContainer && ! panelContent ) {
			return;
		}

		let cardContainer = document.getElementById(
			INSERTER_CARD_CONTAINER_ID
		);

		if ( ! cardContainer ) {
			cardContainer = document.createElement( 'div' );
			cardContainer.id = INSERTER_CARD_CONTAINER_ID;
			cardContainer.className = 'ai-inserter-featured-card-wrapper';

			// Insert immediately after search container (above the "TEXT" category),
			// or as first child of panel content.
			if ( searchContainer && searchContainer.parentElement ) {
				searchContainer.insertAdjacentElement(
					'afterend',
					cardContainer
				);
			} else if ( panelContent && panelContent.parentElement ) {
				panelContent.parentElement.insertBefore(
					cardContainer,
					panelContent
				);
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
