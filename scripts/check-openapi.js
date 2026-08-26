#!/usr/bin/env node
/**
 * OpenAPI coverage ratchet (API-1).
 *
 * openapi.json describes 79 of 175 routes. The point of this guard is not to
 * document the remaining 96 today — that is weeks of work. The point is that the
 * number may only ever go DOWN, and that nothing NEW lands undocumented.
 *
 * This is the same shape as check-file-budgets.js, and for the same reason. A
 * guard that demands the finished state is red from its first commit, so it gets
 * added last, gets no soak time, and runs for real on release day. A ratchet is
 * green on day one and turns the gap into a burn-down.
 *
 * Why the gap exists at all is worth recording, because it changes what this
 * guard is for. Coverage was 53/163 at v1.7.0 and is 79/175 now; since v2.0.0 no
 * route has been added and no path documented. The spec never ran away — it never
 * ran. So this is inherited debt, not active drift, and a cheap guard that stops
 * NEW debt is worth more than a sprint that clears the old.
 *
 * WHAT IT CANNOT DO: everything here is static. It proves a route has an entry;
 * it cannot prove the entry is true. The four defects that opened the 2.5 work —
 * a request schema with the wrong field names, a 200 documented for a handler
 * that returns 201, two undocumented query params — all have a path, an
 * operationId, tags and a 200. They pass every rule below. Only the contract test
 * (scripts/run-contract-tests.sh) can see those.
 *
 * Run standalone, via `npm run lint:openapi`, or `--update` to re-record.
 */

const fs = require('fs')
const path = require('path')
const { parseRoutes, routeKey } = require('./lib/route-parser.js')

const REPO_ROOT = path.resolve(__dirname, '..')
const SPEC_FILE = path.join(REPO_ROOT, 'openapi.json')
const LEDGER_FILE = path.join(REPO_ROOT, '.openapi-coverage.json')

const VERBS = ['get', 'post', 'put', 'delete', 'patch', 'head', 'options']

/** Every operation in the spec, keyed the same way routes are. */
function specOperations(spec) {
	const ops = new Map()
	for (const [p, item] of Object.entries(spec.paths || {})) {
		if (!item || typeof item !== 'object') continue
		for (const verb of VERBS) {
			if (!item[verb]) continue
			ops.set(routeKey(verb, p), { path: p, verb: verb.toUpperCase(), op: item[verb] })
		}
	}
	return ops
}

/**
 * Which key does this route appear under in the spec?
 *
 * The 'ocs' block is mounted at /ocs/v2.php/apps/intravox at runtime, so a route
 * there can legitimately be documented under either its raw url or the prefixed
 * one. Accepting both keeps this guard out of a decision that belongs in the
 * spec, not here.
 */
function candidateKeys(route) {
	const keys = [routeKey(route.verb, route.url)]
	if (route.ocsUrl) keys.push(routeKey(route.verb, route.ocsUrl))
	return keys
}

function analyse() {
	const spec = JSON.parse(fs.readFileSync(SPEC_FILE, 'utf8'))
	const routes = parseRoutes()
	const ops = specOperations(spec)

	const declaredTags = new Set((spec.tags || []).map((t) => t.name))

	const matched = new Set()
	const undocumented = []
	const excludedSeen = []

	// Routes that deliberately have no operation, each with its reason. They are not
	// gaps and must not read as debt — but they stay listed, because 'not documented'
	// silently becoming 'forgotten' is the failure this whole guard exists to stop.
	const excluded = (loadLedger() || {}).excluded || {}

	for (const route of routes) {
		const id = `${route.verb} ${route.url}`
		if (excluded[id]) { excludedSeen.push(id); continue }

		const hit = candidateKeys(route).find((k) => ops.has(k))
		if (hit) matched.add(hit)
		else undocumented.push(id)
	}

	// An operation the router does not serve. Always a hard failure: it is a
	// promise to a client that nothing can keep.
	const phantom = [...ops.keys()].filter((k) => !matched.has(k))

	// Quality debt, per rule, over the operations that ARE documented.
	const debt = { missing_operation_id: [], missing_tags: [], undeclared_tag: [], duplicate_operation_id: [], response_without_schema: [] }

	// operationId must be unique across the whole document: a generator turns it
	// into a function name, and two operations sharing one silently collide — the
	// second overwrites the first and one endpoint vanishes from the client.
	const seenIds = new Map()

	for (const key of matched) {
		const { op } = ops.get(key)
		if (!op.operationId) debt.missing_operation_id.push(key)
		else if (seenIds.has(op.operationId)) {
			debt.duplicate_operation_id.push(`${key} (${op.operationId}, also ${seenIds.get(op.operationId)})`)
		} else seenIds.set(op.operationId, key)
		if (!Array.isArray(op.tags) || op.tags.length === 0) debt.missing_tags.push(key)
		else for (const t of op.tags) if (!declaredTags.has(t)) debt.undeclared_tag.push(`${key} (${t})`)

		const ok = (op.responses || {})['200'] || (op.responses || {})['201']
		if (ok && !ok.$ref && !ok.content) debt.response_without_schema.push(key)
	}

	for (const k of Object.keys(debt)) debt[k].sort()

	return { routes, ops, undocumented: undocumented.sort(), phantom: phantom.sort(), debt, excluded, excludedSeen }
}

function loadLedger() {
	if (!fs.existsSync(LEDGER_FILE)) return null
	return JSON.parse(fs.readFileSync(LEDGER_FILE, 'utf8'))
}

function writeLedger(state) {
	const payload = {
		_comment:
			'Generated by scripts/check-openapi.js. These lists may only SHRINK. ' +
			'Each entry is a route that openapi.json does not describe, or a documented ' +
			'operation that falls short of the quality rules. Run `npm run lint:openapi -- --update` ' +
			'after documenting one, and the ratchet clicks: the lower number can never be exceeded again.',
		_totals: {
			routes: state.routes.length,
			excluded: state.excludedSeen.length,
			in_scope: state.routes.length - state.excludedSeen.length,
			documented: state.routes.length - state.excludedSeen.length - state.undocumented.length,
			undocumented: state.undocumented.length,
		},
		excluded: state.excluded,
		undocumented: state.undocumented,
		quality_debt: state.debt,
	}
	fs.writeFileSync(LEDGER_FILE, JSON.stringify(payload, null, 2) + '\n')
}

function diff(current, recorded) {
	const was = new Set(recorded || [])
	return {
		added: current.filter((x) => !was.has(x)),
		fixed: (recorded || []).filter((x) => !current.includes(x)),
	}
}

function main() {
	const update = process.argv.includes('--update')
	const state = analyse()
	const ledger = loadLedger()

	// Excluded routes are neither documented nor debt, so they come out of the
	// denominator rather than quietly inflating the numerator. Counting a route we
	// decided NOT to describe as "documented" would let the percentage rise by
	// giving up, which is the one number this guard must never be able to fake.
	const total = state.routes.length
	const excludedCount = state.excludedSeen.length
	const inScope = total - excludedCount
	const documented = inScope - state.undocumented.length
	const pct = ((documented / inScope) * 100).toFixed(0)

	if (!ledger) {
		writeLedger(state)
		console.log(`✓ Wrote initial OpenAPI coverage baseline: ${documented}/${total} routes documented (${pct}%)`)
		console.log(`  ${state.undocumented.length} routes and ${Object.values(state.debt).flat().length} quality items recorded as debt.`)
		return
	}

	if (update) {
		writeLedger(state)
		console.log(`✓ OpenAPI ledger updated — ${documented}/${total} routes documented (${pct}%), ${state.undocumented.length} to go`)
		return
	}

	const failures = []

	// A route the spec does not describe AND that is not already on the ledger.
	const cov = diff(state.undocumented, ledger.undocumented)
	if (cov.added.length) {
		failures.push({
			title: 'New undocumented route(s) — every new route needs an openapi.json entry',
			lines: cov.added,
			hint: 'Add the path to openapi.json. Do not add it to .openapi-coverage.json; that list may only shrink.',
		})
	}

	// A phantom operation is never acceptable, ledger or not.
	if (state.phantom.length) {
		failures.push({
			title: 'Operation(s) in openapi.json with no matching route',
			lines: state.phantom,
			hint: 'Either the route was removed and the spec still promises it, or the path is misspelled.',
		})
	}

	// An exclusion for a route that no longer exists is a stale excuse: it makes the
	// list look considered while describing something gone. Cheap to catch here.
	const liveRoutes = new Set(state.routes.map((r) => `${r.verb} ${r.url}`))
	const staleExclusions = Object.keys(state.excluded).filter((id) => !liveRoutes.has(id))
	if (staleExclusions.length) {
		failures.push({
			title: 'Exclusion(s) for route(s) that no longer exist',
			lines: staleExclusions,
			hint: 'Remove the entry from .openapi-coverage.json — the route it excuses is gone.',
		})
	}

	// Quality rules, each ratcheted independently.
	const RULE_HINT = {
		missing_operation_id: 'Add an operationId — client generators need it.',
		duplicate_operation_id: 'Two operations share one operationId; a generated client loses one of them.',
		missing_tags: 'Add tags — untagged operations vanish from grouped docs.',
		undeclared_tag: 'Declare the tag in the top-level tags[] array.',
		response_without_schema: 'Give the success response a content schema.',
	}
	for (const rule of Object.keys(state.debt)) {
		const d = diff(state.debt[rule], (ledger.quality_debt || {})[rule])
		if (d.added.length) {
			failures.push({ title: `New violation of "${rule}"`, lines: d.added, hint: RULE_HINT[rule] })
		}
	}

	if (failures.length) {
		console.error('✗ OpenAPI guard failed\n')
		for (const f of failures) {
			console.error(`  ${f.title}:`)
			for (const l of f.lines) console.error(`      ${l}`)
			console.error(`    → ${f.hint}\n`)
		}
		process.exit(1)
	}

	// Progress: the ledger is stale in the good direction.
	const fixedCov = diff(state.undocumented, ledger.undocumented).fixed
	const fixedDebt = Object.keys(state.debt).flatMap((r) => diff(state.debt[r], (ledger.quality_debt || {})[r]).fixed)

	if (fixedCov.length || fixedDebt.length) {
		console.error('✗ The ratchet needs to click — these are now fixed but still on the ledger:\n')
		for (const l of [...fixedCov, ...fixedDebt]) console.error(`      ${l}`)
		console.error('\n  Run: npm run lint:openapi -- --update')
		process.exit(1)
	}

	console.log(`✓ OpenAPI coverage: ${documented}/${inScope} in-scope routes documented (${pct}%), ${state.undocumented.length} on the ledger, ${excludedCount} excluded, 0 phantom`)
}

main()
