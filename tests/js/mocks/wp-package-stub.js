/**
 * Referenced from ../../../jest-unit.config.js.
 *
 * Stub for @wordpress/* packages that Jest needs to resolve but whose real
 * behavior doesn't matter for the unit under test (pure functions like
 * interpolateTemplate() don't touch block registration or component
 * rendering at all).
 *
 * These packages ship as TypeScript/ESM-first source in their published npm
 * versions, which Jest's default (non-transforming-node_modules) config
 * can't parse -- and webpack never actually resolves them from disk anyway
 * (they're mapped to `wp.*` globals via @wordpress/dependency-extraction-
 * webpack-plugin at build time), so there is no real "correct" version to
 * npm-install here. A Proxy that hands back a no-op function for anything
 * accessed keeps the module graph resolvable without pretending to
 * reimplement the real package.
 */
const noop = () => undefined;

module.exports = new Proxy(
	{},
	{
		get: () => noop,
	}
);
