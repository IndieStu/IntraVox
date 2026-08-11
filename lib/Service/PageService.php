<?php
declare(strict_types=1);

namespace OCA\IntraVox\Service;

use OCA\IntraVox\AppInfo\Application;
use OCA\IntraVox\Constants;
use OCA\IntraVox\Event\PageDeletedEvent;
use OCA\IntraVox\Exception\CrossLanguageMoveException;
use OCA\IntraVox\Exception\ForbiddenException;
use OCA\IntraVox\Exception\PageConflictException;
use OCA\IntraVox\Exception\PageNotFoundException;
use OCA\IntraVox\Service\GroupContextService;
use OCA\IntraVox\Service\News\NewsContentExtractor;
use OCA\IntraVox\Service\Path\PagePathHelper;
use OCA\IntraVox\Service\Sanitize\ColorSanitizer;
use OCA\IntraVox\Service\Sanitize\HtmlSanitizer;
use OCA\IntraVox\Service\Sanitize\MediaSanitizer;
use OCA\IntraVox\Service\Sanitize\UrlSanitizer;
use OCA\IntraVox\Service\Search\PageSearchHelper;
use OCA\IntraVox\Service\Template\TemplateMetadataExtractor;
use OCA\IntraVox\Service\Util\PageIdUtils;
use OCA\IntraVox\Service\Version\PageVersionFormatter;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUserSession;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\App\IAppManager;
use OCP\ICacheFactory;
use OCP\ICache;
use Psr\Log\LoggerInterface;
use OCP\Files\Cache\ICacheEntry;
use OCA\Files_Versions\Versions\IVersionManager;
use OCA\Files_Versions\Versions\IVersion;

class PageService {
    private const ALLOWED_WIDGET_TYPES = ['text', 'heading', 'image', 'links', 'divider', 'video', 'news', 'people', 'calendar', 'feed', 'photo-story', 'file-story'];
    private const ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
    private const ALLOWED_VIDEO_TYPES = ['video/mp4', 'video/webm', 'video/ogg'];
    private const ALLOWED_MEDIA_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
        'video/mp4', 'video/webm', 'video/ogg'
    ];
    private const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg',  // Images
        'mp4', 'webm', 'ogg',                         // Videos
    ];
    private const MAX_IMAGE_SIZE = 2097152; // 2MB (PHP default upload limit)
    private const MAX_VIDEO_SIZE = 52428800; // 50MB
    private const MAX_MEDIA_SIZE = 52428800; // 50MB (largest of image/video limits)
    private const MAX_SVG_SIZE = 1048576; // 1MB for SVG files (prevent XML bomb attacks)
    private const MAX_COLUMNS = 5;
    private const DEFAULT_LANGUAGE = 'en';

    private IRootFolder $rootFolder;
    private IUserSession $userSession;
    private string $userId;
    private IAppManager $appManager;
    private SetupService $setupService;
    private IConfig $config;
    private IDBConnection $db;
    /** @var array<string, string>|null Request-lifetime cache of MetaVox field labels */
    private ?array $metaVoxFieldLabelsCache = null;
    /** @var array<string, bool> Request-lifetime cache of per-field view permissions */
    private array $metaVoxFieldViewCache = [];
    /** @var array<int, int> file_id => groupfolder_id, filled by getMetaVoxDataForFiles */
    private array $metaVoxGroupfolderByFile = [];
    private LoggerInterface $logger;
    private IEventDispatcher $eventDispatcher;
    private PublicationSettingsService $publicationSettings;
    private PageIndexService $pageIndexService;
    private ?IVersionManager $versionManager = null;
    private ?ICache $distributedCache = null;
    private ?ICache $permissionsDistributedCache = null;
    private array $pageFolderCache = [];

    /** @var array Request-level cache for page data */
    private array $pageDataCache = [];

    /** @var array Request-level cache for page list */
    private ?array $listPagesCache = null;

    /** @var array Request-level cache for pages by folder path */
    private array $folderPathCache = [];

    /** @var array Request-level cache for directory listings */
    private array $directoryListingCache = [];

    /** @var array Request-level cache for folder permissions */
    private array $permissionsCache = [];

    /** @var array Request-level cache for file contents */
    private array $fileContentCache = [];

    /** @var array Static cache for page tree (shared across requests within same PHP process) */
    private static array $pageTreeCache = [];

    /** @var int Cache TTL for page tree in seconds */
    private const PAGE_TREE_CACHE_TTL = 300; // 5 minutes

    /**
     * @var int While > 0, only the expensive part of clearCache() (static tree
     * cache + distributed cache clear()) is deferred to the end of a batch.
     * Request-level caches are still invalidated per item. A 100-item bulk op
     * would otherwise clear the distributed cache 100 times.
     */
    private int $suppressClearDepth = 0;

    /** @var bool Set when the deferred (distributed) clear was requested in a batch. */
    private bool $clearRequestedWhileSuppressed = false;

    /**
     * Get the effective upload limit in bytes (minimum of upload_max_filesize and post_max_size)
     */
    public function getUploadLimit(): int {
        $uploadMax = $this->parsePhpSize(ini_get('upload_max_filesize') ?: '2M');
        $postMax = $this->parsePhpSize(ini_get('post_max_size') ?: '8M');

        // Use the smaller of the two, but cap at our app's MAX_MEDIA_SIZE
        $phpLimit = min($uploadMax, $postMax);
        return min($phpLimit, self::MAX_MEDIA_SIZE);
    }

    /**
     * Parse PHP size notation (e.g., '2M', '8M', '512K') to bytes
     */
    /**
     * @deprecated Delegated to PageIdUtils::parsePhpSize.
     */
    private function parsePhpSize(string $size): int {
        return $this->idUtils->parsePhpSize($size);
    }

    /**
     * Public flush hook for callers that mutate the underlying filesystem
     * outside of PageService (notably ImportService, NavigationService,
     * BulkOperationService) and need the IntraVox cache layers to forget
     * everything so a fresh read rebuilds. Equivalent to the internal
     * clearCache() but exposed for cross-service invalidation.
     */
    public function invalidateAllCaches(): void {
        $this->clearCache();
    }

    /**
     * Begin a batch: suppress the (expensive, blanket) clearCache() that each
     * mutation triggers, so a bulk operation clears the caches once at the end
     * instead of once per item. Must be paired with endDeferredClear() in a
     * finally block. Reentrant — nested begins are counted.
     */
    public function beginDeferredClear(): void {
        $this->suppressClearDepth++;
    }

    /**
     * End a batch. When the outermost begin is released, if any mutation asked
     * for a clear while suppressed, perform exactly one real clearCache() now.
     */
    public function endDeferredClear(): void {
        if (--$this->suppressClearDepth <= 0) {
            $this->suppressClearDepth = 0;
            if ($this->clearRequestedWhileSuppressed) {
                $this->clearRequestedWhileSuppressed = false;
                $this->clearCache();
            }
        }
    }

    /**
     * Clear all request-level caches (call after mutations)
     */
    private function clearCache(?string $pageId = null): void {
        // Request-level caches are always invalidated immediately: these are cheap
        // array resets, and doing them per item keeps every mutation seeing a
        // truthful filesystem view mid-batch (identical to the non-batch path).
        if ($pageId !== null) {
            unset($this->pageDataCache[$pageId]);
            unset($this->pageFolderCache[$pageId]);
        } else {
            $this->pageDataCache = [];
            $this->pageFolderCache = [];
            $this->folderPathCache = [];
            $this->directoryListingCache = [];
            $this->permissionsCache = [];
            $this->fileContentCache = [];
        }
        $this->listPagesCache = null;

        // The expensive part — the static tree cache and the two distributed
        // caches (IPC/Redis clear()) — is what makes a 100-item bulk op wipe the
        // distributed cache 100×. Defer only these to the end of the batch.
        if ($this->suppressClearDepth > 0) {
            $this->clearRequestedWhileSuppressed = true;
            return;
        }

        // Clear *all* group-keyed tree caches: a single page mutation can be
        // visible to any group that has read access via GroupFolder ACL, and
        // we have no efficient way to enumerate those from here. The bucket
        // count is small (≤ groups × languages, typically ~40), so a blanket
        // clear is cheaper than tracking dependencies. This also clears the
        // news-version counters (PR-13) and content caches (PR-12) for the
        // same reason; subsequent reads re-initialize the counter at 0 and
        // rebuild from source.
        self::$pageTreeCache = [];
        if ($this->distributedCache !== null) {
            $this->distributedCache->clear();
        }
        // The per-language page-path map cached in PermissionService is also
        // invalidated by any page create/update/delete.
        if ($this->permissionsDistributedCache !== null) {
            $this->permissionsDistributedCache->clear();
        }
    }

    /**
     * Preserve originalSrc for video widgets during page updates.
     * This ensures that video URLs are not lost when the domain whitelist changes.
     * When a video is blocked, its originalSrc is preserved so it can be re-enabled
     * if the admin adds the domain back to the whitelist.
     */
    private function preserveVideoOriginalUrls(array $newData, array $existingData): array {
        // Build a map of existing video widgets by their ID
        $existingVideos = [];
        $this->collectVideoWidgets($existingData, $existingVideos);

        // Update new data with preserved originalSrc values
        $this->updateVideoWidgetsWithOriginalUrls($newData, $existingVideos);

        return $newData;
    }

    /**
     * Collect all video widgets from page data into a map keyed by widget ID
     */
    private function collectVideoWidgets(array $data, array &$videos): void {
        // Process main rows
        if (isset($data['layout']['rows']) && is_array($data['layout']['rows'])) {
            foreach ($data['layout']['rows'] as $row) {
                if (isset($row['widgets']) && is_array($row['widgets'])) {
                    foreach ($row['widgets'] as $widget) {
                        if (($widget['type'] ?? '') === 'video' && isset($widget['id'])) {
                            $videos[$widget['id']] = $widget;
                        }
                    }
                }
            }
        }

        // Process side columns
        if (isset($data['layout']['sideColumns']) && is_array($data['layout']['sideColumns'])) {
            foreach (['left', 'right'] as $side) {
                if (isset($data['layout']['sideColumns'][$side]['widgets']) && is_array($data['layout']['sideColumns'][$side]['widgets'])) {
                    foreach ($data['layout']['sideColumns'][$side]['widgets'] as $widget) {
                        if (($widget['type'] ?? '') === 'video' && isset($widget['id'])) {
                            $videos[$widget['id']] = $widget;
                        }
                    }
                }
            }
        }

        // Process header row
        if (isset($data['layout']['headerRow']['widgets']) && is_array($data['layout']['headerRow']['widgets'])) {
            foreach ($data['layout']['headerRow']['widgets'] as $widget) {
                if (($widget['type'] ?? '') === 'video' && isset($widget['id'])) {
                    $videos[$widget['id']] = $widget;
                }
            }
        }
    }

    /**
     * Update video widgets in new data with originalSrc from existing widgets
     */
    private function updateVideoWidgetsWithOriginalUrls(array &$data, array $existingVideos): void {
        // Process main rows
        if (isset($data['layout']['rows']) && is_array($data['layout']['rows'])) {
            foreach ($data['layout']['rows'] as $rowIndex => &$row) {
                if (isset($row['widgets']) && is_array($row['widgets'])) {
                    foreach ($row['widgets'] as $widgetIndex => &$widget) {
                        $this->preserveWidgetOriginalUrl($widget, $existingVideos);
                    }
                }
            }
        }

        // Process side columns
        if (isset($data['layout']['sideColumns']) && is_array($data['layout']['sideColumns'])) {
            foreach (['left', 'right'] as $side) {
                if (isset($data['layout']['sideColumns'][$side]['widgets']) && is_array($data['layout']['sideColumns'][$side]['widgets'])) {
                    foreach ($data['layout']['sideColumns'][$side]['widgets'] as $widgetIndex => &$widget) {
                        $this->preserveWidgetOriginalUrl($widget, $existingVideos);
                    }
                }
            }
        }

        // Process header row
        if (isset($data['layout']['headerRow']['widgets']) && is_array($data['layout']['headerRow']['widgets'])) {
            foreach ($data['layout']['headerRow']['widgets'] as $widgetIndex => &$widget) {
                $this->preserveWidgetOriginalUrl($widget, $existingVideos);
            }
        }
    }

    /**
     * Preserve originalSrc for a single video widget
     */
    private function preserveWidgetOriginalUrl(array &$widget, array $existingVideos): void {
        if (($widget['type'] ?? '') !== 'video') {
            return;
        }

        // Skip local videos - they don't have originalSrc
        if (($widget['provider'] ?? '') === 'local') {
            return;
        }

        $widgetId = $widget['id'] ?? null;
        if ($widgetId && isset($existingVideos[$widgetId])) {
            $existing = $existingVideos[$widgetId];

            // If the new widget has no src or originalSrc, but the existing one does,
            // preserve the originalSrc so the URL isn't lost
            $newSrc = $widget['src'] ?? '';
            $newOriginalSrc = $widget['originalSrc'] ?? '';
            $existingOriginalSrc = $existing['originalSrc'] ?? '';
            $existingSrc = $existing['src'] ?? '';

            // Preserve originalSrc: use existing originalSrc if new one is empty
            if (empty($newOriginalSrc)) {
                if (!empty($existingOriginalSrc)) {
                    $widget['originalSrc'] = $existingOriginalSrc;
                } elseif (!empty($existingSrc)) {
                    // Fallback: use existing src as originalSrc
                    $widget['originalSrc'] = $existingSrc;
                }
            }

            // If new src is empty but we have originalSrc, keep it for re-validation
            if (empty($newSrc) && !empty($widget['originalSrc'] ?? '')) {
                // The sanitizeWidget function will re-validate against current whitelist
                // and either allow it (setting src) or block it (keeping blocked=true)
            }
        }
    }

    /**
     * Get cached directory listing for a folder
     */
    private function getCachedDirectoryListing(\OCP\Files\Folder $folder): array {
        $path = $folder->getPath();
        if (!isset($this->directoryListingCache[$path])) {
            $this->directoryListingCache[$path] = $folder->getDirectoryListing();
        }
        return $this->directoryListingCache[$path];
    }

    /**
     * Get cached permissions for a node (folder or file)
     */
    private function getCachedPermissions(\OCP\Files\Node $node): int {
        $path = $node->getPath();
        if (!isset($this->permissionsCache[$path])) {
            $this->permissionsCache[$path] = $node->getPermissions();
        }
        return $this->permissionsCache[$path];
    }

    /**
     * Build the canRead/canWrite/canCreate/canDelete/canShare permission object
     * for a node from Nextcloud's filesystem view — the single source of truth
     * used by getPage(), getFolderPermissions(), the page tree and listings.
     *
     * canWrite/canCreate/canDelete AND the raw permission bit with the node's
     * capability method. For a read-only GroupFolder / Team Folder member WITHOUT
     * Advanced Permissions (ACLs), getPermissions() can still report UPDATE/CREATE
     * because the group read-only toggle is enforced on the mount mask, not always
     * reflected per child node (issue #70). isUpdateable()/isCreatable()/
     * isDeletable() DO account for mount writability and are already trusted
     * elsewhere (canEdit below, template creation, NavigationService/FooterService
     * canEdit). Under ACLs the bitmask is already correct and these methods reflect
     * it, so AND-ing can only ever REMOVE a wrongly-granted capability, never grant
     * one — it never turns a genuinely writable folder read-only. `raw` is kept
     * un-AND-ed for API back-compat.
     *
     * `protected` (not private) only to give unit tests a seam; no runtime
     * behaviour depends on the visibility.
     */
    protected function permissionsFromNode(\OCP\Files\Node $node): array {
        $perms = $this->getCachedPermissions($node);
        return [
            'canRead' => ($perms & 1) !== 0,
            'canWrite' => ($perms & 2) !== 0 && $node->isUpdateable(),
            'canCreate' => ($perms & 4) !== 0 && $node->isCreatable(),
            'canDelete' => ($perms & 8) !== 0 && $node->isDeletable(),
            'canShare' => ($perms & 16) !== 0,
            'raw' => $perms,
        ];
    }

    /**
     * Permissions for a single page, where "write" is gated on the page FILE and
     * the remaining capabilities describe operations on the page FOLDER.
     *
     * Why the split: editing a page writes its JSON file (updatePage preflights
     * $file->isUpdateable() and then putContent()s the file). In a read-only
     * Team Folder without ACLs the FOLDER can report isUpdateable()=true while
     * the FILE reports false, so a folder-derived canWrite showed an "Edit page"
     * button that then 403'd on save (issue #70). canCreate/canDelete stay
     * folder-derived: creating a child or removing the page are folder-level
     * operations, consistent with the tree/listing builders.
     */
    protected function permissionsForPage(\OCP\Files\Node $folder, \OCP\Files\Node $file): array {
        $perms = $this->permissionsFromNode($folder);
        $filePerms = $this->getCachedPermissions($file);
        $perms['canWrite'] = ($filePerms & 2) !== 0 && $file->isUpdateable();
        return $perms;
    }

    /**
     * Get cached file content (prevents repeated reads of same file within request)
     */
    private function getCachedFileContent(\OCP\Files\File $file): string {
        $path = $file->getPath();
        if (!isset($this->fileContentCache[$path])) {
            $this->fileContentCache[$path] = $file->getContent();
        }
        return $this->fileContentCache[$path];
    }

    private HtmlSanitizer $htmlSanitizer;
    private UrlSanitizer $urlSanitizer;
    private ColorSanitizer $colorSanitizer;
    private MediaSanitizer $mediaSanitizer;
    private PageVersionFormatter $versionFormatter;
    private TemplateMetadataExtractor $templateMetadata;
    private NewsContentExtractor $newsContent;
    private PageSearchHelper $searchHelper;
    private PagePathHelper $pathHelper;
    private PageIdUtils $idUtils;
    private GroupContextService $groupContext;
    private LanguageService $languageService;
    private HomepageService $homepageService;
    private NavigationService $navigationService;

    public function __construct(
        IRootFolder $rootFolder,
        IUserSession $userSession,
        SetupService $setupService,
        IConfig $config,
        IDBConnection $db,
        LoggerInterface $logger,
        IEventDispatcher $eventDispatcher,
        PublicationSettingsService $publicationSettings,
        ICacheFactory $cacheFactory,
        PageIndexService $pageIndexService,
        HtmlSanitizer $htmlSanitizer,
        UrlSanitizer $urlSanitizer,
        ColorSanitizer $colorSanitizer,
        MediaSanitizer $mediaSanitizer,
        PageVersionFormatter $versionFormatter,
        TemplateMetadataExtractor $templateMetadata,
        NewsContentExtractor $newsContent,
        PageSearchHelper $searchHelper,
        PagePathHelper $pathHelper,
        PageIdUtils $idUtils,
        GroupContextService $groupContext,
        LanguageService $languageService,
        HomepageService $homepageService,
        NavigationService $navigationService,
        IAppManager $appManager,
        ?string $userId
    ) {
        $this->rootFolder = $rootFolder;
        $this->userSession = $userSession;
        $this->setupService = $setupService;
        $this->config = $config;
        $this->db = $db;
        $this->logger = $logger;
        $this->eventDispatcher = $eventDispatcher;
        $this->publicationSettings = $publicationSettings;
        $this->pageIndexService = $pageIndexService;
        $this->htmlSanitizer = $htmlSanitizer;
        $this->urlSanitizer = $urlSanitizer;
        $this->colorSanitizer = $colorSanitizer;
        $this->mediaSanitizer = $mediaSanitizer;
        $this->versionFormatter = $versionFormatter;
        $this->templateMetadata = $templateMetadata;
        $this->newsContent = $newsContent;
        $this->searchHelper = $searchHelper;
        $this->pathHelper = $pathHelper;
        $this->idUtils = $idUtils;
        $this->groupContext = $groupContext;
        $this->languageService = $languageService;
        $this->homepageService = $homepageService;
        $this->navigationService = $navigationService;
        $this->appManager = $appManager;
        $this->userId = $userId ?? '';

        if ($cacheFactory->isAvailable()) {
            $this->distributedCache = $cacheFactory->createDistributed('intravox-pages');
            // Mutations also invalidate the per-language path map cached
            // alongside PermissionService; we hold a thin handle to it here
            // rather than circular-injecting that service.
            $this->permissionsDistributedCache = $cacheFactory->createDistributed('intravox-permissions');
        }

        // Lazy load version manager (files_versions may not be enabled)
        try {
            $this->versionManager = \OC::$server->get(IVersionManager::class);
        } catch (\Exception $e) {
            $this->logger->info('[PageService] Version manager not available: ' . $e->getMessage());
        }
    }

    /**
     * Get the user's TRUE intranet language (base code) from their Nextcloud
     * language preference, e.g. 'nl_NL' -> 'nl', 'da' -> 'da'.
     *
     * VoxCloud language model: we return the user's actual language and do NOT
     * silently remap it to English here. Two consumers rely on this:
     *   - getLanguageFolder() resolves the content folder and falls back to the
     *     English folder itself when the user's language folder is absent, so a
     *     language without content still renders *something*.
     *   - getLanguageContentStatus() needs the real language to detect "the
     *     user's language has no content" and drive the fallback notice. The old
     *     enabled_languages remap broke that: a Danish user was reported as
     *     English, so the notice never showed.
     */
    private function getUserLanguage(): string {
        if (!$this->userId) {
            return self::DEFAULT_LANGUAGE;
        }

        $lang = $this->config->getUserValue($this->userId, 'core', 'lang', self::DEFAULT_LANGUAGE);

        // Extract base language code (e.g., 'nl_NL' -> 'nl').
        $langCode = explode('_', $lang)[0];

        // Guard against malformed values; fall back to the default language.
        return preg_match('/^[a-z]{2,3}$/', $langCode) ? $langCode : self::DEFAULT_LANGUAGE;
    }

    /**
     * Resolve which page is the homepage for a language (configurable homepage).
     *
     * Returns the configured pointer target if set AND it resolves to a real
     * page; otherwise falls back to the legacy loose `home.json` (uniqueId
     * 'home' / the page at the language root). This fallback is the entire
     * back-compat story: installs without a homepage.json behave exactly as
     * before.
     *
     * @return string uniqueId of the homepage ('home' for the legacy default).
     */
    public function getHomepageUniqueId(?string $language = null): string {
        // Without an explicit language, use the language the user is actually
        // shown (recommended-language fallback, #75) so the homepage pointer is
        // resolved in — and checked against — the served language's folder.
        $lang = $language ?? $this->resolveEffectiveLanguage() ?? $this->getUserLanguage();

        $pointer = $this->homepageService->getHomepageUniqueId($lang);
        if ($pointer !== null && $pointer !== '' && $pointer !== 'home') {
            // Only honour the pointer when it resolves to an existing page.
            try {
                $folder = $this->getLanguageFolderByCode($lang);
                if ($this->findPageByUniqueId($folder, $pointer) !== null) {
                    return $pointer;
                }
            } catch (\Exception $e) {
                // Fall through to the legacy default.
            }
        }

        // Legacy default: the loose home.json in the language root.
        //
        // Resolve it to the uniqueId the file actually carries. Returning the
        // bare string 'home' hands the frontend an id that matches no page in
        // listPages(), so `pages.find(p => p.uniqueId === homepageUniqueId)`
        // came up empty and the reader fell through to a slug/path heuristic
        // that ends at `pages[0]` — the alphabetically first page. On dev that
        // put every Dutch reader on "API Referentie" instead of "Welkom bij
        // IntraVox", while English (which uses the normalised home/home.json
        // layout, so it already had a real uniqueId) worked fine.
        //
        // Falls back to the literal 'home' when the file is missing or carries
        // no uniqueId, which is the pre-existing behaviour and what the rest of
        // the legacy path still understands.
        try {
            $folder = $this->getLanguageFolderByCode($lang);
            $homeFile = $folder->get('home.json');
            if ($homeFile instanceof \OCP\Files\File) {
                $data = json_decode($this->getCachedFileContent($homeFile), true);
                $homeUniqueId = is_array($data) ? ($data['uniqueId'] ?? null) : null;
                if (is_string($homeUniqueId) && $homeUniqueId !== '') {
                    return $homeUniqueId;
                }
            }
        } catch (\Exception $e) {
            // No loose home.json in this language — fall through.
        }

        return 'home';
    }

    /**
     * Whether the given uniqueId is the resolved homepage for the language.
     * Handles the legacy 'home' id as well as a configured pointer target.
     */
    public function isHomepage(string $uniqueId, ?string $language = null): bool {
        if ($uniqueId === '') {
            return false;
        }
        return $uniqueId === $this->resolveHomepageNodeUniqueId($language);
    }

    /**
     * The concrete uniqueId (page-…) of the homepage for a language, suitable
     * for badging/comparison in the UI. When a pointer is set it is that
     * uniqueId; otherwise it resolves the legacy loose home.json to its real
     * uniqueId (not the literal 'home'). Optionally pass an already-built tree
     * to resolve the legacy home from it without an extra read.
     *
     * @param array<int,array>|null $tree Optional pre-built page tree.
     */
    public function resolveHomepageNodeUniqueId(?string $language = null, ?array $tree = null): string {
        $resolved = $this->getHomepageUniqueId($language);
        if ($resolved !== 'home') {
            return $resolved;
        }

        // Legacy default: map 'home' to the real uniqueId of the loose home.json.
        try {
            $folder = $this->getLanguageFolderByCode($language ?? $this->getUserLanguage());
            if ($folder->nodeExists('home.json')) {
                $data = json_decode($folder->get('home.json')->getContent(), true);
                if (is_array($data) && !empty($data['uniqueId'])) {
                    return (string)$data['uniqueId'];
                }
            }
        } catch (\Exception $e) {
            // Fall through.
        }

        // Last resort: first root node of a supplied tree.
        if (is_array($tree) && isset($tree[0]['uniqueId'])) {
            return (string)$tree[0]['uniqueId'];
        }
        return 'home';
    }

    /**
     * Create a simple .nomedia marker for the _media folder
     * The folder name "_media" itself is the primary identifier
     */
    private function createMediaFolderMarker($mediaFolder): void {
        try {
            // Only create a simple .nomedia file
            // This is standard practice for media storage folders
            if (!$mediaFolder->nodeExists('.nomedia')) {
                $nomediaFile = $mediaFolder->newFile('.nomedia');
                $nomediaFile->putContent('');
            }
        } catch (\Exception $e) {
            // Not critical if this fails
            $this->logger->debug('Could not create .nomedia file', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get the language folder within IntraVox
     *
     * `protected` (not private) purely to give unit tests a seam to inject a
     * fake language folder; no runtime behaviour depends on the visibility.
     */
    protected function getLanguageFolder() {
        $baseFolder = $this->getIntraVoxFolder();
        $lang = $this->getUserLanguage();

        try {
            return $baseFolder->get($lang);
        } catch (NotFoundException $e) {
            // If language folder doesn't exist, try default language
            if ($lang !== self::DEFAULT_LANGUAGE) {
                try {
                    return $baseFolder->get(self::DEFAULT_LANGUAGE);
                } catch (NotFoundException $e2) {
                    // Create default language folder if it doesn't exist
                    return $baseFolder->newFolder(self::DEFAULT_LANGUAGE);
                }
            }
            // Create the requested language folder
            return $baseFolder->newFolder($lang);
        }
    }

    /**
     * The language whose content the CURRENT user will actually be SHOWN on the
     * landing/read paths. Read-only resolution — NEVER used to decide where to
     * write (authoring must always target the user's own language folder).
     *
     * Order (issue #75):
     *   1. the user's own display language, if it has real content
     *   2. the admin "recommended" (primary) language, if it has real content
     *      and differs from the user's language — this is what the admin
     *      settings promise: "if there is none, they are shown the recommended
     *      language below"
     *   3. English ('en'), if it has real content
     *   4. null — nothing can be served (pure other-language install) → notice
     *
     * "Has real content" = languageFolderHasRealContent (a homepage that is not
     * a _generated placeholder), matching how languagesWithContent is built, so
     * a non-null result is always one of languagesWithContent. primaryLanguage
     * already defaults to 'en', so when unset the chain collapses to user → en.
     */
    private function resolveEffectiveLanguage(): ?string {
        $userLang = $this->getUserLanguage();
        $candidates = [$userLang];

        $primary = $this->languageService->getPrimaryLanguage();
        if ($primary !== $userLang) {
            $candidates[] = $primary;
        }
        if (!in_array(self::DEFAULT_LANGUAGE, $candidates, true)) {
            $candidates[] = self::DEFAULT_LANGUAGE;
        }

        $baseFolder = $this->getIntraVoxFolder();
        foreach ($candidates as $code) {
            try {
                $folder = $baseFolder->get($code);
            } catch (NotFoundException $e) {
                continue;
            }
            if ($folder instanceof \OCP\Files\Folder
                && $this->languageFolderHasRealContent($folder)) {
                return $code;
            }
        }
        return null;
    }

    /**
     * Content folder for READING/VIEWING for the current user, honouring the
     * recommended-language fallback (issue #75). When nothing resolves it falls
     * back to the plain write-target folder (getLanguageFolder), so callers get
     * a valid — possibly empty — folder rather than an exception; the fallback
     * notice decides separately whether to blank the page.
     *
     * `protected` (like getLanguageFolder/getIntraVoxFolder) only to give unit
     * tests a seam for language resolution; no runtime behaviour depends on it.
     */
    protected function getReadLanguageFolder(): \OCP\Files\Folder {
        $lang = $this->resolveEffectiveLanguage();
        if ($lang !== null) {
            try {
                $folder = $this->getIntraVoxFolder()->get($lang);
                if ($folder instanceof \OCP\Files\Folder) {
                    return $folder;
                }
            } catch (NotFoundException $e) {
                // fall through to the write-target folder
            }
        }
        return $this->getLanguageFolder();
    }

    /**
     * Which language content folder does $folder sit in?
     *
     * Walks up from $folder to the IntraVox root and returns the top-level
     * segment when it is a language code. Used to record where a page really
     * landed rather than assuming the author's own language.
     *
     * @return string|null the language code, or null when $folder is outside
     *   the IntraVox tree or is the tree root itself.
     */
    private function languageOfFolder(\OCP\Files\Folder $folder): ?string {
        $basePath = rtrim($this->getIntraVoxFolder()->getPath(), '/');
        $path = rtrim($folder->getPath(), '/');
        if ($path === $basePath || strpos($path, $basePath . '/') !== 0) {
            return null;
        }
        $rest = substr($path, strlen($basePath) + 1);
        $first = explode('/', $rest)[0];
        return preg_match('/^[a-z]{2,3}$/', $first) ? $first : null;
    }

    /**
     * Locate a page by uniqueId across every language folder that exists on
     * disk, starting with $primaryFolder.
     *
     * Reading and writing used to resolve the language folder differently:
     * getPage() searched the *effective* language (recommended-language
     * fallback, #75) and then every other language folder, while the write
     * paths searched only the folder for the user's own display language. Any
     * page that IntraVox could render but that lived outside the user's own
     * language folder was therefore impossible to save — the save failed with
     * "Page not found" on a page that was visibly on screen (issue #90).
     *
     * Both sides now locate pages through here. This decides only WHERE AN
     * EXISTING PAGE LIVES, never where a NEW page is created: creation still
     * targets the user's own language folder via getLanguageFolder(). Callers
     * that write remain responsible for permissions — the file returned here
     * is still subject to the isUpdateable() check on the caller's side.
     *
     * @param \OCP\Files\Folder $primaryFolder Folder to search first.
     * @param string $uniqueId The page-… uniqueId to locate.
     * @return array|null findPageByUniqueId() result, or null when unknown.
     */
    private function locatePageAnyLanguage(\OCP\Files\Folder $primaryFolder, string $uniqueId): ?array {
        // Ask the index first. The walk below reads and JSON-parses every page
        // file in every language folder — measured at 9,000 reads for a miss on
        // a 3,000-page x 3-language install — where this is one query.
        //
        // The index is a cache over the filesystem, never an authority: a hit
        // is verified against disk by resolveIndexedPage(), and anything that
        // does not check out falls through to the walk. A stale or empty index
        // therefore costs performance, never correctness, which is what makes
        // it safe to rely on before every write path is proven to maintain it.
        $indexed = $this->locateViaIndex($uniqueId, $primaryFolder);
        if ($indexed !== null) {
            return $indexed;
        }

        // A MISS still costs the full scan, and that is deliberate. The index
        // cannot prove a page does not exist — only that it does not know of
        // one — so treating "not in the index" as "not found" would turn a
        // stale index from a slowdown into pages that vanish. Correctness wins
        // over the cost of the path that answers "no".
        //
        // If that cost ever needs removing, the fix is a completeness marker
        // (a rebuild stamp the write paths keep current), not skipping the scan
        // on the strength of the index being non-empty.
        return $this->locateAcrossLanguages(
            $primaryFolder,
            fn(\OCP\Files\Folder $f) => $this->findPageByUniqueId($f, $uniqueId)
        );
    }

    /**
     * Resolve a uniqueId through the page index, verified against disk.
     *
     * Returns the same shape as findPageByUniqueId() — ['file', 'folder',
     * 'isHome'] — so callers cannot tell which route answered.
     *
     * @return array|null null when the index does not know the id, or when
     *   what it points at no longer matches the file on disk.
     */
    private function locateViaIndex(string $uniqueId, \OCP\Files\Folder $primaryFolder): ?array {
        try {
            $row = $this->pageIndexService->findByUniqueId(
                $uniqueId,
                $this->languageOfFolder($primaryFolder)
            );
            if ($row === null || empty($row['path'])) {
                return null;
            }

            $folder = $this->folderFromAbsolutePath((string)$row['path']);
            if ($folder === null) {
                return null;
            }

            // The index stores what findPageByUniqueId() calls 'folder': the
            // page's OWN {slug}/ folder. The page JSON is {slug}.json, which
            // sits BESIDE that folder in its parent — not inside it. The home
            // page is the exception: its folder IS the language folder and its
            // file (home.json) really is inside.
            $name = $folder->getName();
            $candidates = [];
            try {
                $candidates[] = [$folder->getParent(), $name . '.json', false];
            } catch (\Throwable $e) {
                // No reachable parent (mount root); the in-folder forms below
                // still apply.
            }
            $candidates[] = [$folder, 'home.json', true];
            // Legacy/flat layouts keep {slug}.json inside its own folder.
            $candidates[] = [$folder, $name . '.json', false];

            foreach ($candidates as [$container, $fileName, $isHome]) {
                if (!($container instanceof \OCP\Files\Folder)) {
                    continue;
                }
                try {
                    $file = $container->get($fileName);
                } catch (NotFoundException $e) {
                    continue;
                }
                if (!($file instanceof \OCP\Files\File)) {
                    continue;
                }

                // Verify: the file must really carry this uniqueId. This is
                // what makes a stale index harmless — a page that moved, was
                // deleted, or was overwritten simply fails the check and the
                // caller falls back to the filesystem walk.
                $data = json_decode($this->getCachedFileContent($file), true);
                if (!is_array($data) || ($data['uniqueId'] ?? null) !== $uniqueId) {
                    continue;
                }

                return [
                    'file' => $file,
                    'folder' => $folder,
                    'isHome' => $isHome,
                ];
            }

            return null;
        } catch (\Throwable $e) {
            // Never let an index problem break a read.
            $this->logger->warning('[PageService] index lookup failed, falling back to scan', [
                'uniqueId' => $uniqueId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Resolve an absolute Nextcloud path (as stored in the index) to a Folder
     * inside the current user's IntraVox tree.
     *
     * Deliberately resolves relative to the user's own mounted IntraVox folder
     * rather than the raw path, so the index can never hand a user a folder
     * their mount does not grant them.
     */
    private function folderFromAbsolutePath(string $absolutePath): ?\OCP\Files\Folder {
        $base = $this->getIntraVoxFolder();
        $relative = $this->indexPathToRelative($absolutePath);
        if ($relative === null) {
            return null;
        }
        if ($relative === '') {
            return $base;
        }

        try {
            $node = $base->get($relative);
        } catch (NotFoundException $e) {
            return null;
        }
        return $node instanceof \OCP\Files\Folder ? $node : null;
    }

    /**
     * Normalise a stored index path to a path relative to the IntraVox root.
     *
     * The index is shared by every user, but a Nextcloud path is per-user:
     * the same page is /admin/files/IntraVox/en/about for one account and
     * /Rik/files/IntraVox/en/about for another. Matching a stored path against
     * the CURRENT user's mount therefore failed for everyone except the account
     * that happened to write the row — listPages() returned zero pages and the
     * app showed its first-run welcome screen on a fully populated intranet.
     *
     * Rows written before this fix still hold a per-user absolute path, so both
     * forms are accepted: anything up to and including an `IntraVox/` segment is
     * stripped, and what remains is resolved against the caller's own mount.
     * That keeps the resolution mount-scoped — a row can never hand a user a
     * folder their mount does not grant them — while surviving a stale index.
     *
     * @return string|null relative path ('' for the root), or null when the
     *   path does not sit inside an IntraVox tree at all
     */
    private function indexPathToRelative(string $storedPath): ?string {
        $path = trim($storedPath, '/');
        if ($path === '') {
            return null;
        }

        $base = rtrim($this->getIntraVoxFolder()->getPath(), '/');
        $basePath = trim($base, '/');

        // Fast path: written by this same user (or already relative).
        if ($basePath !== '' && $path === $basePath) {
            return '';
        }
        if ($basePath !== '' && strpos($path, $basePath . '/') === 0) {
            return substr($path, strlen($basePath) + 1);
        }

        // Another user's absolute path, or a legacy row: keep everything after
        // the LAST 'IntraVox' segment, which is the app-root marker in every
        // form of the path.
        $segments = explode('/', $path);
        $rootIndex = null;
        foreach ($segments as $i => $segment) {
            if ($segment === 'IntraVox') {
                $rootIndex = $i;
            }
        }
        if ($rootIndex === null) {
            // No IntraVox segment. Since 2.0 the index stores paths RELATIVE to
            // the app root ('en/about'), which is exactly this shape — so treat
            // it as already relative rather than refusing it. Refusing here made
            // every lookup fail the moment paths were stored relatively.
            //
            // A path that is neither relative nor inside an IntraVox tree simply
            // will not resolve against the caller's mount below, which is the
            // safe outcome: resolution stays mount-scoped either way.
            return $path;
        }

        return implode('/', array_slice($segments, $rootIndex + 1));
    }

    /**
     * Same cross-language walk for a legacy slug id (e.g. "about"), so a slug
     * link resolves wherever the page lives — matching uniqueId links.
     * Only reached after the primary folder came up empty.
     */
    private function locatePageBySlugAnyLanguage(\OCP\Files\Folder $primaryFolder, string $id): ?array {
        return $this->locateAcrossLanguages(
            $primaryFolder,
            fn(\OCP\Files\Folder $f) => $this->findPageById($f, $id)
        );
    }

    /**
     * Run $find against $primaryFolder first, then against every other language
     * folder on disk. Shared by the uniqueId and slug locators.
     *
     * @param callable(\OCP\Files\Folder): ?array $find
     */
    private function locateAcrossLanguages(\OCP\Files\Folder $primaryFolder, callable $find): ?array {
        $result = $find($primaryFolder);
        if ($result !== null) {
            return $result;
        }

        // Scan the remaining language folders that actually exist on disk,
        // rather than an opt-in list, so content in any language (e.g. 'da')
        // stays reachable. Skip the folder we just searched — comparing paths
        // rather than language codes, so the folder that was really searched is
        // the one that is really skipped.
        $baseFolder = $this->getIntraVoxFolder();
        $searchedPath = $primaryFolder->getPath();

        foreach ($this->getCachedDirectoryListing($baseFolder) as $item) {
            if ($item->getType() !== \OCP\Files\FileInfo::TYPE_FOLDER
                || !($item instanceof \OCP\Files\Folder)) {
                continue;
            }
            // Language folders are two/three-letter base codes.
            if (!preg_match('/^[a-z]{2,3}$/', $item->getName())
                || $item->getPath() === $searchedPath) {
                continue;
            }
            $result = $find($item);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Locate a page for a MEDIA operation, and report which language folder it
     * turned out to live in.
     *
     * Media resolution used to start from a language folder chosen for the
     * USER — getLanguageFolder() (the profile language) on the write paths,
     * getReadLanguageFolder() (own → recommended → en, #75) on the list path —
     * and then look for the page only there. Both are the wrong question. A
     * page's media lives next to the page, so the only folder that matters is
     * the one holding the page itself.
     *
     * When the two disagreed, every media operation failed on a page that was
     * plainly on screen: uploads threw "Page not found" while the very same
     * request had already passed its permission check through the
     * cross-language getPage(), listings came back empty so the Shared Library
     * showed names without previews, and thumbnails 404'd (issue #92). This is
     * the same read/write asymmetry #90 fixed for pages, applied to the media
     * cluster that #90 did not reach.
     *
     * Returns the language folder alongside the page so callers can resolve
     * `_media` / `_resources` for the HOME page and for the resources library
     * in that same language, instead of falling back to the user's own.
     *
     * @param string $pageId uniqueId (page-…) or legacy slug id.
     * @return array{result: array, languageFolder: \OCP\Files\Folder}|null
     *   null when the page exists in no language folder at all.
     */
    private function locatePageForMedia(string $pageId): ?array {
        $primary = $this->getReadLanguageFolder();

        $find = function (\OCP\Files\Folder $folder) use ($pageId): ?array {
            if (strpos($pageId, 'page-') === 0) {
                $byUniqueId = $this->findPageByUniqueId($folder, $pageId);
                if ($byUniqueId !== null) {
                    return $byUniqueId;
                }
            }
            // Legacy slug ids (and uniqueIds that predate the page- prefix)
            // stay resolvable, matching the fallback the callers already had.
            return $this->findPageById($folder, $this->sanitizeId($pageId));
        };

        $result = $this->locateAcrossLanguages($primary, $find);
        if ($result === null) {
            return null;
        }

        return [
            'result' => $result,
            'languageFolder' => $this->languageFolderOfPageResult($result) ?? $primary,
        ];
    }

    /**
     * The language content folder that a findPageByUniqueId()/findPageById()
     * result sits in, derived from the page folder's own path.
     *
     * Walks up from the page folder to the language folder rather than trusting
     * the folder the search STARTED from — after a cross-language hit those are
     * not the same, and it is the page's own language that owns its media.
     *
     * @return \OCP\Files\Folder|null null when the path cannot be resolved, in
     *   which case callers fall back to the folder they searched from.
     */
    private function languageFolderOfPageResult(array $result): ?\OCP\Files\Folder {
        $folder = $result['folder'] ?? null;
        if (!($folder instanceof \OCP\Files\Folder)) {
            return null;
        }

        // The home page's "folder" IS the language folder; deeper pages sit
        // somewhere below it. languageOfFolder() names the language either way.
        $language = $this->languageOfFolder($folder);
        if ($language === null) {
            return null;
        }

        try {
            $candidate = $this->getIntraVoxFolder()->get($language);
            return $candidate instanceof \OCP\Files\Folder ? $candidate : null;
        } catch (NotFoundException $e) {
            return null;
        }
    }

    /**
     * Locate an existing page by uniqueId OR legacy slug, across every language
     * folder. The plain "find this page, wherever and however it is addressed"
     * lookup.
     *
     * Several operations each open-coded a subset of this and got a different
     * subset wrong: some tried the uniqueId branch but not the slug branch,
     * some (updateVersionLabel, getCurrentPageContent) had no uniqueId branch at
     * all and so failed on every modern page-… id, and none of them looked
     * outside the caller's own language. Routing them through one helper is what
     * stops that drift.
     *
     * Read-only resolution: callers that write still check permissions on the
     * node they get back.
     *
     * @return array|null findPageByUniqueId()/findPageById() result, or null.
     */
    private function locatePageForOperation(string $pageId): ?array {
        $folder = $this->getReadLanguageFolder();

        if (strpos($pageId, 'page-') === 0) {
            $byUniqueId = $this->locatePageAnyLanguage($folder, $pageId);
            if ($byUniqueId !== null) {
                return $byUniqueId;
            }
        }

        return $this->locatePageBySlugAnyLanguage($folder, $this->sanitizeId($pageId));
    }

    /**
     * Human-readable name for a language code ('en' -> 'English'), for messages
     * a user reads. Falls back to the uppercased code when the name is unknown,
     * so an exotic content folder still produces "EO" rather than nothing.
     *
     * Reuses LanguageService::getAvailableLanguages(), the same source the
     * admin Languages tab and the fallback notice display.
     */
    private function languageDisplayName(string $code): string {
        try {
            foreach ($this->languageService->getAvailableLanguages() as $lang) {
                if (($lang['code'] ?? '') === $code) {
                    $name = $lang['name'] ?? '';
                    if ($name === '') {
                        return strtoupper($code);
                    }
                    // Nextcloud's names describe INTERFACE translations and
                    // carry variant suffixes ('English (US)', 'Deutsch
                    // (Persönlich: Du)'). A content folder is a plain code, so
                    // drop the parenthesised part — "this page is in Deutsch
                    // (Persönlich: Du)" is nonsense to a reader.
                    $base = trim(explode('(', $name)[0]);
                    return $base !== '' ? $base : $name;
                }
            }
        } catch (\Throwable $e) {
            // Naming is cosmetic; never let it break the operation's real error.
        }
        return strtoupper($code);
    }

    /**
     * $parent->get($name) as a Folder, or null when it is missing or is a file.
     * Saves the repeated try/catch around optional `_media` / `_resources`
     * lookups on paths that treat "absent" as an ordinary outcome.
     */
    private function folderOrNull(?\OCP\Files\Folder $parent, string $name): ?\OCP\Files\Folder {
        if ($parent === null) {
            return null;
        }
        try {
            $node = $parent->get($name);
            return $node instanceof \OCP\Files\Folder ? $node : null;
        } catch (NotFoundException $e) {
            return null;
        }
    }

    /**
     * Get language folder by language code
     */
    private function getLanguageFolderByCode(string $lang) {
        $baseFolder = $this->getIntraVoxFolder();

        try {
            return $baseFolder->get($lang);
        } catch (NotFoundException $e) {
            // If language folder doesn't exist, try default language
            if ($lang !== self::DEFAULT_LANGUAGE) {
                try {
                    return $baseFolder->get(self::DEFAULT_LANGUAGE);
                } catch (NotFoundException $e2) {
                    // Create default language folder if it doesn't exist
                    return $baseFolder->newFolder(self::DEFAULT_LANGUAGE);
                }
            }
            // Create the requested language folder
            return $baseFolder->newFolder($lang);
        }
    }

    /**
     * Get the IntraVox folder from user's perspective (mounted GroupFolder)
     *
     * IMPORTANT: Uses the user's mounted folder view to respect GroupFolder ACL
     * This is essential for non-admin users to access the IntraVox folder
     *
     * `protected` (like getLanguageFolder) only to give unit tests a seam for
     * the mounted-folder lookup; no runtime behaviour depends on it.
     */
    protected function getIntraVoxFolder() {
        if (!$this->userId) {
            throw new \Exception('User not logged in');
        }

        // Get user's folder (this respects GroupFolder ACL)
        $userFolder = $this->rootFolder->getUserFolder($this->userId);

        // Get folder from user's perspective (mounted GroupFolder)
        try {
            return $userFolder->get('IntraVox');
        } catch (NotFoundException $e) {
            throw new \Exception("IntraVox folder not found. Please check that you have access to the IntraVox GroupFolder.");
        }
    }

    /**
     * Get permissions for a folder path (relative to IntraVox root)
     * Uses Nextcloud's native filesystem permissions which respect GroupFolder ACL
     *
     * IMPORTANT: Uses the user's mounted folder view to get ACL-aware permissions
     *
     * @param string $relativePath Path relative to IntraVox folder (e.g., "en/about" or "")
     * @return array Permissions object with canRead, canWrite, canCreate, canDelete, canShare
     */
    public function getFolderPermissions(string $relativePath): array {
        try {
            if (!$this->userId) {
                return [
                    'canRead' => false,
                    'canWrite' => false,
                    'canCreate' => false,
                    'canDelete' => false,
                    'canShare' => false,
                    'raw' => 0
                ];
            }

            // Get user's folder (this respects GroupFolder ACL)
            $userFolder = $this->rootFolder->getUserFolder($this->userId);

            // Get IntraVox folder from user's perspective (mounted GroupFolder)
            $intraVoxPath = 'IntraVox';
            if (!empty($relativePath)) {
                $intraVoxPath .= '/' . ltrim($relativePath, '/');
            }

            $folder = $userFolder->get($intraVoxPath);
            return $this->permissionsFromNode($folder);
        } catch (\Exception $e) {
            // If folder doesn't exist, return no permissions
            $this->logger->debug('getFolderPermissions failed for path: ' . $relativePath . ' - ' . $e->getMessage());
            return [
                'canRead' => false,
                'canWrite' => false,
                'canCreate' => false,
                'canDelete' => false,
                'canShare' => false,
                'raw' => 0
            ];
        }
    }

    /**
     * Check if a page ID already exists (recursively through all folders)
     */
    private function pageIdExists(string $id): bool {
        $folder = $this->getLanguageFolder();
        return $this->findPageById($folder, $id) !== null;
    }

    /**
     * Public method to check if a page exists by uniqueId
     * Used by CommentsEntityListener to validate comment objectIds
     */
    public function pageExistsByUniqueId(string $uniqueId): bool {
        try {
            $folder = $this->getReadLanguageFolder();
            return $this->findPageByUniqueId($folder, $uniqueId) !== null;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Recursively find a page by uniqueId
     */
    private function findPageByUniqueId($folder, string $uniqueId, $languageFolder = null): ?array {
        // Track the language folder for isHome detection
        if ($languageFolder === null) {
            $languageFolder = $folder;
        }

        // Check for home.json first (most common case for homepage)
        try {
            $homeFile = $folder->get('home.json');
            $content = $homeFile->getContent();
            $data = json_decode($content, true);
            if ($data && isset($data['uniqueId']) && $data['uniqueId'] === $uniqueId) {
                return ['file' => $homeFile, 'folder' => $folder, 'isHome' => true];
            }
        } catch (NotFoundException $e) {
            // home.json doesn't exist here, continue searching
        }

        // Get directory listing ONCE (cached) and separate files from folders
        $isLanguageRoot = ($folder->getPath() === $languageFolder->getPath());
        $items = $this->getCachedDirectoryListing($folder);
        $subfolderItems = [];

        // FIRST: Check all JSON files in current folder
        foreach ($items as $item) {
            $itemName = $item->getName();

            // Collect subfolders for later recursive search
            if ($item->getType() === \OCP\Files\FileInfo::TYPE_FOLDER) {
                // Skip media folders and special folders
                if ($itemName !== '_media' && $itemName !== 'images' && $itemName !== '.nomedia') {
                    $subfolderItems[] = $item;
                }
                continue;
            }

            // Skip navigation.json and footer.json only in the language root folder
            // (these are config files there, not pages). In subfolders they can be page files.
            $skipFile = ($itemName === 'home.json'); // Always skip home.json, checked above
            if ($isLanguageRoot && ($itemName === 'navigation.json' || $itemName === 'footer.json' || $itemName === 'homepage.json')) {
                $skipFile = true;
            }

            if (substr($itemName, -5) === '.json' && !$skipFile) {
                try {
                    $content = $this->getCachedFileContent($item);
                    $data = json_decode($content, true);
                    if ($data && isset($data['uniqueId']) && $data['uniqueId'] === $uniqueId) {
                        // Determine the correct folder:
                        // If there's a matching subfolder (e.g., company-blog folder for company-blog.json),
                        // return that subfolder. Otherwise return current folder.
                        $baseName = substr($itemName, 0, -5); // Remove .json extension
                        try {
                            $matchingFolder = $folder->get($baseName);
                            if ($matchingFolder->getType() === \OCP\Files\FileInfo::TYPE_FOLDER) {
                                return ['file' => $item, 'folder' => $matchingFolder, 'isHome' => false];
                            }
                        } catch (NotFoundException $e) {
                            // No matching folder, use current folder
                        }
                        return ['file' => $item, 'folder' => $folder, 'isHome' => false];
                    }
                } catch (\Exception $e) {
                    // Skip invalid files
                    continue;
                }
            }
        }

        // SECOND: Recursively search subfolders (already collected above)
        foreach ($subfolderItems as $subfolder) {
            $result = $this->findPageByUniqueId($subfolder, $uniqueId, $languageFolder);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Recursively find a page by ID (legacy support - keep for backward compatibility)
     */
    private function findPageById($folder, string $id): ?array {
        // Check root for home.json
        if ($id === 'home') {
            try {
                $file = $folder->get('home.json');
                return ['file' => $file, 'folder' => $folder];
            } catch (NotFoundException $e) {
                // Continue searching
            }
        }

        // Check if there's a folder with this ID
        try {
            $pageFolder = $folder->get($id);
            if ($pageFolder->getType() === \OCP\Files\FileInfo::TYPE_FOLDER) {
                $jsonFile = $pageFolder->get($id . '.json');
                return ['file' => $jsonFile, 'folder' => $pageFolder];
            }
        } catch (NotFoundException $e) {
            // Continue searching
        }

        // Recursively search subfolders
        foreach ($this->getCachedDirectoryListing($folder) as $item) {
            if ($item->getType() === \OCP\Files\FileInfo::TYPE_FOLDER) {
                $result = $this->findPageById($item, $id);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        return null;
    }

    /**
     * List all pages (recursively)
     */
    public function listPages(): array {
        $folder = $this->getReadLanguageFolder();

        // Titles and statuses come from the index when it has this language,
        // which removes the read + json_decode of every page file. Permissions
        // still come from the filesystem: they depend on GroupFolder ACLs and
        // on who is asking, so they are not derivable from an index row and
        // must never be cached across users.
        $indexed = $this->listPagesFromIndex($folder);
        if ($indexed !== null) {
            return $indexed;
        }

        $intraVoxFolder = $this->getIntraVoxFolder();
        $pages = [];

        // Get base path for relative path calculation
        $basePath = $intraVoxFolder->getPath();

        // Check for home.json in root
        try {
            $homeFile = $folder->get('home.json');
            $content = $homeFile->getContent();
            $data = json_decode($content, true);

            if ($data && isset($data['uniqueId'], $data['title'])) {
                // Calculate relative path from IntraVox root
                $relativePath = substr($folder->getPath(), strlen($basePath) + 1);

                $pages[] = [
                    'uniqueId' => $data['uniqueId'],
                    'title' => $data['title'],
                    'modified' => $data['modified'] ?? $homeFile->getMTime(),
                    'status' => $data['status'] ?? 'published',
                    'permissions' => $this->permissionsFromNode($folder)
                ];
            }
        } catch (NotFoundException $e) {
            // No home page yet
        }

        // Recursively find all pages in subfolders
        $this->findPagesInFolder($folder, $pages, $basePath);

        return $pages;
    }

    /**
     * Link two pages as language versions of each other.
     *
     * Both pages end up sharing one translation group. Symmetric by design:
     * neither becomes the "source", so removing either one later shrinks the
     * group instead of orphaning the other — the failure mode that leaves
     * SharePoint's source-pointer model with dangling references.
     *
     * Refuses to link two pages in the SAME language: a group holds at most one
     * page per language, and allowing a second would make "the German version"
     * ambiguous for the switcher and the reader notice alike.
     *
     * When either page is already linked, the existing group wins and the other
     * page joins it, so linking A→B and later B→C leaves all three together
     * rather than splitting into two pairs.
     *
     * @throws PageNotFoundException when either page cannot be found
     * @throws \InvalidArgumentException when both pages share a language
     */
    public function linkTranslation(string $uniqueIdA, string $uniqueIdB): string {
        if ($uniqueIdA === $uniqueIdB) {
            throw new \InvalidArgumentException('A page cannot be a translation of itself');
        }

        $folder = $this->getReadLanguageFolder();
        $a = $this->locatePageAnyLanguage($folder, $uniqueIdA);
        $b = $this->locatePageAnyLanguage($folder, $uniqueIdB);
        if ($a === null) {
            throw new PageNotFoundException('Page not found: ' . $uniqueIdA);
        }
        if ($b === null) {
            throw new PageNotFoundException('Page not found: ' . $uniqueIdB);
        }

        $langA = $this->languageOfFolder($a['folder']);
        $langB = $this->languageOfFolder($b['folder']);
        if ($langA !== null && $langA === $langB) {
            throw new \InvalidArgumentException(
                'These pages are both in the same language, so one cannot be a translation of the other.'
            );
        }

        // BOTH sides must be writable before either is written. The order
        // matters more than it looks: the group is adopted from whichever side
        // already has one, so writing A first and then failing on B would leave
        // A a member of B's existing group — a link B's editors never made,
        // created by someone without write access to B. Checking up front makes
        // denial happen before any state changes.
        foreach ([$a, $b] as $side) {
            if (!$side['file']->isUpdateable()) {
                throw new ForbiddenException('You need edit permission on both pages to link them');
            }
        }

        // Adopt an existing group when there is one, so linking is additive.
        $dataA = json_decode($a['file']->getContent(), true);
        $dataB = json_decode($b['file']->getContent(), true);
        $group = (is_array($dataA) ? ($dataA['translationGroup'] ?? null) : null)
            ?: (is_array($dataB) ? ($dataB['translationGroup'] ?? null) : null)
            ?: 'tg-' . $this->generateUUID();

        $this->writeTranslationGroup($a, $group);
        $this->writeTranslationGroup($b, $group);
        $this->clearCache();

        return $group;
    }

    /**
     * Create this page in another language and link the two.
     *
     * The entry point an editor actually wants: "make this page in German".
     * Linking two pages that already exist is the rarer case — normally the
     * other version does not exist yet, and asking an editor to first create a
     * blank page elsewhere, find it, and then link it is the workflow every
     * mature CMS avoids. SharePoint's Translation button, Drupal's Translate
     * tab and WPML's "+" all do exactly this in one step.
     *
     * The copy is a STARTING POINT, not a synchronised mirror: content is
     * copied once, and from then on the two pages are independent. The German
     * page may gain a widget the English one does not have. WPML's translation
     * editor enforces structural parity and overwrites a diverging layout;
     * Polylang free starts from a blank page and makes the editor rebuild it.
     * This is the middle neither offers.
     *
     * Lands as a DRAFT: a machine-made copy in the wrong language is not
     * something readers should meet before an editor has been through it.
     *
     * @param string $sourceUniqueId page to translate
     * @param string $language target language code
     * @param string|null $title title for the new page (defaults to the source's)
     * @return array the created page
     * @throws PageNotFoundException when the source does not exist
     * @throws \InvalidArgumentException when the target language is invalid,
     *   is the source's own, or already holds a version of this page
     */
    public function createTranslation(
        string $sourceUniqueId,
        string $language,
        ?string $title = null
    ): array {
        if (!preg_match('/^[a-z]{2,3}$/', $language)) {
            throw new \InvalidArgumentException('Invalid language code: ' . $language);
        }

        $source = $this->locatePageAnyLanguage($this->getReadLanguageFolder(), $sourceUniqueId);
        if ($source === null || !isset($source['file'])) {
            throw new PageNotFoundException('Page not found: ' . $sourceUniqueId);
        }

        $sourceLanguage = $this->languageOfFolder($source['folder']);
        if ($sourceLanguage === $language) {
            throw new \InvalidArgumentException(
                'This page is already in that language.'
            );
        }

        $sourceData = json_decode($source['file']->getContent(), true);
        if (!is_array($sourceData)) {
            throw new \InvalidArgumentException('Could not read the source page');
        }

        // One page per language per group — refuse rather than create a second
        // German version that would make the switcher ambiguous.
        $group = $sourceData['translationGroup'] ?? null;
        if (!empty($group)) {
            foreach ($this->pageIndexService->findByTranslationGroup($group) as $row) {
                if (($row['language'] ?? null) === $language) {
                    throw new \InvalidArgumentException(
                        'A version of this page already exists in that language.'
                    );
                }
            }
        }

        // The target language folder must exist; creating one silently would
        // add a language to the intranet as a side effect of translating.
        try {
            $targetFolder = $this->getIntraVoxFolder()->get($language);
        } catch (NotFoundException $e) {
            throw new \InvalidArgumentException(
                'That language has no content folder yet. Add the language in the admin settings first.'
            );
        }
        if (!($targetFolder instanceof \OCP\Files\Folder)) {
            throw new \InvalidArgumentException('Invalid language folder: ' . $language);
        }
        if (!$targetFolder->isCreatable()) {
            throw new ForbiddenException('You do not have permission to create a page in that language');
        }

        // Assign the group up front so both sides land linked in one write
        // each, rather than being linked afterwards as a second step that
        // could half-fail.
        //
        // Known half-state: if createPage() below fails, the SOURCE keeps this
        // fresh group as its only member. That is harmless by construction —
        // resolveTranslations() excludes the page itself, so a singleton group
        // renders nothing — and the next successful link or unlink rewrites it.
        if (empty($group)) {
            $group = 'tg-' . $this->generateUUID();
            $this->writeTranslationGroup($source, $group);
        }

        $pageData = $sourceData;
        unset($pageData['order']);
        $baseTitle = $this->decodeHtmlEntitiesRecursive((string)($sourceData['title'] ?? 'Untitled'));
        $pageData['title'] = ($title !== null && $title !== '') ? $title : $baseTitle;
        $pageData['id'] = $this->sanitizeId($pageData['title']);
        $pageData['uniqueId'] = 'page-' . $this->generateUUID();
        $pageData['translationGroup'] = $group;
        // Draft: an untranslated copy is not something readers should meet.
        $pageData['status'] = 'draft';
        $pageData['created'] = time();
        $pageData['modified'] = time();

        // Mirror the source's position within its own language tree, so the
        // German page sits where the English one does rather than at the root.
        $sourceRelative = $this->getRelativePathFromRoot($source['folder']);
        $sourceParent = dirname($sourceRelative);
        $parentPath = $language;
        if ($sourceParent !== '.' && $sourceParent !== '') {
            $segments = explode('/', $sourceParent);
            // Swap the language segment for the target language; the rest of
            // the path only exists in the target tree if the parents were
            // translated too, and getOrCreateFolderPath() creates what is missing.
            array_shift($segments);
            $parentPath = $language . (empty($segments) ? '' : '/' . implode('/', $segments));
        }

        $created = $this->createPage($pageData, $parentPath);

        // A translation starts as a copy of the source, so it needs the
        // source's images too — the same way copyPage does it. Without this the
        // text carried over but every image 404'd, because the JSON stores bare
        // file names that resolve against the page being viewed.
        $this->copyPageMedia($source['folder'] ?? null, $created['uniqueId'], 'createTranslation');

        $this->clearCache();

        return $created;
    }

    /**
     * Languages this page could still be created in.
     *
     * A language qualifies when it has a content folder, is not the page's own,
     * and does not already hold a version of this page. Offering anything else
     * would produce a control that fails when used.
     *
     * @return array<int, array{code:string, name:string}>
     */
    public function getTranslatableLanguages(string $pageId): array {
        $result = $this->locatePageAnyLanguage($this->getReadLanguageFolder(), $pageId);
        if ($result === null) {
            throw new PageNotFoundException('Page not found: ' . $pageId);
        }

        $ownLanguage = $this->languageOfFolder($result['folder']);
        $data = json_decode($result['file']->getContent(), true);
        $group = is_array($data) ? ($data['translationGroup'] ?? null) : null;

        $taken = [];
        if (!empty($group)) {
            foreach ($this->pageIndexService->findByTranslationGroup($group) as $row) {
                if (!empty($row['language'])) {
                    $taken[(string)$row['language']] = true;
                }
            }
        }

        $languages = [];
        foreach ($this->getCachedDirectoryListing($this->getIntraVoxFolder()) as $node) {
            if (!($node instanceof \OCP\Files\Folder)) {
                continue;
            }
            $code = $node->getName();
            if (!preg_match('/^[a-z]{2,3}$/', $code)) {
                continue;
            }
            if ($code === $ownLanguage || isset($taken[$code])) {
                continue;
            }
            $languages[] = [
                'code' => $code,
                'name' => $this->languageDisplayName($code),
            ];
        }

        return $languages;
    }

    /**
     * Pages this page could be linked to as a translation.
     *
     * Excludes three sets, each for a reason:
     *   - the page's own language, since a group holds one page per language;
     *   - pages already in a group with something else, so linking cannot
     *     silently steal a page out of an existing set;
     *   - the page itself.
     *
     * Answered from the index, so the picker stays cheap on a large intranet.
     *
     * @param string|null $language limit to one language, or null for all others
     * @return array<int, array{uniqueId:string, title:string, language:string}>
     */
    public function getTranslationCandidates(string $pageId, ?string $language = null): array {
        $folder = $this->getReadLanguageFolder();
        $result = $this->locatePageAnyLanguage($folder, $pageId);
        if ($result === null) {
            throw new PageNotFoundException('Page not found: ' . $pageId);
        }

        $ownLanguage = $this->languageOfFolder($result['folder']);
        $ownData = json_decode($result['file']->getContent(), true);
        $ownGroup = is_array($ownData) ? ($ownData['translationGroup'] ?? null) : null;

        // Languages to offer: everything with content except this page's own.
        //
        // ACL boundary: this listing goes through the caller's OWN mount, so a
        // language folder their ACLs deny never appears — language-level access
        // is enforced here for free. Deliberately NOT re-checked per candidate
        // row below: that would cost one filecache lookup per page in the
        // language (hundreds+), for an editor-facing dialog. The accepted bound
        // is that ACLs on a SUBFOLDER within a readable language can still
        // surface a title here.
        $languages = [];
        foreach ($this->getCachedDirectoryListing($this->getIntraVoxFolder()) as $node) {
            if (!($node instanceof \OCP\Files\Folder)) {
                continue;
            }
            $code = $node->getName();
            if (!preg_match('/^[a-z]{2,3}$/', $code) || $code === $ownLanguage) {
                continue;
            }
            if ($language !== null && $code !== $language) {
                continue;
            }
            $languages[] = $code;
        }

        $candidates = [];
        foreach ($languages as $code) {
            foreach ($this->pageIndexService->getPagesByLanguage($code) as $row) {
                $uniqueId = (string)($row['unique_id'] ?? '');
                if ($uniqueId === '' || $uniqueId === $pageId) {
                    continue;
                }

                // Already linked to something else — offering it would mean
                // silently removing it from that group.
                $group = $row['translation_group'] ?? null;
                if (!empty($group) && $group !== $ownGroup && $this->groupHasOtherMembers($group, $uniqueId)) {
                    continue;
                }

                $candidates[] = [
                    'uniqueId' => $uniqueId,
                    'title' => (string)($row['title'] ?? ''),
                    'language' => $code,
                ];
            }
        }

        return $candidates;
    }

    /** Whether a translation group holds anyone besides $uniqueId. */
    private function groupHasOtherMembers(string $group, string $uniqueId): bool {
        foreach ($this->pageIndexService->findByTranslationGroup($group) as $row) {
            if (($row['unique_id'] ?? null) !== $uniqueId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Detach a page from its translation group.
     *
     * The page gets a fresh group of its own rather than none at all, so
     * "linked" and "unlinked" stay the same shape and the page can be linked
     * again later without a special case.
     *
     * Only ever touches the page asked for. WPML shipped a bug where an update
     * silently re-linked translations an editor had deliberately unlinked;
     * nothing here infers a relationship from similarity.
     *
     * @throws PageNotFoundException when the page cannot be found
     */
    public function unlinkTranslation(string $uniqueId): string {
        $folder = $this->getReadLanguageFolder();
        $result = $this->locatePageAnyLanguage($folder, $uniqueId);
        if ($result === null) {
            throw new PageNotFoundException('Page not found: ' . $uniqueId);
        }

        $group = 'tg-' . $this->generateUUID();
        $this->writeTranslationGroup($result, $group);
        $this->clearCache();

        return $group;
    }

    /**
     * Write a translation group into a page file and its index row.
     *
     * @param array $result findPageByUniqueId()-shaped result
     */
    private function writeTranslationGroup(array $result, string $group): void {
        $file = $result['file'];
        if (!$file->isUpdateable()) {
            throw new ForbiddenException('You do not have permission to edit this page');
        }

        $data = json_decode($file->getContent(), true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Page data could not be read');
        }
        $data['translationGroup'] = $group;
        $file->putContent(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Keep the index in step; the file is already written, so a failure
        // here is non-blocking and `occ intravox:reindex` repairs it.
        try {
            $language = $this->languageOfFolder($result['folder']) ?? $this->getUserLanguage();
            $this->pageIndexService->indexPage(
                $data,
                $language,
                $result['folder']->getPath(),
                $file->getId()
            );
        } catch (\Exception $e) {
            $this->logger->warning('Failed to index translation group', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The other language versions of a page, from its translation group.
     *
     * Answered entirely from the index — one lookup, no tree walk — which is
     * what makes it cheap enough to attach to every page render.
     *
     * Returns [] rather than throwing on any problem: this decorates a page,
     * and a missing switcher is a far smaller failure than a page that will
     * not load. Also returns [] for a page with no group, which is the normal
     * state of every page that is not linked to another language.
     *
     * @return array<int, array{language:string, uniqueId:string, title:string, status:string}>
     */
    private function resolveTranslations(?string $translationGroup, ?string $ownUniqueId): array {
        if (empty($translationGroup)) {
            return [];
        }

        try {
            $rows = $this->pageIndexService->findByTranslationGroup($translationGroup);
        } catch (\Throwable $e) {
            $this->logger->warning('[PageService] translation lookup failed', [
                'translationGroup' => $translationGroup,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        $translations = [];
        foreach ($rows as $row) {
            // Skip the page itself — the list answers "where else".
            if ($ownUniqueId !== null && ($row['unique_id'] ?? null) === $ownUniqueId) {
                continue;
            }
            if (empty($row['language']) || empty($row['unique_id'])) {
                continue;
            }
            // The index is shared by every user, but readability is not:
            // GroupFolder ACLs can deny this caller the folder a group member
            // lives in, and its title/status must not leak past that. Resolve
            // the row's folder through the caller's OWN mount — same rule
            // listPagesFromIndex states for permissions — and skip what the
            // mount does not grant. Costs one filecache lookup per group
            // member, bounded by the number of languages, not pages.
            if ($this->folderFromAbsolutePath((string)($row['path'] ?? '')) === null) {
                continue;
            }
            $translations[] = [
                'language' => (string)$row['language'],
                'uniqueId' => (string)$row['unique_id'],
                'title' => (string)($row['title'] ?? ''),
                'status' => (string)($row['status'] ?? 'published'),
            ];
        }

        return $translations;
    }

    /**
     * Build the page list from the index instead of walking the tree.
     *
     * Returns null when the index cannot serve this language, so the caller
     * falls back to the filesystem walk. That is the whole safety story: the
     * index is a cache, and an empty or partial one costs a slow path, never a
     * short list. A page the index does not know about would otherwise silently
     * disappear from the sidebar — a far worse failure than being slow.
     *
     * Permissions are still read per page from the filesystem. They depend on
     * GroupFolder ACLs and on the current user, so an index row cannot carry
     * them and caching them across users would leak access.
     *
     * @return array|null the page list, or null to fall back to the walk
     */
    private function listPagesFromIndex(\OCP\Files\Folder $folder): ?array {
        $language = $this->languageOfFolder($folder);
        if ($language === null) {
            return null;
        }

        try {
            if (!$this->pageIndexService->hasEntries($language)) {
                return null;
            }
            $rows = $this->pageIndexService->getPagesByLanguage($language);
        } catch (\Throwable $e) {
            $this->logger->warning('[PageService] index listing failed, falling back to scan', [
                'language' => $language,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if (empty($rows)) {
            return null;
        }

        // The homepage must be in the list. It lives as home.json at the
        // language ROOT rather than in a page folder, and on real installs it
        // turns out not to reach the index at all — so serving the index list
        // as-is would silently drop the homepage from the sidebar. Rather than
        // depend on that ever being fixed upstream, verify it here and fall
        // back to the walk when it is missing: a slow, complete list beats a
        // fast one with a hole in it.
        $homeUniqueId = null;
        try {
            $homeFile = $folder->get('home.json');
            if ($homeFile instanceof \OCP\Files\File) {
                $homeData = json_decode($this->getCachedFileContent($homeFile), true);
                $homeUniqueId = is_array($homeData) ? ($homeData['uniqueId'] ?? null) : null;
            }
        } catch (NotFoundException $e) {
            // No loose homepage in this language; nothing to guarantee.
        }
        if ($homeUniqueId !== null) {
            $indexedIds = array_column($rows, 'unique_id');
            if (!in_array($homeUniqueId, $indexedIds, true)) {
                return null;
            }
        }

        $pages = [];
        foreach ($rows as $row) {
            if (empty($row['unique_id']) || empty($row['path'])) {
                continue;
            }

            // Resolve the page folder to read permissions from. A row pointing
            // at something the user cannot reach is skipped rather than served
            // without permissions — the same mount-scoped resolution the
            // uniqueId lookup uses, so the index can never widen access.
            $pageFolder = $this->folderFromAbsolutePath((string)$row['path']);
            if ($pageFolder === null) {
                continue;
            }

            $pages[] = [
                'uniqueId' => (string)$row['unique_id'],
                'title' => (string)($row['title'] ?? ''),
                'modified' => (int)($row['modified_at'] ?? 0),
                'status' => (string)($row['status'] ?? 'published'),
                'permissions' => $this->permissionsFromNode($pageFolder),
            ];
        }

        return $pages;
    }

    /**
     * Whether a language folder holds a REAL (editor-authored) homepage, as
     * opposed to an auto-generated placeholder or no homepage at all.
     *
     * A homepage counts as real when `home.json` exists, parses, and does NOT
     * carry the `_generated` marker written by LanguageHomepageService /
     * demo-data. The marker is dropped on the first editor save, so any edited
     * homepage reads as real. Homepages from installs predating the marker also
     * read as real (no marker present) — which is the safe, no-regression
     * default.
     */
    /**
     * Resolve the homepage JSON for a language folder regardless of storage form
     * (configurable homepage). Checks, in order:
     *   1. a `homepage.json` pointer → the designated root page's JSON;
     *   2. the legacy loose `home.json`;
     *   3. a normalized `home/home.json` folder page (post-normalization default).
     *
     * Returns the decoded page data array, or null when no homepage exists.
     */
    private function resolveLanguageHomepageData(\OCP\Files\Folder $langFolder): ?array {
        // 1. Pointer.
        try {
            if ($langFolder->nodeExists('homepage.json')) {
                $ptr = json_decode($langFolder->get('homepage.json')->getContent(), true);
                $uid = is_array($ptr) ? ($ptr['homepageUniqueId'] ?? null) : null;
                if (is_string($uid) && $uid !== '') {
                    $target = $this->findPageByUniqueId($langFolder, $uid);
                    if ($target !== null && isset($target['file'])) {
                        $data = json_decode($target['file']->getContent(), true);
                        if (is_array($data) && isset($data['title'])) {
                            return $data;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fall through to loose/normalized forms.
        }

        // 2. Legacy loose home.json.
        try {
            if ($langFolder->nodeExists('home.json')) {
                $data = json_decode($langFolder->get('home.json')->getContent(), true);
                if (is_array($data) && isset($data['title'])) {
                    return $data;
                }
            }
        } catch (\Throwable $e) {
            // Fall through.
        }

        // 3. Normalized home/home.json.
        try {
            if ($langFolder->nodeExists('home')) {
                $homeFolder = $langFolder->get('home');
                if ($homeFolder instanceof \OCP\Files\Folder && $homeFolder->nodeExists('home.json')) {
                    $data = json_decode($homeFolder->get('home.json')->getContent(), true);
                    if (is_array($data) && isset($data['title'])) {
                        return $data;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fall through.
        }

        return null;
    }

    private function languageFolderHasRealContent(\OCP\Files\Folder $langFolder): bool {
        $data = $this->resolveLanguageHomepageData($langFolder);
        if ($data === null) {
            return false;
        }
        return empty($data['_generated']);
    }

    /**
     * Whether a language folder has a homepage AT ALL — real OR an auto/placeholder
     * one (`_generated`). This is the "active language" signal: a language an admin
     * added via "Add language" has a placeholder homepage and should show up as an
     * active intranet language even before an editor fills it.
     */
    private function languageFolderHasHomepage(\OCP\Files\Folder $langFolder): bool {
        return $this->resolveLanguageHomepageData($langFolder) !== null;
    }

    /**
     * Language content status for the CURRENT user. Drives the landing-page
     * fallback notice and is the "active = where content is" signal for the
     * VoxCloud language model (replaces the enabled_languages opt-in list).
     *
     * Two distinct sets:
     *   - languagesWithContent: only REAL (editor-authored) homepages. The
     *     fallback notice uses this so a placeholder doesn't mask "no content".
     *   - activeLanguages: every language with ANY homepage (incl. an added
     *     placeholder). The admin "Languages with content" chips use this so a
     *     just-added language appears immediately.
     *
     * @return array{
     *   language: string,
     *   hasContent: bool,
     *   servedLanguage: ?string,
     *   languagesWithContent: string[],
     *   activeLanguages: string[]
     * }
     */
    public function getLanguageContentStatus(): array {
        $userLang = $this->getUserLanguage();
        $withContent = [];
        $active = [];

        try {
            $baseFolder = $this->getIntraVoxFolder();
            foreach ($this->getCachedDirectoryListing($baseFolder) as $item) {
                if ($item->getType() !== \OCP\Files\FileInfo::TYPE_FOLDER) {
                    continue;
                }
                $name = $item->getName();
                // Language folders are two-letter base codes (nl, en, de, ...).
                if (!preg_match('/^[a-z]{2,3}$/', $name) || !($item instanceof \OCP\Files\Folder)) {
                    continue;
                }
                if ($this->languageFolderHasHomepage($item)) {
                    $active[] = $name;
                }
                if ($this->languageFolderHasRealContent($item)) {
                    $withContent[] = $name;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[PageService] getLanguageContentStatus failed: ' . $e->getMessage());
        }

        sort($withContent);
        sort($active);

        // The language the user will actually be shown: own language, else the
        // recommended (primary) language, else English — issue #75. null means
        // nothing can be served (only then does the fallback notice appear).
        $served = $this->resolveEffectiveLanguage();

        // Resolve the homepage for the SERVED language (not necessarily the
        // user's), so the app lands on the correct homepage after fallback.
        $homepageUniqueId = null;
        try {
            $homepageUniqueId = $this->resolveHomepageNodeUniqueId($served ?? $userLang);
        } catch (\Throwable $e) {
            // Non-fatal: the frontend falls back to its own heuristic.
        }

        return [
            'language' => $userLang,
            // hasContent = "the user will see real content" (own language, the
            // recommended language, or English all count). Only false when
            // nothing resolves — the sole trigger for the fallback notice.
            'hasContent' => $served !== null,
            'servedLanguage' => $served,
            'languagesWithContent' => $withContent,
            'activeLanguages' => $active,
            'homepageUniqueId' => $homepageUniqueId,
        ];
    }

    /**
     * Number of pages per language folder (base code => count). Used by the
     * admin "remove language" confirmation so it can warn how many pages would
     * be deleted. Counts the homepage plus every `{name}/{name}.json` subpage.
     *
     * @return array<string,int>
     */
    public function getPageCountByLanguage(): array {
        $counts = [];
        try {
            $baseFolder = $this->getIntraVoxFolder();
            foreach ($this->getCachedDirectoryListing($baseFolder) as $item) {
                if ($item->getType() !== \OCP\Files\FileInfo::TYPE_FOLDER) {
                    continue;
                }
                $name = $item->getName();
                if (!preg_match('/^[a-z]{2,3}$/', $name) || !($item instanceof \OCP\Files\Folder)) {
                    continue;
                }
                $pages = [];
                $this->findPagesInFolder($item, $pages, '');
                $count = count($pages);
                // Homepage counts as a page when present (findPagesInFolder skips it).
                if ($item->nodeExists('home.json')) {
                    $count++;
                }
                $counts[$name] = $count;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[PageService] getPageCountByLanguage failed: ' . $e->getMessage());
        }
        return $counts;
    }

    /**
     * Recursively find pages in folders
     */
    private function findPagesInFolder($folder, array &$pages, string $basePath = ''): void {
        foreach ($this->getCachedDirectoryListing($folder) as $item) {
            if ($item->getType() === \OCP\Files\FileInfo::TYPE_FOLDER) {
                $folderName = $item->getName();

                // Skip special folders
                if (in_array($folderName, ['_media', 'images', 'files'])) {
                    continue;
                }

                // Look for {foldername}.json inside the folder
                try {
                    $jsonFile = $item->get($folderName . '.json');

                    // Check if file is readable before trying to get content
                    if (!$jsonFile->isReadable()) {
                        continue;
                    }

                    // Use cached file content to avoid repeated reads
                    $content = $jsonFile instanceof \OCP\Files\File
                        ? $this->getCachedFileContent($jsonFile)
                        : @$jsonFile->getContent();

                    if ($content === false || $content === null) {
                        continue;
                    }

                    $data = json_decode($content, true);

                    if ($data && isset($data['uniqueId'], $data['title'])) {
                        $pages[] = [
                            'uniqueId' => $data['uniqueId'],
                            'title' => $data['title'],
                            'modified' => $data['modified'] ?? $jsonFile->getMTime(),
                            'status' => $data['status'] ?? 'published',
                            'permissions' => $this->permissionsFromNode($item)
                        ];
                    }
                } catch (\Exception $e) {
                    // This folder doesn't contain a valid page or can't be read, continue
                } catch (\Throwable $e) {
                    // Catch any other errors including PHP errors
                    continue;
                }

                // Recursively search subfolders
                $this->findPagesInFolder($item, $pages, $basePath);
            }
        }
    }

    /**
     * List all pages with full content (including layout)
     * OPTIMIZED: Single filesystem traversal for search operations
     * This eliminates the N+1 query pattern where listPages() + getPage() for each
     */
    public function listPagesWithContent(): array {
        $folder = $this->getReadLanguageFolder();
        $pages = [];

        // Check for home.json in root
        try {
            $homeFile = $folder->get('home.json');
            $content = $homeFile->getContent();
            $data = json_decode($content, true);

            if ($data && isset($data['uniqueId'])) {
                // fileId lets callers (search) join MetaVox metadata onto the page.
                $data['fileId'] = $homeFile->getId();
                $pages[] = $this->sanitizePage($data);
            }
        } catch (NotFoundException $e) {
            // No home page yet
        }

        // Recursively find all pages with full content
        $this->findPagesWithContentInFolder($folder, $pages);

        return $pages;
    }

    /**
     * Recursively find pages with full content in folders
     */
    private function findPagesWithContentInFolder($folder, array &$pages): void {
        foreach ($this->getCachedDirectoryListing($folder) as $item) {
            if ($item->getType() === \OCP\Files\FileInfo::TYPE_FOLDER) {
                $folderName = $item->getName();

                // Skip special folders
                if (in_array($folderName, ['_media', 'images', 'files'])) {
                    continue;
                }

                // Look for {foldername}.json inside the folder
                try {
                    $jsonFile = $item->get($folderName . '.json');

                    if (!$jsonFile->isReadable()) {
                        continue;
                    }

                    // Use cached file content to avoid repeated reads
                    $content = $jsonFile instanceof \OCP\Files\File
                        ? $this->getCachedFileContent($jsonFile)
                        : @$jsonFile->getContent();

                    if ($content === false || $content === null) {
                        continue;
                    }

                    $data = json_decode($content, true);

                    if ($data && isset($data['uniqueId'])) {
                        // fileId lets callers (search) join MetaVox metadata onto the page.
                        $data['fileId'] = $jsonFile->getId();
                        $pages[] = $this->sanitizePage($data);
                    }
                } catch (\Exception $e) {
                    // This folder doesn't contain a valid page
                } catch (\Throwable $e) {
                    continue;
                }

                // Recursively search subfolders
                $this->findPagesWithContentInFolder($item, $pages);
            }
        }
    }

    /**
     * Get a specific page by uniqueId or legacy id
     */
    public function getPage(string $id): array {
        // Check request-level cache first
        if (isset($this->pageDataCache[$id])) {
            return $this->pageDataCache[$id];
        }

        $folder = $this->getReadLanguageFolder();
        $result = null;

        // Save original ID before sanitization
        $originalId = $id;

        // Check for uniqueId pattern BEFORE sanitization. The cross-language
        // scan inside locatePageAnyLanguage() lets feed links and shared links
        // resolve regardless of which language folder holds the page.
        if (strpos($originalId, 'page-') === 0) {
            $result = $this->locatePageAnyLanguage($folder, $originalId);
            if (!$result) {
                $this->logger->warning('IntraVox: Not found by uniqueId', ['uniqueId' => $originalId]);
            }
        }

        // Only sanitize for legacy ID fallback
        if ($result === null) {
            $id = $this->sanitizeId($originalId);
            $result = $this->findPageById($folder, $id);
            // Slug links get the same cross-language treatment as uniqueId
            // links, so which kind of link a reader follows never decides
            // whether the page resolves.
            if ($result === null) {
                $result = $this->locatePageBySlugAnyLanguage($folder, $id);
            }
        }

        if ($result === null) {
            throw new \Exception('Page not found');
        }

        $content = $result['file']->getContent();
        $data = json_decode($content, true);

        if (!$data) {
            throw new \Exception('Invalid page data');
        }

        // Ensure uniqueId exists for legacy pages
        if (!isset($data['uniqueId'])) {
            $data['uniqueId'] = 'page-' . $this->generateUUID();
            // Save the page with the new uniqueId
            try {
                $result['file']->putContent(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } catch (\Exception $e) {
                // Failed to save uniqueId - page will work but won't have permanent link
            }
        }

        // Cache folder location using both uniqueId and pageId for fast image access
        $pageFolder = $result['folder'];
        $uniqueId = $data['uniqueId'];
        $this->pageFolderCache[$uniqueId] = $pageFolder;
        $this->pageFolderCache[$originalId] = $pageFolder;
        if (isset($id)) {
            $this->pageFolderCache[$id] = $pageFolder;
        }

        // Distributed content cache. Key is content-addressable via mtime, so
        // invalidation is automatic — a write bumps mtime, the next read
        // misses cache and rebuilds. The sanitize+enrich pipeline is the
        // expensive part (~500 lines of widget processing); cache stores
        // the post-sanitize result keyed by `{uniqueId}_{mtime}`.
        $mtime = $result['file']->getMTime();
        $contentCacheKey = 'content_' . $uniqueId . '_' . $mtime;
        if ($this->distributedCache !== null) {
            $cached = $this->distributedCache->get($contentCacheKey);
            if (is_string($cached)) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) {
                    // Permissions are per-user and are NOT stored in the shared
                    // distributed cache (see the set() below). Recompute them
                    // fresh on every hit so one user's canWrite can never leak to
                    // another (issue #70). $result['file']/['folder'] are already
                    // resolved above. This also overwrites any stale permissions
                    // baked in by pre-fix cache entries, so no flush is needed.
                    $decoded['permissions'] = $this->permissionsForPage($result['folder'], $result['file']);
                    $decoded['canEdit'] = $result['file']->isUpdateable();
                    // fileId is user-independent but may be absent from older cache
                    // entries; ensure it's present so the publication gate works.
                    if (!isset($decoded['fileId']) && $result['file'] instanceof \OCP\Files\File) {
                        $decoded['fileId'] = $result['file']->getId();
                    }
                    // MetaVox availability is an install-wide fact and the
                    // groupfolder id is a property of the file's mount, so
                    // neither is cached — availability can change under a cache
                    // entry when the app is enabled or disabled, and entries
                    // written before these fields existed would otherwise never
                    // gain them. Both are cheap: an in-memory app-manager lookup
                    // and a regex over a path.
                    $decoded['metaVoxAvailable'] = $this->isMetaVoxAvailable();
                    if ($decoded['metaVoxAvailable'] && $result['file'] instanceof \OCP\Files\File) {
                        $decoded['groupfolderId'] = $this->groupfolderIdForNode($result['file']);
                    }
                    // Translations are ACL-filtered per user (resolveTranslations
                    // skips group members the caller's mount does not grant), so
                    // one user's list must never be served to another. Stripped
                    // from the shared cache on write — recomputed here on every
                    // hit: one indexed query plus a filecache lookup per group
                    // member.
                    $decoded['translations'] = $this->resolveTranslations(
                        $decoded['translationGroup'] ?? null,
                        $decoded['uniqueId'] ?? null
                    );
                    $this->pageDataCache[$originalId] = $decoded;
                    $this->pageDataCache[$uniqueId] = $decoded;
                    return $decoded;
                }
            }
        }

        // Enrich with real-time path data. Pass the page file so canWrite/canEdit
        // are gated on the file the write path actually targets (issue #70).
        $data = $this->enrichWithPathData($data, $result['folder'], $result['file']);

        $sanitizedData = $this->sanitizePage($data);

        // Cache the result for this request
        $this->pageDataCache[$originalId] = $sanitizedData;
        if (isset($data['uniqueId'])) {
            $this->pageDataCache[$data['uniqueId']] = $sanitizedData;
        }

        // Cache for cross-request reuse (1 hour TTL; older entries are
        // naturally orphaned when mtime changes, distributed-cache GC will
        // clean them up). The distributed cache is shared across users, so the
        // per-user permissions/canEdit are stripped before storing and are
        // recomputed on every read (issue #70). The user-independent enriched
        // fields (path/depth/parent/language/department) stay cached.
        if ($this->distributedCache !== null) {
            $cacheable = $sanitizedData;
            // metaVoxAvailable is stripped for the same reason as permissions:
            // enabling or disabling the app must take effect immediately rather
            // than waiting out an hour-long cache entry. It is recomputed on
            // every read above.
            // translations joins the per-user list: it is ACL-filtered through
            // the caller's mount, so caching it would leak one user's view to
            // another. Recomputed on every cache hit above.
            unset($cacheable['permissions'], $cacheable['canEdit'], $cacheable['metaVoxAvailable'], $cacheable['translations']);
            $this->distributedCache->set($contentCacheKey, json_encode($cacheable), 3600);
        }

        return $sanitizedData;
    }

    /**
     * Enrich page data with real-time path information calculated from filesystem
     */
    private function enrichWithPathData(array $page, $folder, ?\OCP\Files\Node $file = null): array {
        // Get relative path from IntraVox root
        $page['path'] = $this->getRelativePathFromRoot($folder);

        // Calculate depth
        $page['depth'] = $this->calculateDepth($page['path']);

        // Calculate parent path
        $pathParts = explode('/', $page['path']);
        if (count($pathParts) > 1) {
            array_pop($pathParts); // Remove current page
            $page['parentPath'] = implode('/', $pathParts);
            $page['parentId'] = basename($page['parentPath']);
        } else {
            $page['parentPath'] = null;
            $page['parentId'] = null;
        }

        // Parse language and department from path
        $parsedPath = explode('/', $page['path']);
        $page['language'] = $parsedPath[0] ?? $this->getUserLanguage();
        $page['department'] = $this->parseDepartmentFromPath($page['path']);

        // Get permissions directly from Nextcloud's filesystem, combining the
        // bitmask with the node capability methods so a read-only GroupFolder
        // member (without ACLs) is reported correctly — see permissionsFromNode().
        // When the page's file node is available, gate canWrite/canEdit on the
        // FILE (the real edit target) rather than the folder, so the "Edit page"
        // affordance matches what the write path actually allows (issue #70).
        if ($file !== null) {
            $page['permissions'] = $this->permissionsForPage($folder, $file);
            $page['canEdit'] = $file->isUpdateable();
            // Expose the page file's id so the publication gate can resolve the
            // scheduled-publish MetaVox fields (publish/expiration) for this page.
            if ($file instanceof \OCP\Files\File) {
                $page['fileId'] = $file->getId();
            }
            // Concurrency token: the editor sends this back on save, and
            // updatePage() refuses a write whose baseVersion predates the file
            // on disk. Deliberately the file's mtime rather than the `modified`
            // field in the JSON, which is client-supplied and would compare a
            // value against itself.
            $page['baseVersion'] = $file->getMTime();

            // Which languages this page exists in. Powers the reader's "also
            // available in X" notice and the language switcher, and tells an
            // editor at a glance what still needs translating.
            //
            // Excludes the page's own language: the list answers "where ELSE
            // can I read this", so including the page you are on would only add
            // a no-op entry to every switcher.
            $page['translations'] = $this->resolveTranslations(
                $page['translationGroup'] ?? null,
                $page['uniqueId'] ?? null
            );

            // Whether the MetaVox tab and its menu entry should exist at all.
            // Rides along on a response the client already fetches: this is an
            // in-memory app-manager lookup, no query and no HTTP, so it is
            // cheaper than the separate /api/metavox/status call the sidebar
            // used to make every time it opened.
            $page['metaVoxAvailable'] = $this->isMetaVoxAvailable();

            // The groupfolder holding this page. MetaVox's field definitions are
            // assigned per groupfolder, and its groupfolder-scoped endpoint
            // returns exactly the fields for that folder — where the
            // auto-detecting variant returned every field of every folder.
            //
            // Derived from the file's mount path rather than from MetaVox's
            // value table: that table only holds rows for files that already
            // have values SAVED, so looking there would return nothing for a
            // page whose fields are still empty — precisely the freshly copied
            // and translated pages that need the form most.
            if ($page['metaVoxAvailable'] && $file instanceof \OCP\Files\File) {
                $page['groupfolderId'] = $this->groupfolderIdForNode($file);
            }
        } else {
            $page['permissions'] = $this->permissionsFromNode($folder);
            $page['canEdit'] = $folder->isUpdateable();
        }

        return $page;
    }

    /**
     * Get relative path from IntraVox root folder
     */
    private function getRelativePathFromRoot($folder): string {
        $intraVoxPath = $this->getIntraVoxFolder()->getPath();
        $folderPath = $folder->getPath();

        // Remove IntraVox base path
        $relativePath = str_replace($intraVoxPath . '/', '', $folderPath);

        return $relativePath;
    }

    /**
     * Calculate nesting depth from path
     *
     * Base paths (depth 0):
     * - nl/public/ (public pages)
     * - nl/departments/{dept}/ (department pages)
     */
    /**
     * @deprecated Delegated to PagePathHelper::calculateDepth.
     */
    private function calculateDepth(string $path): int {
        return $this->pathHelper->calculateDepth($path);
    }

    /**
     * Get maximum allowed depth for a given path
     */
    private function getMaxDepthForPath(string $path): int {
        $pathParts = explode('/', trim($path, '/'));

        // Remove language if present. Uses the available (= every NC-known)
        // language set so paths in any language an admin added (e.g. 'da') get
        // correct depth math, not only the ones IntraVox ships a translation for.
        if (count($pathParts) > 0 && $this->languageService->isLanguageAvailable($pathParts[0])) {
            array_shift($pathParts);
        }

        // Public pages: max depth 5
        if (count($pathParts) > 0 && $pathParts[0] === 'public') {
            return 5;
        }

        // Department pages: max depth 5
        if (count($pathParts) > 0 && $pathParts[0] === 'departments') {
            return 5;
        }

        // Default: max depth 5
        return 5;
    }

    /**
     * Validate that creating a child page at the given path wouldn't exceed max depth
     */
    private function validateDepth(string $parentPath): void {
        $currentDepth = $this->calculateDepth($parentPath);
        $maxDepth = $this->getMaxDepthForPath($parentPath);

        if ($currentDepth >= $maxDepth) {
            throw new \InvalidArgumentException(
                "Cannot create child page: maximum nesting depth of {$maxDepth} would be exceeded"
            );
        }
    }

    /**
     * Determine page type based on path and structure
     *
     * @return string 'department'|'container'|'page'
     */
    /**
     * @deprecated Delegated to PagePathHelper::determinePageType.
     */
    private function determinePageType(string $path, bool $hasChildren): string {
        return $this->pathHelper->determinePageType($path, $hasChildren);
    }

    /**
     * @deprecated Delegated to PagePathHelper::parseDepartmentFromPath.
     */
    private function parseDepartmentFromPath(string $path): ?string {
        return $this->pathHelper->parseDepartmentFromPath($path);
    }

    /**
     * Get breadcrumb trail for a page
     *
     * Returns array of breadcrumb items from home to current page
     */
    public function getBreadcrumb(string $pageId): array {
        $page = $this->getPage($pageId);
        $breadcrumb = [];
        $language = $this->getUserLanguage();

        // Check if current page is the home page (legacy id/path detection, plus
        // a configured homepage pointer via uniqueId).
        $isHomePage = ($pageId === 'home' ||
                       preg_match('/^[a-z]{2,3}\/home$/', $page['path']) ||
                       preg_match('/^[a-z]{2,3}$/', $page['path']) ||
                       (!empty($page['uniqueId']) && $this->isHomepage((string)$page['uniqueId'], $language)));

        // Read home breadcrumb label from navigation.json (first item title)
        // This allows users to customize the label via the navigation editor
        $homeTitle = 'Home';
        $homeUniqueId = $isHomePage ? $page['uniqueId'] : null;
        try {
            $folder = $this->getReadLanguageFolder();
            if ($folder->nodeExists('navigation.json')) {
                $navFile = $folder->get('navigation.json');
                $navData = json_decode($navFile->getContent(), true, 64);
                if ($navData && !empty($navData['items'][0]['title'])) {
                    $homeTitle = $navData['items'][0]['title'];
                }
                if (!$isHomePage && $navData && !empty($navData['items'][0]['uniqueId'])) {
                    $homeUniqueId = $navData['items'][0]['uniqueId'];
                }
            }
        } catch (\Exception $e) {
            // fallback to 'Home'
        }

        // Always start with Home
        $breadcrumb[] = [
            'id' => 'home',
            'uniqueId' => $homeUniqueId,
            'title' => $homeTitle,
            'path' => $language . '/home',
            'url' => $isHomePage ? null : '#home',
            'current' => $isHomePage
        ];

        // If this is the home page, we're done - don't add duplicate
        if ($isHomePage) {
            return $breadcrumb;
        }

        // Build breadcrumb from the full path
        // Example path: en/departments/marketing/campaigns
        $pathParts = explode('/', $page['path']);
        $accumulatedPath = '';

        foreach ($pathParts as $index => $part) {
            // Build accumulated path for looking up parent pages
            if (!empty($accumulatedPath)) {
                $accumulatedPath .= '/';
            }
            $accumulatedPath .= $part;

            // Skip language folder in breadcrumb display (but include in accumulated path)
            if ($index === 0 && $this->languageService->isLanguageAvailable($part)) {
                continue;
            }

            // Skip 'home' as it's already added
            if ($part === 'home') {
                continue;
            }

            // Check if this is the last item (current page)
            if ($index === count($pathParts) - 1) {
                // Add current page (not clickable)
                $breadcrumb[] = [
                    'uniqueId' => $page['uniqueId'],
                    'title' => $page['title'],
                    'path' => $page['path'],
                    'url' => null,
                    'current' => true
                ];
                break;
            }

            // Try to find parent page by its folder path
            try {
                $parentPage = $this->findPageByFolderPath($accumulatedPath);
                if ($parentPage) {
                    $breadcrumb[] = [
                        'id' => $part,
                        'uniqueId' => $parentPage['uniqueId'],
                        'title' => $parentPage['title'],
                        'path' => $parentPage['path'],
                        'url' => '#' . $parentPage['uniqueId'],
                        'current' => false
                    ];
                } else {
                    // No page found for this folder - use folder name as label but don't make clickable
                    $breadcrumb[] = [
                        'id' => $part,
                        'uniqueId' => null,
                        'title' => ucfirst(str_replace('-', ' ', $part)),
                        'path' => $accumulatedPath,
                        'url' => null,
                        'current' => false
                    ];
                }
            } catch (\Exception $e) {
                // Parent page not found or error loading it
                // Use folder name as fallback
                $breadcrumb[] = [
                    'id' => $part,
                    'uniqueId' => null,
                    'title' => ucfirst(str_replace('-', ' ', $part)),
                    'path' => $accumulatedPath,
                    'url' => null,
                    'current' => false
                ];
            }
        }

        return $breadcrumb;
    }

    /**
     * Find a page by its folder path relative to IntraVox root
     *
     * @param string $folderPath e.g., "en/departments" or "en/departments/marketing"
     * @return array|null Page data or null if not found
     */
    private function findPageByFolderPath(string $folderPath): ?array {
        // Check request-level cache first
        if (isset($this->folderPathCache[$folderPath])) {
            return $this->folderPathCache[$folderPath];
        }

        try {
            $intraVoxFolder = $this->getIntraVoxFolder();
            $folder = $intraVoxFolder->get($folderPath);

            if (!($folder instanceof \OCP\Files\Folder)) {
                $this->folderPathCache[$folderPath] = null;
                return null;
            }

            // Look for a JSON file in this folder (page definition)
            $files = $this->getCachedDirectoryListing($folder);
            foreach ($files as $file) {
                if ($file instanceof \OCP\Files\File &&
                    pathinfo($file->getName(), PATHINFO_EXTENSION) === 'json' &&
                    $file->getName() !== 'images.json') {

                    $content = $file->getContent();
                    $data = json_decode($content, true);

                    if ($data && isset($data['uniqueId'])) {
                        // Enrich with path data (file gates canWrite/canEdit, #70)
                        $data = $this->enrichWithPathData($data, $folder, $file);
                        $result = $this->sanitizePage($data);
                        $this->folderPathCache[$folderPath] = $result;
                        return $result;
                    }
                }
            }
        } catch (\Exception $e) {
            // Folder or page not found
            $this->logger->debug("Could not find page at path {$folderPath}: " . $e->getMessage());
        }

        $this->folderPathCache[$folderPath] = null;
        return null;
    }

    /**
     * Get or create folder path recursively
     * Example: "nl/departments/marketing/campaigns" will create all intermediate folders
     *
     * A sub-page belongs in its PARENT's language folder, not in the author's
     * own. When the path names a language, that language wins: an English
     * editor adding a page under a German parent writes into de/, exactly where
     * the parent lives. Previously the language segment was stripped and the
     * remainder re-created under the author's own language, which fabricated an
     * empty mirror tree (de/departments/marketing/) whose parent pages did not
     * exist there — the created page vanished from the context it was made in.
     */
    private function getOrCreateFolderPath(string $path): \OCP\Files\Folder {
        $pathParts = explode('/', trim($path, '/'));

        // A leading language segment selects the content folder to build in.
        // Fall back to the author's own language folder when the path carries
        // no language (legacy callers) or when that language has no folder yet.
        $currentFolder = null;
        if (count($pathParts) > 0 && $this->languageService->isLanguageAvailable($pathParts[0])) {
            $langCode = array_shift($pathParts);
            try {
                $candidate = $this->getIntraVoxFolder()->get($langCode);
                if ($candidate instanceof \OCP\Files\Folder) {
                    $currentFolder = $candidate;
                }
            } catch (NotFoundException $e) {
                // No folder for that language — fall through to the author's own.
            }
        }
        if ($currentFolder === null) {
            $currentFolder = $this->getLanguageFolder();
        }

        // Create each folder in path if it doesn't exist
        foreach ($pathParts as $folderName) {
            try {
                $currentFolder = $currentFolder->get($folderName);
                if ($currentFolder->getType() !== \OCP\Files\FileInfo::TYPE_FOLDER) {
                    throw new \InvalidArgumentException("Path component '{$folderName}' exists but is not a folder");
                }
            } catch (NotFoundException $e) {
                $currentFolder = $currentFolder->newFolder($folderName);
            }
        }

        return $currentFolder;
    }

    /**
     * Create a page at a specific path with parent support
     *
     * @param string $pageId The page ID (used as folder name)
     * @param array $data Page data (without id - id is the folder name)
     * @param string|null $parentPath Optional parent path (e.g., "nl/departments/marketing")
     * @return array Created page data
     */
    private function createPageAtPath(string $pageId, array $data, ?string $parentPath = null): array {
        $language = $this->getUserLanguage();

        // Determine target folder
        if ($parentPath) {
            // Validate depth before creating
            $this->validateDepth($parentPath);

            // Get or create parent folder path
            $targetFolder = $this->getOrCreateFolderPath($parentPath);
        } else {
            // No parent = create at the root of the language being VIEWED, so a
            // new page lands in the structure the author is actually working in
            // rather than in their profile language. getReadLanguageFolder()
            // resolves own language → recommended → en, and falls back to the
            // author's own folder when nothing else resolves.
            $targetFolder = $this->getReadLanguageFolder();
        }

        // Preflight: creating a page writes a file (and a folder) into $targetFolder.
        // A read-only GroupFolder member must get a clean 403 here instead of a
        // filesystem-level 400 (issue #70).
        if (!$targetFolder->isCreatable()) {
            throw new ForbiddenException('You do not have permission to create a page here');
        }

        // Special handling for home page (always at root)
        if ($pageId === 'home') {
            $file = $targetFolder->newFile('home.json');
            $file->putContent(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            // Create _media folder for home if it doesn't exist
            try {
                $mediaFolder = $targetFolder->get('_media');
                $this->createMediaFolderMarker($mediaFolder);
            } catch (NotFoundException $e) {
                $mediaFolder = $targetFolder->newFolder('_media');
                $this->createMediaFolderMarker($mediaFolder);
            }

            $this->scanPageFolder($targetFolder);
        } else {
            // Create folder for page
            try {
                $pageFolder = $targetFolder->newFolder($pageId);
            } catch (\Exception $e) {
                throw new \InvalidArgumentException('Failed to create page folder: ' . $e->getMessage());
            }

            // Create {pageId}.json inside the folder
            try {
                $file = $pageFolder->newFile($pageId . '.json');
                $file->putContent(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } catch (\Exception $e) {
                throw new \InvalidArgumentException('Failed to create page file: ' . $e->getMessage());
            }

            // Create _media subfolder
            try {
                $mediaFolder = $pageFolder->newFolder('_media');
                // Add a .nomedia file to indicate this is a special folder
                $this->createMediaFolderMarker($mediaFolder);
            } catch (\Exception $e) {
                // Media folder might already exist, that's okay
                try {
                    $mediaFolder = $pageFolder->get('_media');
                    $this->createMediaFolderMarker($mediaFolder);
                } catch (\Exception $ex) {
                    // Couldn't get media folder
                }
            }

            $this->scanPageFolder($pageFolder);

            // Cache the folder reference for immediate reuse (e.g., when copying media from template)
            if (isset($data['uniqueId'])) {
                $this->pageFolderCache[$data['uniqueId']] = $pageFolder;
            }
        }

        // Update page metadata index (non-blocking — page was already saved).
        // Index the language the page actually LANDED in, which is not always
        // the author's own (a sub-page follows its parent's language).
        //
        // The stored path is the ABSOLUTE path of the folder holding the page
        // JSON, matching updatePage() and rebuildIndex(). This used to store a
        // relative parent path here and an absolute one everywhere else, so the
        // same table held two incompatible path shapes — which breaks both the
        // index lookup (it resolves the stored path) and repathSubtree() (it
        // matches on a path prefix).
        try {
            $language = $this->languageOfFolder($targetFolder) ?? $this->getUserLanguage();
            $this->pageIndexService->indexPage(
                $data,
                $language,
                $targetFolder->getPath(),
                $file->getId()
            );
        } catch (\Exception $e) {
            $this->logger->warning('Failed to index new page', ['error' => $e->getMessage()]);
        }

        // Return data with id for frontend (id is derived from folder name)
        return array_merge(['id' => $pageId], $data);
    }

    /**
     * Create a new page
     *
     * @param array $data Page data (id, title, content, etc.)
     * @param string|null $parentPath Optional parent path for nested pages (e.g., "nl/departments/marketing")
     * @return array Created page data
     */
    public function createPage(array $data, ?string $parentPath = null): array {
        if (!isset($data['id']) || !isset($data['title'])) {
            throw new \InvalidArgumentException('Missing required fields: id, title');
        }

        $data['id'] = $this->sanitizeId($data['id']);

        // If ID already exists, append a number to make it unique
        $originalId = $data['id'];
        $counter = 2;
        while ($this->pageIdExists($data['id'])) {
            $data['id'] = $originalId . '-' . $counter;
            $counter++;
        }

        // Generate uniqueId if not provided
        if (!isset($data['uniqueId'])) {
            $data['uniqueId'] = 'page-' . $this->generateUUID();
        }

        // Every page belongs to a translation group, even when it is the only
        // member. Giving each new page its own group from the start means
        // "linked" and "not linked" are the same shape — there is no special
        // case for an unlinked page, and linking later is a value change rather
        // than a structural one. A caller that supplies a group (adding a
        // translation of an existing page) keeps it.
        if (empty($data['translationGroup'])) {
            $data['translationGroup'] = 'tg-' . $this->generateUUID();
        }

        $validatedData = $this->validateAndSanitizePage($data);

        // Use the new createPageAtPath helper - pass id separately (not stored in JSON)
        $created = $this->createPageAtPath($data['id'], $validatedData, $parentPath);

        // Flush all cached page-tree + permission map entries so subsequent
        // reads (loadPages, getPageTree) immediately see the new page.
        // Historically only updatePage/deletePage did this; createPage
        // relied on the static cache's TTL to age out, which became
        // visible as "create page from template renders blank" once PR-3
        // shifted to a 5-minute distributed tree cache.
        $this->clearCache();

        return $created;
    }

    /**
     * Scan a page folder to make it immediately visible in Files app
     * This uses Nextcloud's Scanner to add the folder to the file cache
     *
     * @param \OCP\Files\Folder $folder The folder to scan (can be page folder or language folder)
     */
    private function scanPageFolder($folder): void {
        try {
            $folderPath = $folder->getPath();

            // For groupfolders, run occ files:scan directly
            // Match pattern: /__groupfolders/{id}/files/{anything}
            if (preg_match('#/__groupfolders/(\d+)/files/(.+)$#', $folderPath, $matches)) {
                $relativePath = $matches[2]; // e.g., "en/team-sales/sales-1"

                $user = $this->userSession->getUser();
                if (!$user) {
                    return;
                }

                $username = $user->getUID();
                $scanPath = "/{$username}/files/IntraVox/{$relativePath}";

                // Execute occ files:scan (already running as www-data via web server)
                $command = sprintf(
                    'php /var/www/nextcloud/occ files:scan --path=%s 2>&1',
                    escapeshellarg($scanPath)
                );

                exec($command, $output, $returnCode);

                if ($returnCode !== 0) {
                    $this->logger->warning('Failed to scan page folder', [
                        'path' => $scanPath,
                        'exit_code' => $returnCode,
                        'output' => implode("\n", $output ?? [])
                    ]);
                }

                return;
            }

            // Fallback for non-groupfolder paths (shouldn't happen in IntraVox)
            $storage = $folder->getStorage();
            $scanner = $storage->getScanner();
            $cache = $storage->getCache();

            $internalPath = $folder->getInternalPath();
            if (preg_match('#__groupfolders/\d+/(.+)$#', $internalPath, $matches)) {
                $scanPath = $matches[1];
            } else {
                $scanPath = $internalPath;
            }

            $scanner->scan($scanPath, true);
            $cache->correctFolderSize($scanPath, ['recursive' => true]);

        } catch (\Exception $e) {
            // Log but don't throw - if scanning fails, the page is still created
            $this->logger->error('Failed to scan page folder', [
                'path' => $folder->getPath(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Propagate cache size changes up the folder tree
     * This is critical for groupfolders to make new content visible
     */
    private function propagateCacheSizes($cache, $internalPath): void {
        try {
            // Start from the given path and work up to the root
            $currentPath = $internalPath;

            while ($currentPath !== '' && $currentPath !== '.') {
                // Update the size for this folder
                $cache->correctFolderSize($currentPath);

                // Move to parent folder
                $parentPath = dirname($currentPath);
                if ($parentPath === $currentPath || $parentPath === '.') {
                    break;
                }
                $currentPath = $parentPath;
            }
        } catch (\Exception $e) {
            // Silently fail - cache propagation is not critical
        }
    }

    /**
     * Update an existing page
     */
    public function updatePage(string $id, array $data): array {
        // Save original ID before sanitization
        $originalId = $id;

        // Get the current user
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \InvalidArgumentException('No user in session');
        }

        $languageFolder = $this->getLanguageFolder();
        $result = null;

        // Check for uniqueId pattern (page-xxx) BEFORE sanitization. Editing an
        // existing page writes back to wherever that page actually lives, which
        // is not necessarily the current user's own language folder (issue #90);
        // the isUpdateable() preflight below still gates the write.
        if (strpos($originalId, 'page-') === 0) {
            $result = $this->locatePageAnyLanguage($languageFolder, $originalId);
        }

        // Fallback to legacy ID lookup if not found by uniqueId
        if ($result === null) {
            try {
                $id = $this->sanitizeId($originalId);
                $result = $this->findPageById($languageFolder, $id);
            } catch (\Exception $e) {
                throw new \InvalidArgumentException('Failed to find page: ' . $e->getMessage());
            }
        }

        if ($result === null) {
            throw new PageNotFoundException('Page not found: ' . $originalId);
        }

        // Get the file
        $file = $result['file'];

        // Preflight the write capability on the actual file/mount. permissionsFromNode
        // already gates canWrite on this, but a read-only GroupFolder member must get a
        // clean 403 here rather than a filesystem-level 400 if anything reported wrong
        // (issue #70). This also avoids Nextcloud core's share-access-list side effect
        // ("foreach() on null") that a doomed putContent would otherwise trigger.
        if (!$file->isUpdateable()) {
            throw new ForbiddenException('You do not have permission to edit this page');
        }

        try {
            $existingContent = $file->getContent();
            $existingData = json_decode($existingContent, true);
            if (!is_array($existingData)) {
                $existingData = [];
            }
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('Failed to read existing page data: ' . $e->getMessage());
        }

        // Optimistic concurrency. putContent() replaces the WHOLE document, so
        // a save built on stale content erases everything written since — not a
        // field, the entire page. PageLockService catches the common case, but
        // locks expire after 15 minutes without a heartbeat, so a tab left open
        // comes back with stale content and no lock to stop it.
        //
        // The FILE's mtime is the version token, not the `modified` field in the
        // JSON: that field is whatever the client last sent (updatePage never
        // stamps it), so it would compare a value against itself. The mtime is
        // set by the filesystem on every write and cannot be spoofed by a stale
        // client.
        //
        // A client that sends no baseVersion — an older frontend, a script, an
        // import — is not blocked. This rejects only a save that demonstrably
        // started from an older version, never one that merely failed to say.
        $submittedBase = $data['baseVersion'] ?? null;
        if (is_numeric($submittedBase)) {
            $currentMtime = $file->getMTime();
            if ((int)$submittedBase < $currentMtime) {
                $this->logger->warning('[updatePage] stale write rejected', [
                    'pageId' => $originalId,
                    'baseVersion' => (int)$submittedBase,
                    'currentMtime' => $currentMtime,
                ]);
                throw new PageConflictException(
                    'This page was changed by someone else while you were editing it. '
                    . 'Reload the page to get the latest version before saving again.'
                );
            }
        }

        // Never persist the transport-only concurrency token.
        unset($data['baseVersion']);

        // Preserve uniqueId from existing data
        if (isset($existingData['uniqueId'])) {
            $data['uniqueId'] = $existingData['uniqueId'];
        }

        // Same for the translation group: it belongs to the page, not to the
        // payload a client happens to send. An editor saving from a UI that
        // knows nothing about translation groups (or an older frontend, or a
        // script) must not silently unlink the page from its other languages.
        //
        // Linking and unlinking are explicit operations with their own entry
        // points; an ordinary save is never one of them.
        if (isset($existingData['translationGroup'])) {
            $data['translationGroup'] = $existingData['translationGroup'];
        }

        // Preserve originalSrc for video widgets to prevent URL loss when whitelist changes
        $data = $this->preserveVideoOriginalUrls($data, $existingData);

        try {
            $validatedData = $this->validateAndSanitizePage($data);
        } catch (\Exception $e) {
            $this->logger->error('[updatePage] Validation failed: ' . $e->getMessage(), [
                'pageId' => $originalId,
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \InvalidArgumentException('Page validation failed: ' . $e->getMessage());
        }

        try {
            // Create version before update using GroupFolders VersionsBackend
            // GroupFolders 20.1.7+ has reliable versioning support
            $this->createVersionBeforeUpdate($file);

            // Update the file
            $file->putContent(json_encode($validatedData, JSON_PRETTY_PRINT));

        } catch (\Exception $e) {
            throw new \InvalidArgumentException('Failed to write updated page data: ' . $e->getMessage());
        }

        // Clear caches for this page (and uniqueId if present)
        $this->clearCache($originalId);
        if (isset($validatedData['uniqueId'])) {
            $this->clearCache($validatedData['uniqueId']);
        }

        // Update page metadata index (non-blocking — page was already saved).
        // Index the language the page actually LIVES in, never the editor's
        // own: since #90 an editor can save a page outside their own language,
        // and getUserLanguage() here wrote rows under the WRONG language. The
        // index is keyed (unique_id, language), so those rows did not match the
        // existing entry — every such save INSERTed a duplicate under a
        // language the page was never in, and nothing ever cleaned them up.
        // Mirrors createPageAtPath(), which already derives it from the folder.
        try {
            $folderPath = $result['folder']->getPath();
            $language = $this->languageOfFolder($result['folder']) ?? $this->getUserLanguage();
            $this->pageIndexService->indexPage($validatedData, $language, $folderPath, $file->getId());
        } catch (\Exception $e) {
            $this->logger->warning('Failed to update page index', ['error' => $e->getMessage()]);
        }

        // Return data with id for frontend (id is derived from folder name)
        // Get id from folder name (for home page it's 'home', otherwise folder basename)
        $pageId = ($result['isHome'] ?? false) ? 'home' : $result['folder']->getName();

        // Hand back the version this write produced, so the editor can keep
        // saving without reloading. Without it the client would still hold the
        // token from page load, and its NEXT save would look stale against the
        // file it just wrote — a conflict with itself.
        return array_merge(
            ['id' => $pageId],
            $validatedData,
            ['baseVersion' => $file->getMTime()]
        );
    }

    /**
     * Delete a page and all its assets
     */
    public function deletePage(string $id): void {
        if ($id === 'home') {
            throw new \InvalidArgumentException('Cannot delete home page');
        }

        // Resolve by uniqueId (page-…) first, then fall back to legacy folder id.
        // Deletion follows the page across language folders, so a page the user
        // can see is also a page the user can delete (issue #90); the caller's
        // permission check still decides whether the delete is allowed.
        $languageFolder = $this->getLanguageFolder();
        $result = strpos($id, 'page-') === 0
            ? $this->locatePageAnyLanguage($languageFolder, $id)
            : $this->findPageById($languageFolder, $this->sanitizeId($id));

        if ($result === null) {
            throw new PageNotFoundException('Page not found: ' . $id);
        }

        // Normalize $id to the folder name for downstream index/event use.
        $id = isset($result['folder']) ? $result['folder']->getName() : $this->sanitizeId($id);

        // Read the page JSON once for uniqueId (homepage guard + comment cleanup).
        $pageData = [];
        if (isset($result['file'])) {
            $decoded = json_decode($result['file']->getContent(), true);
            if (is_array($decoded)) {
                $pageData = $decoded;
            }
        }

        // The configured homepage cannot be deleted — reassign it first
        // (issue: configurable homepage). Distinguishable error so the UI can
        // prompt the user to pick another homepage.
        $resolvedUniqueId = $pageData['uniqueId'] ?? '';
        if ($resolvedUniqueId !== '' && $this->isHomepage($resolvedUniqueId)) {
            throw new \InvalidArgumentException('HOMEPAGE_PROTECTED');
        }

        // Get page data before deletion to retrieve uniqueId for comment cleanup
        try {
            $uniqueId = $pageData['uniqueId'] ?? '';

            // Dispatch event to cleanup comments/reactions before deleting the page
            if (!empty($uniqueId)) {
                $this->eventDispatcher->dispatchTyped(new PageDeletedEvent($id, $uniqueId));
            }
        } catch (\Exception $e) {
            // Log but don't block deletion if event dispatch fails
            $this->logger->warning('Failed to dispatch PageDeletedEvent for page ' . $id . ': ' . $e->getMessage());
        }

        // Remove from page metadata index before deleting files (non-blocking).
        // Deleting a page deletes everything nested inside it, so the subtree
        // goes too: removePage() only knows this one uniqueId, and without the
        // subtree sweep the children linger as rows pointing at folders that
        // no longer exist.
        $deletedPath = $result['folder']->getPath();
        try {
            if (!empty($uniqueId)) {
                $this->pageIndexService->removePage($uniqueId);
            }
            $this->pageIndexService->removeSubtree($deletedPath);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to remove page from index', ['error' => $e->getMessage()]);
        }

        // Delete the entire folder (includes .json, images/, files/)
        $result['folder']->delete();

        // Clear caches
        $this->clearCache();
    }

    /**
     * Move a page (with its whole subtree) under a different parent (issue #69).
     *
     * The page keeps its uniqueId — so internal links and URLs by uniqueId stay
     * valid — while its folder is relocated into the target parent's folder.
     * Children ride along inside the moved folder. On a folder-name collision at
     * the destination the folder is given a `-2`/`-3` suffix (mirrors createPage);
     * the uniqueId is untouched.
     *
     * @param string $pageId       uniqueId (or legacy id) of the page to move.
     * @param string $targetParentId uniqueId of the destination parent; '' = root.
     * @throws \InvalidArgumentException On home, self/descendant cycles, depth.
     * @throws \Exception When source or target cannot be located.
     */
    /**
     * Set a root-level page as the homepage for the current language
     * (issue: configurable homepage). Validates the page exists AND sits at the
     * language root; lazily normalizes a still-loose home.json into a folder page
     * first so the old homepage becomes reorderable; then writes the pointer.
     *
     * @throws \InvalidArgumentException When the page is unknown or not at root.
     */
    public function setHomepage(string $uniqueId): void {
        $lang = $this->getUserLanguage();
        $languageFolder = $this->getLanguageFolder();

        // Resolve the target and require it to be a real page.
        $target = $this->findPageByUniqueId($languageFolder, $uniqueId);
        if ($target === null || !isset($target['folder'])) {
            throw new \InvalidArgumentException('Page not found');
        }

        // Must be a ROOT-level page: its folder's parent is the language root
        // (or it is the loose home itself). Compare parent paths.
        $isLooseHome = !empty($target['isHome']);
        if (!$isLooseHome) {
            $parentPath = dirname($target['folder']->getPath());
            if ($parentPath !== $languageFolder->getPath()) {
                throw new \InvalidArgumentException('Only root-level pages can be the homepage');
            }
        }

        // If the target is already the resolved homepage, nothing to do.
        if ($this->isHomepage($uniqueId, $lang)) {
            return;
        }

        // Pages never move when the homepage changes — only the pointer shifts.
        // The old loose home.json simply stays where it is and shows up as a
        // normal root page once the pointer designates a different page.
        $this->homepageService->setHomepageUniqueId($uniqueId, $lang);
        $this->clearCache();
    }

    public function movePage(string $pageId, string $targetParentId): void {
        if ($pageId === 'home') {
            throw new \InvalidArgumentException('The home page cannot be moved');
        }

        $languageFolder = $this->getLanguageFolder();

        // Locate the source page folder, following it across language folders
        // like every other operation on an existing page (#90). This is safe
        // ONLY because the destination is anchored to the source's own language
        // below and the language guard backs it up: resolving the source
        // cross-language while leaving the destination on the user's language
        // is what would relocate content between languages.
        $source = strpos($pageId, 'page-') === 0
            ? $this->locatePageAnyLanguage($languageFolder, $pageId)
            : $this->locatePageBySlugAnyLanguage($languageFolder, $this->sanitizeId($pageId));
        if (!$source || !isset($source['folder'])) {
            throw new PageNotFoundException('Page not found: ' . $pageId);
        }

        // The page's OWN language folder governs this move, not the user's.
        // Everything below (the root destination, the depth check, the language
        // guard) is anchored here so a move can never leave the tree the page
        // lives in. Falls back to the user's folder only when the language
        // cannot be derived, which keeps single-language installs unchanged.
        $sourceLanguageFolder = $this->languageFolderOfPageResult($source) ?? $languageFolder;

        // The configured homepage cannot be moved — reassign it first
        // (issue: configurable homepage).
        $sourceUniqueId = strpos($pageId, 'page-') === 0 ? $pageId : '';
        if ($sourceUniqueId === '' && isset($source['file'])) {
            $decoded = json_decode($source['file']->getContent(), true);
            $sourceUniqueId = is_array($decoded) ? ($decoded['uniqueId'] ?? '') : '';
        }
        if ($sourceUniqueId !== '' && $this->isHomepage($sourceUniqueId)) {
            throw new \InvalidArgumentException('HOMEPAGE_PROTECTED');
        }

        $sourceFolder = $source['folder'];
        $sourcePath = $sourceFolder->getPath();

        // Resolve the destination parent folder (root or a page's own folder).
        if ($targetParentId === '' ) {
            // Root of the page's OWN language, never the user's. Using the
            // user's folder here would physically relocate the page (and its
            // whole subtree) into another language the moment the source
            // resolved cross-language — silently, with no undo.
            $targetParentFolder = $sourceLanguageFolder;
        } else {
            // Search from the source's language first: a move within one tree
            // is the normal case, and it keeps the parent lookup consistent
            // with the source rather than with the user's profile language.
            $targetResult = strpos($targetParentId, 'page-') === 0
                ? $this->locatePageAnyLanguage($sourceLanguageFolder, $targetParentId)
                : $this->findPageById($sourceLanguageFolder, $this->sanitizeId($targetParentId));
            if (!$targetResult || !isset($targetResult['folder'])) {
                throw new PageNotFoundException('Target parent page not found: ' . $targetParentId);
            }
            $targetParentFolder = $targetResult['folder'];
        }
        $targetParentPath = $targetParentFolder->getPath();

        // Language guard — the backstop for everything above. Even if a future
        // change miscomputes the destination, a move that would cross language
        // folders is refused rather than performed. Language folders are
        // independent content trees, so this is a relocation between intranets,
        // not a translation.
        $sourceLanguage = $this->languageOfFolder($sourceFolder);
        $targetLanguage = $this->languageOfFolder($targetParentFolder);
        if ($sourceLanguage !== null && $targetLanguage !== null && $sourceLanguage !== $targetLanguage) {
            throw new CrossLanguageMoveException(sprintf(
                'This page is in %s and cannot be moved into the %s structure. Pages stay in the language they were written in.',
                $this->languageDisplayName($sourceLanguage),
                $this->languageDisplayName($targetLanguage)
            ));
        }

        // Cycle guard: refuse moving into itself or one of its own descendants,
        // which would detach (and lose) the subtree.
        if ($targetParentPath === $sourcePath
            || strpos($targetParentPath . '/', $sourcePath . '/') === 0) {
            throw new \InvalidArgumentException('Cannot move a page into itself or its descendant');
        }

        // No-op if already directly under the target parent.
        if (dirname($sourcePath) === $targetParentPath) {
            return;
        }

        // Respect the configured max nesting depth at the destination.
        $targetRelPath = $this->getRelativePathFromRoot($targetParentFolder);
        $this->validateDepth($targetRelPath);

        // Permission preflight. movePage() had none at all: it called move()
        // and relied on the filesystem to throw, which surfaces as an opaque
        // 500 and leaves any partial state unguarded. Mirrors the checks in
        // createPageAtPath() (isCreatable) and updatePage() (isUpdateable).
        // A move both removes from the source and creates at the destination,
        // so both sides are checked.
        if (!$sourceFolder->isDeletable()) {
            throw new ForbiddenException('You do not have permission to move this page');
        }
        if (!$targetParentFolder->isCreatable()) {
            throw new ForbiddenException('You do not have permission to move a page here');
        }

        // Resolve a non-colliding folder name at the destination (mirror createPage).
        $baseName = $sourceFolder->getName();
        $newName = $baseName;
        $counter = 2;
        while ($targetParentFolder->nodeExists($newName)) {
            $newName = $baseName . '-' . $counter;
            $counter++;
        }

        // Relocate the whole folder; children travel inside it.
        $newPath = $targetParentPath . '/' . $newName;
        $sourceFolder->move($newPath);

        // The index stores a path per page, and the move just invalidated it
        // for this page AND every descendant that travelled with it. Rewriting
        // the prefix is one statement per affected row; re-walking the subtree
        // would be the filesystem traversal the index exists to avoid.
        // Non-blocking: the move already succeeded on disk, so a failure here
        // must not surface as a failed move — `occ intravox:reindex` repairs it.
        try {
            $this->pageIndexService->repathSubtree($sourcePath, $newPath);
        } catch (\Throwable $e) {
            $this->logger->warning('movePage: could not repath index subtree', [
                'from' => $sourcePath,
                'to' => $newPath,
                'error' => $e->getMessage(),
            ]);
        }

        // Send the moved page to the end of its new siblings by clearing its
        // explicit order — the stable comparator then places it after ordered
        // siblings, i.e. last. (A fresh reorder can pin it precisely later.)
        try {
            $movedResult = strpos($pageId, 'page-') === 0
                ? $this->findPageByUniqueId($targetParentFolder, $pageId)
                : $this->findPageById($targetParentFolder, $this->sanitizeId($pageId));
            if ($movedResult && isset($movedResult['file'])) {
                $file = $movedResult['file'];
                $data = json_decode($file->getContent(), true);
                if (is_array($data) && array_key_exists('order', $data)) {
                    unset($data['order']);
                    $file->putContent(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }
            }
        } catch (\Throwable $e) {
            // Non-fatal: the move succeeded; ordering just falls back to legacy.
            $this->logger->warning('movePage: could not reset order after move', ['error' => $e->getMessage()]);
        }

        // Critical: refresh tree + permission caches so the move is visible.
        $this->clearCache();
    }

    /**
     * Upload media (image or video) for a specific page
     * Unified endpoint that stores all media in a single '_media' folder
     */
    public function uploadMedia(string $pageId, array $file): string {
        if (!isset($file['tmp_name']) || !isset($file['name'])) {
            throw new \InvalidArgumentException('Invalid file upload');
        }

        // Check if tmp_name is empty (upload failed on server)
        if (empty($file['tmp_name'])) {
            $errorCode = $file['error'] ?? -1;
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds form upload limit',
                UPLOAD_ERR_PARTIAL => 'File only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'Upload stopped by PHP extension',
            ];
            $message = $errorMessages[$errorCode] ?? "Upload failed (error code: $errorCode)";
            throw new \InvalidArgumentException($message);
        }

        $pageId = $this->sanitizeId($pageId);

        // Validate file type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, self::ALLOWED_MEDIA_TYPES)) {
            throw new \InvalidArgumentException('Invalid file type. Allowed: JPEG, PNG, GIF, WebP, SVG, MP4, WebM, OGG');
        }

        // Validate file size
        if ($file['size'] > self::MAX_MEDIA_SIZE) {
            throw new \InvalidArgumentException('File too large. Maximum size is 50MB.');
        }

        // Additional validation for image files (prevents polyglot attacks)
        if (in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
            $this->validateImageFile($file['tmp_name'], $mimeType);
        }

        // SVG files get special treatment: smaller size limit + sanitization
        if ($mimeType === 'image/svg+xml') {
            if ($file['size'] > self::MAX_SVG_SIZE) {
                throw new \InvalidArgumentException('SVG file too large. Maximum size is 1MB.');
            }
            $content = file_get_contents($file['tmp_name']);
            $content = $this->sanitizeSVG($content);
        } else {
            $content = file_get_contents($file['tmp_name']);
        }

        // Sanitize filename with prefix based on type
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $isVideo = in_array($mimeType, self::ALLOWED_VIDEO_TYPES);
        $prefix = $isVideo ? 'vid_' : 'img_';
        $filename = uniqid($prefix, true) . '.' . $extension;

        // Media belongs to the page, so resolve the page across every language
        // folder and upload into the language it actually lives in (issue #92).
        $located = $this->locatePageForMedia($pageId);
        if ($located === null) {
            throw new PageNotFoundException('Page not found: ' . $pageId);
        }
        $result = $located['result'];
        $languageFolder = $located['languageFolder'];

        // Get media folder for this page
        if ($result['isHome'] ?? false) {
            // Home media is in root/_media/
            try {
                $mediaFolder = $languageFolder->get('_media');
            } catch (NotFoundException $e) {
                $mediaFolder = $languageFolder->newFolder('_media');
            }
        } else {
            $pageFolder = $result['folder'];

            // Get or create media subfolder
            try {
                $mediaFolder = $pageFolder->get('_media');
            } catch (NotFoundException $e) {
                $mediaFolder = $pageFolder->newFolder('_media');
            }
        }

        $newFile = $mediaFolder->newFile($filename);
        $newFile->putContent($content);

        // Invalidate the per-page content cache so the next getPage()
        // includes the freshly uploaded asset. Without this a save-then-
        // navigate-back sequence served the cached page-render where the
        // media reference was still missing — particularly visible on
        // image widgets that just got their src bumped.
        $this->clearCache($pageId);

        return $filename;
    }

    /**
     * Get media (image or video) for a specific page
     * Unified endpoint that serves all media from a single '_media' folder
     */
    public function getMedia(string $pageId, string $filename) {
        // Save original BEFORE sanitization
        $originalPageId = $pageId;
        $filename = basename($filename); // Prevent directory traversal

        // The language whose content this user is shown, which is where the
        // home page and the cache fast-path below look first. A page in another
        // language is picked up by the cross-language miss path further down.
        $languageFolder = $this->getReadLanguageFolder();

        try {
            // Handle home page with original pageId
            if ($originalPageId === 'home' ||
                $originalPageId === '2e8f694e-147e-4793-8949-4732e679ae6b' ||
                $originalPageId === 'page-2e8f694e-147e-4793-8949-4732e679ae6b') {

                $mediaFolder = $languageFolder->get('_media');
                $file = $mediaFolder->get($filename);

                if ($file->getType() !== \OCP\Files\FileInfo::TYPE_FILE) {
                    throw new \Exception('Not a file');
                }

                // Get mime type (with fallback for incorrect GroupFolder cache)
                $mimeType = $this->resolveMediaMimeType($file);

                // Validate it's an allowed media type
                if (!in_array($mimeType, self::ALLOWED_MEDIA_TYPES)) {
                    throw new \Exception('Invalid media type');
                }

                // Create stream response
                $response = new \OCP\AppFramework\Http\StreamResponse($file->fopen('rb'));
                $response->addHeader('Content-Type', $mimeType);
                $response->addHeader('Content-Disposition', 'inline; filename="' . $file->getName() . '"');
                // Use longer cache for images, shorter for videos
                $isVideo = in_array($mimeType, self::ALLOWED_VIDEO_TYPES);
                $cacheTime = $isVideo ? 86400 : 31536000;
                $response->addHeader('Cache-Control', 'public, max-age=' . $cacheTime);

                return $response;
            }

            // Try cache with BOTH original and sanitized IDs
            $mediaFolder = null;
            $pageId = $this->sanitizeId($originalPageId);

            if (isset($this->pageFolderCache[$originalPageId])) {
                // Cache hit with original ID (page-abc-123...)
                $pageFolder = $this->pageFolderCache[$originalPageId];
                try {
                    $mediaFolder = $pageFolder->get('_media');
                } catch (NotFoundException $e) {
                    // No media folder
                }
            } else if (isset($this->pageFolderCache[$pageId])) {
                // Cache hit with sanitized ID (abc-123...)
                $pageFolder = $this->pageFolderCache[$pageId];
                try {
                    $mediaFolder = $pageFolder->get('_media');
                } catch (NotFoundException $e) {
                    // No media folder
                }
            }

            // If cache miss, search using ORIGINAL pageId
            if ($mediaFolder === null) {
                $mediaFolder = $this->findMediaFolderForPage($languageFolder, $originalPageId);
            }

            // Still nothing: the page may simply live in another language than
            // the one this user reads, which used to 404 every image on it
            // (#92). Only reached on a genuine miss, so the common case keeps
            // the single-folder walk above and pays nothing for this.
            if ($mediaFolder === null) {
                $located = $this->locatePageForMedia($originalPageId);
                if ($located !== null) {
                    $mediaFolder = ($located['result']['isHome'] ?? false)
                        ? $this->folderOrNull($located['languageFolder'], '_media')
                        : $this->folderOrNull($located['result']['folder'] ?? null, '_media');
                }
            }

            if ($mediaFolder === null) {
                throw new \Exception('Media folder not found');
            }

            $file = $mediaFolder->get($filename);

            if ($file->getType() !== \OCP\Files\FileInfo::TYPE_FILE) {
                throw new \Exception('Not a file');
            }

            // Get mime type (with fallback for incorrect GroupFolder cache)
            $mimeType = $this->resolveMediaMimeType($file);

            // Validate it's an allowed media type
            if (!in_array($mimeType, self::ALLOWED_MEDIA_TYPES)) {
                throw new \Exception('Invalid media type');
            }

            // Create stream response
            $response = new \OCP\AppFramework\Http\StreamResponse($file->fopen('rb'));
            $response->addHeader('Content-Type', $mimeType);
            $response->addHeader('Content-Disposition', 'inline; filename="' . $file->getName() . '"');
            // Use longer cache for images, shorter for videos
            $isVideo = in_array($mimeType, self::ALLOWED_VIDEO_TYPES);
            $cacheTime = $isVideo ? 86400 : 31536000;
            $response->addHeader('Cache-Control', 'public, max-age=' . $cacheTime);

            return $response;
        } catch (NotFoundException $e) {
            throw new \Exception('Media not found');
        }
    }

    /**
     * Resolve MIME type for a media file, with extension-based fallback.
     *
     * GroupFolders can store incorrect MIME types (application/octet-stream)
     * in the file cache. When that happens, fall back to extension detection.
     */
    private function resolveMediaMimeType(\OCP\Files\File $file): string {
        $mimeType = $file->getMimeType();

        if ($mimeType === 'application/octet-stream') {
            $ext = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
            $mimeType = match ($ext) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'svg' => 'image/svg+xml',
                'mp4' => 'video/mp4',
                'webm' => 'video/webm',
                'ogg' => 'video/ogg',
                default => $mimeType,
            };
        }

        return $mimeType;
    }

    /**
     * Sanitize page ID
     */
    /**
     * @deprecated Delegated to PageIdUtils::sanitizeId.
     */
    private function sanitizeId(string $id): string {
        return $this->idUtils->sanitizeId($id);
    }

    /**
     * Recursively find media folder for a page by uniqueId
     */
    private function findMediaFolderForPage($folder, string $uniqueId): ?\OCP\Files\Folder {
        // First scan JSON files in CURRENT folder to see if page is here
        $foundMatch = false;
        foreach ($this->getCachedDirectoryListing($folder) as $item) {
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
        foreach ($this->getCachedDirectoryListing($folder) as $item) {
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
     * Validate and sanitize page data
     */
    private function validateAndSanitizePage(array $data): array {
        // Note: 'id' is NOT stored in JSON - the folder name IS the id
        $sanitized = [
            'title' => $this->sanitizeText($data['title']),
            'layout' => [
                'columns' => 1, // Default to 1 column
                'rows' => []
            ]
        ];

        // Preserve uniqueId if provided (for internal references)
        if (isset($data['uniqueId'])) {
            $sanitized['uniqueId'] = $data['uniqueId'];
        }

        // Translation group: the shared id linking the language versions of one
        // page. This is a strict whitelist, so an unlisted field is silently
        // dropped on every save — without this line a page would lose its
        // translation links the moment anyone edited it.
        //
        // Format-checked rather than sanitised: it is an identifier we generate
        // ('tg-' + UUID), never user prose, so anything that does not look like
        // one is dropped rather than escaped. Absence is valid and means the
        // page is not linked to any other language.
        if (isset($data['translationGroup'])
            && is_string($data['translationGroup'])
            && preg_match('/^tg-[a-f0-9-]{36}$/', $data['translationGroup'])
        ) {
            $sanitized['translationGroup'] = $data['translationGroup'];
        }

        // Preserve settings object (engagement settings for comments/reactions)
        if (isset($data['settings']) && is_array($data['settings'])) {
            $sanitized['settings'] = [
                'allowReactions' => isset($data['settings']['allowReactions']) ? (bool)$data['settings']['allowReactions'] : true,
                'allowComments' => isset($data['settings']['allowComments']) ? (bool)$data['settings']['allowComments'] : true,
                'allowCommentReactions' => isset($data['settings']['allowCommentReactions']) ? (bool)$data['settings']['allowCommentReactions'] : true,
            ];
        }

        // Preserve page status (draft/published). Default to "published" for backward compatibility.
        if (isset($data['status']) && in_array($data['status'], ['draft', 'published'], true)) {
            $sanitized['status'] = $data['status'];
        }

        // Sibling sort order within the parent (issue #69). Integer; absence
        // means "legacy" — the comparator keeps such pages in filesystem order.
        if (isset($data['order']) && is_numeric($data['order'])) {
            $sanitized['order'] = (int)$data['order'];
        }

        if (isset($data['layout']['rows']) && is_array($data['layout']['rows'])) {
            foreach ($data['layout']['rows'] as $row) {
                if (isset($row['widgets']) && is_array($row['widgets'])) {
                    $sanitizedWidgets = [];

                    foreach ($row['widgets'] as $widget) {
                        $sanitizedWidget = $this->sanitizeWidget($widget);
                        if ($sanitizedWidget) {
                            $sanitizedWidgets[] = $sanitizedWidget;
                        } else {
                            // Log when widgets are dropped for debugging
                            $this->logger->warning('[validateAndSanitizePage] Widget dropped during validation', [
                                'type' => $widget['type'] ?? 'unknown',
                                'reason' => 'validation_failed'
                            ]);
                        }
                    }

                    $sanitizedRow = ['widgets' => $sanitizedWidgets];

                    // Preserve row ID if set (needed for collapsible state tracking)
                    if (isset($row['id'])) {
                        $sanitizedRow['id'] = $this->sanitizeText($row['id']);
                    }

                    // Preserve row-specific column count if set
                    if (isset($row['columns'])) {
                        $sanitizedRow['columns'] = $this->validateColumns($row['columns']);
                    }

                    // Preserve row background color if set
                    if (isset($row['backgroundColor'])) {
                        $sanitizedRow['backgroundColor'] = $this->sanitizeBackgroundColor($row['backgroundColor']);
                    }

                    // Preserve collapsible row settings
                    if (isset($row['collapsible'])) {
                        $sanitizedRow['collapsible'] = (bool)$row['collapsible'];
                    }
                    if (isset($row['sectionTitle'])) {
                        $sanitizedRow['sectionTitle'] = $this->sanitizeText($row['sectionTitle']);
                    }
                    if (isset($row['defaultCollapsed'])) {
                        $sanitizedRow['defaultCollapsed'] = (bool)$row['defaultCollapsed'];
                    }

                    // Keep row if it has widgets OR a background color OR is collapsible (don't silently drop empty styled/collapsible rows)
                    if (!empty($sanitizedWidgets) || !empty($sanitizedRow['backgroundColor']) || !empty($sanitizedRow['collapsible'])) {
                        $sanitized['layout']['rows'][] = $sanitizedRow;
                    }
                }
            }
        }

        // Validate and sanitize side columns
        if (isset($data['layout']['sideColumns']) && is_array($data['layout']['sideColumns'])) {
            $sanitized['layout']['sideColumns'] = $this->sanitizeSideColumns($data['layout']['sideColumns']);
        }

        // Validate and sanitize header row
        if (isset($data['layout']['headerRow']) && is_array($data['layout']['headerRow'])) {
            $sanitized['layout']['headerRow'] = $this->sanitizeHeaderRow($data['layout']['headerRow']);
        }

        return $sanitized;
    }

    /**
     * Sanitize side columns data
     */
    private function sanitizeSideColumns(array $sideColumns): array {
        $sanitized = [];

        foreach (['left', 'right'] as $side) {
            if (isset($sideColumns[$side]) && is_array($sideColumns[$side])) {
                $sideData = $sideColumns[$side];

                $sanitizedSide = [
                    'enabled' => !empty($sideData['enabled']),
                    'backgroundColor' => isset($sideData['backgroundColor'])
                        ? $this->sanitizeBackgroundColor($sideData['backgroundColor'])
                        : '',
                    'widgets' => []
                ];

                // Sanitize widgets in this side column
                if (isset($sideData['widgets']) && is_array($sideData['widgets'])) {
                    foreach ($sideData['widgets'] as $widget) {
                        $sanitizedWidget = $this->sanitizeWidget($widget);
                        if ($sanitizedWidget) {
                            $sanitizedSide['widgets'][] = $sanitizedWidget;
                        }
                    }
                }

                $sanitized[$side] = $sanitizedSide;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize header row data
     */
    private function sanitizeHeaderRow(array $headerRow): array {
        $sanitized = [
            'enabled' => !empty($headerRow['enabled']),
            'backgroundColor' => isset($headerRow['backgroundColor'])
                ? $this->sanitizeBackgroundColor($headerRow['backgroundColor'])
                : '',
            'widgets' => []
        ];

        // Sanitize widgets in header row
        if (isset($headerRow['widgets']) && is_array($headerRow['widgets'])) {
            foreach ($headerRow['widgets'] as $widget) {
                $sanitizedWidget = $this->sanitizeWidget($widget);
                if ($sanitizedWidget) {
                    $sanitized['widgets'][] = $sanitizedWidget;
                }
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize widget data
     */
    /**
     * Sanitize a widget's viewer-side facet configuration.
     *
     * One helper shared by every faceted widget type. Four copies is how
     * they drift, and a key that is not enumerated here disappears on the
     * first save with no error at all — the same failure mode that quietly
     * ate `showPagination`.
     *
     * @param mixed $raw
     * @param string $fieldPattern regex a facet field name must match
     */
    private function sanitizeViewerFilters($raw, string $fieldPattern): array {
        $default = [
            'enabled' => false,
            'facets' => [],
            'searchFields' => [],
            'searchEnabled' => true,
            'layout' => 'sidebar',
        ];

        if (!is_array($raw)) {
            return $default;
        }

        $facets = [];
        if (isset($raw['facets']) && is_array($raw['facets'])) {
            foreach ($raw['facets'] as $entry) {
                if (count($facets) >= 12) {
                    break;
                }

                // Accept both a bare field name and a full config object.
                $field = is_array($entry) ? (string)($entry['field'] ?? '') : (string)$entry;
                $field = trim($field);
                if ($field === '' || !preg_match($fieldPattern, $field)) {
                    continue;
                }

                $limit = is_array($entry) ? (int)($entry['limit'] ?? 8) : 8;
                $limit = max(5, min($limit, 100));

                $facets[] = [
                    'field' => $field,
                    'label' => is_array($entry) ? $this->sanitizeText((string)($entry['label'] ?? '')) : '',
                    'limit' => $limit,
                    'collapsed' => is_array($entry) && ($entry['collapsed'] ?? false) === true,
                ];
            }
        }

        $searchFields = [];
        if (isset($raw['searchFields']) && is_array($raw['searchFields'])) {
            foreach ($raw['searchFields'] as $entry) {
                if (count($searchFields) >= 8) {
                    break;
                }
                $field = trim((string)$entry);
                if ($field !== '' && preg_match($fieldPattern, $field)) {
                    $searchFields[] = $field;
                }
            }
        }

        return [
            'enabled' => ($raw['enabled'] ?? false) === true,
            'facets' => $facets,
            'searchFields' => array_values(array_unique($searchFields)),
            'searchEnabled' => ($raw['searchEnabled'] ?? true) !== false,
            'layout' => ($raw['layout'] ?? 'sidebar') === 'top' ? 'top' : 'sidebar',
        ];
    }

    private function sanitizeWidget(array $widget): ?array {
        if (!isset($widget['type']) || !in_array($widget['type'], self::ALLOWED_WIDGET_TYPES)) {
            return null;
        }

        $sanitized = [
            'type' => $widget['type'],
            'column' => max(1, min((int)($widget['column'] ?? 1), self::MAX_COLUMNS)),
            'order' => (int)($widget['order'] ?? 1)
        ];

        // Preserve widget ID if present (needed for frontend to identify widgets)
        if (isset($widget['id'])) {
            $sanitized['id'] = $this->sanitizeText($widget['id']);
        }

        switch ($widget['type']) {
            case 'text':
                // Text widgets now contain HTML from rich text editor - sanitize HTML not text
                $sanitized['content'] = $this->sanitizeHtml($widget['content'] ?? '');
                break;

            case 'heading':
                $sanitized['content'] = $this->sanitizeText($widget['content'] ?? '');
                $sanitized['level'] = max(1, min((int)($widget['level'] ?? 2), 6));
                break;

            case 'image':
                $sanitized['src'] = $this->sanitizePath($widget['src'] ?? '');
                $sanitized['alt'] = $this->sanitizeText($widget['alt'] ?? '');
                // Preserve optional image properties
                if (isset($widget['width'])) {
                    $sanitized['width'] = $this->sanitizeText((string)($widget['width'] ?? ''));
                }
                if (isset($widget['objectFit'])) {
                    $allowedFits = ['cover', 'contain', 'fill', 'none', 'scale-down'];
                    $sanitized['objectFit'] = in_array($widget['objectFit'], $allowedFits) ? $widget['objectFit'] : 'cover';
                }
                if (isset($widget['objectPosition'])) {
                    $allowedPositions = ['center', 'top', 'bottom', 'left', 'right'];
                    $sanitized['objectPosition'] = in_array($widget['objectPosition'], $allowedPositions) ? $widget['objectPosition'] : 'center';
                }
                // Preserve mediaFolder property (for _resources folder media)
                if (isset($widget['mediaFolder'])) {
                    $allowedFolders = ['page', 'resources'];
                    $sanitized['mediaFolder'] = in_array($widget['mediaFolder'], $allowedFolders) ? $widget['mediaFolder'] : 'page';
                }
                // Preserve image link properties
                if (isset($widget['linkType'])) {
                    $allowedLinkTypes = ['none', 'internal', 'external'];
                    $sanitized['linkType'] = in_array($widget['linkType'], $allowedLinkTypes) ? $widget['linkType'] : 'none';
                }
                if (isset($widget['linkUrl'])) {
                    $sanitized['linkUrl'] = $this->sanitizeUrl($widget['linkUrl']);
                }
                if (isset($widget['linkPageId'])) {
                    $sanitized['linkPageId'] = $this->sanitizeText($widget['linkPageId']);
                }
                break;

            case 'links':
                $sanitized['items'] = [];
                if (isset($widget['items']) && is_array($widget['items'])) {
                    foreach ($widget['items'] as $link) {
                        $sanitizedLink = [];
                        // Preserve title if present
                        if (isset($link['title'])) {
                            $sanitizedLink['title'] = $this->sanitizeText($link['title']);
                        }
                        // Use sanitizeHtml for link text to allow HTML entities and formatting
                        $sanitizedLink['text'] = $this->sanitizeHtml($link['text'] ?? '');
                        $sanitizedLink['url'] = $this->sanitizeUrl($link['url'] ?? '');
                        $sanitizedLink['icon'] = $this->sanitizeText($link['icon'] ?? '');
                        // Preserve uniqueId for internal page links
                        if (isset($link['uniqueId']) && !empty($link['uniqueId'])) {
                            $sanitizedLink['uniqueId'] = $this->sanitizeText($link['uniqueId']);
                        }
                        // Preserve target attribute
                        if (isset($link['target'])) {
                            $allowedTargets = ['_self', '_blank'];
                            $sanitizedLink['target'] = in_array($link['target'], $allowedTargets) ? $link['target'] : '_self';
                        }
                        if (isset($link['backgroundColor'])) {
                            $sanitizedLink['backgroundColor'] = $this->sanitizeBackgroundColor($link['backgroundColor']);
                        }
                        $sanitized['items'][] = $sanitizedLink;
                    }
                }
                $sanitized['columns'] = max(1, min((int)($widget['columns'] ?? 2), 4));
                if (isset($widget['layout'])) {
                    $allowedLayouts = ['list', 'tiles'];
                    $sanitized['layout'] = in_array($widget['layout'], $allowedLayouts) ? $widget['layout'] : 'list';
                }
                if (isset($widget['backgroundColor'])) {
                    $sanitized['backgroundColor'] = $this->sanitizeBackgroundColor($widget['backgroundColor']);
                }
                break;

            case 'divider':
                // Preserve divider styling properties
                if (isset($widget['style'])) {
                    $allowedStyles = ['solid', 'dashed', 'dotted'];
                    $sanitized['style'] = in_array($widget['style'], $allowedStyles) ? $widget['style'] : 'solid';
                }
                if (isset($widget['color'])) {
                    $sanitized['color'] = $this->sanitizeBackgroundColor($widget['color']);
                }
                if (isset($widget['height'])) {
                    // Allow valid CSS height values like "2px", "1rem", etc.
                    $sanitized['height'] = preg_match('/^\d+(px|rem|em|%)$/', $widget['height'])
                        ? $widget['height']
                        : '2px';
                }
                break;

            case 'video':
                // Video widget - embed URL or local file
                // Supports: 'embed' (generic URL), 'local' (uploaded file)
                // Legacy 'peertube' is treated as 'embed' for backwards compatibility
                $provider = ($widget['provider'] ?? 'embed') === 'local' ? 'local' : 'embed';
                $sanitized['provider'] = $provider;
                $sanitized['title'] = $this->sanitizeText($widget['title'] ?? '');

                if ($provider === 'embed') {
                    // FIX: Check src (embed URL) first, then fallback to originalSrc
                    // The frontend converts youtube.com → youtube-nocookie.com in src
                    // but preserves the original URL in originalSrc
                    // We need to validate src (the embed URL) against the whitelist
                    $srcUrl = $widget['src'] ?? '';
                    $originalUrl = $widget['originalSrc'] ?? '';

                    // Validate src first (converted embed URL), fallback to originalSrc
                    $urlToValidate = !empty($srcUrl) ? $srcUrl : $originalUrl;
                    $sanitizedUrl = $this->sanitizeVideoEmbedUrl($urlToValidate);

                    if ($sanitizedUrl === '' && !empty($originalUrl)) {
                        // URL was blocked - preserve original URL so it can work again
                        // if admin adds the domain to whitelist later
                        $sanitized['src'] = '';
                        $sanitized['originalSrc'] = $originalUrl; // Preserve for later
                        $sanitized['blocked'] = true;
                        // Show blockedDomain based on what we validated
                        $blockedHost = !empty($srcUrl)
                            ? parse_url($srcUrl, PHP_URL_HOST)
                            : parse_url($originalUrl, PHP_URL_HOST);
                        $sanitized['blockedDomain'] = $blockedHost ?? '';
                    } else {
                        $sanitized['src'] = $sanitizedUrl;
                        $sanitized['originalSrc'] = $originalUrl ?: $sanitizedUrl; // Always preserve original
                        $sanitized['blocked'] = false;
                    }
                } else {
                    // Local video file - sanitize path
                    $sanitized['src'] = $this->sanitizePath($widget['src'] ?? '');
                    $sanitized['blocked'] = false;
                }

                // Preserve mediaFolder property (for _resources folder media)
                if (isset($widget['mediaFolder'])) {
                    $allowedFolders = ['page', 'resources'];
                    $sanitized['mediaFolder'] = in_array($widget['mediaFolder'], $allowedFolders) ? $widget['mediaFolder'] : 'page';
                }

                // Playback options (boolean values)
                $sanitized['autoplay'] = (bool) ($widget['autoplay'] ?? false);
                $sanitized['loop'] = (bool) ($widget['loop'] ?? false);
                $sanitized['muted'] = (bool) ($widget['muted'] ?? false);
                break;

            case 'news':
                // News widget - displays pages from a folder with optional MetaVox filters
                $sanitized['title'] = $this->sanitizeText($widget['title'] ?? '');
                $sanitized['sourcePath'] = $this->sanitizePath($widget['sourcePath'] ?? '');
                // sourcePageId is the uniqueId of the source page/folder (new PageTreeSelect approach)
                $sanitized['sourcePageId'] = isset($widget['sourcePageId']) && !empty($widget['sourcePageId'])
                    ? preg_replace('/[^a-zA-Z0-9_-]/', '', $widget['sourcePageId'])
                    : null;

                // Layout options
                $allowedLayouts = ['list', 'grid', 'carousel'];
                $sanitized['layout'] = in_array($widget['layout'] ?? 'list', $allowedLayouts)
                    ? $widget['layout']
                    : 'list';

                // Grid columns (2-4)
                $sanitized['columns'] = max(2, min((int)($widget['columns'] ?? 3), 4));

                // Limit (1-20 items)
                $sanitized['limit'] = max(1, min((int)($widget['limit'] ?? 5), 20));

                // Sort options
                $allowedSortBy = ['modified', 'title'];
                $sanitized['sortBy'] = in_array($widget['sortBy'] ?? 'modified', $allowedSortBy)
                    ? $widget['sortBy']
                    : 'modified';

                $allowedSortOrder = ['asc', 'desc'];
                $sanitized['sortOrder'] = in_array($widget['sortOrder'] ?? 'desc', $allowedSortOrder)
                    ? $widget['sortOrder']
                    : 'desc';

                // Display options (booleans)
                $sanitized['showImage'] = (bool)($widget['showImage'] ?? true);
                $sanitized['showDate'] = (bool)($widget['showDate'] ?? true);
                $sanitized['showExcerpt'] = (bool)($widget['showExcerpt'] ?? true);
                $sanitized['excerptLength'] = max(50, min((int)($widget['excerptLength'] ?? 100), 500));

                // Carousel autoplay interval (0-30 seconds, 0 = disabled)
                $sanitized['autoplayInterval'] = max(0, min((int)($widget['autoplayInterval'] ?? 5), 30));

                // Background color — editor exposes a three-option toggle (default /
                // hover / primary). Validated against the same whitelist used for
                // rows and link items.
                if (isset($widget['backgroundColor'])) {
                    $sanitized['backgroundColor'] = $this->sanitizeBackgroundColor($widget['backgroundColor']);
                }

                // MetaVox filters
                $sanitized['filters'] = [];
                if (isset($widget['filters']) && is_array($widget['filters'])) {
                    foreach ($widget['filters'] as $filter) {
                        if (isset($filter['fieldName']) && !empty($filter['fieldName'])) {
                            $allowedOperators = [
                                // Text
                                'equals', 'contains', 'not_contains', 'in',
                                // Empty
                                'not_empty', 'empty',
                                // Date
                                'before', 'after',
                                // Number
                                'greater_than', 'less_than', 'greater_or_equal', 'less_or_equal',
                                // Checkbox
                                'is_true', 'is_false',
                                // Multiselect
                                'contains_all',
                            ];
                            $sanitizedFilter = [
                                'fieldName' => $this->sanitizeText($filter['fieldName']),
                                'operator' => in_array($filter['operator'] ?? 'equals', $allowedOperators)
                                    ? $filter['operator']
                                    : 'equals',
                                'value' => $this->sanitizeText((string)($filter['value'] ?? '')),
                                'values' => [],
                            ];

                            // Sanitize values array (for 'in', 'contains', 'contains_all' operators)
                            if (isset($filter['values']) && is_array($filter['values'])) {
                                $sanitizedFilter['values'] = array_map(
                                    fn($v) => $this->sanitizeText((string)$v),
                                    $filter['values']
                                );
                            }

                            $sanitized['filters'][] = $sanitizedFilter;
                        }
                    }
                }

                $allowedFilterOperators = ['AND', 'OR'];
                $sanitized['filterOperator'] = in_array($widget['filterOperator'] ?? 'AND', $allowedFilterOperators)
                    ? $widget['filterOperator']
                    : 'AND';

                // Publication date filter (show only published pages)
                $sanitized['filterPublished'] = (bool)($widget['filterPublished'] ?? false);
                break;

            case 'people':
                // People widget - displays user profiles
                $sanitized['title'] = $this->sanitizeText($widget['title'] ?? '');

                // Selection mode
                $allowedModes = ['manual', 'filter'];
                $sanitized['selectionMode'] = in_array($widget['selectionMode'] ?? 'manual', $allowedModes)
                    ? $widget['selectionMode']
                    : 'manual';

                // Selected users (array of user IDs for manual mode)
                $sanitized['selectedUsers'] = [];
                if (isset($widget['selectedUsers']) && is_array($widget['selectedUsers'])) {
                    foreach ($widget['selectedUsers'] as $userId) {
                        // User IDs are alphanumeric strings
                        $sanitizedUserId = preg_replace('/[^a-zA-Z0-9_.@\-]/', '', (string)$userId);
                        if (!empty($sanitizedUserId)) {
                            $sanitized['selectedUsers'][] = $sanitizedUserId;
                        }
                    }
                }

                // Filters (for filter mode)
                $sanitized['filters'] = [];
                if (isset($widget['filters']) && is_array($widget['filters'])) {
                    foreach ($widget['filters'] as $filter) {
                        if (isset($filter['fieldName']) && !empty($filter['fieldName'])) {
                            $allowedOperators = [
                                'equals', 'contains', 'not_contains', 'in', 'not_empty', 'empty',
                                // Date operators
                                'is_today', 'within_next_days', 'before', 'after',
                            ];
                            $sanitizedFilter = [
                                'fieldName' => $this->sanitizeText($filter['fieldName']),
                                'operator' => in_array($filter['operator'] ?? 'equals', $allowedOperators)
                                    ? $filter['operator']
                                    : 'equals',
                                'value' => $this->sanitizeFilterValue((string)($filter['value'] ?? '')),
                                'values' => [],
                            ];

                            // Sanitize values array (for 'in' operator)
                            if (isset($filter['values']) && is_array($filter['values'])) {
                                $sanitizedFilter['values'] = array_map(
                                    fn($v) => $this->sanitizeFilterValue((string)$v),
                                    $filter['values']
                                );
                            }

                            $sanitized['filters'][] = $sanitizedFilter;
                        }
                    }
                }

                $allowedFilterOperators = ['AND', 'OR'];
                $sanitized['filterOperator'] = in_array($widget['filterOperator'] ?? 'AND', $allowedFilterOperators)
                    ? $widget['filterOperator']
                    : 'AND';

                // Layout options
                $allowedLayouts = ['card', 'list', 'grid'];
                $sanitized['layout'] = in_array($widget['layout'] ?? 'card', $allowedLayouts)
                    ? $widget['layout']
                    : 'card';

                // Grid/card columns (2-4)
                $sanitized['columns'] = max(2, min((int)($widget['columns'] ?? 3), 4));

                // Limit (1-50 people)
                $sanitized['limit'] = max(1, min((int)($widget['limit'] ?? 12), 50));

                // Sort options
                $allowedSortBy = ['displayName', 'email'];
                $sanitized['sortBy'] = in_array($widget['sortBy'] ?? 'displayName', $allowedSortBy)
                    ? $widget['sortBy']
                    : 'displayName';

                $allowedSortOrder = ['asc', 'desc'];
                $sanitized['sortOrder'] = in_array($widget['sortOrder'] ?? 'asc', $allowedSortOrder)
                    ? $widget['sortOrder']
                    : 'asc';

                // Display options (showFields object)
                $sanitized['showFields'] = [
                    // Basic information
                    'avatar' => (bool)($widget['showFields']['avatar'] ?? true),
                    'displayName' => (bool)($widget['showFields']['displayName'] ?? true),
                    'pronouns' => (bool)($widget['showFields']['pronouns'] ?? false),
                    'role' => (bool)($widget['showFields']['role'] ?? true),
                    'headline' => (bool)($widget['showFields']['headline'] ?? false),
                    'department' => (bool)($widget['showFields']['department'] ?? true),
                    'title' => (bool)($widget['showFields']['title'] ?? ($widget['showFields']['role'] ?? true)),
                    // Contact
                    'email' => (bool)($widget['showFields']['email'] ?? true),
                    'phone' => (bool)($widget['showFields']['phone'] ?? false),
                    'address' => (bool)($widget['showFields']['address'] ?? false),
                    'website' => (bool)($widget['showFields']['website'] ?? false),
                    'birthdate' => (bool)($widget['showFields']['birthdate'] ?? false),
                    // Extended
                    'biography' => (bool)($widget['showFields']['biography'] ?? false),
                    'socialLinks' => (bool)($widget['showFields']['socialLinks'] ?? false),
                    'customFields' => (bool)($widget['showFields']['customFields'] ?? false),
                ];

                // Pagination toggle. Read by PeopleWidget.vue but never
                // persisted here, so it silently reset on every save.
                $sanitized['showPagination'] = ($widget['showPagination'] ?? true) !== false;

                // Viewer-side facet configuration
                $sanitized['viewerFilters'] = $this->sanitizeViewerFilters(
                    $widget['viewerFilters'] ?? null,
                    '/^[a-z][a-z0-9_]{0,63}$/i'
                );

                // Background color
                if (isset($widget['backgroundColor'])) {
                    $sanitized['backgroundColor'] = $this->sanitizeBackgroundColor($widget['backgroundColor']);
                }
                break;

            case 'calendar':
                // Calendar widget - displays events from shared calendars
                $sanitized['title'] = $this->sanitizeText($widget['title'] ?? '');

                // Calendar keys (array of strings)
                $sanitized['calendarIds'] = [];
                if (isset($widget['calendarIds']) && is_array($widget['calendarIds'])) {
                    foreach ($widget['calendarIds'] as $id) {
                        $strId = trim((string) $id);
                        if ($strId !== '') {
                            $sanitized['calendarIds'][] = $strId;
                        }
                    }
                }

                // External ICS URLs (array of HTTPS URLs, max 5)
                $sanitized['externalIcsUrls'] = [];
                if (isset($widget['externalIcsUrls']) && is_array($widget['externalIcsUrls'])) {
                    foreach (array_slice($widget['externalIcsUrls'], 0, 5) as $url) {
                        $url = trim((string) $url);
                        if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https') {
                            $sanitized['externalIcsUrls'][] = $url;
                        }
                    }
                }

                // Date range
                $allowedRanges = ['upcoming', 'this_week', 'next_two_weeks', 'this_month', 'next_three_months', 'next_six_months', 'next_year', 'past_week', 'past_month', 'past_three_months'];
                $sanitized['dateRange'] = in_array($widget['dateRange'] ?? 'upcoming', $allowedRanges)
                    ? $widget['dateRange']
                    : 'upcoming';

                // Limit (1-20 events)
                $sanitized['limit'] = max(1, min((int) ($widget['limit'] ?? 5), 20));

                // Display options
                $sanitized['showTime'] = (bool) ($widget['showTime'] ?? true);
                $sanitized['showLocation'] = (bool) ($widget['showLocation'] ?? false);

                // Background color
                if (isset($widget['backgroundColor'])) {
                    $sanitized['backgroundColor'] = $this->sanitizeBackgroundColor($widget['backgroundColor']);
                }
                break;

            case 'photo-story':
                $config = is_array($widget['config'] ?? null) ? $widget['config'] : [];
                $sanitizedConfig = [];
                $sanitizedConfig['folderPath'] = $this->sanitizeFolderPath($config['folderPath'] ?? '');
                $allowedModes = ['timeline', 'highlights', 'grid', 'on-this-day'];
                $sanitizedConfig['mode'] = in_array($config['mode'] ?? 'timeline', $allowedModes, true)
                    ? $config['mode']
                    : 'timeline';
                // Long-list handling: infinite scroll (default) or page buttons.
                $sanitizedConfig['paginationMode'] = (($config['paginationMode'] ?? 'infinite') === 'pages')
                    ? 'pages' : 'infinite';
                // Photos per page in page-buttons mode. Separate from `limit`,
                // which stays the total cap across the whole list.
                if (isset($config['pageSize']) && $config['pageSize'] !== '' && $config['pageSize'] !== null) {
                    $sanitizedConfig['pageSize'] = max(1, min((int)$config['pageSize'], 500));
                }
                if (isset($config['limit']) && $config['limit'] !== '' && $config['limit'] !== null) {
                    $sanitizedConfig['limit'] = max(1, min((int)$config['limit'], 500));
                }
                $sanitizedConfig['columns'] = max(2, min((int)($config['columns'] ?? 3), 5));
                $sanitizedConfig['showCaptions'] = !isset($config['showCaptions']) || (bool)$config['showCaptions'];
                $sanitizedConfig['showMap'] = !empty($config['showMap']);
                // Phase 2.8 — per-day mini-map. Default true so existing pages get them.
                $sanitizedConfig['showDayMaps'] = !isset($config['showDayMaps']) || (bool)$config['showDayMaps'];

                // Sort direction. Default 'desc' (newest first).
                $sanitizedConfig['sortOrder'] = (($config['sortOrder'] ?? 'desc') === 'asc') ? 'asc' : 'desc';

                // Sort key. Accepts file-level columns (mtime/name/size), the
                // virtual 'taken_at' (NC core), or any MetaVox field name. Pattern
                // restriction prevents nonsense input from reaching the backend
                // where it would be ignored anyway, but keeps the page-JSON tidy.
                $rawSortBy = (string)($config['sortBy'] ?? 'mtime');
                $sanitizedConfig['sortBy'] = preg_match('/^[a-z][a-z0-9_]{0,63}$/i', $rawSortBy)
                    ? $rawSortBy : 'mtime';

                // Phase 2.4 — cross-folder mode + MetaVox filter rows.
                $sanitizedConfig['allMetaVoxFolders'] = !empty($config['allMetaVoxFolders']);
                $rawFilters = is_array($config['metaVoxFilters'] ?? null) ? $config['metaVoxFilters'] : [];
                $allowedOps = ['equals', 'contains', 'in', 'year_equals'];
                $cleanFilters = [];
                foreach ($rawFilters as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $field = isset($entry['field']) ? (string)$entry['field'] : '';
                    $op = isset($entry['op']) ? (string)$entry['op'] : '';
                    $val = $entry['value'] ?? '';
                    if ($field === '' || !preg_match('/^exif_[a-z_]+$/', $field)) {
                        continue;
                    }
                    if (!in_array($op, $allowedOps, true)) {
                        continue;
                    }
                    if (is_array($val)) {
                        $coerced = [];
                        foreach ($val as $v) {
                            $s = is_scalar($v) ? trim((string)$v) : '';
                            if ($s !== '') {
                                $coerced[] = mb_substr($s, 0, 200);
                            }
                        }
                        if (empty($coerced)) {
                            continue;
                        }
                        $val = array_values($coerced);
                    } else {
                        $s = is_scalar($val) ? trim((string)$val) : '';
                        if ($s === '') {
                            continue;
                        }
                        $val = mb_substr($s, 0, 200);
                    }
                    $cleanFilters[] = ['field' => $field, 'op' => $op, 'value' => $val];
                }
                $sanitizedConfig['metaVoxFilters'] = $cleanFilters;

                // Visual style (already used in the editor but wasn't persisted yet — add it here)
                $allowedStyles = ['magazine', 'apple', 'travelogue'];
                $sanitizedConfig['style'] = in_array($config['style'] ?? 'apple', $allowedStyles, true)
                    ? $config['style']
                    : 'apple';

                $sanitized['config'] = $sanitizedConfig;
                break;

            case 'file-story':
                // FileStoryWidget — documents counterpart of photo-story.
                // Lighter sanitization since it has fewer config knobs (no map,
                // no visual styles, no day-maps, no cross-folder mode).
                $config = is_array($widget['config'] ?? null) ? $widget['config'] : [];
                $sanitizedConfig = [];
                $sanitizedConfig['folderPath'] = $this->sanitizeFolderPath($config['folderPath'] ?? '');
                $allowedModes = ['timeline', 'tiles', 'list', 'grouped'];
                $sanitizedConfig['mode'] = in_array($config['mode'] ?? 'timeline', $allowedModes, true)
                    ? $config['mode'] : 'timeline';
                // Long-list handling: infinite scroll (default) or page buttons (#78).
                $sanitizedConfig['paginationMode'] = (($config['paginationMode'] ?? 'infinite') === 'pages')
                    ? 'pages' : 'infinite';
                // Documents per page in page-buttons mode. Separate from `limit`,
                // which stays the total cap across the whole list (#78).
                if (isset($config['pageSize']) && $config['pageSize'] !== '' && $config['pageSize'] !== null) {
                    $sanitizedConfig['pageSize'] = max(1, min((int)$config['pageSize'], 500));
                }
                if (isset($config['limit']) && $config['limit'] !== '' && $config['limit'] !== null) {
                    $sanitizedConfig['limit'] = max(1, min((int)$config['limit'], 500));
                }
                $sanitizedConfig['sortOrder'] = (($config['sortOrder'] ?? 'desc') === 'asc') ? 'asc' : 'desc';
                $rawSortBy = (string)($config['sortBy'] ?? 'mtime');
                $sanitizedConfig['sortBy'] = preg_match('/^[a-z][a-z0-9_]{0,63}$/i', $rawSortBy)
                    ? $rawSortBy : 'mtime';
                $rawGroupBy = (string)($config['groupBy'] ?? 'category');
                $sanitizedConfig['groupBy'] = preg_match('/^[a-z][a-z0-9_]{0,63}$/i', $rawGroupBy)
                    ? $rawGroupBy : 'category';

                // Timeline granularity: day / month / year. Default "month" for
                // documents because per-day buckets are usually too fine here.
                $rawGran = (string)($config['granularity'] ?? 'month');
                $sanitizedConfig['granularity'] = in_array($rawGran, ['day', 'month', 'year'], true)
                    ? $rawGran : 'month';

                // Date field preference: which timestamp to display in the row.
                $rawDateField = (string)($config['dateField'] ?? 'mtime');
                $sanitizedConfig['dateField'] = in_array($rawDateField, ['mtime', 'taken_at', 'created'], true)
                    ? $rawDateField : 'mtime';

                // Visible columns: whitelist-filter the user-supplied list.
                $allowedCols = ['date', 'size', 'path'];
                $rawCols = is_array($config['visibleColumns'] ?? null) ? $config['visibleColumns'] : ['date'];
                $cleanCols = [];
                foreach ($rawCols as $col) {
                    if (is_string($col) && in_array($col, $allowedCols, true) && !in_array($col, $cleanCols, true)) {
                        $cleanCols[] = $col;
                    }
                }
                $sanitizedConfig['visibleColumns'] = $cleanCols;

                // Tile size — only meaningful in tiles-mode but persisted across
                // modes so toggling between modes keeps the user's previous choice.
                $rawTileSize = (string)($config['tileSize'] ?? 'medium');
                $sanitizedConfig['tileSize'] = in_array($rawTileSize, ['small', 'medium', 'large'], true)
                    ? $rawTileSize : 'medium';

                // Reuse the photo-story filter sanitization (same shape).
                $rawFilters = is_array($config['metaVoxFilters'] ?? null) ? $config['metaVoxFilters'] : [];
                $allowedOps = ['equals', 'contains', 'in', 'year_equals'];
                $cleanFilters = [];
                foreach ($rawFilters as $entry) {
                    if (!is_array($entry)) continue;
                    $field = isset($entry['field']) ? (string)$entry['field'] : '';
                    $op = isset($entry['op']) ? (string)$entry['op'] : '';
                    $val = $entry['value'] ?? '';
                    if ($field === '' || !preg_match('/^[a-z][a-z0-9_]{0,63}$/i', $field)) continue;
                    if (!in_array($op, $allowedOps, true)) continue;
                    if (is_array($val)) {
                        $coerced = [];
                        foreach ($val as $v) {
                            $s = is_scalar($v) ? trim((string)$v) : '';
                            if ($s !== '') $coerced[] = mb_substr($s, 0, 200);
                        }
                        if (empty($coerced)) continue;
                        $val = array_values($coerced);
                    } else {
                        $s = is_scalar($val) ? trim((string)$val) : '';
                        if ($s === '') continue;
                        $val = mb_substr($s, 0, 200);
                    }
                    $cleanFilters[] = ['field' => $field, 'op' => $op, 'value' => $val];
                }
                $sanitizedConfig['metaVoxFilters'] = $cleanFilters;

                $sanitized['config'] = $sanitizedConfig;
                break;

            case 'feed':
                // Feed widget - displays items from external RSS/Atom feeds or LMS APIs
                $sanitized['title'] = $this->sanitizeText($widget['title'] ?? '');

                // Source type — dynamically accept configured LMS types
                $configuredTypes = array_unique(array_column(
                    json_decode($this->config->getAppValue(Application::APP_ID, 'feed_connections', '[]'), true) ?: [],
                    'type'
                ));
                $allowedSourceTypes = array_unique(array_merge(['rss', 'connection'], $configuredTypes));
                $sanitized['sourceType'] = in_array($widget['sourceType'] ?? 'rss', $allowedSourceTypes)
                    ? $widget['sourceType']
                    : 'rss';

                // Feed URL (for RSS type)
                $sanitized['feedUrl'] = $this->sanitizeText($widget['feedUrl'] ?? '');

                // LMS connection ID and course ID
                $sanitized['connectionId'] = $this->sanitizeText($widget['connectionId'] ?? '');
                $sanitized['courseId'] = $this->sanitizeText($widget['courseId'] ?? '');

                // Content type (for LMS types)
                $allowedContentTypes = ['', 'news', 'my-courses', 'deadlines', 'courses', 'assignments', 'open', 'overdue', 'milestones', 'recently-updated', 'pages', 'documents', 'list', 'bugs', 'recent', 'created-recent'];
                $sanitized['contentType'] = in_array($widget['contentType'] ?? '', $allowedContentTypes, true)
                    ? ($widget['contentType'] ?? '')
                    : '';

                // SharePoint list/library ID, Jira project key, Moodle forum ID
                $sanitized['listId'] = $this->sanitizeText($widget['listId'] ?? '');
                $sanitized['jiraProject'] = $this->sanitizeText($widget['jiraProject'] ?? '');
                $sanitized['moodleForumId'] = $this->sanitizeText($widget['moodleForumId'] ?? '');

                // Layout
                $sanitized['layout'] = in_array($widget['layout'] ?? 'list', ['list', 'grid'])
                    ? $widget['layout']
                    : 'list';

                // Columns (for grid layout, 2-4)
                $sanitized['columns'] = max(2, min((int) ($widget['columns'] ?? 3), 4));

                // Limit (1-20 items)
                $sanitized['limit'] = max(1, min((int) ($widget['limit'] ?? 5), 20));

                // Display options
                $sanitized['showImage'] = (bool) ($widget['showImage'] ?? true);
                $sanitized['showDate'] = (bool) ($widget['showDate'] ?? true);
                $sanitized['showExcerpt'] = (bool) ($widget['showExcerpt'] ?? true);
                $sanitized['showSource'] = (bool) ($widget['showSource'] ?? false);
                $sanitized['excerptLength'] = max(50, min((int) ($widget['excerptLength'] ?? 150), 500));
                $sanitized['openInNewTab'] = (bool) ($widget['openInNewTab'] ?? true);

                // Sort and filter
                $sanitized['sortBy'] = in_array($widget['sortBy'] ?? 'date', ['date', 'title'], true) ? $widget['sortBy'] : 'date';
                $sanitized['sortOrder'] = in_array($widget['sortOrder'] ?? 'desc', ['asc', 'desc'], true) ? $widget['sortOrder'] : 'desc';
                $filterKeyword = trim((string) ($widget['filterKeyword'] ?? ''));
                $sanitized['filterKeyword'] = mb_substr($filterKeyword, 0, 100);

                // Background color
                if (isset($widget['backgroundColor'])) {
                    $sanitized['backgroundColor'] = $this->sanitizeBackgroundColor($widget['backgroundColor']);
                }
                break;
        }

        return $sanitized;
    }

    /**
     * Sanitize text content (prevent XSS)
     */
    private function sanitizeText(string $text): string {
        // Plain-text fields (page titles, widget titles, alt text, link labels,
        // …) are rendered in text contexts where the frontend escapes on output,
        // so we must NOT HTML-encode here — doing so stored apostrophes as
        // "&apos;", ampersands as "&amp;" etc. and showed the literal entities
        // in the UI (e.g. "Collega&apos;s"). Strip tags and control characters
        // so no markup can survive, but keep the text human-readable. Escaping
        // is the responsibility of each output sink (Vue escapes automatically;
        // the RSS/XML and HTML-export emitters escape at emit time).
        $text = strip_tags($text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        return trim($text);
    }

    /**
     * Sanitize a filter value for safe storage.
     * Unlike sanitizeText(), this does NOT HTML-encode because filter values
     * are used for programmatic comparison against raw user profile data.
     */
    private function sanitizeFilterValue(string $value): string {
        $value = strip_tags($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        return trim($value);
    }

    /**
     * Decode HTML entities in people widget filter values.
     * Fixes data corrupted by prior use of sanitizeText() (htmlspecialchars)
     * on filter values that are used for programmatic comparison.
     */
    private function decodeFilterValues(array &$widget): void {
        if (!isset($widget['filters']) || !is_array($widget['filters'])) {
            return;
        }
        foreach ($widget['filters'] as &$filter) {
            if (isset($filter['value']) && is_string($filter['value'])) {
                $filter['value'] = $this->decodeHtmlEntitiesRecursive($filter['value']);
            }
            if (isset($filter['values']) && is_array($filter['values'])) {
                $filter['values'] = array_map(
                    fn($v) => is_string($v) ? $this->decodeHtmlEntitiesRecursive($v) : $v,
                    $filter['values']
                );
            }
        }
    }

    /**
     * @deprecated Use HtmlSanitizer::decodeEntitiesRecursive directly.
     * Kept as a thin wrapper so internal call-sites continue to work; will be
     * removed once all call-sites are migrated to the injected sanitizer.
     */
    private function decodeHtmlEntitiesRecursive(string $value): string {
        return $this->htmlSanitizer->decodeEntitiesRecursive($value);
    }

    /**
     * @deprecated Use HtmlSanitizer::sanitize directly. See note above.
     */
    private function sanitizeHtml(string $html): string {
        return $this->htmlSanitizer->sanitize($html);
    }

    /**
     * Sanitize file path - prevent directory traversal and other path attacks
     *
     * Security checks:
     * - Null byte injection
     * - Unicode normalization (NFD/NFC attacks)
     * - Directory traversal (..)
     * - Backslash conversion
     * - Hidden files (starting with .)
     * - Executable file extensions
     *
     * @param string $path User-provided path
     * @return string Safe path
     * @throws \InvalidArgumentException if path is malicious
     */
    /**
     * Sanitize a folder-path that may be the root ("/"). PhotoStory and
     * FileStory widgets treat "/" as "the whole user drive" — a meaningful
     * value that must survive persistence. The generic sanitizePath() strips
     * leading/trailing slashes and would collapse "/" to "" (= "no folder
     * selected"), so widgets that allow root selection use this wrapper.
     */
    private function sanitizeFolderPath(string $path): string {
        $trimmed = trim($path);
        if ($trimmed === '/' || $trimmed === '\\') {
            return '/';
        }
        return $this->sanitizePath($path);
    }

    private function sanitizePath(string $path): string {
        // Allow empty paths (used for news widget sourcePath to indicate "all pages")
        if (empty($path)) {
            return '';
        }

        // 1. Check for null bytes (can bypass extension checks)
        if (strpos($path, "\0") !== false) {
            throw new \InvalidArgumentException('Null bytes not allowed in path');
        }

        // 2. Unicode normalization (prevent NFD/NFC attacks)
        if (class_exists('Normalizer')) {
            $normalized = \Normalizer::normalize($path, \Normalizer::FORM_C);
            if ($normalized === false) {
                throw new \InvalidArgumentException('Invalid unicode sequence in path');
            }
            $path = $normalized;
        }

        // 3. Convert backslashes to forward slashes
        $path = str_replace('\\', '/', $path);

        // 4. Remove leading/trailing slashes
        $path = trim($path, '/');

        // 5. If path becomes empty after trimming, return empty
        if (empty($path)) {
            return '';
        }

        // 6. Detect directory traversal attempts
        if (strpos($path, '..') !== false) {
            throw new \InvalidArgumentException('Path traversal not allowed');
        }

        // 7. Validate path segments
        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            // Empty segments (double slashes)
            if (empty($segment) || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('Invalid path segment');
            }

            // Hidden files (starting with dot)
            if (substr($segment, 0, 1) === '.') {
                throw new \InvalidArgumentException('Hidden files not allowed');
            }

            // Block executable PHP extensions
            if (preg_match('/\.(php|phtml|php[345]|phar|phps|pht)$/i', $segment)) {
                throw new \InvalidArgumentException('Executable files not allowed');
            }
        }

        return $path;
    }

    /**
     * @deprecated Use UrlSanitizer::sanitize directly. Thin wrapper for
     * existing call-sites; remove when migrated.
     */
    private function sanitizeUrl(string $url): string {
        return $this->urlSanitizer->sanitize($url);
    }

    /**
     * Get allowed video domains from config
     * @return array List of allowed HTTPS domains
     */
    private function getAllowedVideoDomains(): array {
        $domains = $this->config->getAppValue(
            'intravox',
            'video_domains',
            Constants::getDefaultVideoDomainsJson()
        );

        // Decode the stored JSON
        $decoded = json_decode($domains, true);

        // Only use defaults if JSON decode FAILED (null), not for empty array
        // This allows admins to explicitly block all video embeds by removing all domains
        if ($decoded === null) {
            return Constants::DEFAULT_VIDEO_DOMAINS;
        }

        return $decoded;
    }

    /**
     * Map of video platform domains to their embed domains.
     * When a user enters youtube.com, the frontend converts it to youtube-nocookie.com.
     * This mapping allows the whitelist check to recognize both.
     */
    private const VIDEO_DOMAIN_ALIASES = [
        // YouTube watch URLs → youtube-nocookie.com embed
        'www.youtube.com' => 'www.youtube-nocookie.com',
        'youtube.com' => 'www.youtube-nocookie.com',
        'm.youtube.com' => 'www.youtube-nocookie.com',
        // Vimeo watch URLs → player.vimeo.com embed
        'www.vimeo.com' => 'player.vimeo.com',
        'vimeo.com' => 'player.vimeo.com',
    ];

    /**
     * Base domains whose subdomains are ALL allowed when the base domain is on
     * the whitelist. Needed for providers that give each customer/space its own
     * subdomain — e.g. mave.io serves iframes from space-{hash}.video-dns.com,
     * so a single fixed allowlist entry can never match every space.
     *
     * Matching is boundary-safe (see sanitizeVideoEmbedUrl): the host must equal
     * the base OR end with '.' . $base, so evilvideo-dns.com and
     * video-dns.com.attacker.com are NOT matched.
     *
     * Keep this in sync with WILDCARD_VIDEO_DOMAINS in
     * src/components/WidgetEditor.vue (frontend Save-gate).
     */
    private const WILDCARD_VIDEO_DOMAINS = ['video-dns.com'];

    /**
     * Sanitize video embed URL
     * Validates against configured whitelist of allowed domains
     * Supports: YouTube, Vimeo, PeerTube, Dailymotion, Twitch, TikTok, etc.
     */
    private function sanitizeVideoEmbedUrl(string $url): string {
        if (empty($url)) {
            return '';
        }

        // Must be HTTPS
        if (!str_starts_with($url, 'https://')) {
            return '';
        }

        // Parse URL
        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['scheme']) || !isset($parsed['host']) || !isset($parsed['path'])) {
            return '';
        }

        // Check against whitelist
        $allowedDomains = $this->getAllowedVideoDomains();
        $host = $parsed['host'];

        // Check if this host has an alias (e.g., youtube.com → youtube-nocookie.com)
        $embedHost = self::VIDEO_DOMAIN_ALIASES[$host] ?? null;

        $isAllowed = false;
        foreach ($allowedDomains as $allowedDomain) {
            $allowedHost = parse_url($allowedDomain, PHP_URL_HOST);
            // Match either the original host OR its embed alias
            if ($host === $allowedHost || ($embedHost && $embedHost === $allowedHost)) {
                $isAllowed = true;
                break;
            }
            // Wildcard base domains (e.g. video-dns.com) also match any of their
            // subdomains. Boundary-safe: only true subdomains (leading '.') match.
            if (in_array($allowedHost, self::WILDCARD_VIDEO_DOMAINS, true)
                && str_ends_with($host, '.' . $allowedHost)) {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed) {
            $this->logger->warning('Video domain not in whitelist: ' . $host);
            return '';
        }

        // Convert watch URLs to embed URLs for known platforms
        // YouTube: https://www.youtube.com/watch?v=VIDEO_ID → https://www.youtube-nocookie.com/embed/VIDEO_ID
        if (in_array($host, ['www.youtube.com', 'youtube.com', 'm.youtube.com'])) {
            parse_str($parsed['query'] ?? '', $queryParams);
            $videoId = $queryParams['v'] ?? null;
            if ($videoId) {
                return 'https://www.youtube-nocookie.com/embed/' . urlencode($videoId);
            }
            // If already an embed URL or other format, pass through
            if (str_contains($parsed['path'], '/embed/')) {
                return 'https://www.youtube-nocookie.com' . $parsed['path'];
            }
        }

        // Vimeo: https://vimeo.com/VIDEO_ID → https://player.vimeo.com/video/VIDEO_ID
        if (in_array($host, ['www.vimeo.com', 'vimeo.com'])) {
            // Extract video ID from path like /123456789 or /123456789?h=xxxxx
            if (preg_match('#^/(\d+)#', $parsed['path'], $matches)) {
                $videoId = $matches[1];
                $embedUrl = 'https://player.vimeo.com/video/' . $videoId;
                // Preserve hash parameter for unlisted videos
                if (isset($parsed['query'])) {
                    parse_str($parsed['query'], $queryParams);
                    if (isset($queryParams['h'])) {
                        $embedUrl .= '?h=' . urlencode($queryParams['h']);
                    }
                }
                return $embedUrl;
            }
        }

        // For PeerTube URLs, enforce privacy settings
        if (str_contains($parsed['path'], '/videos/embed/')) {
            $cleanUrl = $parsed['scheme'] . '://' . $parsed['host'] . $parsed['path'];
            return $cleanUrl . '?p2p=0&peertubeLink=0';
        }

        // For other platforms, return the embed URL with existing query params
        $cleanUrl = $parsed['scheme'] . '://' . $parsed['host'] . $parsed['path'];
        if (isset($parsed['query'])) {
            $cleanUrl .= '?' . $parsed['query'];
        }
        return $cleanUrl;
    }

    /**
     * Validate column count
     */
    private function validateColumns(int $columns): int {
        return max(1, min($columns, self::MAX_COLUMNS));
    }

    /**
     * @deprecated Use ColorSanitizer::sanitize directly. Thin wrapper for
     * existing call-sites; remove when migrated.
     */
    private function sanitizeBackgroundColor(string $color): string {
        return $this->colorSanitizer->sanitize($color);
    }

    /**
     * Sanitize page for output (decode HTML entities for display)
     */
    private function sanitizePage(array $data): array {
        // Re-sanitize widgets on every read to apply current whitelist settings
        // This ensures blocked video domains are marked correctly even if the
        // whitelist changed after the page was saved

        if (isset($data['layout']['rows']) && is_array($data['layout']['rows'])) {
            foreach ($data['layout']['rows'] as $rowIndex => $row) {
                if (isset($row['widgets']) && is_array($row['widgets'])) {
                    foreach ($row['widgets'] as $widgetIndex => $widget) {
                        if (($widget['type'] ?? '') === 'video') {
                            $sanitized = $this->sanitizeWidget($widget);
                            if ($sanitized) {
                                $data['layout']['rows'][$rowIndex]['widgets'][$widgetIndex] = $sanitized;
                            }
                        } elseif (($widget['type'] ?? '') === 'people') {
                            $this->decodeFilterValues($data['layout']['rows'][$rowIndex]['widgets'][$widgetIndex]);
                        }
                    }
                }
            }
        }

        // Also sanitize side columns
        if (isset($data['layout']['sideColumns']) && is_array($data['layout']['sideColumns'])) {
            foreach (['left', 'right'] as $side) {
                if (isset($data['layout']['sideColumns'][$side]['widgets']) && is_array($data['layout']['sideColumns'][$side]['widgets'])) {
                    foreach ($data['layout']['sideColumns'][$side]['widgets'] as $widgetIndex => $widget) {
                        if (($widget['type'] ?? '') === 'video') {
                            $sanitized = $this->sanitizeWidget($widget);
                            if ($sanitized) {
                                $data['layout']['sideColumns'][$side]['widgets'][$widgetIndex] = $sanitized;
                            }
                        } elseif (($widget['type'] ?? '') === 'people') {
                            $this->decodeFilterValues($data['layout']['sideColumns'][$side]['widgets'][$widgetIndex]);
                        }
                    }
                }
            }
        }

        // Also sanitize header row
        if (isset($data['layout']['headerRow']['widgets']) && is_array($data['layout']['headerRow']['widgets'])) {
            foreach ($data['layout']['headerRow']['widgets'] as $widgetIndex => $widget) {
                if (($widget['type'] ?? '') === 'video') {
                    $sanitized = $this->sanitizeWidget($widget);
                    if ($sanitized) {
                        $data['layout']['headerRow']['widgets'][$widgetIndex] = $sanitized;
                    }
                } elseif (($widget['type'] ?? '') === 'people') {
                    $this->decodeFilterValues($data['layout']['headerRow']['widgets'][$widgetIndex]);
                }
            }
        }

        return $data;
    }

    /**
     * Get all versions of a page
     * Uses the standard IVersionManager interface for reliable version retrieval.
     * @throws \Exception if page not found
     */
    public function getPageVersions(string $pageId): array {
        $this->logger->info('[getPageVersions] START - Getting versions for page: ' . $pageId);

        $folder = $this->getLanguageFolder();
        $result = null;

        // Check for uniqueId pattern (page-xxxx) like getPage() does. Follows
        // the page across language folders so an operation on a page the user
        // can see never fails with "Page not found" (issue #90).
        if (strpos($pageId, 'page-') === 0) {
            $result = $this->locatePageAnyLanguage($folder, $pageId);
        }

        // Fall back to legacy ID lookup
        if ($result === null) {
            $result = $this->findPageById($folder, $this->sanitizeId($pageId));
        }

        if (!$result) {
            $this->logger->warning('[getPageVersions] Page not found: ' . $pageId);
            throw new \Exception('Page not found: ' . $pageId);
        }

        $file = $result['file'];
        $user = $this->userSession->getUser();

        $this->logger->info('[getPageVersions] File found: ' . $file->getPath() . ' (ID: ' . $file->getId() . ')');
        $this->logger->info('[getPageVersions] Storage class: ' . get_class($file->getStorage()));

        if (!$user) {
            $this->logger->warning('[getPageVersions] No user in session');
            return [];
        }

        $this->logger->info('[getPageVersions] User: ' . $user->getUID());

        if (!$this->versionManager) {
            $this->logger->warning('[getPageVersions] Version manager not available');
            return [];
        }

        $this->logger->info('[getPageVersions] Version manager class: ' . get_class($this->versionManager));

        try {
            $this->logger->info('[getPageVersions] Calling getVersionsForFile...');

            // Use IVersionManager - works for all storage types including GroupFolders
            $versions = $this->versionManager->getVersionsForFile($user, $file);

            $this->logger->info('[getPageVersions] IVersionManager returned ' . count($versions) . ' versions');

            // Get current file metadata (like Nextcloud Files app shows)
            $currentVersion = [
                'timestamp' => $file->getMTime(),
                'size' => $file->getSize(),
                'author' => $this->getCurrentFileAuthor($file),
                'relativeTime' => $this->formatRelativeTime($file->getMTime()),
            ];

            return [
                'currentVersion' => $currentVersion,
                'versions' => $this->formatVersionsFromBackend($versions),
            ];

        } catch (\Exception $e) {
            $this->logger->error('[getPageVersions] Failed to get page versions: ' . $e->getMessage(), [
                'pageId' => $pageId,
                'exception' => $e->getTraceAsString(),
            ]);
            return [
                'currentVersion' => null,
                'versions' => [],
            ];
        }
    }

    /**
     * Format versions from IVersionManager to array format for API response
     */
    /**
     * @deprecated Delegated to PageVersionFormatter::formatVersions.
     */
    private function formatVersionsFromBackend(array $versions): array {
        return $this->versionFormatter->formatVersions($versions);
    }

    /**
     * @deprecated Delegated to PageVersionFormatter::getAuthor.
     */
    private function getVersionAuthor(IVersion $version): ?string {
        return $this->versionFormatter->getAuthor($version);
    }

    /**
     * @deprecated Delegated to PageVersionFormatter::getLabel.
     */
    private function getVersionLabel(IVersion $version): ?string {
        return $this->versionFormatter->getLabel($version);
    }

    /**
     * Get the author of the current file (last modifier)
     * Uses the file owner as fallback since we don't track individual modifiers
     */
    private function getCurrentFileAuthor(\OCP\Files\File $file): ?string {
        try {
            // Try to get the owner of the file
            $owner = $file->getOwner();
            if ($owner !== null) {
                return $owner->getDisplayName() ?: $owner->getUID();
            }
            // Fallback to current user if available
            $user = $this->userSession->getUser();
            return $user ? ($user->getDisplayName() ?: $user->getUID()) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Format a version timestamp for display
     * Uses relative time format like Nextcloud Files app
     */
    /**
     * @deprecated Delegated to PageVersionFormatter::formatRelativeTime.
     */
    private function formatVersionDate(int $timestamp): string {
        return $this->versionFormatter->formatRelativeTime($timestamp);
    }

    /**
     * @deprecated Delegated to PageVersionFormatter::formatRelativeTime.
     */
    private function formatRelativeTime(int $timestamp): string {
        return $this->versionFormatter->formatRelativeTime($timestamp);
    }

    /**
     * Find a file by its ID within a folder
     */
    private function findFileByIdInFolder(\OCP\Files\Folder $folder, int $fileId): ?\OCP\Files\File {
        try {
            $files = $this->getCachedDirectoryListing($folder);
            foreach ($files as $item) {
                if ($item->getId() === $fileId && $item instanceof \OCP\Files\File) {
                    return $item;
                }
                if ($item instanceof \OCP\Files\Folder) {
                    $found = $this->findFileByIdInFolder($item, $fileId);
                    if ($found) {
                        return $found;
                    }
                }
            }
        } catch (\Exception $e) {
            // Error searching folder
        }
        return null;
    }

    /**
     * Restore a specific version of a page
     * Uses IVersionManager for reliable version restoration across all storage types.
     * @throws \Exception if page or version not found
     */
    public function restorePageVersion(string $pageId, int $timestamp): array {
        $folder = $this->getLanguageFolder();
        $result = null;

        // Check for uniqueId pattern (page-xxxx) like getPage() does. Follows
        // the page across language folders so an operation on a page the user
        // can see never fails with "Page not found" (issue #90).
        if (strpos($pageId, 'page-') === 0) {
            $result = $this->locatePageAnyLanguage($folder, $pageId);
        }

        // Fall back to legacy ID lookup
        if ($result === null) {
            $result = $this->findPageById($folder, $this->sanitizeId($pageId));
        }

        if (!$result) {
            throw new \Exception('Page not found: ' . $pageId);
        }

        $file = $result['file'];
        $user = $this->userSession->getUser();

        if (!$user) {
            throw new \Exception('No user in session');
        }

        if (!$this->versionManager) {
            throw new \Exception('Version manager not available');
        }

        try {
            // Use IVersionManager - works for all storage types including GroupFolders
            $versions = $this->versionManager->getVersionsForFile($user, $file);

            // Find the version with matching timestamp
            $targetVersion = null;
            foreach ($versions as $version) {
                if ($version->getTimestamp() === $timestamp) {
                    $targetVersion = $version;
                    break;
                }
            }

            if (!$targetVersion) {
                throw new \Exception('Version not found for timestamp: ' . $timestamp);
            }

            // Rollback via IVersionManager
            $this->versionManager->rollback($targetVersion);

            // Re-obtain a fresh file node after rollback - the original $file
            // may have stale internal state after the storage-level rollback
            $freshFile = $result['folder']->get($file->getName());
            $content = $freshFile->getContent();
            $restoredData = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Restored version contains invalid JSON data');
            }

            // Return data with id for frontend (id is derived from folder name)
            // For home page it's 'home', otherwise use the folder basename
            $resolvedId = ($pageId === 'home') ? 'home' : $result['folder']->getName();
            return array_merge(['id' => $resolvedId], $restoredData);
        } catch (\Exception $e) {
            $this->logger->error('[restorePageVersion] Failed to restore version', [
                'error' => $e->getMessage(),
                'pageId' => $pageId,
                'timestamp' => $timestamp
            ]);
            throw new \Exception('Failed to restore version: ' . $e->getMessage());
        }
    }

    /**
     * Get human-readable relative time
     */
    private function getRelativeTime(int $timestamp): string {
        $diff = time() - $timestamp;

        if ($diff < 60) {
            return 'just now';
        } elseif ($diff < 3600) {
            $minutes = floor($diff / 60);
            return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 2592000) {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 31536000) {
            $months = floor($diff / 2592000);
            return $months . ' month' . ($months > 1 ? 's' : '') . ' ago';
        } else {
            $years = floor($diff / 31536000);
            return $years . ' year' . ($years > 1 ? 's' : '') . ' ago';
        }
    }

    /**
     * Create a version before updating a file
     * Uses IVersionManager for version creation across all storage types
     */
    private function createVersionBeforeUpdate(\OCP\Files\File $file): void {
        if (!$this->versionManager) {
            return;
        }

        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return;
            }

            // Use IVersionManager - works for all storage types including GroupFolders
            $this->versionManager->createVersion($user, $file);
        } catch (\Exception $e) {
            // Don't throw - versioning failure shouldn't prevent saves
            $this->logger->warning('[createVersionBeforeUpdate] Failed: ' . $e->getMessage());
        }
    }

    /**
     * Generate a UUID v4
     */
    /**
     * @deprecated Delegated to PageIdUtils::generateUUID.
     */
    private function generateUUID(): string {
        return $this->idUtils->generateUUID();
    }

    /**
     * Get the actual file ID from the database using the groupfolder storage
     *
     * This is necessary because $file->getId() may return the user mount file ID
     * instead of the groupfolder storage file ID that MetaVox needs.
     *
     * @param \OCP\Files\File $file The file object
     * @param \OCP\Files\Folder $folder The parent folder
     * @return int The actual file ID from the groupfolder storage
     */
    /**
     * The groupfolder a node lives in, or null when it is not in one.
     *
     * Read from the mount path (`/__groupfolders/{id}/…`) rather than from
     * MetaVox's value table, which only lists files that already have values
     * stored and so cannot answer this for a page with empty fields.
     *
     * @param \OCP\Files\Node $node
     */
    private function groupfolderIdForNode($node): ?int {
        try {
            // The mount knows its own folder id. Note that getPath() is NOT a
            // source for this: it returns the per-user mount path
            // (/Rik/files/IntraVox/…), not /__groupfolders/{id}/…, so parsing
            // it yields nothing.
            $mount = $node->getMountPoint();
            if (method_exists($mount, 'getFolderId')) {
                return (int)$mount->getFolderId();
            }

            // Fallback for mount types that do not expose it: the storage id
            // still carries the folder id (local::…/__groupfolders/1/).
            if (preg_match('#/__groupfolders/(\d+)/#', $node->getStorage()->getId(), $m)) {
                return (int)$m[1];
            }
        } catch (\Throwable $e) {
            // A node whose mount cannot be read is not worth failing the page
            // response over; the MetaVox tab simply stays empty.
        }
        return null;
    }

    private function getFileIdFromDatabase($file, $folder): int {
        try {
            $filePath = $file->getPath();

            // Extract groupfolder ID and relative path from the full path
            // Path format: /__groupfolders/4/files/en/home.json
            // We need: groupfolderId=4, relPath="files/en/home.json"
            if (preg_match('#/__groupfolders/(\d+)/(.+)$#', $filePath, $matches)) {
                $groupfolderId = (int)$matches[1];
                $relPath = $matches[2];

                // Find the storage ID for this groupfolder
                $qb = $this->db->getQueryBuilder();
                $qb->select('storage_id')
                    ->from('group_folders')
                    ->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($groupfolderId)));

                $result = $qb->executeQuery();
                $gfRow = $result->fetch();
                $result->closeCursor();

                if (!$gfRow || !isset($gfRow['storage_id'])) {
                    return $file->getId();
                }

                $storageId = (int)$gfRow['storage_id'];

                // Query filecache for the file in the groupfolder storage
                $qb2 = $this->db->getQueryBuilder();
                $qb2->select('fileid')
                    ->from('filecache')
                    ->where($qb2->expr()->eq('storage', $qb2->createNamedParameter($storageId)))
                    ->andWhere($qb2->expr()->eq('path', $qb2->createNamedParameter($relPath)))
                    ->andWhere($qb2->expr()->eq('name', $qb2->createNamedParameter($file->getName())));

                $result2 = $qb2->executeQuery();
                $row = $result2->fetch();
                $result2->closeCursor();

                if ($row && isset($row['fileid'])) {
                    return (int)$row['fileid'];
                }
            }

            // Fallback to file object ID
            return $file->getId();

        } catch (\Exception $e) {
            $this->logger->error('Failed to get file ID from database', [
                'error' => $e->getMessage(),
                'fileName' => $file->getName()
            ]);
            return $file->getId();
        }
    }

    /**
     * Get metadata for a page (simplified version using already loaded page data)
     */
    public function getPageMetadata(string $pageId): array {
        // Get page and file info
        $folder = $this->getLanguageFolder();
        $result = null;

        // Check for uniqueId pattern (page-xxxx). Follows the page across
        // language folders so an operation on a page the user can see never
        // fails with "Page not found" (issue #90).
        if (strpos($pageId, 'page-') === 0) {
            $result = $this->locatePageAnyLanguage($folder, $pageId);
        }

        // Fall back to legacy ID lookup
        if ($result === null) {
            $result = $this->findPageById($folder, $this->sanitizeId($pageId));
        }

        if (!$result) {
            throw new \Exception('Page not found: ' . $pageId);
        }

        $file = $result['file'];
        $folder = $result['folder'];

        // Get filesystem timestamps
        $mtime = $file->getMTime();
        $ctime = $file->getCreationTime();
        // Fallback: if creation time is 0 (not supported by groupfolder/storage), use mtime
        if ($ctime === 0) {
            $ctime = $mtime;
        }

        // Get page content for other metadata
        $content = $file->getContent();
        $data = json_decode($content, true);

        // Enrich with path data (file gates canWrite/canEdit, #70)
        $data = $this->enrichWithPathData($data, $folder, $file);

        // Format path to show full Nextcloud path starting with /IntraVox/
        $displayPath = isset($data['path']) ? '/IntraVox/' . $data['path'] : '';

        // Get file info for MetaVox integration
        $fileId = $file->getId();
        $size = $file->getSize();
        $internalPath = $file->getInternalPath();
        $storagePath = $file->getPath();

        // Get parent folder fileId for Files app link
        $parentFolderId = null;
        try {
            $parentFolderId = $folder->getId();
        } catch (\Exception $e) {
            // Not critical
        }

        // Get permissions from enriched data (uses Nextcloud's native permissions)
        $permissions = $data['permissions'] ?? [
            'canRead' => true,
            'canWrite' => false,
            'canCreate' => false,
            'canDelete' => false,
            'canShare' => false,
            'raw' => 1
        ];

        // Return metadata using filesystem timestamps
        $metadata = [
            'title' => $data['title'] ?? 'Untitled',
            'uniqueId' => $data['uniqueId'] ?? '',
            'language' => $data['language'] ?? $this->getUserLanguage(),
            'created' => $ctime,
            'createdFormatted' => date('Y-m-d H:i:s', $ctime),
            'createdRelative' => $this->getRelativeTime($ctime),
            'modified' => $mtime,
            'modifiedFormatted' => date('Y-m-d H:i:s', $mtime),
            'modifiedRelative' => $this->getRelativeTime($mtime),
            // Path-related data (already in page)
            'path' => $storagePath,
            'depth' => $data['depth'] ?? 0,
            'parentId' => $data['parentId'] ?? null,
            'parentPath' => $data['parentPath'] ?? null,
            'department' => $data['department'] ?? null,
            'canEdit' => $permissions['canWrite'] ?? false,
            // Additional data for MetaVox integration
            'fileId' => $fileId,
            'size' => $size,
            'parentFolderId' => $parentFolderId,
            'mountPoint' => 'IntraVox',
            // Permissions - use Nextcloud's native permissions
            'permissions' => $permissions,
        ];

        return $metadata;
    }

    /**
     * Update page metadata (title only for now, similar to Files rename)
     */
    public function updatePageMetadata(string $pageId, array $metadata): array {
        $folder = $this->getLanguageFolder();
        $result = null;

        // Check for uniqueId pattern (page-xxxx). Follows the page across
        // language folders so an operation on a page the user can see never
        // fails with "Page not found" (issue #90).
        if (strpos($pageId, 'page-') === 0) {
            $result = $this->locatePageAnyLanguage($folder, $pageId);
        }

        // Fall back to legacy ID lookup
        if ($result === null) {
            $result = $this->findPageById($folder, $this->sanitizeId($pageId));
        }

        if (!$result) {
            throw new \Exception('Page not found: ' . $pageId);
        }

        $file = $result['file'];

        // Get current content
        $content = $file->getContent();
        $data = json_decode($content, true);

        // Update only allowed fields
        $changed = false;
        $oldTitle = $data['title'] ?? '';
        $newTitle = null;
        if (isset($metadata['title']) && $metadata['title'] !== $data['title']) {
            $newTitle = $this->sanitizeText($metadata['title']);
            $data['title'] = $newTitle;
            $changed = true;
        }

        // Save if changed
        if ($changed) {
            // Create version before update using VersionsBackend
            $this->createVersionBeforeUpdate($file);
            $file->putContent(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            // Keep the navigation menu label in sync when the page is renamed,
            // but only when the label still matched the old title — a label that
            // was deliberately set to something else is left untouched.
            if ($newTitle !== null && !empty($data['uniqueId'])) {
                $this->syncNavigationTitle((string)$data['uniqueId'], $oldTitle, $newTitle);
            }
        }

        return $this->getPageMetadata($pageId);
    }

    /**
     * Keep the navigation menu label in sync after a page rename (issue #84).
     *
     * Walks the navigation tree for the current language and, for every item
     * that points at this page (by uniqueId) whose label still equals the old
     * page title, updates the label to the new title. Items whose label was
     * deliberately set to something else are left as-is. Best-effort: a failure
     * here must never break the rename itself.
     */
    private function syncNavigationTitle(string $uniqueId, string $oldTitle, string $newTitle): void {
        if ($oldTitle === $newTitle) {
            return;
        }
        try {
            $navigation = $this->navigationService->getNavigation();
            $items = $navigation['items'] ?? [];
            $changed = false;

            $walk = function (array &$items) use (&$walk, $uniqueId, $oldTitle, $newTitle, &$changed): void {
                foreach ($items as &$item) {
                    $itemId = $item['uniqueId'] ?? $item['pageId'] ?? null;
                    if ($itemId === $uniqueId && ($item['title'] ?? '') === $oldTitle) {
                        $item['title'] = $newTitle;
                        $changed = true;
                    }
                    if (isset($item['children']) && is_array($item['children'])) {
                        $walk($item['children']);
                    }
                }
                unset($item);
            };
            $walk($items);

            if ($changed) {
                $navigation['items'] = $items;
                $this->navigationService->saveNavigation($navigation);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[PageService] Could not sync navigation title after rename: ' . $e->getMessage());
        }
    }

    /**
     * One-off repair for data corrupted by the old sanitizeText(), which
     * HTML-encoded plain-text fields (title "Collega's" was stored as
     * "Collega&apos;s", "A & B" as "A &amp; B", …). Walks every page JSON in
     * every language folder and decodes the entity-encoded plain-text fields
     * (page title, widget content/alt/title, link titles), then rewrites the
     * file. Idempotent — already-clean text decodes to itself.
     *
     * @param bool $dryRun When true, count changes but do not write.
     * @return array{scanned:int, changed:int, files:string[]} Repair stats.
     */
    public function repairEntities(bool $dryRun = false): array {
        $stats = ['scanned' => 0, 'changed' => 0, 'files' => []];
        $base = $this->getIntraVoxFolder();
        foreach ($this->getCachedDirectoryListing($base) as $langFolder) {
            if (!($langFolder instanceof \OCP\Files\Folder)) {
                continue;
            }
            // Language folders are 2–3 letter codes; skip _media/_resources/etc.
            if (!preg_match('/^[a-z]{2,3}$/', $langFolder->getName())) {
                continue;
            }
            $this->repairEntitiesInFolder($langFolder, $dryRun, $stats);
        }
        return $stats;
    }

    /**
     * Recurse a folder, decoding entity-encoded plain-text in each page JSON.
     *
     * @param array{scanned:int, changed:int, files:string[]} $stats
     */
    private function repairEntitiesInFolder(\OCP\Files\Folder $folder, bool $dryRun, array &$stats): void {
        foreach ($this->getCachedDirectoryListing($folder) as $node) {
            if ($node instanceof \OCP\Files\File && str_ends_with($node->getName(), '.json')) {
                // Only page JSONs carry the fields we repair; navigation.json,
                // footer.json, homepage.json are handled/normalised elsewhere.
                $name = $node->getName();
                if (in_array($name, ['navigation.json', 'footer.json', 'homepage.json'], true)) {
                    continue;
                }
                $stats['scanned']++;
                try {
                    $data = json_decode($node->getContent(), true);
                    if (!is_array($data) || !isset($data['title'])) {
                        continue;
                    }
                    $before = json_encode($data);
                    $this->decodePlainTextFields($data);
                    $after = json_encode($data);
                    if ($before !== $after) {
                        $stats['changed']++;
                        $stats['files'][] = $node->getPath();
                        if (!$dryRun) {
                            $node->putContent(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        }
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('[PageService] repairEntities skipped ' . $node->getPath() . ': ' . $e->getMessage());
                }
            } elseif ($node instanceof \OCP\Files\Folder) {
                $this->repairEntitiesInFolder($node, $dryRun, $stats);
            }
        }
    }

    /**
     * Rebuild the page index from the filesystem, which is the source of truth.
     *
     * The index is a derived structure: pages live as JSON on disk, and the
     * index only exists so lookups do not have to walk that tree. Anything
     * that writes page files outside the service — a restore, a manual copy in
     * the Files app, an `occ files:scan`, an older IntraVox version, or simply
     * a bug — leaves it stale. Without a rebuild, a stale index is unfixable
     * short of editing the database by hand, which is why no read path may be
     * built on the index until this exists.
     *
     * Clears and repopulates in one pass rather than diffing: at intranet
     * scale a full rebuild is seconds, and a diff would have to solve exactly
     * the "which rows are wrong" question that a corrupt index cannot answer.
     *
     * Deliberately does NOT infer or repair translation groupings — it records
     * only what the files say. (WPML shipped a bug where an update silently
     * re-linked translations an editor had deliberately unlinked; guessing
     * relationships during a repair is how that happens.)
     *
     * @param bool $dryRun count what would be indexed without writing
     * @return array{scanned:int, indexed:int, languages:array<string,int>}
     */
    public function rebuildIndex(bool $dryRun = false): array {
        $stats = ['scanned' => 0, 'indexed' => 0, 'languages' => []];

        $base = $this->getIntraVoxFolder();
        $languageFolders = [];
        foreach ($this->getCachedDirectoryListing($base) as $node) {
            if (!($node instanceof \OCP\Files\Folder)) {
                continue;
            }
            // Language folders are 2–3 letter codes; skips _media/_resources.
            if (!preg_match('/^[a-z]{2,3}$/', $node->getName())) {
                continue;
            }
            $languageFolders[] = $node;
        }

        // Clear only after the tree is readable: wiping first and then failing
        // to read would leave the install with no index at all.
        if (!$dryRun) {
            $this->pageIndexService->clearAll();
        }

        foreach ($languageFolders as $langFolder) {
            $lang = $langFolder->getName();
            $stats['languages'][$lang] = 0;
            $this->rebuildIndexInFolder($langFolder, $lang, $dryRun, $stats);
        }

        return $stats;
    }

    /**
     * Recurse one language folder, indexing every page JSON found.
     *
     * @param array{scanned:int, indexed:int, languages:array<string,int>} $stats
     */
    private function rebuildIndexInFolder(
        \OCP\Files\Folder $folder,
        string $language,
        bool $dryRun,
        array &$stats
    ): void {
        foreach ($this->getCachedDirectoryListing($folder) as $node) {
            if ($node instanceof \OCP\Files\Folder) {
                $name = $node->getName();
                // Media and asset folders hold no pages.
                if (in_array($name, ['_media', '_resources', '_templates', 'images', 'files'], true)) {
                    continue;
                }
                $this->rebuildIndexInFolder($node, $language, $dryRun, $stats);
                continue;
            }

            if (!($node instanceof \OCP\Files\File) || !str_ends_with($node->getName(), '.json')) {
                continue;
            }
            // Per-language config files are not pages.
            if (in_array($node->getName(), ['navigation.json', 'footer.json', 'homepage.json'], true)) {
                continue;
            }

            $stats['scanned']++;
            try {
                $data = json_decode($node->getContent(), true);
                if (!is_array($data) || empty($data['uniqueId'])) {
                    // A JSON file without a uniqueId is not an indexable page.
                    continue;
                }
                if (!$dryRun) {
                    $this->pageIndexService->indexPage(
                        $data,
                        $language,
                        $folder->getPath(),
                        $node->getId()
                    );
                }
                $stats['indexed']++;
                $stats['languages'][$language]++;
            } catch (\Throwable $e) {
                // One unreadable file must not abort the whole rebuild.
                $this->logger->warning(
                    '[PageService] rebuildIndex skipped ' . $node->getPath() . ': ' . $e->getMessage()
                );
            }
        }
    }

    /**
     * Decode HTML entities in the plain-text fields of a page-data array,
     * in place: title, and each widget's content/alt/title and link titles.
     */
    private function decodePlainTextFields(array &$data): void {
        if (isset($data['title']) && is_string($data['title'])) {
            $data['title'] = $this->htmlSanitizer->decodeEntitiesRecursive($data['title']);
        }
        $rows = $data['layout']['rows'] ?? null;
        if (!is_array($rows)) {
            return;
        }
        foreach ($rows as &$row) {
            if (isset($row['sectionTitle']) && is_string($row['sectionTitle'])) {
                $row['sectionTitle'] = $this->htmlSanitizer->decodeEntitiesRecursive($row['sectionTitle']);
            }
            $columns = $row['columns'] ?? (isset($row['widgets']) ? [$row] : []);
            foreach ($columns as &$col) {
                foreach (($col['widgets'] ?? []) as &$widget) {
                    foreach (['content', 'alt', 'title'] as $field) {
                        if (isset($widget[$field]) && is_string($widget[$field])) {
                            $widget[$field] = $this->htmlSanitizer->decodeEntitiesRecursive($widget[$field]);
                        }
                    }
                    foreach (($widget['links'] ?? []) as &$link) {
                        if (isset($link['title']) && is_string($link['title'])) {
                            $link['title'] = $this->htmlSanitizer->decodeEntitiesRecursive($link['title']);
                        }
                    }
                    unset($link);
                }
                unset($widget);
            }
            unset($col);
        }
        unset($row);
    }

    /**
     * Persist a new sibling order (issue #69). Writes `order = 0..n` onto each
     * child's page-JSON in the given sequence. A targeted metadata write — no
     * file version is created (order is metadata, not content).
     *
     * @param string|null $parentUniqueId Parent page uniqueId; null/'' = root.
     * @param string[] $orderedChildIds Child uniqueIds in the desired order.
     * @throws \Exception When the parent cannot be located.
     */
    public function reorderSiblings(?string $parentUniqueId, array $orderedChildIds): void {
        $languageFolder = $this->getLanguageFolder();

        // Resolve the parent folder whose direct children we are reordering.
        if ($parentUniqueId === null || $parentUniqueId === '') {
            $parentFolder = $languageFolder;
        } else {
            $parentResult = $this->findPageByUniqueId($languageFolder, $parentUniqueId);
            if (!$parentResult || !isset($parentResult['folder'])) {
                throw new \Exception('Parent page not found: ' . $parentUniqueId);
            }
            $parentFolder = $parentResult['folder'];
        }

        // Build a uniqueId => page-JSON File map of this parent's DIRECT children
        // in a single cached directory pass. Reorder only touches direct children,
        // so we do NOT recurse into their subtrees (the old per-child
        // findPageByUniqueId() walked the whole subtree per id — O(N²) plus
        // uncached reads on a wide set). A child page is a subfolder holding
        // {folderName}.json (the canonical layout, mirrors buildPageTree); the
        // legacy loose {slug}.json at the parent level is also honoured.
        $isLanguageRoot = ($parentFolder->getPath() === $languageFolder->getPath());
        $childMap = [];
        foreach ($this->getCachedDirectoryListing($parentFolder) as $item) {
            $itemName = $item->getName();
            $file = null;

            if ($item->getType() === \OCP\Files\FileInfo::TYPE_FOLDER) {
                // Skip media/special folders (mirror findPageByUniqueId).
                if ($itemName === '_media' || $itemName === 'images' || $itemName === 'files' || $itemName === '.nomedia') {
                    continue;
                }
                try {
                    $candidate = $item->get($itemName . '.json');
                    if ($candidate instanceof \OCP\Files\File) {
                        $file = $candidate;
                    }
                } catch (NotFoundException $e) {
                    continue; // a folder without its page-JSON is not a page
                }
            } else {
                // Loose {slug}.json directly in the parent (legacy flat layout).
                if (substr($itemName, -5) !== '.json' || $itemName === 'home.json') {
                    continue; // home.json is the homepage, never ordered
                }
                if ($isLanguageRoot && ($itemName === 'navigation.json' || $itemName === 'footer.json' || $itemName === 'homepage.json')) {
                    continue; // root config files are not pages
                }
                $file = $item;
            }

            if ($file === null) {
                continue;
            }
            $data = json_decode($this->getCachedFileContent($file), true);
            if (is_array($data) && isset($data['uniqueId'])) {
                $childMap[$data['uniqueId']] = $file;
            }
        }

        foreach ($orderedChildIds as $index => $childId) {
            // The homepage is pinned first and never carries an order — skip the
            // legacy 'home' id as well as a configured pointer target.
            if ($childId === 'home' || $this->isHomepage($childId)) {
                continue;
            }

            // A foreign id (not among this parent's direct children) is simply
            // absent from the map and is skipped, rather than reordered.
            $file = $childMap[$childId] ?? null;
            if ($file === null) {
                continue;
            }

            $data = json_decode($this->getCachedFileContent($file), true);
            if (!is_array($data)) {
                continue;
            }

            if (($data['order'] ?? null) !== $index) {
                $data['order'] = $index;
                $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                $file->putContent($encoded);
                // Keep the per-request content cache honest with what we just wrote.
                $this->fileContentCache[$file->getPath()] = $encoded;
            }
        }

        // Critical: without this the new order stays invisible for up to 5 min.
        $this->clearCache();
    }

    /**
     * Extract groupfolder ID from path
     */
    private function extractGroupfolderId(string $path): ?int {
        if (preg_match('/\/__groupfolders\/(\d+)/', $path, $matches)) {
            return (int)$matches[1];
        }
        return null;
    }

    /**
     * Get groupfolder name from groupfolder ID
     */
    private function getGroupfolderName(?int $groupfolderId): string {
        if ($groupfolderId === null) {
            return 'IntraVox';
        }

        try {
            // Query the group_folders table for the mount_point
            $qb = $this->db->getQueryBuilder();
            $qb->select('mount_point')
                ->from('group_folders')
                ->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($groupfolderId)));

            $result = $qb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();

            if ($row && isset($row['mount_point'])) {
                return $row['mount_point'];
            }
        } catch (\Exception $e) {
            // Fallback on error
        }

        return 'IntraVox';
    }

    /**
     * Get folder ID from database for Files app link
     */
    private function getFolderIdFromDatabase(string $folderPath, ?int $groupfolderId): ?int {
        if ($groupfolderId === null) {
            return null;
        }

        try {
            // Extract relative path from full path
            // Path format: /__groupfolders/4/files/en/mission
            if (preg_match('#/__groupfolders/\d+/(.+)$#', $folderPath, $matches)) {
                $relPath = $matches[1];

                // Find the storage ID for this groupfolder
                $qb = $this->db->getQueryBuilder();
                $qb->select('storage_id')
                    ->from('group_folders')
                    ->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($groupfolderId)));

                $result = $qb->executeQuery();
                $gfRow = $result->fetch();
                $result->closeCursor();

                if (!$gfRow || !isset($gfRow['storage_id'])) {
                    return null;
                }

                $storageId = (int)$gfRow['storage_id'];

                // Query filecache for the folder in the groupfolder storage
                $qb2 = $this->db->getQueryBuilder();
                $qb2->select('fileid')
                    ->from('filecache')
                    ->where($qb2->expr()->eq('storage', $qb2->createNamedParameter($storageId)))
                    ->andWhere($qb2->expr()->eq('path', $qb2->createNamedParameter($relPath)));

                $result2 = $qb2->executeQuery();
                $row = $result2->fetch();
                $result2->closeCursor();

                if ($row && isset($row['fileid'])) {
                    return (int)$row['fileid'];
                }
            }

            return null;

        } catch (\Exception $e) {
            $this->logger->error('Failed to get folder ID from database', [
                'error' => $e->getMessage(),
                'folderPath' => $folderPath
            ]);
            return null;
        }
    }

    /**
     * Format bytes to human readable format
     */
    /**
     * @deprecated Delegated to PageIdUtils::formatBytes.
     */
    private function formatBytes(int $bytes): string {
        return $this->idUtils->formatBytes($bytes);
    }

    /**
     * Check if a page is visible in the Nextcloud file cache
     * This is useful to determine if a groupfolder page has been indexed
     *
     * @param string $pageId The page ID to check
     * @return array Status information about the page's visibility
     */
    public function checkPageCacheStatus(string $pageId): array {
        try {
            $folder = $this->getLanguageFolder();
            $lang = $this->getUserLanguage();

            // For home page, check the JSON file directly
            if ($pageId === 'home') {
                try {
                    $file = $folder->get('home.json');
                    $storage = $file->getStorage();
                    $cache = $storage->getCache();

                    // Try to get cache entry using the storage's cache directly
                    $cacheEntry = $cache->get($file->getInternalPath());

                    return [
                        'visible' => $cacheEntry !== false,
                        'inCache' => $cacheEntry !== false,
                        'fileId' => $cacheEntry !== false ? $cacheEntry->getId() : null,
                        'path' => $file->getPath(),
                        'message' => $cacheEntry !== false ? 'Page is visible in Files app' : 'Page created but waiting for indexing'
                    ];
                } catch (NotFoundException $e) {
                    return [
                        'visible' => false,
                        'inCache' => false,
                        'fileId' => null,
                        'message' => 'Home page file not found'
                    ];
                }
            }

            // For regular pages, check if the page folder exists in cache.
            // Resolve the page itself rather than assuming a folder of that name
            // sits in the caller's own language: this diagnostic reported
            // "Page folder not found" for perfectly healthy pages that simply
            // live in another language, which is a misleading support signal.
            try {
                $located = $this->locatePageForOperation($pageId);
                $pageFolder = $located['folder'] ?? $folder->get($pageId);
                $storage = $pageFolder->getStorage();
                $cache = $storage->getCache();

                // Try to get cache entry using the storage's cache directly
                $cacheEntry = $cache->get($pageFolder->getInternalPath());

                if ($cacheEntry !== false && $cacheEntry instanceof ICacheEntry) {
                    return [
                        'visible' => true,
                        'inCache' => true,
                        'folderId' => $cacheEntry->getId(),
                        'path' => $pageFolder->getPath(),
                        'message' => 'Page is visible in Files app'
                    ];
                } else {
                    // Folder exists on disk but not in cache
                    return [
                        'visible' => false,
                        'inCache' => false,
                        'folderId' => null,
                        'message' => 'Page created but waiting for Nextcloud to index it. This may take 5-15 minutes.'
                    ];
                }
            } catch (NotFoundException $e) {
                return [
                    'visible' => false,
                    'inCache' => false,
                    'folderId' => null,
                    'message' => 'Page folder not found'
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to check page cache status', [
                'error' => $e->getMessage(),
                'pageId' => $pageId
            ]);

            return [
                'visible' => false,
                'inCache' => false,
                'error' => $e->getMessage(),
                'message' => 'Unable to check cache status'
            ];
        }
    }

    /**
     * Update version label
     * Uses IVersionManager with backend access for label updates.
     */
    public function updateVersionLabel(string $pageId, int $timestamp, ?string $label): void {
        // Verify page exists. Had neither a uniqueId branch nor a cross-language
        // fallback, so labelling a version failed on any page-… id and on any
        // page outside the caller's own language (#90).
        $result = $this->locatePageForOperation($pageId);

        if (!$result) {
            throw new PageNotFoundException('Page not found: ' . $pageId);
        }

        $file = $result['file'];
        $user = $this->userSession->getUser();

        if (!$user) {
            throw new \Exception('No user in session');
        }

        if (!$this->versionManager) {
            throw new \Exception('Version manager not available');
        }

        // Use IVersionManager - works for all storage types including GroupFolders
        $versions = $this->versionManager->getVersionsForFile($user, $file);

        foreach ($versions as $version) {
            if ($version->getTimestamp() === $timestamp) {
                // Get the backend for this storage to access setVersionLabel
                $backend = $this->versionManager->getBackendForStorage($file->getStorage());
                if (method_exists($backend, 'setVersionLabel')) {
                    $backend->setVersionLabel($version, $label ?? '');
                    return;
                }
                throw new \Exception('Version labels not supported by this storage backend');
            }
        }

        throw new \Exception('Version not found');
    }

    /**
     * Get version content for preview
     * Uses IVersionManager for reliable version content retrieval across all storage types.
     */
    public function getVersionContent(string $pageId, int $timestamp): array {
        $folder = $this->getLanguageFolder();
        $result = null;

        // Check for uniqueId pattern (page-xxxx) like getPage() does. Follows
        // the page across language folders so an operation on a page the user
        // can see never fails with "Page not found" (issue #90).
        if (strpos($pageId, 'page-') === 0) {
            $result = $this->locatePageAnyLanguage($folder, $pageId);
        }

        // Fall back to legacy ID lookup
        if ($result === null) {
            $result = $this->findPageById($folder, $this->sanitizeId($pageId));
        }

        if (!$result) {
            throw new \Exception('Page not found: ' . $pageId);
        }

        $file = $result['file'];
        $user = $this->userSession->getUser();

        if (!$user) {
            throw new \Exception('No user in session');
        }

        if (!$this->versionManager) {
            throw new \Exception('Version manager not available');
        }

        // Use IVersionManager - works for all storage types including GroupFolders
        $versions = $this->versionManager->getVersionsForFile($user, $file);

        foreach ($versions as $version) {
            if ($version->getTimestamp() === $timestamp) {
                // Read version content via IVersionManager (returns a stream resource)
                $stream = $this->versionManager->read($version);

                // Convert stream resource to string
                $content = stream_get_contents($stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }

                return [
                    'title' => 'Version from ' . date('Y-m-d H:i:s', $timestamp),
                    'content' => $content,
                    'rawContent' => $content
                ];
            }
        }

        throw new \Exception('Version not found');
    }

    /**
     * Get current page content for comparison
     */
    public function getCurrentPageContent(string $pageId): array {
        // Same shape as updateVersionLabel(): no uniqueId branch and no
        // cross-language fallback, so the "compare with current" panel in the
        // version history broke on page-… ids and on foreign-language pages.
        $result = $this->locatePageForOperation($pageId);

        if (!$result) {
            throw new PageNotFoundException('Page not found: ' . $pageId);
        }

        $file = $result['file'];
        $content = $file->getContent();

        return [
            'title' => $result['page']['name'] ?? 'Untitled',
            'content' => $content,
            'rawContent' => $content
        ];
    }

    /**
     * Get the full page tree structure for the current language
     * Returns a hierarchical tree of all pages the user has access to
     *
     * OPTIMIZED: Uses static cache with TTL to avoid repeated filesystem traversals
     *
     * @param string|null $currentPageId Optional: uniqueId of the current page to highlight
     * @return array Tree structure with pages and their children
     */
    public function getPageTree(?string $currentPageId = null, ?string $language = null, ?string $rootPageId = null): array {
        // Use provided language, else the language the user is actually shown
        // (recommended-language fallback, #75), else their own language.
        $lang = $language ?? $this->resolveEffectiveLanguage() ?? $this->getUserLanguage();

        // Cache key is groupHash + language. Users that share a group set
        // share a bucket — at enterprise scale (1k+ users, ~10 groups) that
        // turns 2000 entries into ~10.
        //
        // The full per-language tree is cached *whole*; subtree requests
        // filter from that cached blob (issue #45). Caching subtrees
        // separately would multiply key cardinality by the number of
        // candidate roots without saving work.
        $cacheKey = $this->groupContext->getGroupHash() . '_' . $lang;
        $distributedCacheKey = 'tree_' . $cacheKey;
        $now = time();

        // Check in-process static cache first (fastest)
        if (isset(self::$pageTreeCache[$cacheKey])) {
            $cached = self::$pageTreeCache[$cacheKey];
            if (($now - $cached['time']) < self::PAGE_TREE_CACHE_TTL) {
                return $this->shapeTreeResponse($cached['tree'], $currentPageId, $rootPageId);
            }
        }

        // Check distributed cache (shared across PHP processes/requests)
        if ($this->distributedCache !== null) {
            $distributedCached = $this->distributedCache->get($distributedCacheKey);
            if ($distributedCached !== null) {
                $decoded = json_decode($distributedCached, true);
                if ($decoded !== null) {
                    // Populate static cache too for subsequent calls in same request
                    self::$pageTreeCache[$cacheKey] = [
                        'tree' => $decoded,
                        'time' => $now
                    ];
                    return $this->shapeTreeResponse($decoded, $currentPageId, $rootPageId);
                }
            }
        }

        // Build fresh tree for specified language
        $folder = $this->getLanguageFolderByCode($lang);
        $tree = [];

        // Check for home.json in root
        try {
            $homeFile = $folder->get('home.json');
            $content = $homeFile->getContent();
            $data = json_decode($content, true);

            if ($data && isset($data['uniqueId'], $data['title'])) {
                $tree[] = [
                    'uniqueId' => $data['uniqueId'],
                    'title' => $data['title'],
                    'status' => $data['status'] ?? 'published',
                    'fileId' => ($homeFile instanceof \OCP\Files\File) ? $homeFile->getId() : null,
                    'path' => $lang,
                    'language' => $lang,
                    'isCurrent' => false, // Will be set by markCurrentPageInTree
                    'children' => [],
                    'permissions' => $this->permissionsFromNode($folder)
                ];
            }
        } catch (NotFoundException $e) {
            // No home page yet
        }

        // Recursively build tree from subfolders
        $this->buildPageTree($folder, $tree, null, $lang); // Pass null, marking done separately

        // Configurable homepage: if a pointer designates a root page other than
        // the loose home.json, float that node to the front so the homepage is
        // always first (matches the legacy home.json-first behaviour).
        $pointer = $this->homepageService->getHomepageUniqueId($lang);
        if ($pointer !== null && $pointer !== '' && $pointer !== 'home') {
            foreach ($tree as $i => $node) {
                if (($node['uniqueId'] ?? null) === $pointer) {
                    if ($i !== 0) {
                        $picked = array_splice($tree, $i, 1);
                        array_unshift($tree, $picked[0]);
                    }
                    break;
                }
            }
        }

        // Store in static cache
        self::$pageTreeCache[$cacheKey] = [
            'tree' => $tree,
            'time' => $now
        ];

        // Store in distributed cache (shared across requests)
        if ($this->distributedCache !== null) {
            $this->distributedCache->set($distributedCacheKey, json_encode($tree), self::PAGE_TREE_CACHE_TTL);
        }

        return $this->shapeTreeResponse($tree, $currentPageId, $rootPageId);
    }

    /**
     * Apply the response-shaping steps that come after cache lookup:
     * optionally narrow to a subtree, then mark the current page.
     * Centralised so the three cache paths (static, distributed, fresh)
     * stay identical.
     */
    private function shapeTreeResponse(array $tree, ?string $currentPageId, ?string $rootPageId): array {
        if ($rootPageId !== null && $rootPageId !== '') {
            $tree = $this->pathHelper->findSubtree($tree, $rootPageId);
        }
        // markCurrentPageInTree deep-copies the (group-shared) cached tree, so it
        // is safe to overwrite permissions on the copy without polluting the cache.
        $tree = $this->markCurrentPageInTree($tree, $currentPageId);
        // The tree is cached per group-set, but GroupFolder ACLs can grant/deny
        // per USER within the same group. Recompute each node's permissions for
        // the current user from the live filesystem view so per-user ACLs are
        // reflected (issue #86) — same reasoning as the per-read permission
        // recompute in getPage() (issue #70).
        $this->refreshTreePermissions($tree);
        return $tree;
    }

    /**
     * Overwrite each tree node's `permissions` with the current user's live,
     * ACL-aware permissions, resolved from the node's path. Recurses into
     * children. Per-path results are memoised for the request via the shared
     * permissions cache inside getFolderPermissions/permissionsFromNode.
     *
     * @param array<int, array> $nodes
     */
    private function refreshTreePermissions(array &$nodes): void {
        foreach ($nodes as &$node) {
            $path = $node['path'] ?? null;
            if (is_string($path) && $path !== '') {
                try {
                    $node['permissions'] = $this->getFolderPermissions($path);
                } catch (\Throwable $e) {
                    // Leave the cached (group-level) permissions as a safe fallback.
                }
            }
            if (!empty($node['children']) && is_array($node['children'])) {
                $this->refreshTreePermissions($node['children']);
            }
        }
        unset($node);
    }

    /**
     * Mark the current page in a tree structure
     * Creates a deep copy to avoid modifying cached data
     */
    /**
     * @deprecated Delegated to PagePathHelper::markCurrentPageInTree.
     */
    private function markCurrentPageInTree(array $tree, ?string $currentPageId): array {
        return $this->pathHelper->markCurrentPageInTree($tree, $currentPageId);
    }

    /**
     * Recursively build the page tree from folder structure
     */
    /**
     * Stable sibling sort (issue #69). Pages WITH an integer `order` come first,
     * ascending. Pages WITHOUT `order` (all legacy pages) keep their original
     * input order AFTER the ordered ones — so an installation that has never
     * reordered anything does not reshuffle.
     *
     * @param array<int, array> $siblings
     * @return array<int, array>
     */
    private function sortSiblingsByOrder(array $siblings): array {
        $decorated = [];
        foreach ($siblings as $i => $node) {
            $decorated[] = ['i' => $i, 'node' => $node];
        }
        usort($decorated, function ($a, $b) {
            $ao = $a['node']['order'] ?? null;
            $bo = $b['node']['order'] ?? null;
            $aHas = is_int($ao);
            $bHas = is_int($bo);
            if ($aHas && $bHas) {
                return ($ao <=> $bo) ?: ($a['i'] <=> $b['i']);
            }
            if ($aHas !== $bHas) {
                return $aHas ? -1 : 1;
            }
            return $a['i'] <=> $b['i'];
        });
        return array_map(fn ($d) => $d['node'], $decorated);
    }

    private function buildPageTree($folder, array &$tree, ?string $currentPageId, ?string $language = null): void {
        // Collect siblings locally so we can apply the stable order comparator
        // (issue #69) before appending them to the tree in the right sequence.
        $nodes = [];
        foreach ($this->getCachedDirectoryListing($folder) as $item) {
            if ($item->getType() !== \OCP\Files\FileInfo::TYPE_FOLDER) {
                continue;
            }

            $folderName = $item->getName();

            // Skip special folders
            if (in_array($folderName, ['images', 'files', '.nomedia'])) {
                continue;
            }

            // Skip folders starting with emoji (images folders)
            if (preg_match('/^[\x{1F300}-\x{1F9FF}]/u', $folderName)) {
                continue;
            }

            // Look for {foldername}.json inside the folder
            try {
                $jsonFile = $item->get($folderName . '.json');

                // Check if file is readable and user has access
                if (!$jsonFile->isReadable()) {
                    continue;
                }

                // Use cached file content to avoid repeated reads
                $content = $jsonFile instanceof \OCP\Files\File
                    ? $this->getCachedFileContent($jsonFile)
                    : @$jsonFile->getContent();

                if ($content === false || $content === null) {
                    continue;
                }

                $data = json_decode($content, true);

                if ($data && isset($data['uniqueId'], $data['title'])) {
                    // Folder permissions (respects ACLs + mount writability).
                    $perm = $this->permissionsFromNode($item);

                    // Skip if user can't read this folder
                    if (!$perm['canRead']) {
                        continue;
                    }

                    $pageNode = [
                        'uniqueId' => $data['uniqueId'],
                        'title' => $data['title'],
                        'status' => $data['status'] ?? 'published',
                        // fileId of the page JSON, so the tree gate can resolve the
                        // publish/expiration MetaVox fields for scheduled visibility.
                        'fileId' => ($jsonFile instanceof \OCP\Files\File) ? $jsonFile->getId() : null,
                        'path' => $this->getRelativePathFromRoot($item),
                        'language' => $language ?? $this->getUserLanguage(),
                        'isCurrent' => ($currentPageId === $data['uniqueId']),
                        'children' => [],
                        'permissions' => $perm
                    ];

                    // Carry the sibling order (issue #69) for the comparator. Kept
                    // out of the public node shape below — it's stripped after sort.
                    if (isset($data['order']) && is_int($data['order'])) {
                        $pageNode['order'] = $data['order'];
                    }

                    // Recursively get children
                    $this->buildPageTree($item, $pageNode['children'], $currentPageId, $language);

                    $nodes[] = $pageNode;
                }
            } catch (\Exception $e) {
                // This folder doesn't contain a valid page or can't be read, continue
            } catch (\Throwable $e) {
                // Catch any other errors
                continue;
            }
        }

        // Apply the stable sibling order (issue #69) and drop the internal
        // 'order' key so the tree shape the frontend sees is unchanged.
        foreach ($this->sortSiblingsByOrder($nodes) as $node) {
            unset($node['order']);
            $tree[] = $node;
        }
    }

    /**
     * Search pages by query string
     * Searches in page titles and text widget content
     * OPTIMIZED: Loads all content in a single filesystem traversal
     */
    public function searchPages(string $query): array {
        $results = [];
        $query = mb_strtolower($query);

        // Get all pages with full content in a single traversal
        $pagesWithContent = $this->listPagesWithContent();

        // MetaVox metadata is stored alongside the file, not inside the page
        // JSON, so a page tagged "Stad: Luik" is invisible to a content-only
        // search. Batch-load it for every page in one query (no N+1) and treat
        // it as an additional match source below.
        $metaVoxData = $this->getMetaVoxDataForFiles(
            array_values(array_filter(array_column($pagesWithContent, 'fileId')))
        );
        $metaVoxLabels = empty($metaVoxData) ? [] : $this->getMetaVoxFieldLabels();

        foreach ($pagesWithContent as $pageData) {
            $matches = [];
            $score = 0;

            // Skip pages without uniqueId
            if (!isset($pageData['uniqueId']) || empty($pageData['uniqueId'])) {
                continue;
            }

            // Search in title (higher weight)
            if (isset($pageData['title']) && mb_stripos($pageData['title'], $query) !== false) {
                $score += 10;
                $matches[] = [
                    'type' => 'title',
                    'text' => $pageData['title']
                ];
            }

            // Search in uniqueId (medium weight)
            if (mb_stripos($pageData['uniqueId'], $query) !== false) {
                $score += 5;
            }

            // Search in content - layout is already loaded
            // Collect all widgets from all layout areas
            $allWidgets = [];

            // Main rows
            if (isset($pageData['layout']['rows'])) {
                foreach ($pageData['layout']['rows'] as $row) {
                    if (isset($row['widgets'])) {
                        $allWidgets = array_merge($allWidgets, $row['widgets']);
                    }
                }
            }

            // Header row
            if (isset($pageData['layout']['headerRow']['widgets'])) {
                $allWidgets = array_merge($allWidgets, $pageData['layout']['headerRow']['widgets']);
            }

            // Side columns
            if (isset($pageData['layout']['sideColumns']['left']['widgets'])) {
                $allWidgets = array_merge($allWidgets, $pageData['layout']['sideColumns']['left']['widgets']);
            }
            if (isset($pageData['layout']['sideColumns']['right']['widgets'])) {
                $allWidgets = array_merge($allWidgets, $pageData['layout']['sideColumns']['right']['widgets']);
            }

            // Search through all collected widgets
            foreach ($allWidgets as $widget) {
                $widgetMatches = $this->searchWidget($widget, $query);
                foreach ($widgetMatches as $match) {
                    $score += $match['score'];
                    $matches[] = [
                        'type' => $match['type'],
                        'text' => $match['text']
                    ];
                }
            }

            // Search MetaVox metadata (Stad, Thema, ...). Scored between title
            // (10) and plain content so a metadata hit ranks meaningfully but
            // never outranks the page actually being named after the term.
            $fileId = $pageData['fileId'] ?? null;
            $pageMeta = $fileId !== null ? ($metaVoxData[$fileId] ?? []) : [];
            $metaMatches = $this->searchMetaVoxValues(
                $pageMeta,
                $query,
                $metaVoxLabels,
                $fileId !== null ? ($this->metaVoxGroupfolderByFile[$fileId] ?? null) : null
            );
            if (!empty($metaMatches)) {
                $score += 7;
                // The subline mirrors MetaVox's own format so results read the
                // same in both providers: "Label: value" joined with " • ",
                // matching field first, capped at 3 fields.
                $matches[] = [
                    'type' => 'metadata',
                    'text' => $metaMatches['subline'],
                ];
            }

            // If we have matches, add to results
            if ($score > 0) {
                $results[] = [
                    'uniqueId' => $pageData['uniqueId'] ?? null,
                    'title' => $pageData['title'] ?? 'Untitled',
                    'path' => $pageData['path'] ?? '',
                    'score' => $score,
                    'matches' => array_slice($matches, 0, 3), // Limit to 3 matches per page
                    'matchCount' => count($matches)
                ];
            }
        }

        // Sort by score (highest first)
        usort($results, function($a, $b) {
            return $b['score'] - $a['score'];
        });

        // Limit to top 20 results
        return array_slice($results, 0, 20);
    }

    /**
     * Extract a snippet of text around a search query match
     */
    /**
     * @deprecated Delegated to PageSearchHelper::extractSnippet.
     */
    private function extractSnippet(string $text, string $query, int $contextLength = 100): string {
        return $this->searchHelper->extractSnippet($text, $query, $contextLength);
    }

    /**
     * Search a single widget for matches
     *
     * @param array $widget Widget data
     * @param string $query Search query (lowercase)
     * @return array Array of matches with type, text, and score
     */
    /**
     * @deprecated Delegated to PageSearchHelper::searchWidget.
     */
    private function searchWidget(array $widget, string $query): array {
        return $this->searchHelper->searchWidget($widget, $query);
    }

    /**
     * Sanitize filename for safe storage
     * - Validates extension against whitelist
     * - Remove special characters
     * - Convert spaces to underscores
     * - Check for Windows reserved names
     * - Limit to filesystem-safe length
     *
     * @param string $filename Original filename
     * @param bool $validateExtension Whether to validate extension (default true)
     * @return string Sanitized filename
     * @throws \InvalidArgumentException If extension is not allowed
     */
    /**
     * @deprecated Delegated to MediaSanitizer::sanitizeFilename.
     * Thin wrapper for existing call-sites in ApiController and templates.
     */
    public function sanitizeFilename(string $filename, bool $validateExtension = true): string {
        return $this->mediaSanitizer->sanitizeFilename($filename, $validateExtension);
    }

    /**
     * Sanitize SVG file content to prevent XSS attacks
     *
     * Removes: <script>, event handlers, <foreignObject>, DOCTYPE, external refs
     *
     * @param string $svgContent Raw SVG file content
     * @return string Sanitized SVG content
     * @throws \Exception If SVG is malformed or contains disallowed content
     */
    /**
     * @deprecated Delegated to MediaSanitizer::sanitizeSVG.
     */
    private function sanitizeSVG(string $svgContent): string {
        return $this->mediaSanitizer->sanitizeSVG($svgContent);
    }

    /**
     * Validate image file using getimagesize() to prevent polyglot attacks
     *
     * This provides additional security beyond MIME type detection by
     * actually parsing the image headers.
     *
     * @param string $tmpFile Path to temporary uploaded file
     * @param string $detectedMime MIME type detected by finfo
     * @throws \InvalidArgumentException If image is invalid or MIME type doesn't match
     */
    /**
     * @deprecated Delegated to MediaSanitizer::validateImageFile.
     */
    private function validateImageFile(string $tmpFile, string $detectedMime): void {
        $this->mediaSanitizer->validateImageFile($tmpFile, $detectedMime);
    }

    /**
     * Check if media file exists in page/_media or _resources folder
     *
     * @param string $pageId Page unique ID
     * @param string $filename Filename to check
     * @param string $targetFolder 'page' or 'resources'
     * @return bool True if file exists
     */
    public function checkMediaExists(string $pageId, string $filename, string $targetFolder): bool {
        try {
            $filename = basename($filename); // Prevent directory traversal

            // Must resolve the page exactly as the upload does, or the
            // duplicate check inspects a different folder than the one written
            // to — silently answering "no duplicate" and overwriting nothing,
            // or prompting about a file the upload will not touch (#92).
            $located = $this->locatePageForMedia($pageId);
            if ($located === null) {
                return false;
            }
            $result = $located['result'];
            $languageFolder = $located['languageFolder'];

            if ($targetFolder === 'resources') {
                // Check in _resources folder
                try {
                    $resourcesFolder = $languageFolder->get('_resources');
                    $resourcesFolder->get($filename);
                    return true;
                } catch (NotFoundException $e) {
                    return false;
                }
            } else {

                // Get media folder
                if ($result['isHome'] ?? false) {
                    try {
                        $mediaFolder = $languageFolder->get('_media');
                    } catch (NotFoundException $e) {
                        return false;
                    }
                } else {
                    $pageFolder = $result['folder'];
                    try {
                        $mediaFolder = $pageFolder->get('_media');
                    } catch (NotFoundException $e) {
                        return false;
                    }
                }

                // Check if file exists
                try {
                    $mediaFolder->get($filename);
                    return true;
                } catch (NotFoundException $e) {
                    return false;
                }
            }
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Upload media with original filename
     *
     * @param string $pageId Page unique ID
     * @param array $file Uploaded file data
     * @param string $targetFolder 'page' or 'resources'
     * @param bool $overwrite Whether to overwrite existing file
     * @return array ['filename' => '...', 'exists' => bool]
     * @throws \Exception On upload failure or if file exists and overwrite is false
     */
    public function uploadMediaWithOriginalName(string $pageId, array $file, string $targetFolder, bool $overwrite = false): array {
        if (!isset($file['tmp_name']) || !isset($file['name'])) {
            throw new \InvalidArgumentException('Invalid file upload');
        }

        // Check if tmp_name is empty (upload failed on server)
        if (empty($file['tmp_name'])) {
            $errorCode = $file['error'] ?? -1;
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds form upload limit',
                UPLOAD_ERR_PARTIAL => 'File only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'Upload stopped by PHP extension',
            ];
            $message = $errorMessages[$errorCode] ?? "Upload failed (error code: $errorCode)";
            throw new \InvalidArgumentException($message);
        }

        // Validate file type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, self::ALLOWED_MEDIA_TYPES)) {
            throw new \InvalidArgumentException('Invalid file type. Allowed: JPEG, PNG, GIF, WebP, SVG, MP4, WebM, OGG');
        }

        // Validate file size
        if ($file['size'] > self::MAX_MEDIA_SIZE) {
            throw new \InvalidArgumentException('File too large. Maximum size is 50MB.');
        }

        // Additional validation for image files (prevents polyglot attacks)
        if (in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
            $this->validateImageFile($file['tmp_name'], $mimeType);
        }

        // SVG files get special treatment: smaller size limit + sanitization
        if ($mimeType === 'image/svg+xml') {
            if ($file['size'] > self::MAX_SVG_SIZE) {
                throw new \InvalidArgumentException('SVG file too large. Maximum size is 1MB.');
            }
            $content = file_get_contents($file['tmp_name']);
            $content = $this->sanitizeSVG($content);
        } else {
            $content = file_get_contents($file['tmp_name']);
        }

        // Sanitize original filename
        $filename = $this->sanitizeFilename($file['name']);

        // Check if file exists
        $fileExists = $this->checkMediaExists($pageId, $filename, $targetFolder);
        if ($fileExists && !$overwrite) {
            throw new \Exception('File already exists');
        }

        // Resolve the page first: both branches want the language folder the
        // page really lives in, not the uploader's own profile language (#92).
        $located = $this->locatePageForMedia($pageId);
        if ($located === null) {
            throw new PageNotFoundException('Page not found: ' . $pageId);
        }
        $result = $located['result'];
        $languageFolder = $located['languageFolder'];

        // Get target folder based on targetFolder parameter
        if ($targetFolder === 'resources') {
            // Upload to _resources folder — the shared library of the page's
            // own language, so the asset lands where that page can serve it.
            try {
                $uploadFolder = $languageFolder->get('_resources');
            } catch (NotFoundException $e) {
                $uploadFolder = $languageFolder->newFolder('_resources');
            }
        } else {
            // Get media folder for this page
            if ($result['isHome'] ?? false) {
                // Home media is in root/_media/
                try {
                    $uploadFolder = $languageFolder->get('_media');
                } catch (NotFoundException $e) {
                    $uploadFolder = $languageFolder->newFolder('_media');
                }
            } else {
                $pageFolder = $result['folder'];

                // Get or create media subfolder
                try {
                    $uploadFolder = $pageFolder->get('_media');
                } catch (NotFoundException $e) {
                    $uploadFolder = $pageFolder->newFolder('_media');
                }
            }
        }

        // Upload file (content already sanitized for SVG)
        if ($fileExists && $overwrite) {
            // Overwrite existing file
            $existingFile = $uploadFolder->get($filename);
            $existingFile->putContent($content);
        } else {
            // Create new file
            $newFile = $uploadFolder->newFile($filename);
            $newFile->putContent($content);
        }

        // Invalidate the per-page content cache so the next getPage()
        // reflects the new media file. See uploadMedia() for context.
        $this->clearCache($pageId);

        return [
            'filename' => $filename,
            'exists' => $fileExists
        ];
    }

    /**
     * Get list of media files in a folder
     *
     * @param string $pageId Page unique ID
     * @param string $folderType 'page' or 'resources'
     * @param string $subPath Subfolder path for resources (optional)
     * @return array List of media files with metadata
     */
    public function getMediaList(string $pageId, string $folderType, string $subPath = ''): array {
        try {
            // List from the page's own language folder. getReadLanguageFolder()
            // answers "what should this USER see", which for the Shared Library
            // of a specific page is the wrong question: it listed one language's
            // _resources while the widget resolved images from another, so the
            // picker showed names whose previews always 404'd (#92).
            $located = $this->locatePageForMedia($pageId);
            if ($located === null) {
                return [];
            }
            $languageFolder = $located['languageFolder'];
            $result = $located['result'];
            $mediaFiles = [];

            if ($folderType === 'resources') {
                // List files in _resources folder
                try {
                    $resourcesFolder = $languageFolder->get('_resources');

                    // Navigate to subfolder if path provided
                    $targetFolder = $resourcesFolder;
                    if (!empty($subPath)) {
                        $subPath = trim($subPath, '/');
                        $targetFolder = $resourcesFolder->get($subPath);
                    }

                    $files = $targetFolder->getDirectoryListing();

                    foreach ($files as $file) {
                        $relativePath = $this->getRelativePath($file, $resourcesFolder);

                        if ($file->getType() === \OCP\Files\FileInfo::TYPE_FOLDER) {
                            // It's a folder
                            $mediaFiles[] = [
                                'type' => 'folder',
                                'name' => $file->getName(),
                                'path' => $relativePath,
                                'modified' => $file->getMTime()
                            ];
                        } else {
                            // It's a file
                            $mediaFiles[] = [
                                'type' => 'file',
                                'name' => $file->getName(),
                                'path' => $relativePath,
                                'size' => $file->getSize(),
                                'mimeType' => $file->getMimetype(),
                                'modified' => $file->getMTime()
                            ];
                        }
                    }
                } catch (NotFoundException $e) {
                    // _resources folder or subfolder doesn't exist
                    return [];
                }
            } else {
                // List files in page/_media folder — $result was already
                // resolved cross-language above.

                // Get media folder
                if ($result['isHome'] ?? false) {
                    try {
                        $mediaFolder = $languageFolder->get('_media');
                    } catch (NotFoundException $e) {
                        return [];
                    }
                } else {
                    $pageFolder = $result['folder'];
                    try {
                        $mediaFolder = $pageFolder->get('_media');
                    } catch (NotFoundException $e) {
                        return [];
                    }
                }

                // List files
                $files = $mediaFolder->getDirectoryListing();
                foreach ($files as $file) {
                    if ($file->getType() === \OCP\Files\FileInfo::TYPE_FILE) {
                        $mediaFiles[] = [
                            'name' => $file->getName(),
                            'size' => $file->getSize(),
                            'mimeType' => $file->getMimetype(),
                            'modified' => $file->getMTime()
                        ];
                    }
                }
            }

            // Sort by name
            usort($mediaFiles, function($a, $b) {
                return strcmp($a['name'], $b['name']);
            });

            return $mediaFiles;

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get media file from _resources folder
     *
     * @param string $path File path (can include subfolders)
     * @return \OCP\Files\File File object
     * @throws NotFoundException If file not found
     */
    public function getResourcesMediaFile(string $path) {
        // Path is already sanitized by ApiController::sanitizePath()
        //
        // This route carries no pageId, so the page's language cannot be
        // resolved the way the other media paths do. Look in the language the
        // user reads first, then in the remaining language folders: a shared
        // asset referenced from a page in another language is still a legitimate
        // request, and answering 404 blanked those images (#92).
        $readFolder = $this->getReadLanguageFolder();

        $file = $this->findResourceIn($readFolder, $path);
        if ($file !== null) {
            return $file;
        }

        $baseFolder = $this->getIntraVoxFolder();
        $searchedPath = $readFolder->getPath();

        foreach ($this->getCachedDirectoryListing($baseFolder) as $item) {
            if ($item->getType() !== \OCP\Files\FileInfo::TYPE_FOLDER
                || !($item instanceof \OCP\Files\Folder)) {
                continue;
            }
            if (!preg_match('/^[a-z]{2,3}$/', $item->getName())
                || $item->getPath() === $searchedPath) {
                continue;
            }
            $file = $this->findResourceIn($item, $path);
            if ($file !== null) {
                return $file;
            }
        }

        throw new NotFoundException('Media file not found: ' . $path);
    }

    /**
     * Resolve $path inside one language folder's `_resources`, or null.
     * Kept separate so the cross-language walk above reads as a walk.
     */
    private function findResourceIn(\OCP\Files\Folder $languageFolder, string $path): ?\OCP\Files\Node {
        $resourcesFolder = $this->folderOrNull($languageFolder, '_resources');
        if ($resourcesFolder === null) {
            return null;
        }
        try {
            return $resourcesFolder->get($path);
        } catch (NotFoundException $e) {
            return null;
        }
    }

    /**
     * Get relative path from resources root
     * @param \OCP\Files\Node $node File or folder node
     * @param \OCP\Files\Folder $resourcesRoot _resources folder root
     * @return string Relative path (e.g., "logos/company.png")
     */
    private function getRelativePath(\OCP\Files\Node $node, \OCP\Files\Folder $resourcesRoot): string {
        $fullPath = $node->getPath();
        $rootPath = $resourcesRoot->getPath();
        return ltrim(substr($fullPath, strlen($rootPath)), '/');
    }

    /**
     * Get news pages for the News widget
     *
     * @param string $sourcePath Source folder path (relative to language folder)
     * @param array $filters MetaVox filters to apply
     * @param string $filterOperator 'AND' or 'OR' for combining filters
     * @param int $limit Maximum number of results
     * @param string $sortBy Field to sort by ('modified' or 'title')
     * @param string $sortOrder Sort direction ('asc' or 'desc')
     * @return array News items with excerpts and images
     */
    public function getNewsPages(
        string $sourcePath = '',
        array $filters = [],
        string $filterOperator = 'AND',
        int $limit = 5,
        string $sortBy = 'modified',
        string $sortOrder = 'desc',
        ?string $sourcePageId = null,
        bool $filterPublished = false
    ): array {
        $folder = $this->getReadLanguageFolder();
        $pages = [];
        // Match the served language (recommended-language fallback, #75) so
        // the news cache key and date localisation agree with the folder.
        $language = $this->resolveEffectiveLanguage() ?? $this->getUserLanguage();

        // Version-counter cache: the news widget result depends on all pages in
        // the source folder plus user-supplied filters/sort/limit, plus the
        // user's group context (permissions). We don't want to rebuild on every
        // dashboard render, but invalidation must be instant on any page write.
        //
        // Strategy: a per-language counter that PageService::clearCache bumps
        // on every mutation. Cache entries embed the current counter value;
        // after a bump, every old entry is unreachable (no reader looks under
        // the stale counter), so they age out via TTL without ever serving
        // stale data. Plan B4 from the roadmap.
        $newsVersionKey = 'news_version_' . $language;
        $newsVersion = 0;
        $newsCacheKey = null;
        if ($this->distributedCache !== null) {
            $newsVersion = (int) ($this->distributedCache->get($newsVersionKey) ?? 0);
            $paramHash = md5(json_encode([
                $sourcePath, $filters, $filterOperator, $limit, $sortBy,
                $sortOrder, $sourcePageId, $filterPublished,
            ]));
            $newsCacheKey = 'news_' . $language . '_' . $this->groupContext->getGroupHash()
                . '_v' . $newsVersion . '_' . $paramHash;
            $cached = $this->distributedCache->get($newsCacheKey);
            if (is_string($cached)) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        // If sourcePageId is provided, find that page and use its folder as source
        // Also include the selected page itself in the results
        $sourcePageData = null;
        if (!empty($sourcePageId)) {
            try {
                $result = $this->findPageByUniqueId($folder, $sourcePageId);
                if ($result && isset($result['folder'])) {
                    $folder = $result['folder'];
                    // Store the source page data to include it in results
                    if (isset($result['file'])) {
                        $sourcePageData = $result;
                    }
                } else {
                    $this->logger->warning('News widget: Source page not found', ['sourcePageId' => $sourcePageId]);
                    return ['items' => [], 'total' => 0, 'metavoxAvailable' => $this->isMetaVoxAvailable()];
                }
            } catch (\Exception $e) {
                $this->logger->warning('News widget: Error finding source page', ['sourcePageId' => $sourcePageId, 'error' => $e->getMessage()]);
                return ['items' => [], 'total' => 0, 'metavoxAvailable' => $this->isMetaVoxAvailable()];
            }
        }
        // Legacy: If sourcePath is provided (but no sourcePageId), navigate to that folder
        elseif (!empty($sourcePath)) {
            $sourcePath = trim($sourcePath, '/');
            try {
                $folder = $folder->get($sourcePath);
            } catch (NotFoundException $e) {
                $this->logger->warning('News widget: Source folder not found', ['path' => $sourcePath]);
                return ['items' => [], 'total' => 0, 'metavoxAvailable' => $this->isMetaVoxAvailable()];
            }
        }

        // Recursively collect pages from the source folder.
        // Pass a hard cap to allow early-exit and prevent unbounded filesystem scans.
        $collectLimit = max($limit * 4, 200); // collect enough for filtering/sorting, cap at 200 minimum
        $this->findNewsPagesInFolder($folder, $pages, $language, $collectLimit);

        // Add the selected source page itself to the results (if sourcePageId was provided)
        if ($sourcePageData !== null && isset($sourcePageData['file'])) {
            try {
                $content = $sourcePageData['file']->getContent();
                $data = json_decode($content, true);

                if ($data && isset($data['uniqueId'], $data['title'])) {
                    // Get folder permissions
                    $folderPerms = $this->getCachedPermissions($sourcePageData['folder']);

                    // Only add if user can read
                    if (($folderPerms & 1) !== 0) {
                        $excerpt = $this->getPageExcerpt($data, 150);
                        $imageData = $this->getPageFirstImage($data);
                        $imagePath = null;
                        if ($imageData) {
                            if (($imageData['mediaFolder'] ?? 'page') === 'resources') {
                                $imagePath = '/apps/intravox/api/resources/media/' . $imageData['src'];
                            } else {
                                $imagePath = '/apps/intravox/api/pages/' . $data['uniqueId'] . '/media/' . $imageData['src'];
                            }
                        }

                        $newsItem = [
                            'uniqueId' => $data['uniqueId'],
                            'title' => $data['title'],
                            'status' => $data['status'] ?? 'published',
                            'excerpt' => $excerpt,
                            'image' => $imageData ? $imageData['src'] : null,
                            'imagePath' => $imagePath,
                            'modified' => $sourcePageData['file']->getMTime(),
                            'modifiedFormatted' => $this->formatDateLocalized($sourcePageData['file']->getMTime(), $language),
                            'path' => $sourcePageData['folder']->getPath(),
                            'fileId' => $sourcePageData['file']->getId(),
                        ];

                        // Add to beginning of pages array (it's the "parent" page)
                        array_unshift($pages, $newsItem);
                    }
                }
            } catch (\Exception $e) {
                $this->logger->debug('News widget: Could not add source page to results', ['error' => $e->getMessage()]);
            }
        }

        // Apply MetaVox filters if any and if MetaVox is available
        if (!empty($filters) && $this->isMetaVoxAvailable()) {
            $pages = $this->applyMetaVoxFilters($pages, $filters, $filterOperator);
        }

        // Apply the publication filter when the widget asks for published pages
        // only. Not gated on MetaVox: the manual draft/published status must be
        // honoured even when no publication date fields are configured.
        if ($filterPublished) {
            $pages = $this->applyPublicationDateFilter($pages);
        }

        $total = count($pages);

        // Sort pages
        usort($pages, function($a, $b) use ($sortBy, $sortOrder) {
            if ($sortBy === 'title') {
                $cmp = strcasecmp($a['title'] ?? '', $b['title'] ?? '');
            } else {
                // Default: sort by modified
                $cmp = ($a['modified'] ?? 0) - ($b['modified'] ?? 0);
            }
            return $sortOrder === 'asc' ? $cmp : -$cmp;
        });

        // Limit results
        $pages = array_slice($pages, 0, $limit);

        $result = [
            'items' => $pages,
            'total' => $total,
            'metavoxAvailable' => $this->isMetaVoxAvailable(),
        ];

        // Cache for 5 minutes — the version-counter scheme makes correctness
        // independent of TTL (a counter bump renders this entry unreachable),
        // so the TTL only bounds memory growth from orphaned entries.
        if ($this->distributedCache !== null && $newsCacheKey !== null) {
            $this->distributedCache->set($newsCacheKey, json_encode($result), 300);
        }

        return $result;
    }

    /**
     * Recursively find news pages in a folder
     *
     * @param int $maxCollect Hard cap on items to collect (0 = unlimited)
     */
    private function findNewsPagesInFolder($folder, array &$pages, string $language, int $maxCollect = 0): void {
        foreach ($this->getCachedDirectoryListing($folder) as $item) {
            // Early-exit when we have collected enough items
            if ($maxCollect > 0 && count($pages) >= $maxCollect) {
                return;
            }
            if ($item->getType() !== \OCP\Files\FileInfo::TYPE_FOLDER) {
                continue;
            }

            $folderName = $item->getName();

            // Skip special folders
            if (in_array($folderName, ['_media', '_resources', 'images', 'files'])) {
                continue;
            }

            // Look for {foldername}.json inside the folder
            try {
                $jsonFile = $item->get($folderName . '.json');

                if (!$jsonFile->isReadable()) {
                    continue;
                }

                $content = $jsonFile instanceof \OCP\Files\File
                    ? $this->getCachedFileContent($jsonFile)
                    : @$jsonFile->getContent();

                if ($content === false || $content === null) {
                    continue;
                }

                $data = json_decode($content, true);

                if ($data && isset($data['uniqueId'], $data['title'])) {
                    // Get folder permissions
                    $folderPerms = $this->getCachedPermissions($item);

                    // Skip if user can't read
                    if (($folderPerms & 1) === 0) {
                        continue;
                    }

                    // Extract excerpt from first text widget
                    $excerpt = $this->getPageExcerpt($data, 150);

                    // Find first image
                    $imageData = $this->getPageFirstImage($data);
                    $imagePath = null;
                    if ($imageData) {
                        if (($imageData['mediaFolder'] ?? 'page') === 'resources') {
                            $imagePath = '/apps/intravox/api/resources/media/' . $imageData['src'];
                        } else {
                            $imagePath = '/apps/intravox/api/pages/' . $data['uniqueId'] . '/media/' . $imageData['src'];
                        }
                    }

                    // Build relative path
                    $relativePath = $this->getRelativePathFromRoot($item);

                    // Get file modification time
                    $modified = $jsonFile->getMTime();

                    // Format modified date in user's locale
                    $modifiedFormatted = $this->formatDateLocalized($modified, $language);

                    $pages[] = [
                        'uniqueId' => $data['uniqueId'],
                        'title' => $data['title'],
                        // Needed by the publication gate: without it every item
                        // looked "published" and drafts leaked into News lists.
                        'status' => $data['status'] ?? 'published',
                        'excerpt' => $excerpt,
                        'image' => $imageData ? $imageData['src'] : null,
                        'imagePath' => $imagePath,
                        'modified' => $modified,
                        'modifiedFormatted' => $modifiedFormatted,
                        'path' => $relativePath,
                        'fileId' => $jsonFile->getId(),
                        'permissions' => [
                            'canRead' => ($folderPerms & 1) !== 0,
                            // AND with the node capability so a read-only GroupFolder
                            // member is reported correctly (issue #70), consistent
                            // with permissionsFromNode().
                            'canWrite' => ($folderPerms & 2) !== 0 && $item->isUpdateable(),
                            'raw' => $folderPerms
                        ]
                    ];
                }
            } catch (\Exception $e) {
                // This folder doesn't contain a valid page
            } catch (\Throwable $e) {
                continue;
            }

            // Recursively search subfolders
            $this->findNewsPagesInFolder($item, $pages, $language, $maxCollect);

            // Re-check limit after recursion to avoid scanning more siblings
            if ($maxCollect > 0 && count($pages) >= $maxCollect) {
                return;
            }
        }
    }

    /**
     * Extract an excerpt from page content (first text widget)
     */
    /**
     * @deprecated Delegated to NewsContentExtractor::getExcerpt.
     */
    public function getPageExcerpt(array $pageData, int $length = 150): string {
        return $this->newsContent->getExcerpt($pageData, $length);
    }

    /**
     * @deprecated Delegated to NewsContentExtractor::stripMarkdown.
     */
    private function stripMarkdown(string $text): string {
        return $this->newsContent->stripMarkdown($text);
    }

    /**
     * Find the first image in a page's layout
     * Returns array with 'src' and 'mediaFolder' or null if no image found
     */
    /**
     * @deprecated Delegated to NewsContentExtractor::getFirstImage.
     */
    public function getPageFirstImage(array $pageData): ?array {
        return $this->newsContent->getFirstImage($pageData);
    }

    /**
     * Check if MetaVox app is available
     */
    private function isMetaVoxAvailable(): bool {
        try {
            return $this->appManager->isInstalled('metavox') && $this->appManager->isEnabledForUser('metavox');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Apply MetaVox filters to pages
     *
     * @param array $pages Pages to filter
     * @param array $filters Filter definitions
     * @param string $operator 'AND' or 'OR'
     * @return array Filtered pages
     */
    private function applyMetaVoxFilters(array $pages, array $filters, string $operator = 'AND'): array {
        if (empty($filters) || !$this->isMetaVoxAvailable()) {
            return $pages;
        }

        // Get file IDs from pages
        $fileIds = array_filter(array_column($pages, 'fileId'));
        if (empty($fileIds)) {
            return $pages;
        }

        // Fetch MetaVox data for all file IDs
        $metaVoxData = $this->getMetaVoxDataForFiles($fileIds);

        // Filter pages based on MetaVox values
        return array_filter($pages, function($page) use ($filters, $operator, $metaVoxData) {
            $fileId = $page['fileId'] ?? null;
            if (!$fileId) {
                return $operator === 'OR'; // No fileId = no match for AND, possible match for OR
            }

            $meta = $metaVoxData[$fileId] ?? [];
            $results = [];

            foreach ($filters as $filter) {
                $fieldName = $filter['fieldName'] ?? '';
                $filterOperator = $filter['operator'] ?? 'equals';
                $filterValue = $filter['value'] ?? '';
                $filterValues = $filter['values'] ?? [];
                $actualValue = $meta[$fieldName] ?? null;

                // Use values array for operators that work with multiple values
                if (in_array($filterOperator, ['in', 'contains', 'contains_all']) && !empty($filterValues)) {
                    $filterValue = $filterValues;
                }

                $results[] = $this->matchesFilter($actualValue, $filterOperator, $filterValue);
            }

            if (empty($results)) {
                return true;
            }

            return $operator === 'AND'
                ? !in_array(false, $results, true)
                : in_array(true, $results, true);
        });
    }

    /**
     * Check if a value matches a filter
     */
    private function matchesFilter($value, string $operator, $filterValue): bool {
        switch ($operator) {
            // Text/general operators
            case 'equals':
                return $value === $filterValue;
            case 'contains':
                if (is_array($value)) {
                    // For multiselect: check if filterValue is in the array
                    return in_array($filterValue, $value);
                }
                return is_string($value) && str_contains($value, $filterValue);
            case 'not_contains':
                if (is_array($value)) {
                    return !in_array($filterValue, $value);
                }
                return is_string($value) && !str_contains($value, $filterValue);
            case 'in':
                $allowedValues = is_array($filterValue) ? $filterValue : [$filterValue];
                return in_array($value, $allowedValues);
            case 'not_empty':
                return !empty($value);
            case 'empty':
                return empty($value);

            // Date operators
            case 'before':
                $dateValue = $this->parseDate($value);
                $dateFilter = $this->parseDate($filterValue);
                if (!$dateValue || !$dateFilter) return false;
                return $dateValue < $dateFilter;
            case 'after':
                $dateValue = $this->parseDate($value);
                $dateFilter = $this->parseDate($filterValue);
                if (!$dateValue || !$dateFilter) return false;
                return $dateValue > $dateFilter;

            // Number operators
            case 'greater_than':
                if (!is_numeric($value) || !is_numeric($filterValue)) return false;
                return (float)$value > (float)$filterValue;
            case 'less_than':
                if (!is_numeric($value) || !is_numeric($filterValue)) return false;
                return (float)$value < (float)$filterValue;
            case 'greater_or_equal':
                if (!is_numeric($value) || !is_numeric($filterValue)) return false;
                return (float)$value >= (float)$filterValue;
            case 'less_or_equal':
                if (!is_numeric($value) || !is_numeric($filterValue)) return false;
                return (float)$value <= (float)$filterValue;

            // Checkbox operators
            case 'is_true':
                return $value === true || $value === 'true' || $value === '1' || $value === 1;
            case 'is_false':
                return $value === false || $value === 'false' || $value === '0' || $value === 0 || $value === '';

            // Multiselect operators
            case 'contains_all':
                if (!is_array($value)) return false;
                $requiredValues = is_array($filterValue) ? $filterValue : [$filterValue];
                foreach ($requiredValues as $required) {
                    if (!in_array($required, $value)) return false;
                }
                return true;

            default:
                return false;
        }
    }

    /**
     * Filter pages based on publication dates from MetaVox fields
     *
     * Logic: (Publish date is empty OR Publish date <= today)
     *    AND (Expiration date is empty OR Expiration date > today)
     *
     * @param array $pages Pages to filter
     * @return array Filtered pages that are currently published
     */
    private function applyPublicationDateFilter(array $pages): array {
        // Delegate to the shared, time-aware publication gate so a News list uses
        // exactly the same definition of "published" as the rest of the app
        // (this also fixes the earlier date-only comparison where "today 03:25"
        // already counted as published). A page is kept only when it is
        // effectively published right now — which includes hiding manual drafts,
        // even when no publication date fields are configured or MetaVox is
        // absent. Previously this returned early in those cases, so "show only
        // published pages" let drafts through.
        $metaVoxData = $this->publicationMetaForFiles(array_column($pages, 'fileId'));

        return array_values(array_filter($pages, function($page) use ($metaVoxData) {
            $fileId = $page['fileId'] ?? null;
            // Without a fileId we cannot look up dates, but the page's own
            // draft/published status is still authoritative — don't let a draft
            // through just because its metadata is unavailable.
            $meta = $fileId ? ($metaVoxData[$fileId] ?? []) : [];
            return $this->effectivePublishState($page, $meta) === 'published';
        }));
    }

    /**
     * Effective publication state of a single page, evaluated live ("lazy") so a
     * scheduled page flips to published the moment its publish time passes — no
     * cron needed. Combines the manual draft/published status with the
     * admin-configured MetaVox publish/expiration date fields.
     *
     * Returns one of:
     *   'published' — publicly visible now
     *   'draft'     — manually held back
     *   'scheduled' — publish date is in the future
     *   'expired'   — expiration date has passed
     *
     * Only 'published' is visible to readers/anonymous visitors; the other three
     * are hidden from them but shown to users with write permission.
     *
     * @param array      $page        Page array (needs 'status' and 'fileId')
     * @param array|null $metaForFile Pre-fetched MetaVox fields for this file
     *                                (fieldName => value). Pass this in list
     *                                contexts to avoid an N+1 query; when null it
     *                                is looked up on demand.
     */
    public function effectivePublishState(array $page, ?array $metaForFile = null): string {
        $manualDraft = ($page['status'] ?? 'published') === 'draft';

        $publishField = $this->publicationSettings->getPublishDateField();
        $expireField = $this->publicationSettings->getExpirationDateField();

        // No scheduling configured, or MetaVox unavailable → the manual
        // draft/published flag governs, exactly as before.
        if ((empty($publishField) && empty($expireField)) || !$this->isMetaVoxAvailable()) {
            return $manualDraft ? 'draft' : 'published';
        }

        $meta = $metaForFile;
        if ($meta === null) {
            $fileId = $page['fileId'] ?? null;
            $meta = $fileId ? ($this->getMetaVoxDataForFiles([$fileId])[$fileId] ?? []) : [];
        }

        // Interpret the publish/expire dates AND "now" in one consistent instance
        // timezone. The MetaVox datetime-local input stores a naive local time
        // (e.g. "2026-08-04T15:57:00", no zone); the editor entered it in their
        // local time. Comparing that against a UTC "now" was off by the UTC
        // offset, so a page could read "Scheduled" when it was already live.
        $tz = $this->publicationTimezone();
        $now = new \DateTime('now', $tz);

        // Resolve the configured date values (field names are admin-configurable
        // in the IntraVox settings and may differ or be empty).
        $publishAt = (!empty($publishField) && !empty($meta[$publishField]))
            ? $this->parseDateTime((string)$meta[$publishField], $tz) : null;
        $expireAt = (!empty($expireField) && !empty($meta[$expireField]))
            ? $this->parseDateTime((string)$meta[$expireField], $tz) : null;

        // WordPress-style model: a Publish-on DATE, when set, governs publication
        // and overrides the manual draft flag — so you never get the confusing
        // "Draft badge + past publish date" combination. The manual draft only
        // applies when no publish date is set.
        if ($publishAt !== null) {
            if ($publishAt > $now) {
                return 'scheduled'; // future → not live yet (draft flag ignored)
            }
            // publish date has passed → published, subject only to expiration below.
        } elseif ($manualDraft) {
            return 'draft'; // no publish date → manual draft holds it back
        }

        // Expiration applies regardless of how the page became published.
        if ($expireAt !== null && $expireAt <= $now) {
            return 'expired';
        }

        return 'published';
    }

    /**
     * Whether a page must be hidden from a viewer WITHOUT write permission.
     * True for draft, scheduled (future) and expired pages.
     *
     * @param array      $page
     * @param array|null $metaForFile Optional pre-fetched MetaVox fields (see
     *                                effectivePublishState) to avoid N+1 queries.
     */
    public function isHiddenFromReaders(array $page, ?array $metaForFile = null): bool {
        return $this->effectivePublishState($page, $metaForFile) !== 'published';
    }

    /**
     * Whether a page has an active publish/expiration date (from the configured
     * MetaVox fields). When true, that date governs publication and the manual
     * draft/published toggle is overridden — the editor UI uses this to explain
     * why the toggle is showing the effective state instead of the raw status.
     *
     * @param array      $page
     * @param array|null $metaForFile Optional pre-fetched MetaVox fields.
     */
    public function hasPublicationDate(array $page, ?array $metaForFile = null): bool {
        $publishField = $this->publicationSettings->getPublishDateField();
        $expireField = $this->publicationSettings->getExpirationDateField();
        if ((empty($publishField) && empty($expireField)) || !$this->isMetaVoxAvailable()) {
            return false;
        }
        $meta = $metaForFile;
        if ($meta === null) {
            $fileId = $page['fileId'] ?? null;
            $meta = $fileId ? ($this->getMetaVoxDataForFiles([$fileId])[$fileId] ?? []) : [];
        }
        return (!empty($publishField) && !empty($meta[$publishField]))
            || (!empty($expireField) && !empty($meta[$expireField]));
    }

    /**
     * Time-aware date/time parse for publication scheduling. Unlike parseDate()
     * (which truncates to Y-m-d and made "today 03:25" count as already
     * published), this preserves the time component so a same-day schedule is
     * respected to the minute.
     *
     * The MetaVox datetime-local input stores a NAIVE local time with no zone
     * (e.g. "2026-08-04T15:57:00"); such values are interpreted in $tz (the
     * instance timezone). Values that carry an explicit offset ("…Z" / "+02:00")
     * keep their own zone.
     *
     * @param string             $dateStr
     * @param \DateTimeZone|null  $tz Timezone for naive values (default: instance).
     * @return \DateTime|null Parsed date/time, or null if unparseable.
     */
    private function parseDateTime(string $dateStr, ?\DateTimeZone $tz = null): ?\DateTime {
        $dateStr = trim($dateStr);
        if ($dateStr === '') {
            return null;
        }
        $tz = $tz ?? $this->publicationTimezone();

        $formats = [
            'Y-m-d\TH:i:s',   // ISO 8601 (naive): 2025-01-15T14:30:00
            'Y-m-d\TH:i',     // datetime-local input: 2025-01-15T14:30
            'Y-m-d H:i:s',    // 2025-01-15 14:30:00
            'Y-m-d H:i',      // 2025-01-15 14:30
            'd-m-Y H:i:s',    // European with time
            'd-m-Y H:i',
            'Y-m-d',          // date only → midnight
            'd-m-Y',
            'm/d/Y',
            'd/m/Y',
            'Y/m/d',
        ];

        // If the value carries an explicit zone/offset, honour it (don't force $tz).
        if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $dateStr)) {
            try {
                return new \DateTime($dateStr);
            } catch (\Exception $e) {
                // fall through to format parsing
            }
        }

        foreach ($formats as $format) {
            // Parse naive values IN the instance timezone so the comparison
            // against "now" (also in $tz) is apples-to-apples.
            $date = \DateTime::createFromFormat($format, $dateStr, $tz);
            if ($date !== false) {
                // For date-only formats, createFromFormat keeps the current time;
                // normalise those to start-of-day so "publish on <date>" means
                // 00:00 of that day.
                if (!str_contains($format, 'H')) {
                    $date->setTime(0, 0, 0);
                }
                return $date;
            }
        }

        $timestamp = strtotime($dateStr);
        if ($timestamp !== false) {
            return (new \DateTime('now', $tz))->setTimestamp($timestamp);
        }

        return null;
    }

    /**
     * The timezone in which naive publication dates and "now" are compared.
     * Prefers an explicit instance timezone (NC system `logtimezone`), then the
     * current user's Nextcloud timezone (intranet ≈ org timezone), then the
     * server default. Consistent for logged-in users and anonymous visitors.
     */
    private function publicationTimezone(): \DateTimeZone {
        // 1. Admin-set instance timezone.
        $sys = (string)$this->config->getSystemValue('logtimezone', '');
        if ($sys !== '') {
            try { return new \DateTimeZone($sys); } catch (\Exception $e) {}
        }
        // 2. Current user's NC timezone (empty for anonymous share visitors).
        $uid = $this->userSession->getUser()?->getUID();
        if ($uid) {
            $userTz = (string)$this->config->getUserValue($uid, 'core', 'timezone', '');
            if ($userTz !== '') {
                try { return new \DateTimeZone($userTz); } catch (\Exception $e) {}
            }
        }
        // 3. Server default (PHP date.timezone).
        try {
            return new \DateTimeZone(date_default_timezone_get());
        } catch (\Exception $e) {
            return new \DateTimeZone('UTC');
        }
    }

    /**
     * Parse a date string to Y-m-d format for comparison
     *
     * @param string $dateStr Date string in various formats
     * @return string|null Normalized date in Y-m-d format, or null if parsing failed
     */
    private function parseDate(string $dateStr): ?string {
        if (empty($dateStr)) {
            return null;
        }

        $dateStr = trim($dateStr);

        // Try common date formats
        $formats = [
            'Y-m-d',        // ISO format: 2025-01-15
            'd-m-Y',        // European: 15-01-2025
            'm/d/Y',        // US: 01/15/2025
            'd/m/Y',        // European with slash: 15/01/2025
            'Y/m/d',        // Alternative ISO: 2025/01/15
            'Y-m-d H:i:s',  // ISO with time: 2025-01-15 14:30:00
            'd-m-Y H:i:s',  // European with time
            'Y-m-d\TH:i:s', // ISO 8601: 2025-01-15T14:30:00
        ];

        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat($format, $dateStr);
            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        }

        // Try strtotime as fallback for natural language dates
        $timestamp = strtotime($dateStr);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }

        return null;
    }

    /**
     * Public batch accessor for MetaVox fields, so list-context callers (page
     * loading, tree, search) can fetch once and hand per-page metadata to
     * effectivePublishState()/isHiddenFromReaders() — avoiding an N+1 query.
     * Returns [] when scheduling is not configured or MetaVox is unavailable.
     *
     * @param int[] $fileIds
     * @return array<int, array<string, string>> fileId => [fieldName => value]
     */
    public function publicationMetaForFiles(array $fileIds): array {
        $publishField = $this->publicationSettings->getPublishDateField();
        $expireField = $this->publicationSettings->getExpirationDateField();
        if ((empty($publishField) && empty($expireField)) || empty($fileIds)) {
            return [];
        }
        return $this->getMetaVoxDataForFiles(array_values(array_filter($fileIds)));
    }

    /**
     * Get MetaVox metadata for multiple files
     *
     * @param array $fileIds Array of file IDs
     * @return array Associative array: fileId => [fieldName => value, ...]
     */
    private function getMetaVoxDataForFiles(array $fileIds): array {
        if (empty($fileIds) || !$this->isMetaVoxAvailable()) {
            return [];
        }

        try {
            // Query the metavox_file_gf_meta table directly
            $qb = $this->db->getQueryBuilder();
            $qb->select('file_id', 'field_name', 'field_value', 'groupfolder_id')
                ->from('metavox_file_gf_meta')
                ->where($qb->expr()->in('file_id', $qb->createNamedParameter($fileIds, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)));

            $result = $qb->executeQuery();
            $rows = $result->fetchAll();
            $result->closeCursor();

            // Organize by file ID. The shape stays field_name => value (callers
            // like applyMetaVoxFilters rely on it); the owning groupfolder is
            // recorded separately so per-field view permissions can be scoped.
            $metaData = [];
            foreach ($rows as $row) {
                $fileId = (int)$row['file_id'];
                $fieldName = $row['field_name'];
                $fieldValue = $row['field_value'];

                if (!isset($metaData[$fileId])) {
                    $metaData[$fileId] = [];
                }
                $metaData[$fileId][$fieldName] = $fieldValue;
                $this->metaVoxGroupfolderByFile[$fileId] = (int)$row['groupfolder_id'];
            }

            return $metaData;

        } catch (\Exception $e) {
            $this->logger->error('Failed to get MetaVox data', [
                'error' => $e->getMessage(),
                'fileIds' => $fileIds
            ]);
            return [];
        }
    }

    /**
     * Map field_name => field_label for MetaVox fields, so search sublines show
     * the human label ("Stad") rather than the raw column name ("stad").
     * Cached for the request; falls back to an empty map when MetaVox is absent,
     * in which case callers use the raw field name.
     *
     * @return array<string, string>
     */
    private function getMetaVoxFieldLabels(): array {
        if ($this->metaVoxFieldLabelsCache !== null) {
            return $this->metaVoxFieldLabelsCache;
        }

        $this->metaVoxFieldLabelsCache = [];

        if (!$this->isMetaVoxAvailable()) {
            return $this->metaVoxFieldLabelsCache;
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('field_name', 'field_label')
                ->from('metavox_gf_fields');

            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $this->metaVoxFieldLabelsCache[$row['field_name']] = $row['field_label'];
            }
            $result->closeCursor();
        } catch (\Exception $e) {
            $this->logger->warning('Failed to load MetaVox field labels', [
                'error' => $e->getMessage(),
            ]);
        }

        return $this->metaVoxFieldLabelsCache;
    }

    /**
     * Search a page's MetaVox metadata for the query and build a subline.
     *
     * The subline format deliberately mirrors MetaVox's own search provider
     * (MetadataSearchProvider::formatMetadataSubline): "Label: value" parts
     * joined with " • ", the matching field first, capped at three fields — so
     * the same document reads identically in both providers' results.
     *
     * Fields the user may not view are skipped, so a restricted MetaVox field
     * cannot leak through an IntraVox search result.
     *
     * @param array<string, mixed> $meta   field_name => value for one file
     * @param string $query                lowercased search term
     * @param array<string, string> $labels field_name => field_label
     * @param int|null $groupfolderId      folder owning the file, for permission scoping
     * @return array{subline: string}|null null when nothing matched
     */
    private function searchMetaVoxValues(array $meta, string $query, array $labels, ?int $groupfolderId = null): ?array {
        if (empty($meta) || $query === '') {
            return null;
        }

        $matching = [];
        $other = [];
        $found = false;

        foreach ($meta as $fieldName => $value) {
            // Multiselect values are stored JSON-encoded; flatten to a string so
            // both the match test and the subline read naturally.
            if (is_string($value) && str_starts_with($value, '[')) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $value = implode(', ', array_filter($decoded, 'is_scalar'));
                }
            }
            if (!is_scalar($value)) {
                continue;
            }
            $value = (string)$value;
            if ($value === '') {
                continue;
            }
            if (!$this->canViewMetaVoxField($fieldName, $groupfolderId)) {
                continue;
            }

            $part = ($labels[$fieldName] ?? $fieldName) . ': ' . $value;
            if (mb_stripos($value, $query) !== false) {
                $matching[] = $part;
                $found = true;
            } else {
                $other[] = $part;
            }
        }

        if (!$found) {
            return null;
        }

        $parts = array_merge($matching, $other);
        return ['subline' => implode(' • ', array_slice($parts, 0, 3))];
    }

    /**
     * Whether the current user may view a MetaVox field. Delegates to MetaVox's
     * own PermissionService (resolved lazily — MetaVox is an optional app).
     *
     * On any failure we return false: hiding a field costs a subline entry,
     * showing one the user may not see would leak metadata.
     */
    private function canViewMetaVoxField(string $fieldName, ?int $groupfolderId = null): bool {
        $cacheKey = $fieldName . ':' . ($groupfolderId ?? 'null');
        if (isset($this->metaVoxFieldViewCache[$cacheKey])) {
            return $this->metaVoxFieldViewCache[$cacheKey];
        }

        $allowed = false;
        try {
            if ($this->userId !== '') {
                $permissionService = \OC::$server->get(\OCA\MetaVox\Service\PermissionService::class);
                $allowed = $permissionService->hasPermission(
                    $this->userId,
                    \OCA\MetaVox\Service\PermissionService::PERM_VIEW_METADATA,
                    $groupfolderId,
                    $fieldName
                );
            }
        } catch (\Throwable $e) {
            $allowed = false;
        }

        $this->metaVoxFieldViewCache[$cacheKey] = $allowed;
        return $allowed;
    }

    /**
     * Format a timestamp in a localized date format
     */
    private function formatDateLocalized(int $timestamp, string $language): string {
        $months = [
            'nl' => ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'],
            'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
            'de' => ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'],
            'fr' => ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'],
        ];

        $monthNames = $months[$language] ?? $months['en'];
        $monthIndex = (int)date('n', $timestamp) - 1;
        $day = date('j', $timestamp);
        $year = date('Y', $timestamp);

        return "$day {$monthNames[$monthIndex]} $year";
    }

    /**
     * Get list of available source folders for the News widget
     * Returns top-level folders in the language folder that contain pages
     */
    public function getNewsSourcFolders(): array {
        $folder = $this->getLanguageFolder();
        $folders = [];

        foreach ($this->getCachedDirectoryListing($folder) as $item) {
            if ($item->getType() !== \OCP\Files\FileInfo::TYPE_FOLDER) {
                continue;
            }

            $folderName = $item->getName();

            // Skip special folders
            if (in_array($folderName, ['_media', '_resources', 'images', 'files'])) {
                continue;
            }

            // Check if this folder contains any pages
            if ($this->folderContainsPages($item)) {
                $folders[] = [
                    'path' => $folderName,
                    'name' => $folderName,
                ];
            }
        }

        // Sort alphabetically
        usort($folders, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        return $folders;
    }

    /**
     * Check if a folder contains any pages (recursively)
     */
    private function folderContainsPages($folder): bool {
        $folderName = $folder->getName();

        // Check if this folder itself is a page
        try {
            $folder->get($folderName . '.json');
            return true;
        } catch (NotFoundException $e) {
            // Not a page folder
        }

        // Check subfolders
        foreach ($this->getCachedDirectoryListing($folder) as $item) {
            if ($item->getType() === \OCP\Files\FileInfo::TYPE_FOLDER) {
                $subFolderName = $item->getName();

                // Skip special folders
                if (in_array($subFolderName, ['_media', '_resources', 'images', 'files'])) {
                    continue;
                }

                if ($this->folderContainsPages($item)) {
                    return true;
                }
            }
        }

        return false;
    }

    // =========================================================================
    // TEMPLATE METHODS
    // =========================================================================

    /**
     * Find a page folder by its uniqueId
     *
     * @param string $uniqueId Page uniqueId
     * @return \OCP\Files\Folder|null The page folder or null if not found
     */
    private function findPageFolder(string $uniqueId): ?\OCP\Files\Folder {
        // Check cache first
        if (isset($this->pageFolderCache[$uniqueId])) {
            return $this->pageFolderCache[$uniqueId];
        }

        try {
            // Follow the page across language folders. This resolves the folder
            // media is copied FROM and TO, and it fails by returning null, which
            // callers treat as "no media" — so on a foreign-language page,
            // "Save as template" and copy-page silently produced a page with no
            // images at all rather than reporting anything (#90 family).
            $result = $this->locatePageAnyLanguage($this->getReadLanguageFolder(), $uniqueId);
            if ($result !== null && isset($result['folder'])) {
                $folder = $result['folder'];
                $this->pageFolderCache[$uniqueId] = $folder;
                return $folder;
            }
        } catch (\Exception $e) {
            $this->logger->warning('Could not find page folder for: ' . $uniqueId . ' - ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Get the templates folder for the current user's language
     *
     * @return \OCP\Files\Folder|null The templates folder or null if not accessible
     */
    private function getTemplatesFolder(): ?\OCP\Files\Folder {
        try {
            $langFolder = $this->getLanguageFolder();
            if ($langFolder->nodeExists('_templates')) {
                $templatesFolder = $langFolder->get('_templates');
                if ($templatesFolder instanceof \OCP\Files\Folder) {
                    return $templatesFolder;
                }
            }
            return null;
        } catch (\Exception $e) {
            $this->logger->warning('Could not access templates folder: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * List all available page templates
     *
     * @return array List of template metadata
     */
    public function listTemplates(): array {
        $templatesFolder = $this->getTemplatesFolder();
        if ($templatesFolder === null) {
            return [];
        }

        $templates = [];

        try {
            foreach ($templatesFolder->getDirectoryListing() as $item) {
                if (!($item instanceof \OCP\Files\Folder)) {
                    continue;
                }

                $templateId = $item->getName();

                // Skip special folders
                if (str_starts_with($templateId, '.') || $templateId === '_media') {
                    continue;
                }

                // Try to read the template JSON file
                try {
                    $jsonFile = $item->get($templateId . '.json');
                    if (!($jsonFile instanceof \OCP\Files\File)) {
                        continue;
                    }

                    $content = json_decode($jsonFile->getContent(), true);
                    if (!$content) {
                        continue;
                    }

                    // Extract preview metadata
                    $preview = $this->extractTemplatePreviewMetadata($content);

                    $templates[] = [
                        'id' => $templateId,
                        'uniqueId' => $content['uniqueId'] ?? 'template-' . $templateId,
                        'title' => $content['title'] ?? $templateId,
                        'description' => $content['description'] ?? '',
                        'created' => $content['created'] ?? $jsonFile->getMTime(),
                        'modified' => $jsonFile->getMTime(),
                        'createdBy' => $content['createdBy'] ?? '',
                        'preview' => $preview,
                    ];
                } catch (NotFoundException $e) {
                    // Template folder exists but no JSON file, skip
                    continue;
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to list templates: ' . $e->getMessage());
        }

        // Sort by title
        usort($templates, fn($a, $b) => strcasecmp($a['title'], $b['title']));

        return $templates;
    }

    /**
     * Extract preview metadata from template content for display in template picker
     *
     * @param array $content Template content data
     * @return array Preview metadata
     */
    /**
     * @deprecated Delegated to TemplateMetadataExtractor::extract.
     */
    private function extractTemplatePreviewMetadata(array $content): array {
        return $this->templateMetadata->extract($content);
    }

    /**
     * Get a specific template by ID
     *
     * @param string $templateId Template ID (folder name)
     * @return array|null Template data or null if not found
     */
    public function getTemplate(string $templateId): ?array {
        $templatesFolder = $this->getTemplatesFolder();
        if ($templatesFolder === null) {
            return null;
        }

        try {
            if (!$templatesFolder->nodeExists($templateId)) {
                return null;
            }

            $templateFolder = $templatesFolder->get($templateId);
            if (!($templateFolder instanceof \OCP\Files\Folder)) {
                return null;
            }

            $jsonFile = $templateFolder->get($templateId . '.json');
            if (!($jsonFile instanceof \OCP\Files\File)) {
                return null;
            }

            $content = json_decode($jsonFile->getContent(), true);
            if (!$content) {
                return null;
            }

            return $content;
        } catch (NotFoundException $e) {
            return null;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get template: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Save a page as a template
     *
     * @param string $pageUniqueId The uniqueId of the page to save as template
     * @param string $templateTitle Title for the template
     * @param string|null $templateDescription Optional description
     * @return array Result with success status and template data or error message
     */
    public function saveAsTemplate(string $pageUniqueId, string $templateTitle, ?string $templateDescription = null): array {
        try {
            // Get the source page
            $pageData = $this->getPage($pageUniqueId);
            if (!$pageData) {
                return ['success' => false, 'error' => 'Page not found'];
            }

            // Get or create templates folder
            $langFolder = $this->getLanguageFolder();
            if (!$langFolder->nodeExists('_templates')) {
                $langFolder->newFolder('_templates');
            }
            $templatesFolder = $langFolder->get('_templates');

            // Generate template ID from title
            $templateId = $this->sanitizeId($templateTitle);

            // Handle duplicate names by appending number
            $originalId = $templateId;
            $counter = 1;
            while ($templatesFolder->nodeExists($templateId)) {
                $counter++;
                $templateId = $originalId . '-' . $counter;
            }

            // Create template folder
            $templateFolder = $templatesFolder->newFolder($templateId);

            // Create _media folder in template
            $templateMediaFolder = $templateFolder->newFolder('_media');

            // Prepare template data
            $templateData = $pageData;
            $templateData['uniqueId'] = 'template-' . $this->generateUUID();
            $templateData['title'] = $templateTitle;
            $templateData['description'] = $templateDescription ?? '';
            $templateData['isTemplate'] = true;
            $templateData['created'] = time();
            $templateData['createdBy'] = $this->userId;
            $templateData['sourcePageId'] = $pageUniqueId;

            // Remove page-specific data
            unset($templateData['path']);
            unset($templateData['parentPath']);

            // Copy media files from source page to template
            $pageFolder = $this->findPageFolder($pageUniqueId);
            if ($pageFolder && $pageFolder->nodeExists('_media')) {
                $sourceMediaFolder = $pageFolder->get('_media');
                if ($sourceMediaFolder instanceof \OCP\Files\Folder) {
                    $this->copyMediaFolderContents($sourceMediaFolder, $templateMediaFolder);
                }
            }

            // Write template JSON
            $jsonFile = $templateFolder->newFile($templateId . '.json');
            $jsonFile->putContent(json_encode($templateData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            $this->logger->info('Created template: ' . $templateId . ' from page: ' . $pageUniqueId);

            return [
                'success' => true,
                'templateId' => $templateId,
                'template' => [
                    'id' => $templateId,
                    'uniqueId' => $templateData['uniqueId'],
                    'title' => $templateData['title'],
                    'description' => $templateData['description'],
                    'created' => $templateData['created'],
                    'createdBy' => $templateData['createdBy'],
                ],
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to save as template: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Delete a template
     *
     * @param string $templateId Template ID (folder name)
     * @return array Result with success status
     */
    public function deleteTemplate(string $templateId): array {
        try {
            $templatesFolder = $this->getTemplatesFolder();
            if ($templatesFolder === null) {
                return ['success' => false, 'error' => 'Templates folder not accessible'];
            }

            if (!$templatesFolder->nodeExists($templateId)) {
                return ['success' => false, 'error' => 'Template not found'];
            }

            $templateFolder = $templatesFolder->get($templateId);
            $templateFolder->delete();

            $this->logger->info('Deleted template: ' . $templateId);

            return ['success' => true];
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete template: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Create a new page from a template
     *
     * @param string $templateId Template ID to use
     * @param string $pageTitle Title for the new page
     * @param string|null $parentPath Optional parent path for nested pages
     * @return array Result with success status and page data
     */
    public function createPageFromTemplate(string $templateId, string $pageTitle, ?string $parentPath = null): array {
        try {
            // Get template data
            $templateData = $this->getTemplate($templateId);
            if ($templateData === null) {
                return ['success' => false, 'error' => 'Template not found'];
            }

            // Prepare page data from template
            $pageData = $templateData;

            // Generate new page ID and uniqueId
            $pageId = $this->sanitizeId($pageTitle);
            $pageData['id'] = $pageId;
            $pageData['title'] = $pageTitle;
            $pageData['uniqueId'] = 'page-' . $this->generateUUID();
            $pageData['created'] = time();
            $pageData['modified'] = time();

            // Remove template-specific fields
            unset($pageData['isTemplate']);
            unset($pageData['description']);
            unset($pageData['createdBy']);
            unset($pageData['sourcePageId']);

            // New pages from templates always start as draft
            $pageData['status'] = 'draft';

            // Create the page using existing method
            $createdPage = $this->createPage($pageData, $parentPath);

            // Copy media files from template to new page
            $templatesFolder = $this->getTemplatesFolder();
            if ($templatesFolder && $templatesFolder->nodeExists($templateId)) {
                $templateFolder = $templatesFolder->get($templateId);
                if ($templateFolder instanceof \OCP\Files\Folder && $templateFolder->nodeExists('_media')) {
                    $templateMediaFolder = $templateFolder->get('_media');

                    // Get the new page's folder (should be in cache from createPage)
                    $newPageFolder = $this->findPageFolder($createdPage['uniqueId']);
                    $this->logger->info('Template media copy: page folder found = ' . ($newPageFolder ? 'yes' : 'no') . ' for ' . $createdPage['uniqueId']);
                    if ($newPageFolder && $templateMediaFolder instanceof \OCP\Files\Folder) {
                        // Create _media folder if not exists
                        if (!$newPageFolder->nodeExists('_media')) {
                            $newPageFolder->newFolder('_media');
                        }
                        $pageMediaFolder = $newPageFolder->get('_media');
                        if ($pageMediaFolder instanceof \OCP\Files\Folder) {
                            $this->copyMediaFolderContents($templateMediaFolder, $pageMediaFolder);
                        }
                    }
                }
            }

            $this->logger->info('Created page from template: ' . $templateId . ' -> ' . $createdPage['uniqueId']);

            // Re-fetch through getPage() so the response includes
            // enrichWithPathData (path, breadcrumb info, permissions) and
            // a sanitize pass — the same shape the frontend gets on a
            // normal page load. Without this the editor mounts with a
            // half-populated page and rendered blank until manual save +
            // reload. Falls back to createdPage if the fresh read fails
            // for any reason (e.g. ACL race on a brand-new folder).
            try {
                $fullPage = $this->getPage($createdPage['uniqueId']);
            } catch (\Exception $e) {
                $this->logger->warning(
                    '[createPageFromTemplate] getPage failed on freshly created page, falling back to validated data',
                    ['uniqueId' => $createdPage['uniqueId'], 'error' => $e->getMessage()]
                );
                $fullPage = $createdPage;
            }

            return [
                'success' => true,
                'page' => $fullPage,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to create page from template: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Copy a page (its content + media) into a new draft page (issue: copy page).
     *
     * Mirrors createPageFromTemplate: reuses createPage() for a fresh uniqueId +
     * collision-safe slug, keeps the layout/widgets (widget ids are page-scoped
     * and the copy gets its own media folder), copies media assets, and never
     * inherits the homepage pointer. Result is a draft under the same parent
     * (or an explicit target parent).
     *
     * @param string      $sourceUniqueId uniqueId of the page to copy.
     * @param string|null $targetParentId uniqueId of the destination parent; null/'' = same parent as source (root when source is root).
     * @param string|null $newTitle       Title for the copy; defaults to "{title} (copy)".
     * @return array The freshly created page (getPage shape).
     * @throws \Exception When the source cannot be located.
     */
    public function copyPage(string $sourceUniqueId, ?string $targetParentId = null, ?string $newTitle = null): array {
        $languageFolder = $this->getLanguageFolder();

        // A copy follows its source across language folders, like every other
        // operation on an existing page (#90).
        $source = $this->locatePageAnyLanguage($languageFolder, $sourceUniqueId);
        if ($source === null || !isset($source['file'])) {
            throw new PageNotFoundException('Page not found: ' . $sourceUniqueId);
        }

        $sourceData = json_decode($source['file']->getContent(), true);
        if (!is_array($sourceData)) {
            throw new \Exception('Could not read source page');
        }

        // Determine the destination parent path.
        $parentPath = null;
        if ($targetParentId !== null && $targetParentId !== '') {
            $targetParent = $this->locatePageAnyLanguage($languageFolder, $targetParentId);
            if ($targetParent === null || !isset($targetParent['folder'])) {
                throw new PageNotFoundException('Target parent not found: ' . $targetParentId);
            }
            // getRelativePathFromRoot() keeps the leading language segment, and
            // getOrCreateFolderPath() honours it, so the copy lands in the
            // target parent's language rather than the copier's.
            $parentPath = $this->getRelativePathFromRoot($targetParent['folder']);
        } elseif (isset($source['folder'])) {
            // Same parent as the source. For a page at the language ROOT,
            // dirname() yields '.', which used to become null and sent the copy
            // to the reader's own language folder — an English page copied by a
            // German user landed in de/. Fall back to the source's own language
            // root instead, so a copy never changes language.
            $sourceRelPath = $this->getRelativePathFromRoot($source['folder']);
            $sourceParentPath = dirname($sourceRelPath);
            if ($sourceParentPath === '.' || $sourceParentPath === '') {
                $sourceLanguage = $this->languageOfFolder($source['folder']);
                $parentPath = $sourceLanguage;
            } else {
                $parentPath = $sourceParentPath;
            }
        }

        // Build the copy's page data (fresh identity, draft status).
        // Decode the source title first: it is stored HTML-encoded (sanitizeText),
        // and createPage re-encodes it — without decoding, "Tips &amp; Tricks"
        // would double-encode to "Tips &amp;amp; Tricks (copy)".
        $baseTitle = $this->decodeHtmlEntitiesRecursive((string)($sourceData['title'] ?? 'Untitled'));
        $title = $newTitle !== null && $newTitle !== '' ? $newTitle : $baseTitle . ' (copy)';
        $pageData = $sourceData;
        unset($pageData['order']); // never inherit sibling order
        $pageData['id'] = $this->sanitizeId($title);
        $pageData['title'] = $title;
        $pageData['uniqueId'] = 'page-' . $this->generateUUID();
        $pageData['status'] = 'draft';
        $pageData['created'] = time();
        $pageData['modified'] = time();

        $createdPage = $this->createPage($pageData, $parentPath);

        // Copy media assets from the source page folder into the copy.
        $this->copyPageMedia($source['folder'] ?? null, $createdPage['uniqueId'], 'copyPage');

        $this->clearCache();

        try {
            return $this->getPage($createdPage['uniqueId']);
        } catch (\Exception $e) {
            return $createdPage;
        }
    }

    /**
     * Give a newly derived page its own copy of the source page's media.
     *
     * A page's images live in a `_media` folder beside its JSON, and the JSON
     * stores only the FILE NAME — the URL is built client-side from whichever
     * page is being viewed (see WidgetEditor.vue:696). So a derived page needs
     * the files themselves and nothing rewritten; without them every image
     * resolves to a 404 under the new page id.
     *
     * Copies rather than shares the files, so editing or deleting an image on
     * the translation cannot alter the original.
     *
     * Failure is logged, not thrown: losing the images is bad, but it is not
     * worth discarding a page that was already written to disk.
     *
     * @param \OCP\Files\Folder|null $sourceFolder folder holding the source page
     * @param string $newUniqueId the derived page
     * @param string $context caller name, for the log line
     */
    private function copyPageMedia(?\OCP\Files\Folder $sourceFolder, string $newUniqueId, string $context): void {
        try {
            if ($sourceFolder === null || !$sourceFolder->nodeExists('_media')) {
                return;
            }
            $sourceMedia = $sourceFolder->get('_media');
            if (!($sourceMedia instanceof \OCP\Files\Folder)) {
                return;
            }
            $newPageFolder = $this->findPageFolder($newUniqueId);
            if ($newPageFolder === null) {
                return;
            }
            if (!$newPageFolder->nodeExists('_media')) {
                $newPageFolder->newFolder('_media');
            }
            $targetMedia = $newPageFolder->get('_media');
            if ($targetMedia instanceof \OCP\Files\Folder) {
                $this->copyMediaFolderContents($sourceMedia, $targetMedia);
            }
        } catch (\Exception $e) {
            $this->logger->warning($context . ': media copy failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Copy all files from one media folder to another (within Nextcloud storage)
     *
     * @param \OCP\Files\Folder $source Source folder
     * @param \OCP\Files\Folder $target Target folder
     */
    private function copyMediaFolderContents(\OCP\Files\Folder $source, \OCP\Files\Folder $target): void {
        try {
            foreach ($source->getDirectoryListing() as $item) {
                $name = $item->getName();

                // Skip hidden files
                if (str_starts_with($name, '.')) {
                    continue;
                }

                if ($item instanceof \OCP\Files\File) {
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
                } elseif ($item instanceof \OCP\Files\Folder) {
                    // Recursively copy subfolder
                    try {
                        if (!$target->nodeExists($name)) {
                            $newSubFolder = $target->newFolder($name);
                        } else {
                            $newSubFolder = $target->get($name);
                        }
                        if ($newSubFolder instanceof \OCP\Files\Folder) {
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
     * Check if the user can create templates (has write access to _templates folder)
     *
     * @return bool
     */
    public function canCreateTemplates(): bool {
        try {
            $langFolder = $this->getLanguageFolder();

            // Check if _templates folder exists
            if (!$langFolder->nodeExists('_templates')) {
                // Check if user can create it
                return $langFolder->isCreatable();
            }

            $templatesFolder = $langFolder->get('_templates');
            return $templatesFolder->isCreatable();
        } catch (\Exception $e) {
            return false;
        }
    }
}
