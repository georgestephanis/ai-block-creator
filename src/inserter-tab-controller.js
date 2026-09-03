/**
 * Inserter Tab Controller.
 * Adds an "AI" / "✨ AI Blocks" tab to Gutenberg's Block Inserter tablist.
 */

import { createElement, createRoot, render } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import AIInserterTab from './components/AIInserterTab';

const TAB_BUTTON_ID = 'ai-block-creator-inserter-tab-btn';
const PANEL_CONTAINER_ID = 'ai-block-creator-inserter-panel';

let panelRoot = null;
let isAiTabActive = false;

/**
 * Mounts or renders the AIInserterTab component into the custom panel container.
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
 * Activates the custom AI tab and hides the default Gutenberg inserter content.
 *
 * @param {HTMLElement} tablist        The tablist element containing all tab buttons.
 * @param {HTMLElement} aiTabBtn       The injected AI tab button.
 * @param {HTMLElement} panelContainer The container holding our AIInserterTab.
 * @param {Function}    onOpenModal    Callback to launch the modal.
 */
function activateAiTab( tablist, aiTabBtn, panelContainer, onOpenModal ) {
	isAiTabActive = true;

	// Set active attributes on the AI tab button.
	aiTabBtn.setAttribute( 'aria-selected', 'true' );
	aiTabBtn.classList.add( 'is-active', 'components-tab-panel__tab-active' );

	// Deactivate all sibling tabs in the tablist.
	const siblingTabs = tablist.querySelectorAll(
		'button[role="tab"]:not(#' + TAB_BUTTON_ID + ')'
	);
	siblingTabs.forEach( ( tab ) => {
		tab.setAttribute( 'aria-selected', 'false' );
		tab.classList.remove( 'is-active', 'components-tab-panel__tab-active' );
	} );

	// Hide the default tab panel content in Gutenberg's inserter.
	const parentMenu = tablist.closest(
		'.block-editor-inserter__menu, .block-editor-tabbed-sidebar, .block-editor-inserter__sidebar, .edit-post-editor-regions__inserter'
	);
	if ( parentMenu ) {
		const defaultPanels = parentMenu.querySelectorAll(
			'.block-editor-inserter__tablist-content, .block-editor-tabbed-sidebar__tab-content, .block-editor-inserter__panel, .components-tab-panel__tab-content, .block-editor-inserter__content'
		);
		defaultPanels.forEach( ( panel ) => {
			panel.style.setProperty( 'display', 'none', 'important' );
		} );
	}

	// Show our custom AI panel and render contents.
	panelContainer.style.display = 'block';
	renderTabContent( panelContainer, onOpenModal );
}

/**
 * Deactivates the custom AI tab and restores Gutenberg's default tab panel content.
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

	if ( aiTabBtn ) {
		aiTabBtn.setAttribute( 'aria-selected', 'false' );
		aiTabBtn.classList.remove(
			'is-active',
			'components-tab-panel__tab-active'
		);
	}

	if ( panelContainer ) {
		panelContainer.style.display = 'none';
	}

	// Restore default Gutenberg inserter panel visibility.
	const parentMenu = tablist.closest(
		'.block-editor-inserter__menu, .block-editor-tabbed-sidebar, .block-editor-inserter__sidebar, .edit-post-editor-regions__inserter'
	);
	if ( parentMenu ) {
		const defaultPanels = parentMenu.querySelectorAll(
			'.block-editor-inserter__tablist-content, .block-editor-tabbed-sidebar__tab-content, .block-editor-inserter__panel, .components-tab-panel__tab-content, .block-editor-inserter__content'
		);
		defaultPanels.forEach( ( panel ) => {
			panel.style.removeProperty( 'display' );
		} );
	}
}

/**
 * Watches for the Gutenberg Inserter tablist and injects the AI tab button.
 *
 * @param {Function} onOpenModal Callback to launch the modal.
 * @return {Function} Cleanup function.
 */
export function watchForInserterTabs( onOpenModal ) {
	const injectTab = () => {
		const tablist = document.querySelector(
			'.block-editor-inserter__tablist, .block-editor-tabbed-sidebar [role="tablist"], .edit-post-editor-regions__inserter [role="tablist"], .block-editor-inserter__menu [role="tablist"], [aria-label="Blocks and patterns"]'
		);

		if ( ! tablist ) {
			return;
		}

		let aiTabBtn = document.getElementById( TAB_BUTTON_ID );
		let panelContainer = document.getElementById( PANEL_CONTAINER_ID );

		// Create panel container if needed.
		if ( ! panelContainer ) {
			panelContainer = document.createElement( 'div' );
			panelContainer.id = PANEL_CONTAINER_ID;
			panelContainer.className = 'ai-inserter-panel-container';
			panelContainer.style.display = 'none';

			const parentMenu = tablist.closest(
				'.block-editor-inserter__menu, .block-editor-tabbed-sidebar, .block-editor-inserter__sidebar, .edit-post-editor-regions__inserter'
			);
			if ( parentMenu ) {
				parentMenu.appendChild( panelContainer );
			} else if ( tablist.parentElement ) {
				tablist.parentElement.appendChild( panelContainer );
			}
		}

		// Create tab button if needed.
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

			// Add click listeners to sibling tabs so switching back deactivates the AI panel.
			const siblingTabs = tablist.querySelectorAll(
				'button[role="tab"]:not(#' + TAB_BUTTON_ID + ')'
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
