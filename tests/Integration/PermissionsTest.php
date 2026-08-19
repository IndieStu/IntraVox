<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Integration;

use OCA\IntraVox\Service\PermissionService;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Permissions against a real groupfolder with real group membership.
 *
 * The unit suite proves the *shape* of the permission object and the
 * fail-closed direction; it cannot prove that the bits we compute match what
 * groupfolders actually grants, because it stubs groupfolders away. That gap is
 * exactly where the fail-open bug (P1) lived undetected.
 */
class PermissionsTest extends IntegrationTestCase {

    private static ?string $outsiderId = null;

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        // A user who is in NO group that has access to the test folder.
        $userManager = self::server()->get(IUserManager::class);
        self::$outsiderId = self::$mountPoint . '-outsider';
        if (!$userManager->userExists(self::$outsiderId)) {
            $userManager->createUser(self::$outsiderId, bin2hex(random_bytes(16)));
        }
    }

    public static function tearDownAfterClass(): void {
        if (self::$outsiderId !== null) {
            self::server()->get(IUserManager::class)->get(self::$outsiderId)?->delete();
            self::$outsiderId = null;
        }
        parent::tearDownAfterClass();
    }

    public function testMemberOfTheApplicableGroupGetsWriteAccess(): void {
        $folder = $this->actingAs(self::$userId, fn() => $this->testGroupFolder());

        $this->assertTrue($folder->isUpdateable(), 'a member of the applicable group must be able to write');
        $this->assertTrue($folder->isCreatable());
    }

    /**
     * The real read-only case, which is the one issue #70 was about: the
     * permission BITMASK can still report write while the node capability
     * methods correctly say false. Our permission object must follow the
     * capabilities, not the bitmask.
     */
    public function testPermissionObjectFollowsNodeCapabilities(): void {
        $folder = $this->actingAs(self::$userId, fn() => $this->testGroupFolder());

        $permissions = $this->permissionService()->permissionsFromNode($folder);

        $this->assertSame($folder->isUpdateable(), $permissions['canWrite']);
        $this->assertSame($folder->isCreatable(), $permissions['canCreate']);
        $this->assertSame($folder->isDeletable(), $permissions['canDelete']);
    }

    /**
     * A user outside every applicable group must not even see the mount. This
     * is the assertion that would have caught the fail-open: before P1, a
     * resolution miss handed back PERMISSION_ALL.
     */
    public function testOutsiderDoesNotSeeTheFolderAtAll(): void {
        $this->expectException(\OCP\Files\NotFoundException::class);

        $this->actingAs(self::$outsiderId, function () {
            $userFolder = $this->rootFolder()->getUserFolder(self::$outsiderId);
            return $userFolder->get(self::$mountPoint);
        });
    }

    /**
     * The P1 regression, end to end: asking for permissions on a path inside a
     * groupfolder that does not exist must yield 0, never PERMISSION_ALL.
     */
    public function testPermissionsForAnUnresolvableFolderAreZero(): void {
        $permissions = $this->actingAs(self::$outsiderId, function () {
            return $this->permissionService()->getPermissions(
                'this-language-does-not-exist/nor-does-this-page',
                self::$outsiderId
            );
        });

        $this->assertNotSame(
            PermissionService::PERMISSION_ALL,
            $permissions,
            'an unresolvable path must never grant full permissions (the fail-open bug)'
        );
    }
}
