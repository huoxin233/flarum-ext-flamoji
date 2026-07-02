const path = require('path');
const config = require('flarum-webpack-config')();



// emoji-mart's published dist/module.js is already browser-targeted ES —
// skip babel for it. Without this, babel transforms ES6 classes into plain
// functions, breaking HTMLElement subclasses (Web Components).
const babelRule = config.module.rules.find(
  (r) => r.loader === 'babel-loader'
);
if (babelRule) {
  babelRule.exclude = /node_modules\/(emoji-mart|@emoji-mart)\//;
}

module.exports = config;
