<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Integration;

use OCA\IntraVox\Service\SetupService;
use OCP\Files\Folder;

/**
 * The resolution path: given a groupfolder, can IntraVox find the folder its
 * content lives in, and does it agree with itself about which one that is?
 *
 * This is the code the multi-site seam (P2) replaces, so it is the code that
 * most needs a before/after witness. It also has three fallback layers that
 * exist because of real production breakage (mounted member view → raw
 * __groupfolders walk), none of which unit tests can reach.
 */
class GroupFolderResolutionTest extends IntegrationTestCase {

    public function testTestFolderIsMountedForItsMember(): void {
        $folder = $this->testGroupFolder();

        $this->assertInstanceOf(Folder::class, $folder);
        $this->assertTrue($folder->isReadable(), 'a member must be able to read the folder');
    }

    /**
     * A groupfolder resolved through a member's mounted view reports the
     * MOUNT path (/<uid>/files/<mountPoint>), not the internal
     * /__groupfolders/<id>/files path.
     *
     * This contradicts the docblock on SetupService::getGroupfolderObject(),
     * which claims mounted nodes "still report their internal path" and that
     * "all existing path-parsing callers keep working unchanged". Verified
     * against the real IntraVox groupfolder on dev: getPath() returns
     * /Femke/files/IntraVox. The comment is wrong; the behaviour it describes
     * is what the RAW fallback produces, not the preferred path.
     *
     * Pinned here because the multi-site seam (P2) has to preserve whatever is
     * actually true, and the comment is not it.
     */
    public function testMountedNodeReportsTheMountPath(): void {
        $folder = $this->testGroupFolder();

        $this->assertSame(
            '/' . self::$userId . '/files/' . self::$mountPoint,
            $folder->getPath(),
            'a mounted groupfolder reports its mount path'
        );
        $this->assertStringNotContainsString(
            '__groupfolders',
            $folder->getPath(),
            'the internal path is what the raw fallback yields, not the mounted view'
        );
    }

    /**
     * The name-based resolution that the whole app relies on today, and that
     * the multi-site registry is meant to replace with a folder_id lookup.
     * Pinning it means P2 has something to be byte-identical against.
     */
    public function testResolutionFindsTheFolderByMountPointName(): void {
        $resolved = $this->actingAs(self::$userId, function () {
            $userFolder = $this->rootFolder()->getUserFolder(self::$userId);
            return $userFolder->get(self::$mountPoint);
        });

        $this->assertSame(
            $this->testGroupFolder()->getId(),
            $resolved->getId(),
            'name-based lookup and mounted view must agree on the same folder'
        );
    }

    /**
     * The guard the plan asks for: if the name IntraVox resolves by is wrong,
     * the suite must fail rather than quietly pass. Renaming production config
     * inside a test is not safe, so this asserts the inverse directly — a name
     * that does not exist must not resolve to something.
     */
    public function testAWrongFolderNameResolvesToNothing(): void {
        $this->expectException(\OCP\Files\NotFoundException::class);

        $userFolder = $this->rootFolder()->getUserFolder(self::$userId);
        $userFolder->get(self::$mountPoint . '-does-not-exist');
    }

    /**
     * getSharedFolder() must resolve the folder IntraVox actually lives in.
     *
     * The identity assertion matters more than it looks: an earlier version of
     * this test only compared two consecutive calls to each other, which passes
     * happily even when both return the wrong folder. That is precisely what
     * happened when GROUPFOLDER_NAME was mutated to prove the suite bites — it
     * did not. Assert the NAME, so a wrong constant fails here.
     */
    public function testSharedFolderResolvesTheIntraVoxGroupfolder(): void {
        $folder = $this->setupService()->getSharedFolder();

        $this->assertSame(
            'IntraVox',
            $folder->getName(),
            'getSharedFolder() must resolve the groupfolder named IntraVox'
        );
    }

    /**
     * And it must be stable within a request — the P1 memo must not hand back
     * a different folder on the second call.
     */
    public function testSharedFolderLookupIsStableWithinARequest(): void {
        $service = $this->setupService();

        $first = $service->getSharedFolder();
        $second = $service->getSharedFolder();

        $this->assertSame(
            $first->getId(),
            $second->getId(),
            'the memoised lookup must return the same folder, not a stale or second one'
        );
    }
}
