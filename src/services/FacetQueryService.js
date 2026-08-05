import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { normalizeList } from './filterSpec.js'

/**
 * Transport and serialisation for viewer-side faceted queries.
 *
 * Stateless by design, matching CacheService / PrefetchService / CommentService.
 * All reactive state lives in the facetedWidget mixin — keeping the two apart
 * is what lets the chips, the panel and any future permalink button agree on
 * one encoding without sharing a component.
 */

/** Separator between fields in a serialised refinement string. */
const FIELD_SEP = '~'
/** Separator between a field and its values. */
const VALUE_SEP = ':'
/** Separator between values of one field. */
const LIST_SEP = '|'
/**
 * Reserved pseudo-field carrying the free-text term.
 *
 * The `$` prefix is what keeps it out of the field namespace: real field
 * names are validated server-side against /^[a-z][a-z0-9_]{0,63}$/i, so no
 * genuine facet field can ever serialise to this token. A bare `q` would
 * collide with a field actually named "q" and silently drop it.
 */
const QUERY_FIELD = '$q'

/**
 * Percent-encode a value for the token.
 *
 * encodeURIComponent() leaves `~` alone (it is an RFC 3986 unreserved
 * character), and `:` and `|` survive in some contexts too — but all three
 * are structural separators here. Escaping them explicitly is what stops a
 * value like "e~f" from splitting the token and silently truncating
 * everything after it.
 *
 * @param {string} value raw value
 * @return {string} encoded value
 */
function encodeToken(value) {
	return encodeURIComponent(String(value))
		.replace(/~/g, '%7E')
		.replace(/:/g, '%3A')
		.replace(/\|/g, '%7C')
}

/**
 * Serialise refinements (plus free text) into one URL-safe token.
 *
 * Every value is encoded individually, so a real value containing `:`, `|`,
 * `~`, `&` or `#` survives the round trip.
 *
 * @param {object} refinements field => array of selected values
 * @param {string} q free-text term
 * @return {string} serialised token, empty when nothing is active
 */
export function serializeRefinements(refinements = {}, q = '') {
	const parts = []

	for (const [field, values] of Object.entries(refinements)) {
		if (!Array.isArray(values) || values.length === 0) {
			continue
		}
		const encoded = values.map(encodeToken).join(LIST_SEP)
		parts.push(`${encodeToken(field)}${VALUE_SEP}${encoded}`)
	}

	if (q) {
		// The pseudo-field is emitted literally, not encoded, so the parser
		// can match it before it decodes anything.
		parts.push(`${QUERY_FIELD}${VALUE_SEP}${encodeToken(q)}`)
	}

	return parts.join(FIELD_SEP)
}

/**
 * Percent-decode a token segment without throwing.
 *
 * decodeURIComponent() raises URIError on a malformed sequence such as `%%%`.
 * The token comes straight from the address bar, so a hand-edited or
 * truncated URL must degrade to "no filter" rather than take the widget down
 * with it.
 *
 * @param {string} value encoded segment
 * @return {?string} decoded value, or null when undecodable
 */
function decodeToken(value) {
	try {
		return decodeURIComponent(value)
	} catch (error) {
		return null
	}
}

/**
 * Inverse of serializeRefinements().
 *
 * Never throws: anything unparseable is skipped.
 *
 * @param {string} token serialised token
 * @return {{refinements: object, q: string}} parsed state
 */
export function parseRefinements(token) {
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

		const values = rawValues
			.split(LIST_SEP)
			.map(decodeToken)
			.filter(v => v !== null && v !== '')

		if (values.length > 0) {
			refinements[field] = values
		}
	}

	return { refinements, q }
}

/**
 * Convert the panel's {field: [values]} shape into canonical filter rows.
 *
 * @param {object} refinements field => array of selected values
 * @return {Array} canonical rows
 */
export function toFilterRows(refinements = {}) {
	return normalizeList(
		Object.entries(refinements)
			.filter(([, values]) => Array.isArray(values) && values.length > 0)
			.map(([field, values]) => ({ field, op: 'in', value: values })),
	)
}

/**
 * Run a faceted query against a widget's endpoint.
 *
 * @param {object} options query options
 * @param {string} options.endpoint app-relative endpoint, e.g. '/api/people'
 * @param {object} options.baseParams params from the widget config (editor side)
 * @param {object} options.refinements viewer refinements, field => values
 * @param {string} options.q free-text term
 * @param {Array} options.facets field names to compute facets for
 * @param {number} options.facetLimit max values per facet
 * @param {AbortSignal} options.signal cancellation signal
 * @return {Promise<object>} normalised response
 */
export async function runFacetedQuery({
	endpoint,
	baseParams = {},
	refinements = {},
	q = '',
	facets = [],
	facetLimit = 20,
	signal = undefined,
}) {
	const params = new URLSearchParams()

	for (const [key, value] of Object.entries(baseParams)) {
		if (value === null || value === undefined || value === '') {
			continue
		}
		params.append(key, typeof value === 'object' ? JSON.stringify(value) : String(value))
	}

	const rows = toFilterRows(refinements)
	if (rows.length > 0) {
		params.append('refine', JSON.stringify(rows))
	}
	if (q) {
		params.append('q', q)
	}
	if (facets.length > 0) {
		params.append('facets', facets.join(','))
		params.append('facetLimit', String(facetLimit))
	}

	const url = generateUrl(`/apps/intravox${endpoint}?${params.toString()}`)

	const response = await axios.get(url, {
		signal,
		validateStatus: s => (s >= 200 && s < 300) || s === 304,
	})

	return normalizeResponse(response.data)
}

/**
 * Give every widget the same response shape to render from.
 *
 * @param {object} data raw response body
 * @return {object} normalised response
 */
export function normalizeResponse(data) {
	const payload = data ?? {}

	return {
		items: payload.users ?? payload.files ?? payload.items ?? [],
		total: Number(payload.total ?? 0),
		hasMore: Boolean(payload.hasMore),
		facets: Array.isArray(payload.facets) ? payload.facets : [],
		meta: payload.meta ?? { approximate: false, scanned: 0, cap: 0 },
	}
}

/**
 * Count how many individual values are selected across all facets.
 *
 * @param {object} refinements field => array of selected values
 * @return {number} total selected values
 */
export function countActiveRefinements(refinements = {}) {
	return Object.values(refinements)
		.filter(Array.isArray)
		.reduce((sum, values) => sum + values.length, 0)
}

export default {
	serializeRefinements,
	parseRefinements,
	toFilterRows,
	runFacetedQuery,
	normalizeResponse,
	countActiveRefinements,
}
