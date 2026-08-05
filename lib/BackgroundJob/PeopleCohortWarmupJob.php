<?php
declare(strict_types=1);

namespace OCA\IntraVox\BackgroundJob;

use OCA\IntraVox\Service\UserService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Rebuilds recently-used People cohorts before a visitor has to wait for one.
 *
 * A cohort snapshot is the compact per-user record the faceted People widget
 * filters and counts over. Building one means enumerating accounts through
 * callForAllUsers(), which on a large or LDAP-backed instance is the single
 * slow step in the whole feature — and its cache entry expires every 15
 * minutes by design, because a stale *count* beside a checkbox is far more
 * noticeable than a stale list.
 *
 * Without this job, one unlucky visitor pays that cost every quarter of an
 * hour. The lock and stale-while-revalidate in UserService already stop that
 * from becoming a thundering herd; this removes the wait entirely.
 *
 * Only cohorts that were actually requested in the last day are rebuilt, and
 * only when their entry has genuinely expired, so a run on an idle instance
 * costs a single cache read.
 *
 * Runs every 10 minutes — slightly ahead of the 15-minute snapshot TTL, so a
 * cohort is normally refreshed before it lapses. TIME_INSENSITIVE, so it does
 * not compete with user-facing cron work.
 */
class PeopleCohortWarmupJob extends TimedJob {
    private const INTERVAL_MINUTES = 10;

    public function __construct(
        ITimeFactory $time,
        private UserService $userService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(self::INTERVAL_MINUTES * 60);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        $started = microtime(true);

        try {
            $result = $this->userService->warmCohorts();
        } catch (\Throwable $e) {
            // Warmup is an optimisation. A failure here must never surface to
            // a user, and must not stop the next run from trying again.
            $this->logger->warning('IntraVox: People cohort warmup failed: ' . $e->getMessage());
            return;
        }

        if (($result['rebuilt'] ?? 0) > 0) {
            $this->logger->debug(sprintf(
                'IntraVox: warmed %d of %d People cohort(s) in %d ms',
                $result['rebuilt'],
                $result['considered'],
                (int)((microtime(true) - $started) * 1000)
            ));
        }
    }
}
