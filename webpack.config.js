/**
 * Two admin bundles, built with the WordPress toolchain so that React and the
 * @wordpress/* packages resolve to the copies WordPress already ships. Each
 * entry emits a sibling .asset.php declaring its script dependencies, which
 * SchemaPress\Assets reads at enqueue time.
 */

const path = require('path')
const defaultConfig = require('@wordpress/scripts/config/webpack.config')

module.exports = {
  ...defaultConfig,
  entry: {
    // the SchemaPress screen: schema building and content editing in one app
    admin: path.resolve(__dirname, 'src/admin/index.js'),
    // the section editor mounted on a bound page's own edit screen
    'page-editor': path.resolve(__dirname, 'src/page-editor/index.js')
  },
  output: {
    ...defaultConfig.output,
    path: path.resolve(__dirname, 'build'),
    filename: '[name].js'
  }
}
