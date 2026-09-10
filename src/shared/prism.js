/**
 * Syntax highlighting for the documentation's code samples.
 *
 * Prism, with only the grammars the documentation actually uses. The package is
 * 3.7MB of languages; the five loaded below are what docs/*.md is written in.
 *
 * The colors are not Prism's. A theme stylesheet would arrive with its own
 * background and its own idea of the chrome around it, and fight the block
 * styling in style.css — so only the token CLASSES come from here, and what
 * they look like is declared alongside everything else the app paints.
 */

/* eslint-disable global-require */

// Prism reads its configuration off `window.Prism` as it evaluates, and ES
// imports are hoisted above any statement that could set it — hence `require`.
// without manual mode it highlights the whole document on DOMContentLoaded,
// including, on an admin screen, markup that is none of its business
window.Prism = window.Prism || {}
window.Prism.manual = true

const Prism = require('prismjs')

// markup-templating first: php and twig are both templating grammars defined in
// terms of it, so loading either before it silently yields one that highlights
// nothing. javascript, markup and css are already in the core
require('prismjs/components/prism-markup-templating')
require('prismjs/components/prism-php')
require('prismjs/components/prism-twig')
require('prismjs/components/prism-json')
require('prismjs/components/prism-bash')

// no http grammar. it expects a full request line — `GET /path HTTP/1.1` and
// headers — where this documentation shows query strings, which it tokenises
// into nothing at all. DocsView skips a language Prism does not have, so those
// blocks keep their HTTP label and plain, readable text

/* eslint-enable global-require */

export default Prism
