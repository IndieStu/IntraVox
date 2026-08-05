<?php

declare(strict_types=1);

namespace OCA\IntraVox\Service\People;

/**
 * A compact, cacheable view of the users an editor's widget config selects.
 *
 * The People widget's expensive path builds a *complete* profile for every
 * candidate — account properties, group membership (a second user lookup),
 * and live presence — for up to MAX_FILTER_SCAN users, then throws almost all
 * of it away to render one page. Faceting over that is not affordable.
 *
 * A snapshot keeps only what filtering, faceting and sorting actually need:
 *
 *     ['u' => 'jdoe', 'n' => 'Jan Doe', 'f' => ['role' => 'Manager'], 'g' => ['hr']]
 *
 * Roughly 15-20x smaller than the equivalent profile set, which is what makes
 * per-audience, per-group-context caching affordable where a single shared
 * cache entry used to be the only option. Full profiles are hydrated only for
 * the page that is actually returned, so avatars and presence are live rather
 * than up to an hour stale.
 *
 * This is a value object: no Nextcloud dependencies, no I/O.
 */
final class CohortSnapshot {
	/**
	 * @param array<int, array{u: string, n: string, f: array<string, mixed>, g: array<int, string>}> $rows
	 * @param bool $approximate whether the scan hit its cap, making counts partial
	 * @param int $scanned how many accounts were examined
	 * @param int $cap the cap in force during the scan
	 */
	public function __construct(
		public readonly array $rows,
		public readonly bool $approximate = false,
		public readonly int $scanned = 0,
		public readonly int $cap = 0,
	) {
	}

	/**
	 * Flatten to the shape FacetCalculator expects: one associative row per
	 * user, with the facet fields hoisted to the top level.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function toFilterRows(): array {
		$out = [];

		foreach ($this->rows as $row) {
			$flat = $row['f'] ?? [];
			$flat['uid'] = $row['u'] ?? '';
			$flat['displayName'] = $row['n'] ?? '';
			$flat['groups'] = $row['g'] ?? [];
			$out[] = $flat;
		}

		return $out;
	}

	/** @return array<string, mixed> */
	public function jsonSerializeForCache(): array {
		return [
			'v' => 1,
			'rows' => $this->rows,
			'approximate' => $this->approximate,
			'scanned' => $this->scanned,
			'cap' => $this->cap,
		];
	}

	/**
	 * Rebuild from a cache payload, or null when the payload is unusable or
	 * from an older format.
	 *
	 * @param mixed $decoded
	 */
	public static function fromCache(mixed $decoded): ?self {
		if (!is_array($decoded) || ($decoded['v'] ?? null) !== 1 || !is_array($decoded['rows'] ?? null)) {
			return null;
		}

		return new self(
			$decoded['rows'],
			(bool)($decoded['approximate'] ?? false),
			(int)($decoded['scanned'] ?? 0),
			(int)($decoded['cap'] ?? 0),
		);
	}
}
