<?php
declare(strict_types=1);

namespace OCA\IntraVox\Search;

use OCA\IntraVox\Service\PageIndexService;
use OCA\IntraVox\Service\PageService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

class PageSearchProvider implements IProvider {
    private PageService $pageService;
    private PageIndexService $pageIndexService;
    private IConfig $config;
    private IL10N $l10n;
    private IURLGenerator $urlGenerator;

    public function __construct(
        PageService $pageService,
        PageIndexService $pageIndexService,
        IConfig $config,
        IL10N $l10n,
        IURLGenerator $urlGenerator
    ) {
        $this->pageService = $pageService;
        $this->pageIndexService = $pageIndexService;
        $this->config = $config;
        $this->l10n = $l10n;
        $this->urlGenerator = $urlGenerator;
    }

    public function getId(): string {
        return 'intravox_pages';
    }

    public function getName(): string {
        return $this->l10n->t('IntraVox pages');
    }

    public function getOrder(string $route, array $routeParameters): int {
        // Show IntraVox results at the top, before Files
        // Files typically has order 0, so we use -10 to appear before it
        if (str_starts_with($route, 'intravox.')) {
            return -10;
        }
        return -5;
    }

    public function search(IUser $user, ISearchQuery $query): SearchResult {
        $term = trim($query->getTerm());

        // No hardcoded minimum length here on purpose. Nextcloud enforces it
        // centrally via the `unified-search.min-search-length` app config
        // (default 1): SearchComposer drops a too-short term before the
        // provider is ever called, and the search bar honours the same value.
        // An app-side minimum would silently override the admin's setting and
        // make short but meaningful terms ("HR", "IT", CJK characters)
        // unfindable. An empty term is all we still need to guard against.
        if ($term === '') {
            return SearchResult::complete(
                $this->l10n->t('IntraVox pages'),
                []
            );
        }

        try {
            // Try fast indexed search first (DB query, ~1ms).
            //
            // Normalise the locale to a base code: Nextcloud stores 'nl_NL' or
            // 'de_DE', while the index stores the language FOLDER name, which
            // is always a 2-3 letter base code. Passing the raw locale matched
            // zero rows, so for every user with a regional locale the fast path
            // silently returned nothing and only the full scan answered.
            $locale = $this->config->getUserValue($user->getUID(), 'core', 'lang', 'en');
            $language = explode('_', str_replace('-', '_', $locale))[0];
            $indexedResults = $this->pageIndexService->searchByTitle($term, $language, $query->getLimit());

            // Title-index hits render first (fast path). We no longer return
            // early here: the index only covers titles, so a term that matches
            // a title would otherwise silently hide pages matching on content
            // or MetaVox metadata. Full-text results are appended below, with
            // pages already listed from the index skipped to avoid duplicates.
            $entries = [];
            $seenIds = [];
            foreach ($indexedResults as $row) {
                $url = $this->urlGenerator->linkToRouteAbsolute(
                    'intravox.page.index',
                    ['page' => $row['unique_id']]
                ) . '#' . $row['unique_id'];

                $thumbnailUrl = $this->urlGenerator->imagePath('intravox', 'app-search.svg');
                $entries[] = new SearchResultEntry(
                    $thumbnailUrl,
                    $row['title'],
                    $this->l10n->t('IntraVox page'),
                    $url,
                    '',
                    true
                );
                $seenIds[$row['unique_id']] = true;
            }

            // Full-text search (slower, reads all JSON files) — also the only
            // path that matches MetaVox metadata.
            $results = $this->pageService->searchPages($term);

            foreach ($results as $result) {
                if (isset($seenIds[$result['uniqueId'] ?? ''])) {
                    continue;
                }
                // Create IntraVox app URL (not Files URL)
                // Build URL manually to ensure it goes to the app, not Files
                $pageIdentifier = $result['uniqueId'] ?? $result['id'];

                // Generate base URL to the IntraVox app with query parameter
                // This ensures Nextcloud closes the search popup on navigation
                $url = $this->urlGenerator->linkToRouteAbsolute(
                    'intravox.page.index',
                    ['page' => $pageIdentifier]
                ) . '#' . $pageIdentifier;

                // Get first match for subline
                $subline = '';
                // Use IntraVox app icon for search results
                // The app-search.svg has neutral gray color (#555) for good contrast on both themes
                $thumbnailUrl = $this->urlGenerator->imagePath('intravox', 'app-search.svg');
                $icon = '';

                if (!empty($result['matches'])) {
                    $firstMatch = $result['matches'][0];
                    $matchType = $firstMatch['type'];
                    $matchText = $firstMatch['text'];

                    // Format subline with widget type prefix for clarity
                    switch ($matchType) {
                        case 'title':
                            $subline = $this->l10n->t('IntraVox page');
                            break;
                        case 'content':
                            $subline = $this->truncate($matchText, 100);
                            break;
                        case 'heading':
                            $subline = $this->l10n->t('Heading') . ': ' . $this->truncate($matchText, 90);
                            break;
                        case 'image':
                            $subline = $this->l10n->t('Image') . ': ' . $this->truncate($matchText, 90);
                            break;
                        case 'link':
                            $subline = $this->l10n->t('Link') . ': ' . $this->truncate($matchText, 90);
                            break;
                        case 'file':
                            $subline = $this->l10n->t('File') . ': ' . $this->truncate($matchText, 90);
                            break;
                        case 'video':
                            $subline = $this->l10n->t('Video') . ': ' . $this->truncate($matchText, 90);
                            break;
                        case 'metadata':
                            // Already formatted as MetaVox formats its own
                            // sublines ("Label: value • ..."), so show it as-is.
                            $subline = $this->truncate($matchText, 100);
                            break;
                        default:
                            $subline = $this->truncate($matchText, 100);
                    }
                }

                $entry = new SearchResultEntry(
                    $thumbnailUrl,
                    $result['title'],
                    $subline,
                    $url,
                    $icon,
                    true // rounded icon
                );

                $entries[] = $entry;
            }

            return SearchResult::complete(
                $this->l10n->t('IntraVox pages'),
                $entries
            );
        } catch (\Exception $e) {
            return SearchResult::complete(
                $this->l10n->t('IntraVox pages'),
                []
            );
        }
    }

    private function truncate(string $text, int $length): string {
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        return mb_substr($text, 0, $length) . '...';
    }
}
