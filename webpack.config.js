/**
 * One admin bundle, built with the WordPress toolchain so that React and the
 * @wordpress/* packages resolve to the copies WordPress already ships. The
 * entry emits a sibling .asset.php declaring its script dependencies, which
 * SchemaPress\Assets reads at enqueue time.
 */

const path = require('path')
const defaultConfig = require('@wordpress/scripts/config/webpack.config')

module.exports = {
  ...defaultConfig,
  entry: {
    // one screen: the content-type builder and the content manager
    admin: path.resolve(__dirname, 'src/admin/index.js')
  },
  output: {
    ...defaultConfig.output,
    path: path.resolve(__dirname, 'build'),
    filename: '[name].js'
  }
}
