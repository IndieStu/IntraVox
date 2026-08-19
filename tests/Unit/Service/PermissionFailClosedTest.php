<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service;

use OCA\IntraVox\Service\PermissionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * calculatePermissions() must fail CLOSED when the IntraVox groupfolder
 * cannot be resolved.
 *
 * It used to return PERMISSION_ALL there, on the reasoning that groupfolders
 * might not be set up yet. But every IntraVox page lives inside a groupfolder
 * and setup refuses to run without one, so the reachable causes are all broken
 * states: the groupfolders app disabled, the folder renamed, or setup never
 * having run. Each of those handed out full write access.
 *
 * This is a one-way door — once pages can live in more than one site, a user
 * seeing content they should not is no longer bisectable — so it gets a test
 * that pins the direction rather than a comment.
 */
class PermissionFailClosedTest extends TestCase {

    /**
     * Build a PermissionService whose groupfolder resolution is forced to
     * fail, the way it does when the app is disabled or the folder is gone.
     * Everything downstream of that branch is unreachable, so no other
     * collaborator needs wiring.
     */
    private function svcWithUnresolvableFolder(LoggerInterface $logger): PermissionService {
        $svc = new class extends PermissionService {
            public function __construct() {
            }
            protected function resolveGroupFolderId(string $folderName): ?int {
                return null;
            }
        };
        // $logger is private, so it is wired the way the other PageService
        // tests wire their collaborators rather than by widening visibility.
        (new \ReflectionProperty(PermissionService::class, 'logger'))->setValue($svc, $logger);

        return $svc;
    }

    public function testUnresolvableGroupFolderDeniesEverything(): void {
        $svc = $this->svcWithUnresolvableFolder($this->createMock(LoggerInterface::class));

        $perms = $svc->getPermissions('en/some/page', 'alice');

        $this->assertSame(0, $perms, 'no groupfolder must mean no permissions, not all permissions');
    }

    public function testUnresolvableGroupFolderGrantsNoWriteBit(): void {
        $svc = $this->svcWithUnresolvableFolder($this->createMock(LoggerInterface::class));

        $perms = $svc->getPermissions('en/some/page', 'alice');

        foreach ([
            'read' => PermissionService::PERMISSION_READ,
            'update' => PermissionService::PERMISSION_UPDATE,
            'create' => PermissionService::PERMISSION_CREATE,
            'delete' => PermissionService::PERMISSION_DELETE,
            'share' => PermissionService::PERMISSION_SHARE,
        ] as $name => $bit) {
            $this->assertSame(0, $perms & $bit, "the {$name} bit must not be granted");
        }
    }

    /**
     * The resolution walks every groupfolder on the instance (getAllFolders()
     * is three unbounded queries), so it must happen once per request, not
     * once per permission check.
     */
    public function testResolutionIsMemoisedAcrossChecks(): void {
        $svc = new class extends PermissionService {
            public int $resolveCalls = 0;
            public function __construct() {
            }
            protected function resolveGroupFolderId(string $folderName): ?int {
                $this->resolveCalls++;
                return null;
            }
        };
        (new \ReflectionProperty(PermissionService::class, 'logger'))
            ->setValue($svc, $this->createMock(LoggerInterface::class));

        $svc->getPermissions('en/a', 'alice');
        $svc->getPermissions('en/b', 'alice');
        $svc->getPermissions('en/c', 'bob');

        $this->assertSame(1, $svc->resolveCalls, 'a null answer must be cached too, not re-resolved');
    }
}
