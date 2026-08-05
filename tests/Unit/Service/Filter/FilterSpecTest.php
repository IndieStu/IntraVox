<?php

declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service\Filter;

use OCA\IntraVox\Service\Filter\FilterSpec;
use PHPUnit\Framework\TestCase;

class FilterSpecTest extends TestCase {
	public function testNormalizesPeopleShape(): void {
		$row = FilterSpec::normalizeRow([
			'fieldName' => 'role',
			'operator' => 'contains',
			'value' => 'Manager',
			'values' => [],
		]);

		$this->assertSame(['field' => 'role', 'op' => 'contains', 'value' => 'Manager'], $row);
	}

	public function testNormalizesStoryShape(): void {
		$row = FilterSpec::normalizeRow([
			'field' => 'mv_status',
			'op' => 'equals',
			'value' => 'approved',
		]);

		$this->assertSame(['field' => 'mv_status', 'op' => 'equals', 'value' => 'approved'], $row);
	}

	/**
	 * Locks in the precedence that production page JSON depends on: a
	 * non-empty `values` array wins over `value`.
	 *
	 * If this goes red, saved widget filters have changed meaning. Do not
	 * "fix" the test — fix the code.
	 */
	public function testValuesWinsOverValue(): void {
		$row = FilterSpec::normalizeRow([
			'fieldName' => 'group',
			'operator' => 'in',
			'value' => 'ignored',
			'values' => ['hr', 'marketing'],
		]);

		$this->assertSame(['hr', 'marketing'], $row['value']);
	}

	/**
	 * The operator must survive untouched when `values` is used. The legacy
	 * engines pass the array into whatever operator was configured; rewriting
	 * it to `in` here would change which users a saved widget matches.
	 */
	public function testValuesDoesNotRewriteOperator(): void {
		$row = FilterSpec::normalizeRow([
			'fieldName' => 'group',
			'operator' => 'equals',
			'values' => ['hr'],
		]);

		$this->assertSame('equals', $row['op']);
	}

	public function testEmptyValuesArrayFallsBackToValue(): void {
		$row = FilterSpec::normalizeRow([
			'fieldName' => 'role',
			'operator' => 'equals',
			'value' => 'Adviseur',
			'values' => [],
		]);

		$this->assertSame('Adviseur', $row['value']);
	}

	public function testMissingOperatorDefaultsToEquals(): void {
		$row = FilterSpec::normalizeRow(['field' => 'role', 'value' => 'x']);

		$this->assertSame('equals', $row['op']);
	}

	public function testRowWithoutFieldIsDropped(): void {
		$this->assertNull(FilterSpec::normalizeRow(['operator' => 'equals', 'value' => 'x']));
		$this->assertNull(FilterSpec::normalizeRow(['fieldName' => '   ', 'value' => 'x']));
		$this->assertNull(FilterSpec::normalizeRow('not an array'));
		$this->assertNull(FilterSpec::normalizeRow(null));
	}

	public function testNormalizeListDropsBadRowsAndReindexes(): void {
		$list = FilterSpec::normalizeList([
			['fieldName' => 'role', 'operator' => 'equals', 'value' => 'a'],
			['operator' => 'equals', 'value' => 'orphan'],
			['field' => 'gebouw', 'op' => 'in', 'value' => ['Noord']],
		]);

		$this->assertCount(2, $list);
		$this->assertSame([0, 1], array_keys($list));
		$this->assertSame('role', $list[0]['field']);
		$this->assertSame('gebouw', $list[1]['field']);
	}

	public function testNormalizeListOnNonArray(): void {
		$this->assertSame([], FilterSpec::normalizeList('nope'));
		$this->assertSame([], FilterSpec::normalizeList(null));
	}

	public function testLegacyRoundTripScalar(): void {
		$original = ['fieldName' => 'role', 'operator' => 'contains', 'value' => 'Manager', 'values' => []];

		$legacy = FilterSpec::toLegacyPeople(FilterSpec::normalizeRow($original));

		$this->assertSame('role', $legacy['fieldName']);
		$this->assertSame('contains', $legacy['operator']);
		$this->assertSame('Manager', $legacy['value']);
		$this->assertSame([], $legacy['values']);
	}

	public function testLegacyRoundTripList(): void {
		$original = ['fieldName' => 'group', 'operator' => 'in', 'value' => '', 'values' => ['hr', 'ict']];

		$legacy = FilterSpec::toLegacyPeople(FilterSpec::normalizeRow($original));

		$this->assertSame(['hr', 'ict'], $legacy['values']);
		$this->assertSame('', $legacy['value']);

		// And normalising the emitted legacy row again is stable.
		$again = FilterSpec::normalizeRow($legacy);
		$this->assertSame(['hr', 'ict'], $again['value']);
		$this->assertSame('in', $again['op']);
	}

	public function testAliasFieldResolvesDisplayNameSpellings(): void {
		$this->assertSame('displayName', FilterSpec::aliasField('displayname'));
		$this->assertSame('displayName', FilterSpec::aliasField('displayName'));
		$this->assertSame('displayName', FilterSpec::aliasField('display_name'));
		$this->assertSame('organisation', FilterSpec::aliasField('organization'));
	}

	public function testAliasFieldLeavesUnknownFieldsAlone(): void {
		$this->assertSame('gebouw', FilterSpec::aliasField('gebouw'));
		$this->assertSame('mv_status', FilterSpec::aliasField('mv_status'));
	}

	public function testNormalizeAppliesFieldAlias(): void {
		$row = FilterSpec::normalizeRow(['fieldName' => 'displayname', 'operator' => 'contains', 'value' => 'Jan']);

		$this->assertSame('displayName', $row['field']);
	}

	public function testNullValueIsPreserved(): void {
		$row = FilterSpec::normalizeRow(['field' => 'role', 'op' => 'not_empty']);

		$this->assertNull($row['value']);
	}
}
