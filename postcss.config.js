/**
 * @wordpress/scripts runs postcss-loader over imported stylesheets and picks
 * this file up automatically.
 */

module.exports = {
  plugins: {
    tailwindcss: {},
    autoprefixer: {},
  },
};
