#!/usr/bin/env node
/*
 * Minifies the JavaScript and CSS inside a staged release package.
 *
 *   node scripts/minify-package-assets.cjs dist/bible
 *
 * Every .js and .css file under assets/ that is not already *.min.* is
 * rewritten in place with esbuild (no bundling: whitespace, syntax and local
 * names only; top-level names and the syntax level are unchanged). Scripts are
 * parsed once afterwards. The repository keeps the readable sources.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const esbuild = require('esbuild');

const plugin = process.argv[2];
if (!plugin || !fs.existsSync(path.join(plugin, 'bible.php'))) {
    console.error('Usage: node scripts/minify-package-assets.cjs <staged bible directory>');
    process.exit(2);
}

function walk(dir) {
    return fs.readdirSync(dir, {withFileTypes: true}).flatMap((entry) => {
        const file = path.join(dir, entry.name);
        if (entry.isDirectory()) return walk(file);
        return /\.(js|css)$/.test(entry.name) && !/\.min\.(js|css)$/.test(entry.name) ? [file] : [];
    });
}

let before = 0;
let after = 0;
const files = walk(path.join(plugin, 'assets'));
for (const file of files) {
    const source = fs.readFileSync(file, 'utf8');
    const loader = file.endsWith('.css') ? 'css' : 'js';
    const {code} = esbuild.transformSync(source, {loader, minify: true, legalComments: 'inline', charset: 'utf8'});
    if (loader === 'js') new vm.Script(code, {filename: file});
    fs.writeFileSync(file, code);
    before += Buffer.byteLength(source);
    after += Buffer.byteLength(code);
}
if (!files.length) {
    console.error('No JavaScript or CSS found under assets/');
    process.exit(1);
}
console.log(`Minified ${files.length} files: ${before} → ${after} bytes.`);
