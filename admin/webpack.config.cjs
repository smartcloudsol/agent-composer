const defaultConfig = require("@wordpress/scripts/config/webpack.config");
const path = require("path");

module.exports = {
  ...defaultConfig,
  entry: { index: [path.resolve(process.cwd(), "src", "index.tsx")] },
  externals: {
    ...defaultConfig.externals,
    "@mantine/core": "WpSuiteMantine",
    "@mantine/hooks": "WpSuiteMantine",
    "@mantine/notifications": "WpSuiteMantine"
  }
};
