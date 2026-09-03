/**
 * Tests for interpolateTemplate() — the client-side twin of
 * AI_Block_Renderer::render_template() (see includes/class-ai-block-renderer.php
 * and its PHPUnit counterpart in tests/php/RendererTest.php). Both
 * implementations must behave identically; these cases mirror that suite so a
 * divergence between them fails a test instead of shipping as a bug (this is
 * exactly how BUG-1 through BUG-4 in plans/done/code-review-2026-09-03.md slipped
 * through originally).
 */

import { interpolateTemplate } from '../dynamic-block-factory';

describe( 'interpolateTemplate', () => {
	it( 'returns an empty string for an empty template', () => {
		expect( interpolateTemplate( '', { a: 1 } ) ).toBe( '' );
		expect( interpolateTemplate( undefined, { a: 1 } ) ).toBe( '' );
	} );

	it( 'escapes plain {{var}} interpolation as HTML text', () => {
		const html = interpolateTemplate( '<h3>{{title}}</h3>', {
			title: '<b>Hi</b> & bye',
		} );
		// @wordpress/escape-html's escapeHTML() deliberately does not escape
		// ">" in text-node context -- per the WHATWG spec, only "<" and an
		// ambiguous "&" are meaningful there, so a bare ">" can't start or
		// end a tag. PHP's esc_html() escapes it anyway as extra defense in
		// depth; both are safe, this is just a cosmetic difference in output.
		expect( html ).toBe( '<h3>&lt;b>Hi&lt;/b> &amp; bye</h3>' );
	} );

	it( 'renders {{{raw}}} triple-brace output without stray braces (BUG-1)', () => {
		// The double-brace {{x}} pass must not run first and consume the
		// inner placeholder of {{{x}}}, which is exactly what happened
		// before the fix (output was literally "{escaped}").
		const html = interpolateTemplate( '<div>{{{html}}}</div>', {
			html: '<em>rich</em>',
		} );
		expect( html ).not.toContain( '{' );
		expect( html ).not.toContain( '}' );
	} );

	it( 'resolves boolean conditionals', () => {
		const template =
			'{{#if isFeatured}}YES{{/if}}{{^if isFeatured}}NO{{/if}}';
		expect( interpolateTemplate( template, { isFeatured: false } ) ).toBe(
			'NO'
		);
		expect( interpolateTemplate( template, { isFeatured: true } ) ).toBe(
			'YES'
		);
	} );

	it( 'resolves nested {{#if}} conditionals (BUG-2)', () => {
		const template = 'before{{#if a}}A{{#if b}}B{{/if}}C{{/if}}after';

		expect( interpolateTemplate( template, { a: true, b: true } ) ).toBe(
			'beforeABCafter'
		);
		expect( interpolateTemplate( template, { a: true, b: false } ) ).toBe(
			'beforeACafter'
		);
		expect( interpolateTemplate( template, { a: false, b: true } ) ).toBe(
			'beforeafter'
		);
	} );

	it( 'renders {{#list}} repeaters from newline-delimited strings and arrays', () => {
		const template = '<ul>{{#list items}}<li>{{item}}</li>{{/list}}</ul>';

		expect(
			interpolateTemplate( template, { items: 'a\n<i>b</i>\n' } )
		).toBe( '<ul><li>a</li><li>&lt;i>b&lt;/i></li></ul>' );

		expect( interpolateTemplate( template, { items: [ 'x', 'y' ] } ) ).toBe(
			'<ul><li>x</li><li>y</li></ul>'
		);
	} );

	it( 'still resolves other outer attributes inside a {{#list}} body after {{item}} is filled in', () => {
		// {{#list}} only special-cases {{item}} in its own pass; it doesn't
		// isolate its body from the later top-level {{key}} substitution
		// pass, so any other real attribute name used inside a list item
		// template still resolves from the outer attributes -- verified to
		// match AI_Block_Renderer::render_template()'s PHP behavior, which
		// has the same two-pass structure.
		const template = '{{#list items}}<li>{{item}} {{title}}</li>{{/list}}';
		const html = interpolateTemplate( template, {
			items: 'a\nb',
			title: 'Widget',
		} );
		expect( html ).toBe( '<li>a Widget</li><li>b Widget</li>' );
	} );

	it( 'routes href/src attributes through URL escaping and blocks javascript: (BUG-4)', () => {
		const html = interpolateTemplate( '<a href="{{url}}">x</a>', {
			url: 'javascript:alert(1)',
		} );
		expect( html ).not.toContain( 'javascript:' );
	} );

	it( 'sanitizes style="" interpolation and strips expression()', () => {
		const html = interpolateTemplate( '<div style="color:{{c}}">x</div>', {
			c: 'red;background:expression(alert(1))',
		} );
		expect( html ).not.toContain( 'expression(' );
		expect( html ).toContain( 'color:red' );
	} );

	it( 'stringifies booleans as 1/0 and coerces arrays to a comma list', () => {
		expect( interpolateTemplate( '{{flag}}', { flag: true } ) ).toBe( '1' );
		expect( interpolateTemplate( '{{flag}}', { flag: false } ) ).toBe(
			'0'
		);
		expect(
			interpolateTemplate( '{{items}}', { items: [ 'a', 'b' ] } )
		).toBe( 'a, b' );
	} );

	it( 'interpolates multiple variables within the same style attribute', () => {
		const html = interpolateTemplate(
			'<div style="color: {{color}}; background: {{bg}};">x</div>',
			{ color: 'red', bg: 'blue' }
		);
		expect( html ).toBe(
			'<div style="color: red; background: blue;">x</div>'
		);
	} );

	it( 'interpolates multiple variables within a URL attribute', () => {
		const html = interpolateTemplate(
			'<a href="https://example.com/{{path}}?plan={{plan}}">link</a>',
			{ path: 'pricing', plan: 'pro' }
		);
		expect( html ).toBe(
			'<a href="https://example.com/pricing?plan=pro">link</a>'
		);
	} );

	it( 'renders an empty string for a missing attribute rather than leaving the placeholder', () => {
		expect( interpolateTemplate( '{{missing}}', {} ) ).toBe( '' );
	} );
} );
