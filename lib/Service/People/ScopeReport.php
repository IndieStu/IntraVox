<?php

declare(strict_types=1);

namespace OCA\IntraVox\Service\People;

/**
 * Tallies account-property scopes across a sample of users, so an admin can
 * see what a People widget will stop showing before the upgrade rather than
 * after.
 *
 * Honouring IAccountProperty::getScope() is a security fix, but it is also a
 * behaviour change: a field a colleague marked private disappears from every
 * People widget, and a field marked "local" disappears from public shares.
 * Which fields those are is entirely instance-specific — it depends on what
 * users set in Personal info and on what the directory syncs — so the only
 * honest answer is to go and count.
 *
 * Pure accumulator: no Nextcloud dependencies, no I/O. The command feeds it.
 */
final class ScopeReport {
	/** @var array<string, array<string, int>> property => scope => count */
	private array $tally = [];

	/** @var array<string, int> property => number of users with a non-empty value */
	private array $populated = [];

	private int $users = 0;

	/**
	 * Record one property observation.
	 */
	public function record(string $property, ?string $rawScope, bool $hasValue): void {
		$scope = AccountScopePolicy::normalizeScope($rawScope);

		if (!isset($this->tally[$property])) {
			$this->tally[$property] = [
				AccountScopePolicy::SCOPE_PRIVATE => 0,
				AccountScopePolicy::SCOPE_LOCAL => 0,
				AccountScopePolicy::SCOPE_FEDERATED => 0,
				AccountScopePolicy::SCOPE_PUBLISHED => 0,
			];
			$this->populated[$property] = 0;
		}

		$this->tally[$property][$scope]++;

		if ($hasValue) {
			$this->populated[$property]++;
		}
	}

	public function countUser(): void {
		$this->users++;
	}

	public function users(): int {
		return $this->users;
	}

	/**
	 * Per-property counts, ordered by how much they are affected.
	 *
	 * @return array<int, array{
	 *     property: string,
	 *     populated: int,
	 *     private: int,
	 *     local: int,
	 *     federated: int,
	 *     published: int,
	 *     hiddenFromLoggedIn: int,
	 *     hiddenFromAnonymous: int
	 * }>
	 */
	public function rows(): array {
		$rows = [];

		foreach ($this->tally as $property => $scopes) {
			$private = $scopes[AccountScopePolicy::SCOPE_PRIVATE];
			$local = $scopes[AccountScopePolicy::SCOPE_LOCAL];

			$rows[] = [
				'property' => $property,
				'populated' => $this->populated[$property],
				'private' => $private,
				'local' => $local,
				'federated' => $scopes[AccountScopePolicy::SCOPE_FEDERATED],
				'published' => $scopes[AccountScopePolicy::SCOPE_PUBLISHED],
				// A private property is withheld from everyone.
				'hiddenFromLoggedIn' => $private,
				// Anonymous share visitors additionally lose local-scope ones.
				'hiddenFromAnonymous' => $private + $local,
			];
		}

		usort($rows, static function (array $a, array $b): int {
			if ($a['hiddenFromLoggedIn'] !== $b['hiddenFromLoggedIn']) {
				return $b['hiddenFromLoggedIn'] <=> $a['hiddenFromLoggedIn'];
			}
			if ($a['hiddenFromAnonymous'] !== $b['hiddenFromAnonymous']) {
				return $b['hiddenFromAnonymous'] <=> $a['hiddenFromAnonymous'];
			}
			return strcasecmp($a['property'], $b['property']);
		});

		return $rows;
	}

	/**
	 * Properties that will visibly change, i.e. at least one user hides them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function affectedRows(): array {
		return array_values(array_filter(
			$this->rows(),
			static fn(array $row): bool => $row['hiddenFromAnonymous'] > 0
		));
	}

	/**
	 * True when nothing changes for anyone.
	 */
	public function isClean(): bool {
		return $this->affectedRows() === [];
	}
}
