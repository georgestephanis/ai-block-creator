/**
 * Overrides @wordpress/scripts' default `wp-scripts test-unit-js` config.
 * Picked up automatically because this file is named `jest-unit.config.js`
 * at the project root -- see @wordpress/scripts/utils/config.js.
 */
const defaultConfig = require( '@wordpress/scripts/config/jest-unit.config' );

module.exports = {
	...defaultConfig,
	moduleNameMapper: {
		...defaultConfig.moduleNameMapper,
		// See tests/js/mocks/wp-package-stub.js for why these are stubbed
		// rather than npm-installed real packages.
		'^@wordpress/(blocks|block-editor|components|element)$':
			'<rootDir>/tests/js/mocks/wp-package-stub.js',
	},
};
