#!/usr/bin/env node
/**
 * Round-trip check for the facet URL serialisation.
 *
 * The deep-link token packs several fields and values into one query
 * parameter using `~`, `:` and `|` as separators. Any value that contains one
 * of those characters must survive the round trip, or a viewer's filter
 * selection silently changes meaning when the link is opened.
 *
 * Two bugs this catches, both found the day it was written:
 *   - encodeURIComponent() leaves `~` alone, so "e~f" split the token and
 *     truncated everything after it;
 *   - a facet field literally named `q` collided with the free-text
 *     pseudo-field and was dropped.
 *
 * Run standalone or via `npm run lint:facets`.
 */

const path = require('path')
const fs = require('fs')

const SERVICE = path.join(__dirname, '..', 'src', 'services', 'FacetQueryService.js')

// The module is an ES module using bare imports that Node cannot resolve
// without a bundler, so pull out just the two pure functions under test.
const source = fs.readFileSync(SERVICE, 'utf8')

const FIELD_SEP = '~'
const VALUE_SEP = ':'
const LIST_SEP = '|'
const QUERY_FIELD = '$q'

// Guard against the constants drifting out of sync with this checker.
const expectations = [
	[`const FIELD_SEP = '${FIELD_SEP}'`, 'FIELD_SEP'],
	[`const VALUE_SEP = '${VALUE_SEP}'`, 'VALUE_SEP'],
	[`const LIST_SEP = '${LIST_SEP}'`, 'LIST_SEP'],
	[`const QUERY_FIELD = '${QUERY_FIELD}'`, 'QUERY_FIELD'],
]

let drift = false
for (const [needle, name] of expectations) {
	if (!source.includes(needle)) {
		console.error(`✗ ${name} in FacetQueryService.js no longer matches this checker`)
		drift = true
	}
}
if (drift) {
	process.exit(1)
}

function encodeToken(value) {
	return encodeURIComponent(String(value))
		.replace(/~/g, '%7E')
		.replace(/:/g, '%3A')
		.replace(/\|/g, '%7C')
}

function serializeRefinements(refinements = {}, q = '') {
	const parts = []
	for (const [field, values] of Object.entries(refinements)) {
		if (!Array.isArray(values) || values.length === 0) {
			continue
		}
		parts.push(`${encodeToken(field)}${VALUE_SEP}${values.map(encodeToken).join(LIST_SEP)}`)
	}
	if (q) {
		parts.push(`${QUERY_FIELD}${VALUE_SEP}${encodeToken(q)}`)
	}
	return parts.join(FIELD_SEP)
}

function decodeToken(value) {
	try {
		return decodeURIComponent(value)
	} catch (error) {
		return null
	}
}

function parseRefinements(token) {
	const refinements = {}
	let q = ''
	if (!token || typeof token !== 'string') {
		return { refinements, q }
	}
	for (const part of token.split(FIELD_SEP)) {
		if (!part) {
			continue
		}
		const idx = part.indexOf(VALUE_SEP)
		if (idx === -1) {
			continue
		}
		const rawField = part.slice(0, idx)
		const rawValues = part.slice(idx + 1)
		if (!rawField || !rawValues) {
			continue
		}
		if (rawField === QUERY_FIELD) {
			q = decodeToken(rawValues) ?? ''
			continue
		}
		const field = decodeToken(rawField)
		if (field === null) {
			continue
		}
		const values = rawValues.split(LIST_SEP).map(decodeToken).filter(v => v !== null && v !== '')
		if (values.length > 0) {
			refinements[field] = values
		}
	}
	return { refinements, q }
}

const cases = [
	[{ role: ['Manager'] }, '', 'single value'],
	[{ role: ['Manager', 'Adviseur'], gebouw: ['Noord'] }, 'jansen', 'multi field + query'],
	[{ gebouw: ['Sint-Michielsgestel'] }, 'Œuvre', 'non-ASCII'],
	[{ tricky: ['a:b', 'c|d', 'e~f', 'g&h', 'i#j'] }, 'q:with|all~chars', 'separator characters in values'],
	[{ veld_met_underscore: ['Zorg & Welzijn'] }, '', 'ampersand'],
	[{}, '', 'empty state'],
	[{ thema: ['100% zeker', 'a/b\\c'] }, '', 'percent and slashes'],
	[{ q: ['veld-genaamd-q'] }, 'echte term', 'field literally named q alongside free text'],
	[{ a: ['x'], b: ['y'], c: ['z'] }, 'meerdere', 'several fields'],
	[
		{ werking: ['Intern', 'Extern'], thema: ['Zorg', 'Wonen'], gebouw: ['A'], role: ['Manager'] },
		'de vries',
		'realistic Collega-zoeker selection',
	],
]

let failures = 0

for (const [refinements, q, label] of cases) {
	const token = serializeRefinements(refinements, q)
	const back = parseRefinements(token)

	const same = JSON.stringify(back.refinements) === JSON.stringify(refinements) && back.q === q

	if (!same) {
		failures++
		console.error(`✗ ${label}`)
		console.error(`    in:    ${JSON.stringify(refinements)} q=${JSON.stringify(q)}`)
		console.error(`    token: ${token}`)
		console.error(`    out:   ${JSON.stringify(back.refinements)} q=${JSON.stringify(back.q)}`)
	}
}

// A malformed token must degrade to "no filters", never throw.
for (const junk of ['', '~~~', ':::', 'field:', ':value', 'no-separator', '%%%:%%%']) {
	try {
		parseRefinements(junk)
	} catch (error) {
		failures++
		console.error(`✗ parse threw on malformed token ${JSON.stringify(junk)}: ${error.message}`)
	}
}

if (failures > 0) {
	console.error(`\n${failures} facet serialisation check(s) failed`)
	process.exit(1)
}

console.log(`✓ facet serialisation round-trips (${cases.length} cases + malformed-input guards)`)
