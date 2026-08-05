<?php

declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service\People;

use OCA\IntraVox\Service\People\AccountBulkLoader;
use OCA\IntraVox\Service\People\AccountScopePolicy;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The bulk loader is a faster route to data IAccountManager already returns.
 * Its parsing therefore has to agree with the API exactly — a scope this
 * class reads wrongly is a visibility bug, not a performance bug.
 */
class AccountBulkLoaderTest extends TestCase {
	private function loader(): AccountBulkLoader {
		return new AccountBulkLoader(null, $this->createMock(LoggerInterface::class));
	}

	public function testParsesNextcloudAccountJson(): void {
		$json = json_encode([
			'displayname' => ['value' => 'Jan Doe', 'scope' => 'v2-federated'],
			'role' => ['value' => 'Manager', 'scope' => 'v2-local'],
			'phone' => ['value' => '0612345678', 'scope' => 'v2-private'],
		]);

		$parsed = $this->loader()->parseAccountData($json);

		$this->assertSame('Jan Doe', $parsed['displayname']['value']);
		$this->assertSame('v2-federated', $parsed['displayname']['scope']);
		$this->assertSame('v2-private', $parsed['phone']['scope']);
	}

	/**
	 * A property with no scope must not be given one here. Leaving it empty
	 * lets AccountScopePolicy apply its fail-closed default in the single
	 * place that owns that decision.
	 */
	public function testMissingScopeIsLeftEmptyForThePolicyToDecide(): void {
		$json = json_encode(['role' => ['value' => 'Manager']]);

		$parsed = $this->loader()->parseAccountData($json);

		$this->assertSame('', $parsed['role']['scope']);
		$this->assertSame(
			AccountScopePolicy::SCOPE_LOCAL,
			AccountScopePolicy::normalizeScope($parsed['role']['scope'])
		);
		$this->assertFalse(
			AccountScopePolicy::isVisible($parsed['role']['scope'], AccountScopePolicy::AUDIENCE_ANONYMOUS)
		);
	}

	public function testMalformedRowsAreSkippedNotGuessed(): void {
		$json = json_encode([
			'good' => ['value' => 'x', 'scope' => 'v2-local'],
			'notAnObject' => 'just a string',
			'' => ['value' => 'y', 'scope' => 'v2-local'],
		]);

		$parsed = $this->loader()->parseAccountData($json);

		$this->assertArrayHasKey('good', $parsed);
		$this->assertArrayNotHasKey('notAnObject', $parsed);
		$this->assertArrayNotHasKey('', $parsed);
	}

	public function testGarbageJsonYieldsNothing(): void {
		$loader = $this->loader();

		$this->assertSame([], $loader->parseAccountData(''));
		$this->assertSame([], $loader->parseAccountData('{not json'));
		$this->assertSame([], $loader->parseAccountData('"a string"'));
		$this->assertSame([], $loader->parseAccountData('[1,2,3]'));
	}

	public function testNonScalarValueBecomesEmptyString(): void {
		$json = json_encode(['weird' => ['value' => ['nested'], 'scope' => 'v2-local']]);

		$parsed = $this->loader()->parseAccountData($json);

		$this->assertSame('', $parsed['weird']['value']);
	}

	public function testWithoutDatabaseTheLoaderReportsUnavailable(): void {
		$loader = $this->loader();

		$this->assertFalse($loader->isAvailable());
		$this->assertSame([], $loader->loadAccounts(['a', 'b']));
		$this->assertSame([], $loader->loadCustomFields(['a'], 'intravox', 'custom_fields'));
	}

	public function testEmptyUidListShortCircuits(): void {
		$this->assertSame([], $this->loader()->loadAccounts([]));
	}

	/**
	 * Scopes read in bulk must produce exactly the same visibility decision
	 * as scopes read through the API. This is the property that lets the fast
	 * path and the fallback path coexist without drifting.
	 */
	public function testBulkScopesDriveTheSameVisibilityAsThePolicy(): void {
		$json = json_encode([
			'a' => ['value' => '1', 'scope' => 'v2-private'],
			'b' => ['value' => '2', 'scope' => 'v2-local'],
			'c' => ['value' => '3', 'scope' => 'v2-federated'],
			'd' => ['value' => '4', 'scope' => 'v2-published'],
		]);

		$parsed = $this->loader()->parseAccountData($json);

		$visibleToAnon = [];
		foreach ($parsed as $name => $meta) {
			if (AccountScopePolicy::isVisible($meta['scope'], AccountScopePolicy::AUDIENCE_ANONYMOUS)) {
				$visibleToAnon[] = $name;
			}
		}

		$this->assertSame(['c', 'd'], $visibleToAnon);
	}
}
