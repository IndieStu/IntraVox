<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service\Version;

use OCA\Files_Versions\Versions\IVersion;
use OCA\Files_Versions\Versions\IVersionManager;
use OCA\IntraVox\Service\Version\PageVersionFormatter;
use OCA\IntraVox\Service\Version\PageVersionService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * PageVersionService (service split, PR-13): the version operations that
 * used to live inside PageService, now taking located nodes instead of
 * page ids. The degradation contract matters most — files_versions may be
 * disabled, and a versioning failure must never fail the save or page view
 * it accompanies.
 */
class PageVersionServiceTest extends TestCase {

    private function makeService(?IVersionManager $manager, bool $withUser = true): PageVersionService {
        $user = null;
        if ($withUser) {
            $user = $this->createMock(\OCP\IUser::class);
            $user->method('getUID')->willReturn('alice');
            $user->method('getDisplayName')->willReturn('Alice');
        }
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        $svc = new class extends PageVersionService {
            public function __construct() {
            }
        };
        foreach ([
            'userSession' => $session,
            'formatter' => new PageVersionFormatter(),
            'logger' => $this->createMock(\Psr\Log\LoggerInterface::class),
            'versionManager' => $manager,
        ] as $prop => $value) {
            (new \ReflectionProperty(PageVersionService::class, $prop))->setValue($svc, $value);
        }
        return $svc;
    }

    private function makeVersion(int $timestamp): IVersion {
        $v = $this->createMock(IVersion::class);
        $v->method('getTimestamp')->willReturn($timestamp);
        $v->method('getSize')->willReturn(10);
        return $v;
    }

    private function makeFile(): File {
        $file = $this->createMock(File::class);
        $file->method('getPath')->willReturn('/IntraVox/en/about/about.json');
        $file->method('getName')->willReturn('about.json');
        $file->method('getMTime')->willReturn(1_700_000_000);
        $file->method('getSize')->willReturn(2048);
        $file->method('getOwner')->willReturn(null);
        return $file;
    }

    /** Without files_versions the list degrades to [], never an error. */
    public function testListWithoutVersionManagerReturnsEmpty(): void {
        $svc = $this->makeService(null);
        $this->assertSame([], $svc->listForFile($this->makeFile()));
    }

    public function testListWithoutUserReturnsEmpty(): void {
        $manager = $this->createMock(IVersionManager::class);
        $svc = $this->makeService($manager, false);
        $this->assertSame([], $svc->listForFile($this->makeFile()));
    }

    public function testListReturnsCurrentVersionAndFormattedHistory(): void {
        $manager = $this->createMock(IVersionManager::class);
        $manager->method('getVersionsForFile')->willReturn([
            $this->makeVersion(1_600_000_000),
            $this->makeVersion(1_650_000_000),
        ]);
        $svc = $this->makeService($manager);

        $out = $svc->listForFile($this->makeFile());

        $this->assertSame(1_700_000_000, $out['currentVersion']['timestamp']);
        $this->assertSame('Alice', $out['currentVersion']['author'], 'falls back to session user without an owner');
        $this->assertCount(2, $out['versions']);
        $this->assertSame(1_650_000_000, $out['versions'][0]['timestamp'], 'newest first');
    }

    /** A backend failure returns the empty SHAPE, not an exception. */
    public function testListSwallowsBackendFailure(): void {
        $manager = $this->createMock(IVersionManager::class);
        $manager->method('getVersionsForFile')->willThrowException(new \RuntimeException('backend down'));
        $svc = $this->makeService($manager);

        $out = $svc->listForFile($this->makeFile());

        $this->assertNull($out['currentVersion']);
        $this->assertSame([], $out['versions']);
    }

    public function testRestoreRollsBackAndReturnsFreshContent(): void {
        $target = $this->makeVersion(1_600_000_000);
        $manager = $this->createMock(IVersionManager::class);
        $manager->method('getVersionsForFile')->willReturn([$target]);
        $manager->expects($this->once())->method('rollback')->with($target);

        $file = $this->makeFile();
        $freshFile = $this->createMock(File::class);
        $freshFile->method('getContent')->willReturn('{"uniqueId":"page-x","title":"Restored"}');
        $folder = $this->createMock(Folder::class);
        $folder->method('get')->with('about.json')->willReturn($freshFile);

        $svc = $this->makeService($manager);
        $out = $svc->restoreToTimestamp($file, $folder, 1_600_000_000);

        $this->assertSame('Restored', $out['title'], 'content is re-read from a FRESH node after rollback');
    }

    public function testRestoreUnknownTimestampThrowsWrapped(): void {
        $manager = $this->createMock(IVersionManager::class);
        $manager->method('getVersionsForFile')->willReturn([]);
        $manager->expects($this->never())->method('rollback');

        $svc = $this->makeService($manager);

        $this->expectExceptionMessage('Failed to restore version: Version not found for timestamp: 42');
        $svc->restoreToTimestamp($this->makeFile(), $this->createMock(Folder::class), 42);
    }

    public function testContentAtTimestampReadsStream(): void {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, '{"title":"Old"}');
        rewind($stream);

        $manager = $this->createMock(IVersionManager::class);
        $manager->method('getVersionsForFile')->willReturn([$this->makeVersion(1_600_000_000)]);
        $manager->method('read')->willReturn($stream);

        $svc = $this->makeService($manager);
        $out = $svc->contentAtTimestamp($this->makeFile(), 1_600_000_000);

        $this->assertSame('{"title":"Old"}', $out['content']);
        $this->assertSame($out['content'], $out['rawContent']);
    }

    public function testSetLabelRefusesBackendWithoutLabelSupport(): void {
        $manager = $this->createMock(IVersionManager::class);
        $manager->method('getVersionsForFile')->willReturn([$this->makeVersion(1_600_000_000)]);
        $manager->method('getBackendForStorage')->willReturn(new \stdClass());

        $svc = $this->makeService($manager);

        $this->expectExceptionMessage('Version labels not supported by this storage backend');
        $svc->setLabel($this->makeFile(), 1_600_000_000, 'v1');
    }

    public function testSetLabelDelegatesToCapableBackend(): void {
        $backend = new class {
            public array $calls = [];
            public function setVersionLabel($version, string $label): void {
                $this->calls[] = $label;
            }
        };
        $manager = $this->createMock(IVersionManager::class);
        $manager->method('getVersionsForFile')->willReturn([$this->makeVersion(1_600_000_000)]);
        $manager->method('getBackendForStorage')->willReturn($backend);

        $svc = $this->makeService($manager);
        $svc->setLabel($this->makeFile(), 1_600_000_000, 'pre-release');

        $this->assertSame(['pre-release'], $backend->calls);
    }

    /** Versioning failure must never fail the save it precedes. */
    public function testCreateBeforeUpdateNeverThrows(): void {
        $svcWithout = $this->makeService(null);
        $svcWithout->createBeforeUpdate($this->makeFile());

        $manager = $this->createMock(IVersionManager::class);
        $manager->method('createVersion')->willThrowException(new \RuntimeException('quota'));
        $svcBroken = $this->makeService($manager);
        $svcBroken->createBeforeUpdate($this->makeFile());

        $manager2 = $this->createMock(IVersionManager::class);
        $manager2->expects($this->once())->method('createVersion');
        $svcOk = $this->makeService($manager2);
        $svcOk->createBeforeUpdate($this->makeFile());
    }
}
