<?php
declare(strict_types=1);

namespace OCA\IntraVox\Service\Media;

use OCA\IntraVox\Service\Locator\PageLocator;
use OCP\Files\File;
use OCP\Files\Folder;
use Psr\Log\LoggerInterface;

/**
 * Page media mechanics, extracted from PageService (service split, PR-17):
 * the streamed copy engine every copy/template/translation flow uses, and
 * the recursive `_media` folder resolver. PageService keeps the upload/
 * serve/list orchestration (they compose page location, validation and
 * sanitizing) and hands in located nodes — the split's standing pattern.
 *
 * Everything here is best-effort by contract: a media problem is logged
 * and skipped, never allowed to fail the page operation it accompanies.
 */
class PageMediaService {
    private PageLocator $locator;
    private LoggerInterface $logger;

    public function __construct(
        PageLocator $locator,
        LoggerInterface $logger
    ) {
        $this->locator = $locator;
        $this->logger = $logger;
    }

    /**
     * Copy the source page's `_media` folder into the target page's folder,
     * creating `_media` there when missing. Either side being absent is an
     * ordinary outcome (a page without media, a target that did not resolve).
     */
    public function copyPageMedia(?Folder $sourceFolder, ?Folder $targetPageFolder, string $context): void {
        try {
            if ($sourceFolder === null || !$sourceFolder->nodeExists('_media')) {
                return;
            }
            $sourceMedia = $sourceFolder->get('_media');
            if (!($sourceMedia instanceof Folder)) {
                return;
            }
            if ($targetPageFolder === null) {
                return;
            }
            if (!$targetPageFolder->nodeExists('_media')) {
                $targetPageFolder->newFolder('_media');
            }
            $targetMedia = $targetPageFolder->get('_media');
            if ($targetMedia instanceof Folder) {
                $this->copyMediaFolderContents($sourceMedia, $targetMedia);
            }
        } catch (\Exception $e) {
            $this->logger->warning($context . ': media copy failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Copy all files from one media folder to another (within Nextcloud storage)
     */
    public function copyMediaFolderContents(Folder $source, Folder $target): void {
        try {
            foreach ($source->getDirectoryListing() as $item) {
                $name = $item->getName();

                // Skip hidden files
                if (str_starts_with($name, '.')) {
                    continue;
                }

                if ($item instanceof File) {
                    // Copy STREAMED, never via getContent(): that buffers the
                    // whole file in RAM, and media folders hold videos — a
                    // 2 GB file would hit memory_limit halfway through a copy
                    // or translation. putContent() accepts a resource and
                    // pipes it chunk-wise.
                    try {
                        $stream = $item->fopen('rb');
                        if ($stream === false) {
                            // Storage without stream support; small-file path.
                            $target->newFile($name)->putContent($item->getContent());
                        } else {
                            try {
                                $target->newFile($name)->putContent($stream);
                            } finally {
                                if (is_resource($stream)) {
                                    fclose($stream);
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        $this->logger->warning('Failed to copy media file: ' . $name . ' - ' . $e->getMessage());
                    }
                } elseif ($item instanceof Folder) {
                    // Recursively copy subfolder
                    try {
                        if (!$target->nodeExists($name)) {
                            $newSubFolder = $target->newFolder($name);
                        } else {
                            $newSubFolder = $target->get($name);
                        }
                        if ($newSubFolder instanceof Folder) {
                            $this->copyMediaFolderContents($item, $newSubFolder);
                        }
                    } catch (\Exception $e) {
                        $this->logger->warning('Failed to copy media subfolder: ' . $name . ' - ' . $e->getMessage());
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to copy media folder contents: ' . $e->getMessage());
        }
    }

    /**
     * Recursively find the `_media` folder of the page carrying $uniqueId,
     * starting at $folder. Returns null when the page has no media folder.
     */
    public function findMediaFolderForPage($folder, string $uniqueId): ?Folder {
        // First scan JSON files in CURRENT folder to see if page is here
        $foundMatch = false;
        foreach ($this->locator->cachedDirectoryListing($folder) as $item) {
            if ($item->getType() === \OCP\Files\FileInfo::TYPE_FILE &&
                substr($item->getName(), -5) === '.json' &&
                $item->getName() !== 'navigation.json' &&
                $item->getName() !== 'footer.json' &&
                $item->getName() !== 'homepage.json') {

                $content = $item->getContent();
                $data = json_decode($content, true);

                // Match against uniqueId field
                if ($data && isset($data['uniqueId']) && $data['uniqueId'] === $uniqueId) {
                    $foundMatch = true;
                    break;
                }
            }
        }

        // If we found the matching page JSON in this folder, return its media folder
        if ($foundMatch) {
            try {
                $mediaFolder = $folder->get('_media');
                if ($mediaFolder->getType() === \OCP\Files\FileInfo::TYPE_FOLDER) {
                    return $mediaFolder;
                }
            } catch (\OCP\Files\NotFoundException $e) {
                // Page found but no media folder
                return null;
            }
        }

        // Recursively search subfolders
        foreach ($this->locator->cachedDirectoryListing($folder) as $item) {
            if ($item->getType() === \OCP\Files\FileInfo::TYPE_FOLDER) {
                $itemName = $item->getName();

                // Skip special folders to avoid infinite loops
                if ($itemName === '_media' || $itemName === 'images' || $itemName === 'videos' || $itemName === '.nomedia') {
                    continue;
                }

                // Recurse into subfolder - wrap in try-catch to handle stale cache entries
                try {
                    $result = $this->findMediaFolderForPage($item, $uniqueId);
                    if ($result !== null) {
                        return $result;
                    }
                } catch (\OCP\Files\NotFoundException $e) {
                    // This subfolder doesn't actually exist (stale cache entry) - skip it
                    continue;
                } catch (\Exception $e) {
                    $this->logger->error("findMediaFolderForPage: Error accessing subfolder {$itemName}: {$e->getMessage()}");
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * Mark a media folder with `.nomedia`, standard practice for media
     * storage folders. Never critical.
     */
    public function createMediaFolderMarker($mediaFolder): void {
        try {
            if (!$mediaFolder->nodeExists('.nomedia')) {
                $nomediaFile = $mediaFolder->newFile('.nomedia');
                $nomediaFile->putContent('');
            }
        } catch (\Exception $e) {
            $this->logger->debug('Could not create .nomedia file', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
