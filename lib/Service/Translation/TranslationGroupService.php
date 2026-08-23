<?php
declare(strict_types=1);

namespace OCA\IntraVox\Service\Translation;

use OCA\IntraVox\Exception\ForbiddenException;
use OCA\IntraVox\Service\Locator\PageLocator;
use OCA\IntraVox\Service\PageIndexService;
use OCA\IntraVox\Service\Util\PageIdUtils;
use Psr\Log\LoggerInterface;

/**
 * Translation-group semantics, extracted from PageService (service split,
 * PR-16). A group ties one page per language together as "the same
 * subject"; the invariants live here:
 *
 *   - every page belongs to exactly one group (a fresh singleton when
 *     unlinked — "linked" and "unlinked" share one shape);
 *   - one page per language per group, also under group ADOPTION, where
 *     the collision can come from a member neither side of the new link
 *     ever saw;
 *   - group membership answers come from the index, readability answers
 *     from the caller's own mount — a row must never leak a title past a
 *     GroupFolder ACL.
 *
 * PageService keeps the orchestration around this (locating pages,
 * creating the translated copy, media, cache invalidation) and hands in
 * located results, resolved languages, and a lazy root supplier — the
 * same shape PageLocator uses.
 */
class TranslationGroupService {
    private PageIndexService $pageIndexService;
    private PageLocator $locator;
    private PageIdUtils $idUtils;
    private LoggerInterface $logger;

    public function __construct(
        PageIndexService $pageIndexService,
        PageLocator $locator,
        PageIdUtils $idUtils,
        LoggerInterface $logger
    ) {
        $this->pageIndexService = $pageIndexService;
        $this->locator = $locator;
        $this->idUtils = $idUtils;
        $this->logger = $logger;
    }

    /**
     * A fresh group id. Every page gets one at creation; unlinking assigns
     * a new one rather than none at all.
     */
    public function newGroupId(): string {
        return 'tg-' . $this->idUtils->generateUUID();
    }

    /**
     * Write a translation group into a page file and its index row.
     *
     * @param array $result findPageByUniqueId()-shaped result
     * @param string $language language the page lives in (for the index row)
     */
    public function writeGroup(array $result, string $group, string $language): void {
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
            $this->pageIndexService->indexPage(
                $data,
                $language,
                $result['folder']->getPath(),
                $file->getId(),
                $result['folder']->getId()
            );
        } catch (\Exception $e) {
            $this->logger->warning('Failed to index translation group', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Whether the group holds any member besides $uniqueId.
     */
    public function hasOtherMembers(string $group, string $uniqueId): bool {
        foreach ($this->pageIndexService->findByTranslationGroup($group) as $row) {
            if (($row['unique_id'] ?? null) !== $uniqueId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Refuse a link that would give the adopted group two members in one
     * language. The caller's same-language check only compares the two pages
     * being linked — but the ADOPTED group can hold members neither of them:
     * linking a Dutch page to an English one whose group already contained
     * another Dutch page produced a group with two nl members, which is
     * exactly the ambiguity (which "Dutch version" does the switcher offer?)
     * the one-page-per-language rule exists to prevent. Caught in the wild
     * by a screenshot.
     *
     * @param array<int, array{0:string, 1:?string}> $added [uniqueId, language] per page being linked
     * @throws \InvalidArgumentException on a language collision
     */
    public function assertAdoptionAddsNoDuplicateLanguage(string $group, array $added): void {
        $addedIds = array_map(fn(array $pair) => $pair[0], $added);
        foreach ($this->pageIndexService->findByTranslationGroup($group) as $member) {
            $memberId = (string)($member['unique_id'] ?? '');
            $memberLang = (string)($member['language'] ?? '');
            if (in_array($memberId, $addedIds, true) || $memberLang === '') {
                continue; // the pages being linked may of course be members already
            }
            foreach ($added as [$id, $lang]) {
                if ($lang !== null && $lang === $memberLang) {
                    throw new \InvalidArgumentException(
                        'That group already has a version in that language.'
                    );
                }
            }
        }
    }

    /**
     * Whether the group already holds a version in $language.
     */
    public function groupHasLanguage(string $group, string $language): bool {
        foreach ($this->pageIndexService->findByTranslationGroup($group) as $row) {
            if (($row['language'] ?? null) === $language) {
                return true;
            }
        }
        return false;
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
     * @param callable $root lazy supplier of the caller's IntraVox root —
     *   resolved only when the group actually has other members to check.
     * @return array<int, array{language:string, uniqueId:string, title:string, status:string}>
     */
    public function resolveTranslations(?string $translationGroup, ?string $ownUniqueId, callable $root): array {
        if (empty($translationGroup)) {
            return [];
        }

        try {
            $rows = $this->pageIndexService->findByTranslationGroup($translationGroup);
        } catch (\Throwable $e) {
            $this->logger->warning('[TranslationGroupService] translation lookup failed', [
                'translationGroup' => $translationGroup,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        $rootFolder = null;
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
            if ($rootFolder === null) {
                $rootFolder = $root();
            }
            if ($this->locator->folderFromAbsolutePath($rootFolder, (string)($row['path'] ?? '')) === null) {
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
     * The languages a group already holds a version in.
     *
     * @param string|null $group      the page's group, or null when unlinked
     * @param string|null $excludeId  a member to ignore (the page itself)
     * @return array<string, true> language code => true, for isset() lookups
     */
    public function languagesTaken(?string $group, ?string $excludeId = null): array {
        $taken = [];
        if (empty($group)) {
            return $taken;
        }
        foreach ($this->pageIndexService->findByTranslationGroup($group) as $row) {
            if ($excludeId !== null && ($row['unique_id'] ?? null) === $excludeId) {
                continue;
            }
            if (!empty($row['language'])) {
                $taken[(string)$row['language']] = true;
            }
        }
        return $taken;
    }

    /**
     * The content-language folders under the IntraVox root, minus the page's
     * own and (optionally) everything but one requested language.
     *
     * ACL boundary: this listing goes through the caller's OWN mount, so a
     * language folder their ACLs deny never appears — language-level access
     * is enforced here for free.
     *
     * @param string|null $ownLanguage the page's own language, always excluded
     * @param string|null $only        limit to this one language, or null for all
     * @return array<int, string> language codes
     */
    public function otherContentLanguages(\OCP\Files\Folder $root, ?string $ownLanguage, ?string $only = null): array {
        $languages = [];
        foreach ($this->locator->cachedDirectoryListing($root) as $node) {
            if (!($node instanceof \OCP\Files\Folder)) {
                continue;
            }
            $code = $node->getName();
            if (!preg_match('/^[a-z]{2,3}$/', $code) || $code === $ownLanguage) {
                continue;
            }
            if ($only !== null && $code !== $only) {
                continue;
            }
            $languages[] = $code;
        }
        return $languages;
    }

    /**
     * How many ancestor PAGES a mirrored translation would lack in a language.
     *
     * Walks the source page's parent segments and checks, per level, whether
     * {lang}/{path}/{segment}.json exists — the page file, not just the
     * folder, since a bare folder is exactly what "missing" means here. Cost
     * is one nodeExists per ancestor level, bounded by tree depth.
     */
    public function countMissingAncestors(\OCP\Files\Folder $root, \OCP\Files\Folder $sourceFolder, string $targetLanguage): int {
        try {
            $relative = $this->locator->relativePathFromRoot($root, $sourceFolder);
            $segments = explode('/', $relative);
            // Drop the language segment and the page's own folder; what
            // remains are the ancestor levels.
            array_shift($segments);
            array_pop($segments);
            if ($segments === []) {
                return 0;
            }

            $missing = 0;
            $path = $targetLanguage;
            foreach ($segments as $segment) {
                $path .= '/' . $segment;
                if (!$root->nodeExists($path . '/' . $segment . '.json')) {
                    $missing++;
                }
            }
            return $missing;
        } catch (\Throwable $e) {
            // A hint must never break the language list.
            return 0;
        }
    }

    /**
     * Pages that could be linked to $pageId as a translation.
     *
     * Excludes three sets, each for a reason:
     *   - the page's own language, since a group holds one page per language;
     *   - pages already in a group with something else, so linking cannot
     *     silently steal a page out of an existing set;
     *   - the page itself.
     *
     * Answered from the index, so the picker stays cheap on a large intranet.
     *
     * Deliberately NOT re-checked per candidate row: that would cost one
     * filecache lookup per page in the language (hundreds+), for an
     * editor-facing dialog. The accepted bound is that ACLs on a SUBFOLDER
     * within a readable language can still surface a title here — the
     * language folders themselves are already filtered by the caller's mount
     * in otherContentLanguages().
     *
     * @param array<int, string>   $languages     languages to offer
     * @param array<string, true>  $takenLanguages languages the group already covers
     * @return array<int, array{uniqueId:string, title:string, language:string}>
     */
    public function candidatesInLanguages(string $pageId, ?string $ownGroup, array $languages, array $takenLanguages): array {
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
                if (!empty($group) && $group !== $ownGroup && $this->hasOtherMembers($group, $uniqueId)) {
                    continue;
                }

                // Already a member of THIS page's group — linking it again is a
                // no-op that only clutters the picker.
                if (!empty($group) && $group === $ownGroup) {
                    continue;
                }

                // The group already holds a version in this candidate's
                // language: offering it would produce the two-Dutch-versions
                // ambiguity linkTranslation now refuses. Filter here too, so
                // the picker never offers something that errors when chosen.
                if (isset($takenLanguages[$code])) {
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
}
