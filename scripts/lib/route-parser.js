/**
 * The one parser for appinfo/routes.php.
 *
 * Two scripts need to know what the routes are: generate-route-table.js (which
 * publishes the auth posture) and check-openapi.js (which enforces that every
 * route is documented). Giving them a regex each is how the two drift apart, and
 * a route that one sees and the other does not is exactly the blind spot both
 * guards exist to remove.
 *
 * What this hardens compared to the regex it replaces:
 *
 *   1. It does not depend on key ORDER. The old pattern was welded to
 *      name -> url -> verb; an entry written url-first parsed as zero routes and
 *      the guard reported success on a file it had not read.
 *   2. It strips comments first. The old pattern matched commented-out routes,
 *      so deleting a route by commenting it out left it "present".
 *   3. It counts what it should have found and throws on a mismatch. A parser
 *      that silently returns fewer routes than the file contains is worse than
 *      one that crashes: the guards downstream would certify the remainder.
 *   4. It records which top-level block an entry came from ('ocs' or 'routes'),
 *      because their runtime URLs differ and only the caller knows which form it
 *      wants.
 */

const fs = require('fs')
const path = require('path')

const REPO_ROOT = path.resolve(__dirname, '..', '..')
const ROUTES_FILE = path.join(REPO_ROOT, 'appinfo/routes.php')

/** Nextcloud mounts the 'ocs' block under this prefix at runtime. */
const OCS_PREFIX = '/ocs/v2.php/apps/intravox'

/**
 * Remove PHP comments without touching quoted strings.
 *
 * Written as a scanner rather than a regex on purpose: a URL is full of slashes
 * and a naive /\/\/.*$/ turns '/api//legacy' into '/api'. Comments are replaced
 * by spaces so every byte offset stays where it was, which keeps the block
 * ranges below aligned with the original source.
 */
function stripComments(source) {
	let out = ''
	let inSingle = false
	let inDouble = false

	for (let i = 0; i < source.length; i++) {
		const c = source[i]
		const next = source[i + 1]

		if (!inSingle && !inDouble) {
			if (c === '/' && next === '/') {
				while (i < source.length && source[i] !== '\n') { out += ' '; i++ }
				out += '\n'
				continue
			}
			if (c === '#') {
				while (i < source.length && source[i] !== '\n') { out += ' '; i++ }
				out += '\n'
				continue
			}
			if (c === '/' && next === '*') {
				out += '  '
				i += 2
				while (i < source.length && !(source[i] === '*' && source[i + 1] === '/')) {
					out += source[i] === '\n' ? '\n' : ' '
					i++
				}
				out += '  '
				i++
				continue
			}
		}

		if (c === "'" && !inDouble && source[i - 1] !== '\\') inSingle = !inSingle
		else if (c === '"' && !inSingle && source[i - 1] !== '\\') inDouble = !inDouble

		out += c
	}

	return out
}

/** Byte ranges of the top-level 'ocs' => [...] and 'routes' => [...] blocks. */
function blockRanges(source) {
	const ranges = []

	for (const block of ['ocs', 'routes']) {
		const re = new RegExp(`'${block}'\\s*=>\\s*\\[`, 'g')
		const m = re.exec(source)
		if (!m) continue

		let depth = 0
		let start = m.index + m[0].length - 1
		for (let i = start; i < source.length; i++) {
			if (source[i] === '[') depth++
			else if (source[i] === ']') {
				depth--
				if (depth === 0) { ranges.push({ block, start, end: i }); break }
			}
		}
	}

	return ranges
}

function blockAt(ranges, offset) {
	const hit = ranges.find((r) => offset >= r.start && offset <= r.end)
	return hit ? hit.block : 'routes'
}

/**
 * Every route in appinfo/routes.php.
 *
 * @returns {Array<{name: string, url: string, verb: string, block: string, ocsUrl: string|null}>}
 */
function parseRoutes(file = ROUTES_FILE) {
	const raw = fs.readFileSync(file, 'utf8')
	const source = stripComments(raw)
	const ranges = blockRanges(source)

	// Each entry is a [...] literal. Pull the three keys out by name so the order
	// they were written in does not matter.
	const entryRe = /\[[^[\]]*'(?:name|url|verb)'[^[\]]*\]/g
	const found = []
	let m

	while ((m = entryRe.exec(source)) !== null) {
		const body = m[0]
		const name = /'name'\s*=>\s*'([^']+)'/.exec(body)
		const url = /'url'\s*=>\s*'([^']+)'/.exec(body)
		const verb = /'verb'\s*=>\s*'([^']+)'/.exec(body)
		if (!name || !url || !verb) continue

		const block = blockAt(ranges, m.index)
		found.push({
			name: name[1],
			url: url[1],
			verb: verb[1].toUpperCase(),
			block,
			ocsUrl: block === 'ocs' ? OCS_PREFIX + url[1] : null,
		})
	}

	// The count check: 'name' => appears once per route and nowhere else in this
	// file. If the two disagree the pattern above missed an entry shape, and the
	// only safe response is to stop rather than hand the caller a short list.
	const expected = (source.match(/'name'\s*=>/g) || []).length
	if (found.length !== expected) {
		throw new Error(
			`route-parser: parsed ${found.length} routes but appinfo/routes.php declares ${expected}. ` +
			'An entry shape changed — fix scripts/lib/route-parser.js rather than the caller.'
		)
	}

	return found
}

/**
 * Compare a route URL with an OpenAPI path.
 *
 * Placeholder NAMES are allowed to differ ({id} vs {pageId}); their POSITIONS are
 * not. The spec and the router are separate documents maintained by hand, and
 * demanding identical names would fail the guard on a cosmetic difference while
 * catching nothing real.
 */
function normalisePath(url) {
	return url.replace(/\{[^}]*\}/g, '{}').replace(/\/+$/, '') || '/'
}

/** The key both sides of the guard compare on. */
function routeKey(verb, url) {
	return `${verb.toUpperCase()} ${normalisePath(url)}`
}

module.exports = { parseRoutes, normalisePath, routeKey, stripComments, OCS_PREFIX, ROUTES_FILE, REPO_ROOT }
