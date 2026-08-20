<?php

declare(strict_types=1);

namespace OCA\IntraVox\Service\Cache;

use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Owner of IntraVox' page caches.
 *
 * Before this existed the layers were fields on PageService, which had two
 * consequences. Every service that mutates pages had to reach back into
 * PageService to invalidate — LanguageService carried a setPageService()
 * setter whose only purpose was calling invalidateAllCaches(), a documented DI
 * cycle broken by late-binding at boot — and the tree cache was `static`, so
 * instances shared state through class properties. Both are the same defect:
 * the cache had no owner, so it was everyone's. Introducing this class removed
 * the cycle; LanguageService now injects it directly.
 *
 * Four layers, deliberately kept distinct because they have different costs
 * and different invalidation rules:
 *
 *  1. Request caches (page data, page folders, folder paths). Plain arrays,
 *     cheap to reset, always cleared immediately so a mutation mid-batch still
 *     sees a truthful filesystem view.
 *  2. The tree cache. Was static; now per-instance, since this service is a
 *     singleton in the container and no longer needs class state to be shared.
 *  3. The distributed cache (`intravox-pages`), with three consumers: page
 *     content, the page tree, and the news list plus its version counters.
 *  4. A suppression counter, so a bulk operation clears the expensive layers
 *     once at the end instead of once per item.
 *
 * What this class does NOT do is decide *when* to invalidate. That stays with
 * the callers that know a page changed. This owns storage and lifetime; the
 * policy stays where the domain knowledge is.
 */
class PageCacheService {
    /** Distributed TTL for a rendered page tree (5 minutes). */
    public const PAGE_TREE_TTL = 300;

    /** Distributed TTL for page content (1 hour). */
    public const PAGE_CONTENT_TTL = 3600;

    /** Distributed TTL for a rendered news list (5 minutes). */
    public const NEWS_TTL = 300;

    private ?ICache $distributed = null;

    /** @var array<string, array> Page data by uniqueId and by original id. */
    private array $pageData = [];

    /** @var array<string, \OCP\Files\Folder> Page folders by id. */
    private array $pageFolders = [];

    /** @var array<string, mixed> Resolved folder paths, including negative hits. */
    private array $folderPaths = [];

    /** @var array<string, array> Rendered trees by group/language key. */
    private array $trees = [];

    /**
     * While > 0, clearExpensive() records the request instead of performing it.
     * Reentrant: nested batches are counted, only the outermost one flushes.
     */
    private int $suppressDepth = 0;

    private bool $clearRequestedWhileSuppressed = false;

    /**
     * The factory is nullable so a cache can be built with no distributed
     * backend at all. That is the shape unit tests get: request-level caching
     * still works, the distributed layer is simply absent — the same state as
     * an instance without Redis/APCu configured.
     */
    public function __construct(?ICacheFactory $cacheFactory = null) {
        if ($cacheFactory !== null && $cacheFactory->isAvailable()) {
            $this->distributed = $cacheFactory->createDistributed('intravox-pages');
        }
    }

    // ---------------------------------------------------------------- request

    public function getPageData(string $id): ?array {
        return $this->pageData[$id] ?? null;
    }

    public function setPageData(string $id, array $data): void {
        $this->pageData[$id] = $data;
    }

    public function hasPageFolder(string $id): bool {
        return isset($this->pageFolders[$id]);
    }

    public function getPageFolder(string $id) {
        return $this->pageFolders[$id] ?? null;
    }

    public function setPageFolder(string $id, $folder): void {
        $this->pageFolders[$id] = $folder;
    }

    /**
     * Folder-path lookups cache their misses as null, so presence and value
     * are separate questions — hence the explicit has/get pair rather than a
     * nullable getter.
     */
    public function hasFolderPath(string $path): bool {
        return array_key_exists($path, $this->folderPaths);
    }

    public function getFolderPath(string $path) {
        return $this->folderPaths[$path] ?? null;
    }

    public function setFolderPath(string $path, $result): void {
        $this->folderPaths[$path] = $result;
    }

    // ------------------------------------------------------------------- tree

    public function getTree(string $key): ?array {
        return $this->trees[$key] ?? null;
    }

    public function setTree(string $key, array $entry): void {
        $this->trees[$key] = $entry;
    }

    // ------------------------------------------------------------ distributed

    public function isDistributedAvailable(): bool {
        return $this->distributed !== null;
    }

    public function getDistributed(string $key) {
        return $this->distributed?->get($key);
    }

    public function setDistributed(string $key, $value, int $ttl): void {
        $this->distributed?->set($key, $value, $ttl);
    }

    // ----------------------------------------------------------- invalidation

    /**
     * Drop the request-level entries for one page, or all of them.
     *
     * Always immediate: these are array resets, and doing them per item is
     * what keeps a bulk operation seeing the real filesystem between steps.
     */
    public function clearRequest(?string $pageId = null): void {
        if ($pageId !== null) {
            unset($this->pageData[$pageId], $this->pageFolders[$pageId]);
            return;
        }

        $this->pageData = [];
        $this->pageFolders = [];
        $this->folderPaths = [];
    }

    /**
     * Drop the tree cache and the distributed cache.
     *
     * This is the expensive half — a distributed clear() is an IPC/Redis round
     * trip — so it is what batching defers. Returns false when the call was
     * suppressed, so the caller can skip the collaborator clears that belong
     * to the same flush.
     */
    public function clearExpensive(): bool {
        if ($this->suppressDepth > 0) {
            $this->clearRequestedWhileSuppressed = true;
            return false;
        }

        $this->trees = [];
        $this->distributed?->clear();

        return true;
    }

    public function beginDeferred(): void {
        $this->suppressDepth++;
    }

    /**
     * Close a batch. Returns true when this released the outermost batch AND a
     * clear was requested while suppressed — i.e. when the caller still owes
     * the collaborator services their flush.
     */
    public function endDeferred(): bool {
        if (--$this->suppressDepth > 0) {
            return false;
        }

        $this->suppressDepth = 0;

        if (!$this->clearRequestedWhileSuppressed) {
            return false;
        }

        $this->clearRequestedWhileSuppressed = false;
        $this->trees = [];
        $this->distributed?->clear();

        return true;
    }

    public function isDeferring(): bool {
        return $this->suppressDepth > 0;
    }
}
