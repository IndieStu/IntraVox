/**
 * One canonical shape for a filter row — JS mirror of
 * lib/Service/Filter/FilterSpec.php.
 *
 * IntraVox grew three filter-row schemas independently:
 *
 *   People / News : {fieldName, operator, value, values[]}
 *   Photo / File  : {field, op, value}
 *
 * They mean the same thing. These helpers normalise both onto
 * {field, op, value}, and convert back to the People shape when writing.
 *
 * IMPORTANT — keep this in sync with the PHP version. In particular, do not
 * "improve" the value/values precedence: a non-empty `values` array wins over
 * `value`, and the operator is left untouched. Saved page JSON in production
 * relies on exactly that.
 */

/**
 * Account-property keys that exist under two spellings.
 *
 * The PHP side lowercases account properties (`displayname`) while
 * `displayName` is set separately from the user object. Resolving both onto
 * one key is what stops a facet from silently matching nothing.
 */
const FIELD_ALIASES = {
	displayname: 'displayName',
	display_name: 'displayName',
	organization: 'organisation',
}

/**
 * Resolve a field name onto its canonical spelling.
 *
 * @param {string} field raw field name
 * @return {string} canonical field name
 */
export function aliasField(field) {
	const key = String(field ?? '').trim().toLowerCase()
	return FIELD_ALIASES[key] ?? field
}

/**
 * Normalise a single filter row from either legacy shape.
 *
 * @param {object} row raw filter row
 * @return {?object} `{field, op, value}` or null when unusable
 */
export function normalizeRow(row) {
	if (row === null || typeof row !== 'object' || Array.isArray(row)) {
		return null
	}

	// Accept both spellings; prefer the People/News one when both exist,
	// because that is the shape that carries `values` alongside.
	let field = ''
	if (row.fieldName !== undefined && row.fieldName !== null) {
		field = String(row.fieldName).trim()
	}
	if (field === '' && row.field !== undefined && row.field !== null) {
		field = String(row.field).trim()
	}
	if (field === '') {
		return null
	}

	let op = ''
	if (row.operator !== undefined && row.operator !== null) {
		op = String(row.operator).trim()
	}
	if (op === '' && row.op !== undefined && row.op !== null) {
		op = String(row.op).trim()
	}
	if (op === '') {
		op = 'equals'
	}

	// Precedence, reproduced verbatim from the PHP engines: a non-empty
	// `values` array wins over `value`. The operator is NOT rewritten.
	let value = null
	if (Array.isArray(row.values) && row.values.length > 0) {
		value = [...row.values]
	} else if ('value' in row) {
		value = row.value
	}

	return { field: aliasField(field), op, value }
}

/**
 * Normalise a list of rows, dropping unusable ones.
 *
 * @param {Array} rows raw filter rows
 * @return {Array} canonical rows
 */
export function normalizeList(rows) {
	if (!Array.isArray(rows)) {
		return []
	}
	return rows.map(normalizeRow).filter(row => row !== null)
}

/**
 * Convert a canonical row back to the People/News legacy shape.
 *
 * The widget editors keep writing the legacy shape for people and news so
 * existing page JSON round-trips unchanged.
 *
 * @param {object} row canonical `{field, op, value}`
 * @return {object} legacy `{fieldName, operator, value, values}`
 */
export function toLegacyPeople(row) {
	const value = row?.value ?? null
	const isList = Array.isArray(value)

	return {
		fieldName: String(row?.field ?? ''),
		operator: String(row?.op ?? 'equals'),
		value: isList ? '' : value,
		values: isList ? [...value] : [],
	}
}

export default { aliasField, normalizeRow, normalizeList, toLegacyPeople }
