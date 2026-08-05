<?php

declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service\People;

use OCA\IntraVox\Service\People\AccountScopePolicy;
use PHPUnit\Framework\TestCase;

class AccountScopePolicyTest extends TestCase {
	/**
	 * The visibility matrix. This is the security contract for the People
	 * widget: v2-private must never reach anyone, v2-local must never reach
	 * an anonymous share visitor.
	 *
	 * @return array<string, array{0: ?string, 1: string, 2: bool}>
	 */
	public static function visibilityProvider(): array {
		return [
			// scope, audience, expected visible
			'private hidden from logged-in' => ['v2-private', AccountScopePolicy::AUDIENCE_LOCAL, false],
			'private hidden from anonymous' => ['v2-private', AccountScopePolicy::AUDIENCE_ANONYMOUS, false],
			'local visible to logged-in' => ['v2-local', AccountScopePolicy::AUDIENCE_LOCAL, true],
			'local hidden from anonymous' => ['v2-local', AccountScopePolicy::AUDIENCE_ANONYMOUS, false],
			'federated visible to logged-in' => ['v2-federated', AccountScopePolicy::AUDIENCE_LOCAL, true],
			'federated visible to anonymous' => ['v2-federated', AccountScopePolicy::AUDIENCE_ANONYMOUS, true],
			'published visible to logged-in' => ['v2-published', AccountScopePolicy::AUDIENCE_LOCAL, true],
			'published visible to anonymous' => ['v2-published', AccountScopePolicy::AUDIENCE_ANONYMOUS, true],

			// Legacy v1 values, still present on instances upgraded from NC <= 20.
			'legacy private is instance-local, not hidden' => ['private', AccountScopePolicy::AUDIENCE_LOCAL, true],
			'legacy private hidden from anonymous' => ['private', AccountScopePolicy::AUDIENCE_ANONYMOUS, false],
			'legacy contacts visible to logged-in' => ['contacts', AccountScopePolicy::AUDIENCE_LOCAL, true],
			'legacy contacts visible to anonymous' => ['contacts', AccountScopePolicy::AUDIENCE_ANONYMOUS, true],
			'legacy public visible to logged-in' => ['public', AccountScopePolicy::AUDIENCE_LOCAL, true],
			'legacy public visible to anonymous' => ['public', AccountScopePolicy::AUDIENCE_ANONYMOUS, true],

			// Unknown/empty falls back to local: visible when logged in, never anonymous.
			'empty treated as local' => ['', AccountScopePolicy::AUDIENCE_LOCAL, true],
			'empty hidden from anonymous' => ['', AccountScopePolicy::AUDIENCE_ANONYMOUS, false],
			'garbage treated as local' => ['garbage', AccountScopePolicy::AUDIENCE_LOCAL, true],
			'garbage hidden from anonymous' => ['garbage', AccountScopePolicy::AUDIENCE_ANONYMOUS, false],
			'null treated as local' => [null, AccountScopePolicy::AUDIENCE_LOCAL, true],
			'null hidden from anonymous' => [null, AccountScopePolicy::AUDIENCE_ANONYMOUS, false],
		];
	}

	/**
	 * @dataProvider visibilityProvider
	 */
	public function testVisibility(?string $scope, string $audience, bool $expected): void {
		$this->assertSame(
			$expected,
			AccountScopePolicy::isVisible($scope, $audience),
			sprintf('scope=%s audience=%s', var_export($scope, true), $audience)
		);
	}

	public function testUnknownAudienceIsTreatedAsAnonymous(): void {
		// Fail closed: a typo in an audience string must not widen visibility.
		$this->assertFalse(AccountScopePolicy::isVisible('v2-local', 'wharrgarbl'));
		$this->assertTrue(AccountScopePolicy::isVisible('v2-published', 'wharrgarbl'));
	}

	public function testNormalizeScopeMapsLegacyValues(): void {
		$this->assertSame(AccountScopePolicy::SCOPE_LOCAL, AccountScopePolicy::normalizeScope('private'));
		$this->assertSame(AccountScopePolicy::SCOPE_FEDERATED, AccountScopePolicy::normalizeScope('contacts'));
		$this->assertSame(AccountScopePolicy::SCOPE_PUBLISHED, AccountScopePolicy::normalizeScope('public'));
	}

	public function testNormalizeScopePassesThroughCanonicalValues(): void {
		foreach (['v2-private', 'v2-local', 'v2-federated', 'v2-published'] as $scope) {
			$this->assertSame($scope, AccountScopePolicy::normalizeScope($scope));
		}
	}

	public function testNormalizeScopeTrimsWhitespace(): void {
		$this->assertSame(AccountScopePolicy::SCOPE_PRIVATE, AccountScopePolicy::normalizeScope('  v2-private  '));
	}

	public function testPrivateIsNeverVisibleToAnyAudience(): void {
		// The single most important assertion in this file.
		foreach ([AccountScopePolicy::AUDIENCE_LOCAL, AccountScopePolicy::AUDIENCE_ANONYMOUS, 'anything'] as $audience) {
			$this->assertFalse(AccountScopePolicy::isVisible('v2-private', $audience));
		}
	}
}
