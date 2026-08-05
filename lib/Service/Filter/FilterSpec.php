<?php

declare(strict_types=1);

namespace OCA\IntraVox\Service\Filter;

/**
 * One canonical shape for a filter row.
 *
 * IntraVox grew three filter-row schemas independently:
 *
 *   People / News : {fieldName, operator, value, values[]}
 *   Photo / File  : {field, op, value}
 *
 * They mean the same thing. This class normalises both onto
 * {field, op, value} so new code has exactly one shape to reason about,
 * and can convert back to the People shape for the sanitizer.
 *
 * Deliberately free of Nextcloud dependencies so it is unit-testable.
 *
 * IMPORTANT — do not "improve" the value/values precedence. Saved page JSON
 * in production relies on the exact behaviour reproduced in normalizeRow():
 * a non-empty `values` array wins over `value`, and the operator is left
 * untouched. See UserService::matchesFilters() (the `values` branch) and
 * getUsersByFilters() (the group-filter branch), which both do this.
 */
final class FilterSpec {
	/**
	 * Account-property keys that exist under two spellings.
	 *
	 * UserService::propertyToKey() lowercases account properties, so
	 * IAccountManager gives us `displayname`, while `displayName` is set
	 * separately from IUser::getDisplayName(). Field names arriving from a
	 * widget config or a query string may use either. Resolving both onto one
	 * key is what stops a facet from silently matching nothing.
	 */
	private const FIELD_ALIASES = [
		'displayname' => 'displayName',
		'display_name' => 'displayName',
		'organization' => 'organisation',
	];

	/**
	 * Normalise a single filter row from either legacy shape.
	 *
	 * @param mixed $row
	 * @return array{field: string, op: string, value: mixed}|null
	 *         null when the row is unusable (no field name).
	 */
	public static function normalizeRow(mixed $row): ?array {
		if (!is_array($row)) {
			return null;
		}

		// Accept both spellings; prefer the People/News one when both exist,
		// because that is the shape that carries `values` alongside.
		$field = '';
		if (isset($row['fieldName']) && is_scalar($row['fieldName'])) {
			$field = trim((string)$row['fieldName']);
		}
		if ($field === '' && isset($row['field']) && is_scalar($row['field'])) {
			$field = trim((string)$row['field']);
		}

		if ($field === '') {
			return null;
		}

		$op = '';
		if (isset($row['operator']) && is_scalar($row['operator'])) {
			$op = trim((string)$row['operator']);
		}
		if ($op === '' && isset($row['op']) && is_scalar($row['op'])) {
			$op = trim((string)$row['op']);
		}
		if ($op === '') {
			$op = 'equals';
		}

		// Precedence, reproduced verbatim from UserService: a non-empty
		// `values` array wins over `value`. The operator is NOT rewritten —
		// the existing engines pass the array into whichever operator was
		// configured, and changing that would alter saved pages' meaning.
		$value = null;
		if (!empty($row['values']) && is_array($row['values'])) {
			$value = array_values($row['values']);
		} elseif (array_key_exists('value', $row)) {
			$value = $row['value'];
		}

		return [
			'field' => self::aliasField($field),
			'op' => $op,
			'value' => $value,
		];
	}

	/**
	 * Normalise a list of rows, dropping unusable ones.
	 *
	 * @param mixed $rows
	 * @return array<int, array{field: string, op: string, value: mixed}>
	 */
	public static function normalizeList(mixed $rows): array {
		if (!is_array($rows)) {
			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			$normalized = self::normalizeRow($row);
			if ($normalized !== null) {
				$out[] = $normalized;
			}
		}

		return $out;
	}

	/**
	 * Convert a canonical row back to the People/News legacy shape.
	 *
	 * The sanitizer keeps emitting the legacy shape for `people` and `news`
	 * so existing page JSON round-trips unchanged and a downgrade keeps
	 * working. Only genuinely new config keys use the canonical shape.
	 *
	 * @param array{field: string, op: string, value: mixed} $row
	 * @return array{fieldName: string, operator: string, value: mixed, values: array}
	 */
	public static function toLegacyPeople(array $row): array {
		$value = $row['value'] ?? null;
		$isList = is_array($value);

		return [
			'fieldName' => (string)($row['field'] ?? ''),
			'operator' => (string)($row['op'] ?? 'equals'),
			// Mirror how the legacy readers expect these: when the value is a
			// list it lives in `values` and `value` is blanked, otherwise the
			// reverse. Round-tripping through normalizeRow() is lossless.
			'value' => $isList ? '' : $value,
			'values' => $isList ? array_values($value) : [],
		];
	}

	/**
	 * Resolve a field name onto its canonical spelling.
	 */
	public static function aliasField(string $field): string {
		$key = strtolower(trim($field));

		return self::FIELD_ALIASES[$key] ?? $field;
	}
}
