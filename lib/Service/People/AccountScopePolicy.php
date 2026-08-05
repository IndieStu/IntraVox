<?php

declare(strict_types=1);

namespace OCA\IntraVox\Service\People;

/**
 * Decides whether an account property may be shown to a given audience.
 *
 * Nextcloud stores a visibility scope per account property. IntraVox never
 * consulted it, so every property the account manager returned — including
 * the LDAP/OIDC extras from IAccount::getProperties() — was handed to whoever
 * loaded a People widget, logged in or not. This class is the single place
 * that answers "may this audience see a property with this scope?".
 *
 * Deliberately free of Nextcloud dependencies: the rules are pure string
 * logic, so they can be unit-tested without stubbing IAccountManager.
 */
final class AccountScopePolicy {
	/** Anonymous visitor, e.g. someone following a public share link. */
	public const AUDIENCE_ANONYMOUS = 'anon';

	/** Any logged-in user on this instance. */
	public const AUDIENCE_LOCAL = 'local';

	/**
	 * Canonical v2 scopes, least to most public.
	 *
	 * Mirrors IAccountManager::SCOPE_* but is declared locally so this class
	 * stays dependency-free and testable without the OCP stubs.
	 */
	public const SCOPE_PRIVATE = 'v2-private';
	public const SCOPE_LOCAL = 'v2-local';
	public const SCOPE_FEDERATED = 'v2-federated';
	public const SCOPE_PUBLISHED = 'v2-published';

	/**
	 * Legacy v1 scope values, still present in oc_accounts on instances that
	 * were upgraded from Nextcloud 20 or earlier.
	 *
	 * The v1 'private' is the trap: it meant "this instance only", not
	 * "hidden from everyone". Nextcloud's own v1 -> v2 migration maps it to
	 * v2-local, and so do we. Mapping it to v2-private instead would silently
	 * blank out fields on every legacy instance.
	 */
	private const LEGACY_MAP = [
		'private' => self::SCOPE_LOCAL,
		'contacts' => self::SCOPE_FEDERATED,
		'public' => self::SCOPE_PUBLISHED,
	];

	/** Rank per scope; higher means visible to a wider audience. */
	private const RANK = [
		self::SCOPE_PRIVATE => 0,
		self::SCOPE_LOCAL => 1,
		self::SCOPE_FEDERATED => 2,
		self::SCOPE_PUBLISHED => 3,
	];

	/** Minimum rank a property needs for each audience. */
	private const MIN_RANK = [
		self::AUDIENCE_ANONYMOUS => 2, // federated or published
		self::AUDIENCE_LOCAL => 1,     // local and up
	];

	/**
	 * Map any stored scope string onto a canonical v2 scope.
	 *
	 * Unknown and empty values fall back to v2-local: restrictive enough to
	 * keep a property away from anonymous visitors, permissive enough that a
	 * People widget on an instance with odd data does not render blank cards.
	 */
	public static function normalizeScope(?string $raw): string {
		$value = trim((string)$raw);

		if ($value === '') {
			return self::SCOPE_LOCAL;
		}

		if (isset(self::RANK[$value])) {
			return $value;
		}

		return self::LEGACY_MAP[$value] ?? self::SCOPE_LOCAL;
	}

	/**
	 * Normalise an audience string; anything unrecognised is treated as
	 * anonymous, which is the safer of the two.
	 */
	public static function normalizeAudience(?string $raw): string {
		return $raw === self::AUDIENCE_LOCAL
			? self::AUDIENCE_LOCAL
			: self::AUDIENCE_ANONYMOUS;
	}

	/**
	 * Whether a property carrying $rawScope may be shown to $audience.
	 */
	public static function isVisible(?string $rawScope, string $audience): bool {
		$scope = self::normalizeScope($rawScope);
		$normalizedAudience = self::normalizeAudience($audience);

		return self::RANK[$scope] >= self::MIN_RANK[$normalizedAudience];
	}
}
