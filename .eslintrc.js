/**
 * ESLint for the admin.
 *
 * Deliberately NOT `plugin:@wordpress/recommended`, which is what `wp-scripts
 * lint-js` reaches for when there is no config here. That preset could not
 * load at all in this tree: it pulls @typescript-eslint 6, which pulls
 * ts-api-utils 1.x, which reads TypeScript internals that TypeScript 7 —
 * hoisted here by @wordpress/scripts itself — no longer exposes. The failure
 * is `Cannot read properties of undefined (reading 'Intrinsic')`, it happens
 * before a single file is read, and there is no version of it that is this
 * project's problem to fix: THERE IS NO TYPESCRIPT IN THIS PLUGIN. Loading a
 * TypeScript toolchain to lint plain JavaScript was always the wrong trade.
 *
 * So this is assembled from the pieces that are worth having on a React
 * codebase and nothing else: the rules-of-hooks check, which catches a real
 * class of bug that no test here would; unused and undefined bindings; and
 * eslint-config-prettier last, so ESLint never argues with the formatter about
 * whitespace. Formatting is Prettier's job and only Prettier's.
 *
 * The plugins are dependencies of @wordpress/eslint-plugin rather than direct
 * ones. They are declared in package.json anyway, so `npm ci` cannot leave
 * this config referring to something that is not there.
 *
 *   npm run lint:js
 */

module.exports = {
  root: true,

  env: {
    browser: true,
    es2022: true,
  },

  parserOptions: {
    ecmaVersion: 2022,
    sourceType: 'module',
    ecmaFeatures: {
      jsx: true,
    },
  },

  settings: {
    // the admin is @wordpress/element, which is React 18 with a WordPress name
    react: {
      version: '18.0',
    },
  },

  plugins: ['react', 'react-hooks'],

  extends: [
    'eslint:recommended',
    'plugin:react/recommended',

    // the automatic runtime: wp-scripts compiles JSX without a React import,
    // so the rules that demand one are wrong here
    'plugin:react/jsx-runtime',

    // last, and it must stay last — it switches off every stylistic rule the
    // presets above turn on, which is what keeps ESLint and Prettier from
    // disagreeing about the same line
    'prettier',
  ],

  rules: {
    // the one that earns its place. a hook called conditionally breaks in a
    // way that looks like a state bug three components away
    'react-hooks/rules-of-hooks': 'error',
    'react-hooks/exhaustive-deps': 'warn',

    'no-unused-vars': [
      'error',
      {
        argsIgnorePattern: '^_',
        varsIgnorePattern: '^_',
        ignoreRestSiblings: true,
      },
    ],

    // props are documented in JSDoc above each component, and this codebase
    // does not carry prop-types at runtime
    'react/prop-types': 'off',
  },

  overrides: [
    {
      // prism.js loads its grammars with webpack's require ON PURPOSE. import
      // is hoisted, so converting these would run them before the
      // `window.Prism.manual` assignment they sit under — which is the whole
      // point of that file, and the reason it highlights nothing if reordered.
      files: ['src/shared/prism.js'],
      globals: {
        require: 'readonly',
      },
    },
    {
      // build tooling, not the admin bundle: these run in node
      files: ['*.config.js', '.eslintrc.js'],
      env: {
        node: true,
        browser: false,
      },
    },
  ],
}
