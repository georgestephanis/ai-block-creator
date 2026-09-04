/**
 * Tests for the kind-dispatch helpers that back the two-stage pipeline's
 * second half: turning a planned definition into the right editor
 * registration. These are the pure parts — the registration calls themselves
 * go through @wordpress/blocks, which is stubbed in this suite (see
 * tests/js/mocks/wp-package-stub.js), so what's asserted here is the shape
 * handed to those APIs, which is where the PHP/JS contract can actually drift.
 */

import {
	kindOf,
	buildStyleConfig,
	buildVariationConfig,
	KIND_CUSTOM_BLOCK,
	KIND_BLOCK_STYLE,
	KIND_BLOCK_VARIATION,
} from '../dynamic-block-factory';

describe( 'kindOf', () => {
	it( 'treats a definition with no kind as a custom block', () => {
		// Definitions saved before kinds existed have no `kind` field, and
		// were all custom blocks.
		expect( kindOf( { name: 'ai-block/legacy' } ) ).toBe(
			KIND_CUSTOM_BLOCK
		);
	} );

	it( 'treats an unrecognized kind as a custom block', () => {
		expect( kindOf( { kind: 'block_pattern' } ) ).toBe( KIND_CUSTOM_BLOCK );
	} );

	it( 'is undefined-safe', () => {
		expect( kindOf( undefined ) ).toBe( KIND_CUSTOM_BLOCK );
		expect( kindOf( null ) ).toBe( KIND_CUSTOM_BLOCK );
	} );

	it( 'returns the recognized kinds unchanged', () => {
		expect( kindOf( { kind: KIND_BLOCK_STYLE } ) ).toBe( KIND_BLOCK_STYLE );
		expect( kindOf( { kind: KIND_BLOCK_VARIATION } ) ).toBe(
			KIND_BLOCK_VARIATION
		);
	} );
} );

describe( 'buildStyleConfig', () => {
	it( 'maps a style definition onto registerBlockStyle arguments', () => {
		expect(
			buildStyleConfig( {
				kind: KIND_BLOCK_STYLE,
				name: 'ai-gold-pullquote',
				label: 'Gold Pull-Quote',
				target_block: 'core/quote',
			} )
		).toEqual( {
			target: 'core/quote',
			style: { name: 'ai-gold-pullquote', label: 'Gold Pull-Quote' },
		} );
	} );

	it( 'falls back to title, then name, for the label', () => {
		expect(
			buildStyleConfig( {
				name: 'ai-x',
				title: 'From Title',
				target_block: 'core/quote',
			} ).style.label
		).toBe( 'From Title' );

		expect(
			buildStyleConfig( { name: 'ai-x', target_block: 'core/quote' } )
				.style.label
		).toBe( 'ai-x' );
	} );

	it( 'refuses a definition with nothing to attach to', () => {
		// A style is only ever a class on some other block, so without a
		// target there is nothing to register it against.
		expect( buildStyleConfig( { name: 'ai-x' } ) ).toBeNull();
		expect( buildStyleConfig( { target_block: 'core/quote' } ) ).toBeNull();
		expect( buildStyleConfig( undefined ) ).toBeNull();
	} );
} );

describe( 'buildVariationConfig', () => {
	it( 'maps a variation definition onto registerBlockVariation arguments', () => {
		const { target, variation } = buildVariationConfig( {
			kind: KIND_BLOCK_VARIATION,
			name: 'ai-two-col-feature',
			title: 'Two Column Feature',
			description: 'Image left, text right.',
			icon: 'columns',
			target_block: 'core/columns',
			attributes: { align: 'wide' },
		} );

		expect( target ).toBe( 'core/columns' );
		expect( variation ).toMatchObject( {
			name: 'ai-two-col-feature',
			title: 'Two Column Feature',
			description: 'Image left, text right.',
			icon: 'columns',
			attributes: { align: 'wide' },
			scope: [ 'inserter', 'transform' ],
		} );
	} );

	it( 'expands the flat inner_block_names list into innerBlocks tuples', () => {
		// PHP stores a flat list of names (AI_Block_Store::
		// sanitize_inner_block_names); Gutenberg wants
		// [ name, attributes, innerBlocks ] tuples.
		expect(
			buildVariationConfig( {
				name: 'ai-x',
				target_block: 'core/columns',
				inner_block_names: [ 'core/column', 'core/column' ],
			} ).variation.innerBlocks
		).toEqual( [ [ 'core/column' ], [ 'core/column' ] ] );
	} );

	it( 'omits innerBlocks entirely when there are none', () => {
		const { variation } = buildVariationConfig( {
			name: 'ai-x',
			target_block: 'core/quote',
			inner_block_names: [],
		} );

		expect( variation ).not.toHaveProperty( 'innerBlocks' );
	} );

	it( 'defaults attributes to an empty preset rather than undefined', () => {
		expect(
			buildVariationConfig( { name: 'ai-x', target_block: 'core/quote' } )
				.variation.attributes
		).toEqual( {} );
	} );

	it( 'refuses a definition with nothing to attach to', () => {
		expect( buildVariationConfig( { name: 'ai-x' } ) ).toBeNull();
		expect(
			buildVariationConfig( { target_block: 'core/columns' } )
		).toBeNull();
	} );
} );
