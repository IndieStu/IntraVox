<?php
declare(strict_types=1);

namespace OCA\IntraVox\Service\Version;

use OCA\Files_Versions\Versions\IVersion;
use OCA\Files_Versions\Versions\IVersionManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Version operations on a page's JSON file, extracted from PageService
 * (service split, PR-13). PageService LOCATES the page; this service only
 * receives the located nodes and never does a page lookup of its own —
 * that keeps the locator machinery in one place while the split is under
 * way.
 *
 * All operations go through IVersionManager, which works for every storage
 * type including GroupFolders. The manager is resolved lazily because
 * files_versions may be disabled; each method then degrades exactly the
 * way the original PageService code did (empty list / no-op / exception).
 */
class PageVersionService {
    private IUserSession $userSession;
    private PageVersionFormatter $formatter;
    private LoggerInterface $logger;
    private ?IVersionManager $versionManager = null;

    public function __construct(
        IUserSession $userSession,
        PageVersionFormatter $formatter,
        LoggerInterface $logger
    ) {
        $this->userSession = $userSession;
        $this->formatter = $formatter;
        $this->logger = $logger;

        try {
            $this->versionManager = \OC::$server->get(IVersionManager::class);
        } catch (\Exception $e) {
            $this->logger->info('[PageVersionService] Version manager not available: ' . $e->getMessage());
        }
    }

    /**
     * Version list for a page file: the current file's metadata plus every
     * stored version, formatted for the API. Returns [] without a session
     * user or version manager, and the empty shape on backend failure —
     * version history is never worth failing a page view over.
     */
    public function listForFile(File $file): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            $this->logger->warning('[PageVersionService] No user in session');
            return [];
        }
        if (!$this->versionManager) {
            $this->logger->warning('[PageVersionService] Version manager not available');
            return [];
        }

        try {
            $versions = $this->versionManager->getVersionsForFile($user, $file);

            // Current file metadata, like the Nextcloud Files app shows.
            $currentVersion = [
                'timestamp' => $file->getMTime(),
                'size' => $file->getSize(),
                'author' => $this->currentFileAuthor($file),
                'relativeTime' => $this->formatter->formatRelativeTime($file->getMTime()),
            ];

            return [
                'currentVersion' => $currentVersion,
                'versions' => $this->formatter->formatVersions($versions),
            ];
        } catch (\Exception $e) {
            $this->logger->error('[PageVersionService] Failed to get page versions: ' . $e->getMessage(), [
                'file' => $file->getPath(),
                'exception' => $e->getTraceAsString(),
            ]);
            return [
                'currentVersion' => null,
                'versions' => [],
            ];
        }
    }

    /**
     * Roll the file back to the version with this timestamp and return the
     * restored JSON, decoded. The caller supplies the page folder so a FRESH
     * node can be read after rollback — the original node may carry stale
     * internal state after the storage-level rollback.
     *
     * @throws \Exception When the version is missing or the restore fails.
     */
    public function restoreToTimestamp(File $file, Folder $folder, int $timestamp): array {
        $user = $this->requireUser();
        $manager = $this->requireManager();

        try {
            $targetVersion = $this->findVersion($manager, $user, $file, $timestamp);
            if (!$targetVersion) {
                throw new \Exception('Version not found for timestamp: ' . $timestamp);
            }

            $manager->rollback($targetVersion);

            $freshFile = $folder->get($file->getName());
            $restoredData = json_decode($freshFile->getContent(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Restored version contains invalid JSON data');
            }
            return $restoredData;
        } catch (\Exception $e) {
            $this->logger->error('[PageVersionService] Failed to restore version', [
                'error' => $e->getMessage(),
                'file' => $file->getPath(),
                'timestamp' => $timestamp,
            ]);
            throw new \Exception('Failed to restore version: ' . $e->getMessage());
        }
    }

    /**
     * Content of the version with this timestamp, for the preview panel.
     *
     * @throws \Exception When the version does not exist.
     */
    public function contentAtTimestamp(File $file, int $timestamp): array {
        $user = $this->requireUser();
        $manager = $this->requireManager();

        $version = $this->findVersion($manager, $user, $file, $timestamp);
        if (!$version) {
            throw new \Exception('Version not found');
        }

        // IVersionManager::read returns a stream resource.
        $stream = $manager->read($version);
        $content = stream_get_contents($stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        return [
            'title' => 'Version from ' . date('Y-m-d H:i:s', $timestamp),
            'content' => $content,
            'rawContent' => $content,
        ];
    }

    /**
     * Set (or clear, with null) the label on the version with this timestamp.
     *
     * @throws \Exception When the version is missing or the storage backend
     *                    does not support labels.
     */
    public function setLabel(File $file, int $timestamp, ?string $label): void {
        $user = $this->requireUser();
        $manager = $this->requireManager();

        $version = $this->findVersion($manager, $user, $file, $timestamp);
        if (!$version) {
            throw new \Exception('Version not found');
        }

        $backend = $manager->getBackendForStorage($file->getStorage());
        if (method_exists($backend, 'setVersionLabel')) {
            $backend->setVersionLabel($version, $label ?? '');
            return;
        }
        throw new \Exception('Version labels not supported by this storage backend');
    }

    /**
     * Create a version snapshot before a write. Never throws — a versioning
     * failure must not prevent the save it precedes.
     */
    public function createBeforeUpdate(File $file): void {
        if (!$this->versionManager) {
            return;
        }

        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return;
            }
            $this->versionManager->createVersion($user, $file);
        } catch (\Exception $e) {
            $this->logger->warning('[PageVersionService] createBeforeUpdate failed: ' . $e->getMessage());
        }
    }

    /**
     * Author shown for the CURRENT file state: the file owner, since we do
     * not track individual modifiers; the session user as fallback.
     */
    private function currentFileAuthor(File $file): ?string {
        try {
            $owner = $file->getOwner();
            if ($owner !== null) {
                return $owner->getDisplayName() ?: $owner->getUID();
            }
            $user = $this->userSession->getUser();
            return $user ? ($user->getDisplayName() ?: $user->getUID()) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function findVersion(IVersionManager $manager, $user, File $file, int $timestamp): ?IVersion {
        foreach ($manager->getVersionsForFile($user, $file) as $version) {
            if ($version->getTimestamp() === $timestamp) {
                return $version;
            }
        }
        return null;
    }

    private function requireUser() {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('No user in session');
        }
        return $user;
    }

    private function requireManager(): IVersionManager {
        if (!$this->versionManager) {
            throw new \Exception('Version manager not available');
        }
        return $this->versionManager;
    }
}
