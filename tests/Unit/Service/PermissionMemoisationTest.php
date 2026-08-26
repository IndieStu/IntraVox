<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service;

use OCA\IntraVox\Service\PermissionService;
use OCP\Files\Node;
use PHPUnit\Framework\TestCase;

/**
 * permissionsFromNode() resolves a path once per request.
 *
 * getNodePermissions() already memoised the bitmask, which made it look as if
 * this was handled. It was not: permissionsFromNode() ANDs three capability
 * calls on top — isUpdateable(), isCreatable(), isDeletable() — and each of those
 * goes back to the mount to answer. They ran on every call.
 *
 * Where it pays is listings, not the page tree. Worth being exact, because the
 * tree is the obvious guess and it is the wrong one: refreshTreePermissions()
 * walks each node once and every node has its own path, so nothing repeats and
 * memoising saves nothing there. Its cost is one filecache lookup per node, which
 * this does not touch.
 *
 * A listing is the opposite shape. permissionsForPage() resolves the parent
 * FOLDER for every page in it, and listPages() deliberately never caches
 * permissions across users — so a folder holding fifty pages resolved the same
 * folder fifty times.
 *
 * These assert CALL COUNTS, not milliseconds. What that is worth in wall-clock
 * depends on the mount and the storage backend, and this suite cannot measure
 * that honestly — but "fifty resolutions became one" is verifiable here.
 */
class PermissionMemoisationTest extends TestCase {
    private function service(): PermissionService {
        // permissionsFromNode() touches only the two request caches, and typed
        // property defaults apply without the constructor running.
        return (new \ReflectionClass(PermissionService::class))->newInstanceWithoutConstructor();
    }

    private function node(string $path, int $perms = 31): Node {
        $node = $this->createMock(Node::class);
        $node->method('getPath')->willReturn($path);
        $node->method('getPermissions')->willReturn($perms);

        return $node;
    }

    public function testTheCapabilityCallsHappenOnlyOncePerPath(): void {
        $node = $this->node('/admin/files/IntraVox/nl/home');
        $node->expects($this->once())->method('isUpdateable')->willReturn(true);
        $node->expects($this->once())->method('isCreatable')->willReturn(true);
        $node->expects($this->once())->method('isDeletable')->willReturn(true);

        $service = $this->service();
        $first = $service->permissionsFromNode($node);
        $second = $service->permissionsFromNode($node);

        $this->assertSame($first, $second, 'A memoised answer must be the same answer');
        $this->assertTrue($first['canWrite']);
    }

    public function testDifferentPathsAreResolvedIndependently(): void {
        $home = $this->node('/admin/files/IntraVox/nl/home');
        $home->method('isUpdateable')->willReturn(true);
        $home->method('isCreatable')->willReturn(true);
        $home->method('isDeletable')->willReturn(true);

        // Same bitmask, but the mount says read-only — the AND is what catches it.
        $readonly = $this->node('/admin/files/IntraVox/nl/archive');
        $readonly->method('isUpdateable')->willReturn(false);
        $readonly->method('isCreatable')->willReturn(false);
        $readonly->method('isDeletable')->willReturn(false);

        $service = $this->service();

        $this->assertTrue($service->permissionsFromNode($home)['canWrite']);
        $this->assertFalse(
            $service->permissionsFromNode($readonly)['canWrite'],
            'Memoising must key on the path, never leak one node\'s answer to another'
        );
    }

    /**
     * The case this actually pays for: a listing.
     *
     * permissionsForPage() resolves the parent FOLDER and then ANDs the page
     * FILE's own write bit. Every page in a folder shares that parent, and
     * listPages() deliberately never caches permissions across users — so a
     * folder holding fifty pages resolved the same folder fifty times, three
     * capability calls each. Now once.
     */
    public function testAListingResolvesTheSharedParentFolderOnce(): void {
        $folder = $this->node('/admin/files/IntraVox/nl');
        $folder->expects($this->once())->method('isUpdateable')->willReturn(true);
        $folder->expects($this->once())->method('isCreatable')->willReturn(true);
        $folder->expects($this->once())->method('isDeletable')->willReturn(true);

        $service = $this->service();

        foreach (['home', 'about', 'contact'] as $slug) {
            $file = $this->node('/admin/files/IntraVox/nl/' . $slug . '.json');
            $file->method('isUpdateable')->willReturn(true);
            $result = $service->permissionsForPage($folder, $file);
            $this->assertTrue($result['canWrite']);
        }
    }

    public function testClearingTheCacheForcesAFreshResolve(): void {
        $node = $this->node('/admin/files/IntraVox/nl/home');
        $node->expects($this->exactly(2))->method('isUpdateable')->willReturn(true);
        $node->method('isCreatable')->willReturn(true);
        $node->method('isDeletable')->willReturn(true);

        $service = $this->service();
        $service->permissionsFromNode($node);
        // PageService::clearCache() calls this whenever the filesystem view
        // mutates; a stale permission array would outlive the change that
        // invalidated it.
        $service->clearNodePermissionsCache();
        $service->permissionsFromNode($node);
    }
}
