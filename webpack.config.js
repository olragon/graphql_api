var path = require('path');
var webpack = require('webpack');

module.exports = {
  cache: true,
  mode: 'production',
  devtool: 'cheap-module-source-map',
  entry: ['./js/src/index.js'],
  output: {
    path: path.resolve('./js/dist'),
    filename: '[name].bundle.js',
    publicPath: '/'
  },
  module: {
    rules: [
      {
        test: /\.flow$/,
        loader: 'ignore-loader'
      },
      {
        test: /\.mjs$/,
        include: /node_modules/,
        type: "javascript/auto",
      },
      {
        test: /\.jsx?$/,
        exclude: /(node_modules|bower_components)/,
        use: {
          loader: 'babel-loader',
          options: { babelrc: true }
        }
      },
      {
        test: /\.css$/,
        use: [
          { loader: "style-loader" },
          { loader: "css-loader" }
        ]
      }
    ]
  },
  resolve: {
    mainFields: ['browser', 'main', 'module'],
    extensions: ['.mjs', '.js', '.json', '.jsx']
  }
};