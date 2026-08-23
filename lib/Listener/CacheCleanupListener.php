<?php
declare(strict_types=1);

namespace OCA\IntraVox\Listener;

use OCA\IntraVox\Service\PageIndexService;
use OCP\Comments\ICommentsManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Cache\CacheEntryRemovedEvent;
use Psr\Log\LoggerInterface;

/**
 * Deletes a page's comments once the page is permanently gone.
 *
 * CacheEntryRemovedEvent fires when a file is truly removed from the filecache
 * — the trashbin being emptied, or a delete that bypasses it — and NOT when a
 * file is merely moved to the trashbin. That distinction is the whole point of
 * this listener.
 *
 * Deleting a page moves its folder to the trashbin, which is reversible.
 * Comments used to be hard-deleted at that same moment via PageDeletedEvent,
 * so restoring a page brought it back without its discussion, and there was
 * nothing left to restore. Hanging the cleanup here gives the comments the same
 * lifetime as the file they belong to: they survive the trashbin, come back on
 * restore, and are removed for good when the trashbin is emptied.
 *
 * Restoring needs no code of its own. The rows are simply never deleted while
 * the page sits in the trashbin, and become visible again as soon as the file
 * is back.
 *
 * @template-implements IEventListener<CacheEntryRemovedEvent>
 */
class CacheCleanupListener implements IEventListener {
    private const OBJECT_TYPE = 'intravox_page';

    public function __construct(
        private ICommentsManager $commentsManager,
        private PageIndexService $pageIndexService,
        private LoggerInterface $logger,
    ) {}

    public function handle(Event $event): void {
        if (!$event instanceof CacheEntryRemovedEvent) {
            return;
        }

        $fileId = $event->getFileId();
        if ($fileId <= 0) {
            return;
        }

        // Fires for every file removed anywhere in Nextcloud, so the miss is
        // the common case and has to stay cheap: one indexed lookup, then out.
        try {
            $rows = $this->pageIndexService->findByFileId($fileId);
        } catch (\Exception $e) {
            $this->logger->error('IntraVox: Failed to resolve page for removed file', [
                'fileId' => $fileId,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        if ($rows === []) {
            return;
        }

        // A page is one row per language, so the same file id can legitimately
        // yield several rows. Deduplicated because comments hang on the
        // uniqueId alone, which those rows share.
        $uniqueIds = [];
        foreach ($rows as $row) {
            $uniqueId = (string)($row['unique_id'] ?? '');
            if ($uniqueId !== '') {
                $uniqueIds[$uniqueId] = true;
            }
        }

        foreach (array_keys($uniqueIds) as $uniqueId) {
            try {
                $this->commentsManager->deleteCommentsAtObject(self::OBJECT_TYPE, $uniqueId);
                $this->logger->info('IntraVox: Deleted comments for permanently removed page', [
                    'uniqueId' => $uniqueId,
                    'fileId' => $fileId,
                ]);
            } catch (\Exception $e) {
                $this->logger->error('IntraVox: Failed to delete comments for page', [
                    'uniqueId' => $uniqueId,
                    'fileId' => $fileId,
                    'error' => $e->getMessage(),
                ]);
            }

            // This is the only place index rows are dropped. Deleting a page
            // leaves them in place so a restore needs no repair (see
            // PageService::deletePage), which means the row has to go here or
            // it never goes at all.
            $this->pageIndexService->removePage($uniqueId);
        }
    }
}
