<?php
declare(strict_types=1);

namespace OCA\IntraVox\Command;

use OCA\IntraVox\Service\People\AccountScopePolicy;
use OCA\IntraVox\Service\People\ScopeReport;
use OCP\Accounts\IAccountManager;
use OCP\IUser;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Report which People-widget fields will become invisible once account
 * property scopes are honoured.
 *
 * Run this BEFORE upgrading. IntraVox used to ignore
 * IAccountProperty::getScope() and served every property to every viewer,
 * including anonymous visitors on a public share. From this release it
 * respects the scope, which is a security fix but also a visible behaviour
 * change: fields your colleagues marked private disappear from People
 * widgets, and fields marked "local" disappear from public shares.
 *
 * Which fields those are is instance-specific — it depends on what users set
 * in Personal info and on what your directory syncs — so this command counts
 * them rather than guessing.
 *
 * Usage: occ intravox:people:scope-report [--limit=1000] [--all]
 */
class PeopleScopeReportCommand extends Command {
    private const DEFAULT_LIMIT = 1000;

    public function __construct(
        private IUserManager $userManager,
        private IAccountManager $accountManager
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('intravox:people:scope-report')
            ->setDescription('Report which People-widget fields become hidden once account-property scopes are honoured')
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'How many accounts to sample',
                (string)self::DEFAULT_LIMIT
            )
            ->addOption(
                'all',
                'a',
                InputOption::VALUE_NONE,
                'Scan every account, ignoring --limit (slow on large instances)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $limit = $input->getOption('all') ? PHP_INT_MAX : max(1, (int)$input->getOption('limit'));

        $report = new ScopeReport();
        $scanned = 0;

        $this->userManager->callForAllUsers(function (IUser $user) use ($report, $limit, &$scanned): void {
            if ($scanned >= $limit) {
                return;
            }
            $scanned++;
            $report->countUser();

            try {
                $account = $this->accountManager->getAccount($user);
            } catch (\Throwable $e) {
                return;
            }

            try {
                foreach ($account->getProperties() as $prop) {
                    $scope = null;
                    if (method_exists($prop, 'getScope')) {
                        try {
                            $scope = $prop->getScope();
                        } catch (\Throwable $e) {
                            $scope = null;
                        }
                    }

                    $value = '';
                    try {
                        $value = (string)$prop->getValue();
                    } catch (\Throwable $e) {
                        // Treat an unreadable value as empty.
                    }

                    $report->record(
                        $prop->getName(),
                        is_string($scope) ? $scope : null,
                        trim($value) !== ''
                    );
                }
            } catch (\Throwable $e) {
                // getProperties() is unavailable on some older backends.
            }
        });

        $this->render($output, $report, $scanned, $limit !== PHP_INT_MAX);

        return 0;
    }

    private function render(OutputInterface $output, ScopeReport $report, int $scanned, bool $capped): void {
        $output->writeln('');
        $output->writeln('<info>IntraVox — account property scope report</info>');
        $output->writeln(sprintf('Sampled %d account(s).', $scanned));

        if ($capped) {
            $output->writeln('<comment>This is a sample. Use --all for a complete picture.</comment>');
        }

        $rows = $report->rows();

        if ($rows === []) {
            $output->writeln('');
            $output->writeln('No account properties found. Nothing will change.');
            return;
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '  %-24s %9s %9s %9s %11s %11s',
            'PROPERTY', 'WITH VAL', 'PRIVATE', 'LOCAL', 'FEDERATED', 'PUBLISHED'
        ));
        $output->writeln('  ' . str_repeat('-', 78));

        foreach ($rows as $row) {
            $output->writeln(sprintf(
                '  %-24s %9d %9s %9d %11d %11d',
                $row['property'],
                $row['populated'],
                $row['private'] > 0 ? '<error>' . $row['private'] . '</error>' : '0',
                $row['local'],
                $row['federated'],
                $row['published']
            ));
        }

        $affected = $report->affectedRows();

        $output->writeln('');

        if ($report->isClean()) {
            $output->writeln('<info>Nothing becomes hidden. No visible change for any viewer.</info>');
            return;
        }

        $output->writeln('<comment>After upgrading:</comment>');
        $output->writeln('');

        $hiddenEverywhere = array_values(array_filter(
            $affected,
            static fn(array $r): bool => $r['hiddenFromLoggedIn'] > 0
        ));

        if ($hiddenEverywhere !== []) {
            $output->writeln('  <error>Hidden from ALL viewers</error> (scope: private)');
            foreach ($hiddenEverywhere as $row) {
                $output->writeln(sprintf(
                    '    %-24s %d of %d account(s)',
                    $row['property'],
                    $row['hiddenFromLoggedIn'],
                    $scanned
                ));
            }
            $output->writeln('');
        }

        $output->writeln('  <comment>Hidden from PUBLIC SHARES</comment> (scope: private or local)');
        foreach ($affected as $row) {
            $output->writeln(sprintf(
                '    %-24s %d of %d account(s)',
                $row['property'],
                $row['hiddenFromAnonymous'],
                $scanned
            ));
        }

        $output->writeln('');
        $output->writeln('Users control this themselves under Settings > Personal > Personal info,');
        $output->writeln('using the visibility picker next to each field.');
        $output->writeln('');
        $output->writeln(sprintf(
            'Scopes: %s < %s < %s < %s',
            AccountScopePolicy::SCOPE_PRIVATE,
            AccountScopePolicy::SCOPE_LOCAL,
            AccountScopePolicy::SCOPE_FEDERATED,
            AccountScopePolicy::SCOPE_PUBLISHED
        ));
        $output->writeln('');
        $output->writeln('IntraVox custom fields (set via user preferences, not Personal info)');
        $output->writeln('carry no scope of their own and are treated as ' . AccountScopePolicy::SCOPE_LOCAL . ':');
        $output->writeln('visible to logged-in users, never on a public share.');
    }
}
