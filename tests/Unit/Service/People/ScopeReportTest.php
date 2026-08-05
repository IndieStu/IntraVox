<?php

declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service\People;

use OCA\IntraVox\Service\People\AccountScopePolicy;
use OCA\IntraVox\Service\People\ScopeReport;
use PHPUnit\Framework\TestCase;

/**
 * The scope report is what an admin reads to decide whether an upgrade is
 * safe, so its arithmetic has to match AccountScopePolicy exactly. A report
 * that under-counts is worse than no report at all.
 */
class ScopeReportTest extends TestCase {
	private function findRow(array $rows, string $property): ?array {
		foreach ($rows as $row) {
			if ($row['property'] === $property) {
				return $row;
			}
		}
		return null;
	}

	public function testEmptyReportIsClean(): void {
		$report = new ScopeReport();

		$this->assertTrue($report->isClean());
		$this->assertSame([], $report->rows());
		$this->assertSame(0, $report->users());
	}

	public function testCountsPerScope(): void {
		$report = new ScopeReport();

		$report->record('phone', AccountScopePolicy::SCOPE_PRIVATE, true);
		$report->record('phone', AccountScopePolicy::SCOPE_PRIVATE, true);
		$report->record('phone', AccountScopePolicy::SCOPE_LOCAL, true);
		$report->record('phone', AccountScopePolicy::SCOPE_PUBLISHED, false);

		$row = $this->findRow($report->rows(), 'phone');

		$this->assertSame(2, $row['private']);
		$this->assertSame(1, $row['local']);
		$this->assertSame(0, $row['federated']);
		$this->assertSame(1, $row['published']);
		$this->assertSame(3, $row['populated'], 'only non-empty values count as populated');
	}

	/**
	 * The two numbers an admin actually acts on.
	 */
	public function testHiddenCountsMatchThePolicy(): void {
		$report = new ScopeReport();

		$report->record('email', AccountScopePolicy::SCOPE_PRIVATE, true);
		$report->record('email', AccountScopePolicy::SCOPE_LOCAL, true);
		$report->record('email', AccountScopePolicy::SCOPE_LOCAL, true);
		$report->record('email', AccountScopePolicy::SCOPE_FEDERATED, true);
		$report->record('email', AccountScopePolicy::SCOPE_PUBLISHED, true);

		$row = $this->findRow($report->rows(), 'email');

		// Private is withheld from everyone.
		$this->assertSame(1, $row['hiddenFromLoggedIn']);
		// Anonymous visitors lose private + local.
		$this->assertSame(3, $row['hiddenFromAnonymous']);
	}

	/**
	 * Cross-check every scope against AccountScopePolicy rather than against
	 * a hand-written expectation, so the two cannot drift apart.
	 */
	public function testHiddenCountsAgreeWithPolicyForEveryScope(): void {
		$scopes = [
			AccountScopePolicy::SCOPE_PRIVATE,
			AccountScopePolicy::SCOPE_LOCAL,
			AccountScopePolicy::SCOPE_FEDERATED,
			AccountScopePolicy::SCOPE_PUBLISHED,
		];

		foreach ($scopes as $scope) {
			$report = new ScopeReport();
			$report->record('field', $scope, true);

			$row = $this->findRow($report->rows(), 'field');

			$visibleLocal = AccountScopePolicy::isVisible($scope, AccountScopePolicy::AUDIENCE_LOCAL);
			$visibleAnon = AccountScopePolicy::isVisible($scope, AccountScopePolicy::AUDIENCE_ANONYMOUS);

			$this->assertSame(
				$visibleLocal ? 0 : 1,
				$row['hiddenFromLoggedIn'],
				'logged-in visibility disagrees with policy for ' . $scope
			);
			$this->assertSame(
				$visibleAnon ? 0 : 1,
				$row['hiddenFromAnonymous'],
				'anonymous visibility disagrees with policy for ' . $scope
			);
		}
	}

	public function testLegacyScopesAreNormalisedBeforeCounting(): void {
		$report = new ScopeReport();

		// v1 'private' meant "this instance only", so it must land in local,
		// not private — otherwise the report would scare admins on legacy
		// instances into thinking every field is about to vanish.
		$report->record('website', 'private', true);
		$report->record('website', 'contacts', true);
		$report->record('website', 'public', true);

		$row = $this->findRow($report->rows(), 'website');

		$this->assertSame(0, $row['private']);
		$this->assertSame(1, $row['local']);
		$this->assertSame(1, $row['federated']);
		$this->assertSame(1, $row['published']);
		$this->assertSame(0, $row['hiddenFromLoggedIn']);
	}

	public function testUnknownScopeIsCountedAsLocal(): void {
		$report = new ScopeReport();
		$report->record('custom', null, true);
		$report->record('custom', '', true);
		$report->record('custom', 'garbage', true);

		$row = $this->findRow($report->rows(), 'custom');

		$this->assertSame(3, $row['local']);
		$this->assertSame(0, $row['hiddenFromLoggedIn']);
		$this->assertSame(3, $row['hiddenFromAnonymous']);
	}

	public function testRowsAreOrderedByImpact(): void {
		$report = new ScopeReport();

		$report->record('harmless', AccountScopePolicy::SCOPE_PUBLISHED, true);
		$report->record('somewhat', AccountScopePolicy::SCOPE_LOCAL, true);
		$report->record('worst', AccountScopePolicy::SCOPE_PRIVATE, true);
		$report->record('worst', AccountScopePolicy::SCOPE_PRIVATE, true);

		$this->assertSame(
			['worst', 'somewhat', 'harmless'],
			array_column($report->rows(), 'property')
		);
	}

	public function testAffectedRowsExcludeFullyVisibleProperties(): void {
		$report = new ScopeReport();

		$report->record('open', AccountScopePolicy::SCOPE_PUBLISHED, true);
		$report->record('open', AccountScopePolicy::SCOPE_FEDERATED, true);
		$report->record('closed', AccountScopePolicy::SCOPE_PRIVATE, true);

		$affected = array_column($report->affectedRows(), 'property');

		$this->assertSame(['closed'], $affected);
		$this->assertFalse($report->isClean());
	}

	/**
	 * An instance where nobody restricts anything must report "no change",
	 * so a clean upgrade reads as clean rather than as a wall of zeroes.
	 */
	public function testInstanceWithOnlyPublicFieldsIsClean(): void {
		$report = new ScopeReport();

		foreach (['role', 'organisation', 'website'] as $field) {
			$report->record($field, AccountScopePolicy::SCOPE_PUBLISHED, true);
			$report->record($field, AccountScopePolicy::SCOPE_FEDERATED, true);
		}

		$this->assertTrue($report->isClean());
	}

	public function testUserCounting(): void {
		$report = new ScopeReport();

		$report->countUser();
		$report->countUser();

		$this->assertSame(2, $report->users());
	}
}
