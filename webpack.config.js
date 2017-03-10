var path = require('path');

module.exports = {
  cache: true,
  devtool: 'cheap-module-source-map',
  entry: ['./js/src/index.js'],
  output: {
    path: path.resolve('./js/dist'),
    filename: '[name].bundle.js',
    publicPath: '/'
  },
  module: {
    loaders: [
      {
        test: /\.jsx?$/,
        loader: 'babel-loader?cacheDirectory=true&presets[]=es2015&presets[]=react'
      },
      {
        test: /\.css$/,
        loaders: ["style-loader", "css-loader"]
      }
    ]
  }
};
