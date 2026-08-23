<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Listener;

use OCA\IntraVox\Listener\CacheCleanupListener;
use OCA\IntraVox\Service\PageIndexService;
use OCP\Comments\ICommentsManager;
use OCP\EventDispatcher\Event;
use OCP\Files\Cache\CacheEntryRemovedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CacheCleanupListenerTest extends TestCase {
    private ICommentsManager $commentsManager;
    private PageIndexService $pageIndexService;
    private LoggerInterface $logger;
    private CacheCleanupListener $listener;

    protected function setUp(): void {
        parent::setUp();
        $this->commentsManager = $this->createMock(ICommentsManager::class);
        $this->pageIndexService = $this->createMock(PageIndexService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->listener = new CacheCleanupListener(
            $this->commentsManager,
            $this->pageIndexService,
            $this->logger
        );
    }

    public function testDeletesCommentsForRemovedPage(): void {
        $this->pageIndexService->method('findByFileId')
            ->with(4711)
            ->willReturn([['unique_id' => 'page-abc', 'language' => 'nl']]);

        $this->commentsManager->expects($this->once())
            ->method('deleteCommentsAtObject')
            ->with('intravox_page', 'page-abc');

        // The row is left in place at delete time so a restore needs no repair,
        // so this is the only place it can ever be removed.
        $this->pageIndexService->expects($this->once())
            ->method('removePage')
            ->with('page-abc');

        $this->listener->handle(new CacheEntryRemovedEvent(4711));
    }

    /**
     * A page is deleted as a FOLDER, and the removal event reports only that
     * folder id — never the JSON file inside it. Matching on the stored
     * file_id alone therefore never fired for a real page deletion.
     */
    public function testMatchesOnFolderIdOfDeletedPage(): void {
        // findByFileId() resolves either id; the listener must act on the row
        // it gets back regardless of which one matched.
        $this->pageIndexService->method('findByFileId')
            ->with(15890)
            ->willReturn([['unique_id' => 'page-abc', 'language' => 'nl', 'folder_id' => 15890]]);

        $this->commentsManager->expects($this->once())
            ->method('deleteCommentsAtObject')
            ->with('intravox_page', 'page-abc');
        $this->pageIndexService->expects($this->once())->method('removePage');

        $this->listener->handle(new CacheEntryRemovedEvent(15890));
    }

    /**
     * A page is one row per language, but the comments hang on the uniqueId
     * those rows share — so the same id must not be deleted twice.
     */
    public function testDeduplicatesUniqueIdAcrossLanguageRows(): void {
        $this->pageIndexService->method('findByFileId')->willReturn([
            ['unique_id' => 'page-abc', 'language' => 'nl'],
            ['unique_id' => 'page-abc', 'language' => 'de'],
        ]);

        $this->commentsManager->expects($this->once())
            ->method('deleteCommentsAtObject')
            ->with('intravox_page', 'page-abc');

        $this->listener->handle(new CacheEntryRemovedEvent(4711));
    }

    /**
     * The event fires for every file removed anywhere in Nextcloud. A file that
     * is not an IntraVox page must not touch comments at all.
     */
    public function testUnknownFileDeletesNothing(): void {
        $this->pageIndexService->method('findByFileId')->willReturn([]);

        $this->commentsManager->expects($this->never())
            ->method('deleteCommentsAtObject');
        $this->pageIndexService->expects($this->never())->method('removePage');

        $this->listener->handle(new CacheEntryRemovedEvent(9999));
    }

    /**
     * `file_id` is nullable for rows written before 1.3.0. An empty id must
     * never reach the lookup: matching those rows would wipe the comments of
     * pages that were never touched.
     */
    public function testEmptyFileIdIsIgnored(): void {
        $this->pageIndexService->expects($this->never())->method('findByFileId');
        $this->commentsManager->expects($this->never())->method('deleteCommentsAtObject');

        $this->listener->handle(new CacheEntryRemovedEvent(0));
    }

    public function testNegativeFileIdIsIgnored(): void {
        $this->pageIndexService->expects($this->never())->method('findByFileId');
        $this->commentsManager->expects($this->never())->method('deleteCommentsAtObject');

        $this->listener->handle(new CacheEntryRemovedEvent(-1));
    }

    public function testRowWithoutUniqueIdIsSkipped(): void {
        $this->pageIndexService->method('findByFileId')
            ->willReturn([['unique_id' => '', 'language' => 'nl']]);

        $this->commentsManager->expects($this->never())
            ->method('deleteCommentsAtObject');

        $this->listener->handle(new CacheEntryRemovedEvent(4711));
    }

    public function testUnrelatedEventIsIgnored(): void {
        $this->pageIndexService->expects($this->never())->method('findByFileId');

        $this->listener->handle(new class extends Event {});
    }

    /**
     * A failing lookup must not bring down the delete that triggered it.
     */
    public function testLookupFailureIsContained(): void {
        $this->pageIndexService->method('findByFileId')
            ->willThrowException(new \RuntimeException('db down'));

        $this->commentsManager->expects($this->never())
            ->method('deleteCommentsAtObject');
        $this->logger->expects($this->once())->method('error');

        $this->listener->handle(new CacheEntryRemovedEvent(4711));
    }

    /**
     * One page failing to clean up must not stop the next.
     */
    public function testCommentFailureDoesNotStopOtherPages(): void {
        $this->pageIndexService->method('findByFileId')->willReturn([
            ['unique_id' => 'page-abc', 'language' => 'nl'],
            ['unique_id' => 'page-def', 'language' => 'nl'],
        ]);

        $this->commentsManager->expects($this->exactly(2))
            ->method('deleteCommentsAtObject')
            ->willReturnCallback(function (string $type, string $id): bool {
                if ($id === 'page-abc') {
                    throw new \RuntimeException('nope');
                }
                return true;
            });
        $this->logger->expects($this->once())->method('error');

        $this->listener->handle(new CacheEntryRemovedEvent(4711));
    }
}
