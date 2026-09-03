/**
 * Inserter Tab Controller.
 * Adds an "AI Blocks" tab exclusively to Gutenberg's Block Inserter tablist.
 */

import { createElement, createRoot, render } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import AIInserterTab from './components/AIInserterTab';

const TAB_BUTTON_ID = 'ai-block-creator-inserter-tab-btn';
const PANEL_CONTAINER_ID = 'ai-block-creator-inserter-panel';

let panelRoot = null;
let isAiTabActive = false;

/**
 * Finds the Block Inserter tablist, explicitly distinguishing it from other
 * tablists like List View / Outline.
 *
 * @return {HTMLElement|null} The inserter tablist or null.
 */
function findInserterTablist() {
	const tablists = document.querySelectorAll( '[role="tablist"]' );
	for ( const tablist of tablists ) {
		// Ignore if this tablist is inside Document Overview (List View / Outline).
		if (
			tablist.closest(
				'.edit-post-editor-regions__list-view, .block-editor-list-view-sidebar, .edit-post-editor-regions__sidebar'
			)
		) {
			continue;
		}

		// Must contain a "Blocks" or "Patterns" tab.
		const tabs = Array.from(
			tablist.querySelectorAll( 'button[role="tab"]' )
		);
		const hasInserterTab = tabs.some( ( btn ) => {
			const text = ( btn.textContent || '' ).trim().toLowerCase();
			return text === 'blocks' || text === 'patterns';
		} );

		if ( hasInserterTab ) {
			return tablist;
		}
	}
	return null;
}

/**
 * Mounts the AIInserterTab component into the custom panel container.
 *
 * @param {HTMLElement} container   The panel container element.
 * @param {Function}    onOpenModal Callback to launch the modal.
 */
function renderTabContent( container, onOpenModal ) {
	const element = createElement( AIInserterTab, {
		onOpenModal,
	} );

	if ( createRoot ) {
		if ( ! panelRoot ) {
			panelRoot = createRoot( container );
		}
		panelRoot.render( element );
	} else if ( render ) {
		render( element, container );
	}
}

/**
 * Activates the AI tab and displays the AI panel overlay.
 *
 * @param {HTMLElement} tablist        The tablist element containing all tab buttons.
 * @param {HTMLElement} aiTabBtn       The injected AI tab button.
 * @param {HTMLElement} panelContainer The container holding our AIInserterTab.
 * @param {Function}    onOpenModal    Callback to launch the modal.
 */
function activateAiTab( tablist, aiTabBtn, panelContainer, onOpenModal ) {
	isAiTabActive = true;

	tablist.classList.add( 'has-ai-tab-active' );
	aiTabBtn.setAttribute( 'aria-selected', 'true' );
	aiTabBtn.setAttribute( 'data-active', 'true' );
	aiTabBtn.classList.add( 'is-active', 'components-tab-panel__tab-active' );

	// Deactivate sibling tabs visually and clear data-active so their indicator clears.
	const siblingTabs = tablist.querySelectorAll(
		'button[role="tab"]:not(#' + TAB_BUTTON_ID + ')'
	);
	siblingTabs.forEach( ( tab ) => {
		tab.setAttribute( 'aria-selected', 'false' );
		tab.removeAttribute( 'data-active' );
		tab.classList.remove( 'is-active', 'components-tab-panel__tab-active' );
	} );

	// Display the AI panel container overlay.
	panelContainer.style.display = 'block';
	renderTabContent( panelContainer, onOpenModal );
}

/**
 * Deactivates the AI tab and hides the AI panel overlay.
 *
 * @param {HTMLElement} tablist        The tablist element containing all tab buttons.
 * @param {HTMLElement} aiTabBtn       The injected AI tab button.
 * @param {HTMLElement} panelContainer The container holding our AIInserterTab.
 */
function deactivateAiTab( tablist, aiTabBtn, panelContainer ) {
	if ( ! isAiTabActive ) {
		return;
	}
	isAiTabActive = false;

	if ( tablist ) {
		tablist.classList.remove( 'has-ai-tab-active' );
	}

	if ( aiTabBtn ) {
		aiTabBtn.setAttribute( 'aria-selected', 'false' );
		aiTabBtn.removeAttribute( 'data-active' );
		aiTabBtn.classList.remove(
			'is-active',
			'components-tab-panel__tab-active'
		);
	}

	if ( panelContainer ) {
		panelContainer.style.display = 'none';
	}
}

/**
 * Watches for Gutenberg's Block Inserter tablist and injects the AI Blocks tab.
 *
 * @param {Function} onOpenModal Callback to launch the modal.
 * @return {Function} Cleanup function.
 */
export function watchForInserterTabs( onOpenModal ) {
	const injectTab = () => {
		const tablist = findInserterTablist();
		if ( ! tablist ) {
			// If inserter is closed, ensure inactive state.
			isAiTabActive = false;
			return;
		}

		const parentMenu =
			tablist.closest(
				'.block-editor-tabbed-sidebar, .block-editor-inserter__menu, .block-editor-inserter__sidebar, .edit-post-editor-regions__inserter'
			) || tablist.parentElement;

		let aiTabBtn = document.getElementById( TAB_BUTTON_ID );
		let panelContainer = document.getElementById( PANEL_CONTAINER_ID );

		// Ensure parentMenu has relative positioning for absolute overlay panel.
		if ( parentMenu && ! parentMenu.style.position ) {
			parentMenu.style.position = 'relative';
		}

		// Create panel container if not present.
		if ( ! panelContainer && parentMenu ) {
			panelContainer = document.createElement( 'div' );
			panelContainer.id = PANEL_CONTAINER_ID;
			panelContainer.className = 'ai-inserter-panel-container';
			panelContainer.style.display = 'none';
			parentMenu.appendChild( panelContainer );
		}

		// Create tab button if not present.
		if ( ! aiTabBtn ) {
			aiTabBtn = document.createElement( 'button' );
			aiTabBtn.id = TAB_BUTTON_ID;
			aiTabBtn.type = 'button';
			aiTabBtn.role = 'tab';
			aiTabBtn.className =
				'components-button components-tab-panel__tabs-item ai-inserter-tab-btn';
			aiTabBtn.setAttribute( 'aria-selected', 'false' );
			aiTabBtn.innerHTML =
				'<span class="ai-tab-sparkle">✨</span><span class="ai-tab-label">' +
				__( 'AI Blocks', 'ai-block-creator' ) +
				'</span>';

			aiTabBtn.onclick = ( e ) => {
				e.preventDefault();
				e.stopPropagation();
				activateAiTab( tablist, aiTabBtn, panelContainer, onOpenModal );
			};

			// Insert before close button if present, or append to tablist.
			const closeBtn = tablist.querySelector(
				'.block-editor-inserter__tabs-close-button, button[aria-label="Close"]'
			);
			if ( closeBtn && closeBtn.parentElement === tablist ) {
				tablist.insertBefore( aiTabBtn, closeBtn );
			} else {
				tablist.appendChild( aiTabBtn );
			}

			// Add click listeners to all sibling tabs and close buttons.
			const siblingTabs = tablist.querySelectorAll(
				'button[role="tab"]:not(#' +
					TAB_BUTTON_ID +
					'), .block-editor-inserter__tabs-close-button, button[aria-label="Close"]'
			);
			siblingTabs.forEach( ( tab ) => {
				tab.addEventListener( 'click', () => {
					deactivateAiTab( tablist, aiTabBtn, panelContainer );
				} );
			} );
		}
	};

	injectTab();

	const observer = new window.MutationObserver( injectTab );
	observer.observe( document.body, { childList: true, subtree: true } );

	return () => {
		observer.disconnect();
		if ( panelRoot && panelRoot.unmount ) {
			panelRoot.unmount();
			panelRoot = null;
		}
	};
}
