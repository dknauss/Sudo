import wordpress from '@wordpress/eslint-plugin';
import globals from 'globals';

export default [
	...wordpress.configs[ 'recommended-with-formatting' ],
	{
		files: [ 'admin/js/**/*.js' ],
		languageOptions: {
			globals: {
				...globals.browser,
			},
		},
		rules: {
			// These scripts ship untranspiled and deliberately retain ES5 `var`
			// declarations and explicit object properties for the WordPress 6.4
			// browser support floor.
			'no-var': 'off',
			'object-shorthand': 'off',
			'prefer-const': 'off',
			'no-unused-vars': [
				'error',
				{ ignoreRestSiblings: true, caughtErrors: 'none' },
			],
			'no-restricted-syntax': [
				'error',
				{
					selector: "Property[key.name='dangerouslySetInnerHTML']",
					message: 'Raw HTML injection requires a documented, line-scoped security review.',
				},
			],

			// WordPress-style file headers use `@package WP_Sudo`; the generic
			// JSDoc preset treats @package as a valueless tag.
			'jsdoc/empty-tags': 'off',
		},
	},
];
