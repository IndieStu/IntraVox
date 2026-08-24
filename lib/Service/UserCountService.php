<?php
declare(strict_types=1);

namespace OCA\IntraVox\Service;

use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Counts the users on this instance, for licence and telemetry reporting.
 *
 * One class so the licence path and the telemetry path cannot drift apart —
 * they used to disagree, which meant the same app reported two different
 * numbers for the same server depending on which endpoint you asked.
 *
 * Every count is over *all* accounts, from every backend, whether or not the
 * user has ever logged in. That is what a subscription is priced on ("per
 * named user") and it has to mean the same thing in every VoxCloud app.
 *
 * Notably it does not count group membership. IntraVox used to count members
 * of a group named exactly 'intravox', which counted a different population
 * than the price list charges for and depended on a group that customers
 * rename, split, or never create.
 */
class UserCountService {
	/**
	 * How the count is taken, reported alongside it so the licence server can
	 * tell reliable readings from those produced by older releases (which
	 * counted group members, or only users who had logged in).
	 */
	public const COUNT_METHOD = 'callForAllUsers';

	public function __construct(
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Named users: every account on the instance.
	 *
	 * Floors at 1 — a reachable instance has at least an admin, and a reported
	 * zero has historically meant "counted wrongly" rather than "empty".
	 */
	public function getTotal(): int {
		return $this->count(
			'users',
			fn ($user) => true,
			1,
		);
	}

	/**
	 * Accounts that exist but are disabled.
	 *
	 * They count towards the named-user total, because disabling is how
	 * Nextcloud offboards someone while keeping their file ownership. Reported
	 * separately so the difference is visible when usage is compared against a
	 * contract — otherwise a customer who has shrunk looks like one who never
	 * did.
	 */
	public function getDisabled(): int {
		return $this->count(
			'disabled users',
			fn ($user) => !$user->isEnabled(),
			0,
		);
	}

	/**
	 * Users who logged in within the last N days.
	 *
	 * Not a billing figure — seasonal staff, holidays and a freshly launched
	 * environment all push it down — but it shows whether the seats being paid
	 * for are actually in use, which is the useful half of a renewal
	 * conversation.
	 *
	 * Uses callForSeenUsers because a user who has never logged in cannot have
	 * been active; the last-login timestamp does the rest.
	 */
	public function getActive(int $days): int {
		$cutoff = time() - ($days * 24 * 60 * 60);

		try {
			$count = 0;
			$this->userManager->callForSeenUsers(function ($user) use (&$count, $cutoff) {
				if ($user->getLastLogin() >= $cutoff) {
					$count++;
				}
			});
			return $count;
		} catch (\Exception $e) {
			$this->logger->warning('UserCountService: failed to count active users', [
				'error' => $e->getMessage(),
			]);
			return 0;
		}
	}

	/**
	 * Never let a counting failure break the report that carries it: the
	 * caller is a licence heartbeat or a telemetry ping, and a missing figure
	 * is better than a failed call.
	 */
	private function count(string $label, callable $matches, int $fallback): int {
		try {
			$count = 0;
			$this->userManager->callForAllUsers(function ($user) use (&$count, $matches) {
				if ($matches($user)) {
					$count++;
				}
			});
			return max($fallback, $count);
		} catch (\Exception $e) {
			$this->logger->warning('UserCountService: failed to count ' . $label, [
				'error' => $e->getMessage(),
			]);
			return $fallback;
		}
	}
}
