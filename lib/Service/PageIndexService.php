<?php
declare(strict_types=1);

namespace OCA\IntraVox\Service;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Maintains the page metadata index (intravox_page_index table).
 *
 * This index allows O(1) lookups for page metadata instead of
 * O(N) filesystem traversals and JSON parsing.
 */
class PageIndexService {
    private const TABLE = 'intravox_page_index';

    public function __construct(
        private IDBConnection $db,
        private LoggerInterface $logger,
    ) {}

    /**
     * Upsert a page into the index.
     * Called after page create/update.
     */
    public function indexPage(array $pageData, string $language, string $path, ?int $fileId = null, ?int $folderId = null): void {
        $uniqueId = $pageData['uniqueId'] ?? '';
        if (empty($uniqueId)) {
            return;
        }

        // Store the path relative to the IntraVox root, never the caller's
        // absolute Nextcloud path. The index is shared by every user but a
        // Nextcloud path is per-user: the same page is
        // /admin/files/IntraVox/en/about for one account and
        // /Rik/files/IntraVox/en/about for another. Storing the absolute form
        // meant every row only ever resolved for the account that wrote it —
        // for everyone else listPages() came back empty and the app showed its
        // first-run welcome screen on a fully populated intranet.
        $path = $this->toRelativePath($path);

        try {
            // Try update first
            $qb = $this->db->getQueryBuilder();
            $qb->update(self::TABLE)
                ->set('title', $qb->createNamedParameter($pageData['title'] ?? ''))
                ->set('path', $qb->createNamedParameter($path))
                ->set('status', $qb->createNamedParameter($pageData['status'] ?? 'published'))
                ->set('modified_at', $qb->createNamedParameter(time(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                ->set('parent_id', $qb->createNamedParameter($pageData['parentId'] ?? null))
                ->set('file_id', $qb->createNamedParameter($fileId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                ->set('folder_id', $qb->createNamedParameter($folderId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                ->set('translation_group', $qb->createNamedParameter($pageData['translationGroup'] ?? null))
                ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($uniqueId)))
                ->andWhere($qb->expr()->eq('language', $qb->createNamedParameter($language)));

            $affected = $qb->executeStatement();

            if ($affected === 0) {
                // Insert new entry
                $qb = $this->db->getQueryBuilder();
                $qb->insert(self::TABLE)
                    ->values([
                        'unique_id' => $qb->createNamedParameter($uniqueId),
                        'title' => $qb->createNamedParameter($pageData['title'] ?? ''),
                        'language' => $qb->createNamedParameter($language),
                        'path' => $qb->createNamedParameter($path),
                        'status' => $qb->createNamedParameter($pageData['status'] ?? 'published'),
                        'modified_at' => $qb->createNamedParameter(time(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                        'parent_id' => $qb->createNamedParameter($pageData['parentId'] ?? null),
                        'file_id' => $qb->createNamedParameter($fileId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                        'folder_id' => $qb->createNamedParameter($folderId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                        'translation_group' => $qb->createNamedParameter($pageData['translationGroup'] ?? null),
                    ]);
                $qb->executeStatement();
            }
        } catch (\Exception $e) {
            $this->logger->warning('IntraVox: Failed to index page ' . $uniqueId, ['error' => $e->getMessage()]);
        }
    }

    /**
     * Strip the per-user prefix from a page path.
     *
     * Keeps everything after the last `IntraVox` segment, which is the app-root
     * marker in every form these paths take (`/admin/files/IntraVox/en/about`,
     * `/Rik/files/IntraVox/en/about`, `/__groupfolders/1/files/en/about`).
     * A path already relative is returned unchanged, so this is idempotent and
     * safe to apply to rows written by an older version.
     */
    private function toRelativePath(string $path): string {
        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return '';
        }

        $segments = explode('/', $trimmed);
        $rootIndex = null;
        foreach ($segments as $i => $segment) {
            if ($segment === 'IntraVox') {
                $rootIndex = $i;
            }
        }
        if ($rootIndex === null) {
            // No IntraVox segment: either already relative, or a layout we do
            // not recognise. Leave it alone rather than mangling it.
            return $trimmed;
        }

        return implode('/', array_slice($segments, $rootIndex + 1));
    }

    /**
     * Remove a page from the index.
     * Called after page deletion.
     */
    public function removePage(string $uniqueId, ?string $language = null): void {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete(self::TABLE)
                ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($uniqueId)));

            if ($language !== null) {
                $qb->andWhere($qb->expr()->eq('language', $qb->createNamedParameter($language)));
            }

            $qb->executeStatement();
        } catch (\Exception $e) {
            $this->logger->warning('IntraVox: Failed to remove page from index: ' . $uniqueId, ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get all indexed pages for a language (for fast page listing).
     *
     * @return array Array of page metadata rows
     */
    public function getPagesByLanguage(string $language, ?string $status = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('p.unique_id', 'p.title', 'p.path', 'p.status', 'p.modified_at', 'p.parent_id', 'p.file_id')
            ->from(self::TABLE, 'p')
            ->where($qb->expr()->eq('p.language', $qb->createNamedParameter($language)))
            ->orderBy('p.title', 'ASC');

        if ($status !== null) {
            $qb->andWhere($qb->expr()->eq('p.status', $qb->createNamedParameter($status)));
        }

        $this->whereFileIsLive($qb, 'p');

        $result = $qb->executeQuery();
        $pages = $result->fetchAll();
        $result->closeCursor();
        return $pages;
    }

    /**
     * Search pages by title (fast indexed search).
     *
     * @return array Matching page metadata rows
     */
    public function searchByTitle(string $query, string $language, int $limit = 20): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('p.unique_id', 'p.title', 'p.path', 'p.status', 'p.modified_at')
            ->from(self::TABLE, 'p')
            ->where($qb->expr()->eq('p.language', $qb->createNamedParameter($language)))
            ->andWhere($qb->expr()->iLike('p.title', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($query) . '%')))
            ->orderBy('p.modified_at', 'DESC')
            ->setMaxResults($limit);

        $this->whereFileIsLive($qb, 'p');

        $result = $qb->executeQuery();
        $pages = $result->fetchAll();
        $result->closeCursor();
        return $pages;
    }

    /**
     * Get a single page from the index.
     */
    public function getPage(string $uniqueId, string $language): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('p.*')
            ->from(self::TABLE, 'p')
            ->where($qb->expr()->eq('p.unique_id', $qb->createNamedParameter($uniqueId)))
            ->andWhere($qb->expr()->eq('p.language', $qb->createNamedParameter($language)));

        $this->whereFileIsLive($qb, 'p');

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row ?: null;
    }

    /**
     * Restrict a query to pages whose file is still live.
     *
     * Index rows outlive the page file on purpose: deleting a page moves it to
     * the trashbin, which is reversible, and restoring it fires no event of any
     * kind (verified on NC34). A row dropped at delete time could therefore
     * never be restored, which is why a recovered page used to stay invisible
     * in IntraVox until `occ intravox:reindex` was run by hand.
     *
     * So instead of a flag that nothing could ever clear, liveness is read from
     * the filecache, which Nextcloud itself keeps correct in both directions: a
     * trashed file has its path moved out of `files/`, and a restored file is
     * back under it before any IntraVox code runs. The page drops out of every
     * listing and comes back on restore with no bookkeeping of our own.
     *
     * Joins on the filecache primary key (an eq_ref lookup), so this costs one
     * row per candidate rather than a second query per page.
     *
     * Rows with no file_id — written before 1.3.0 — cannot be checked this way
     * and are treated as live: hiding a page because its id was never recorded
     * would be a worse failure than showing one that is in the trashbin, and
     * `occ intravox:reindex` fills the id in.
     *
     * @param \OCP\DB\QueryBuilder\IQueryBuilder $qb    query selecting from TABLE
     * @param string                              $alias alias used for TABLE
     */
    private function whereFileIsLive(\OCP\DB\QueryBuilder\IQueryBuilder $qb, string $alias): void {
        $qb->leftJoin(
            $alias,
            'filecache',
            'fc',
            $qb->expr()->eq('fc.fileid', $alias . '.file_id')
        )->andWhere($qb->expr()->orX(
            // Never indexed with a file id (pre-1.3.0) — cannot be judged.
            $qb->expr()->isNull($alias . '.file_id'),
            // Live: still sitting under the storage's `files/` root. A trashed
            // file is moved to `trash/…`, a deleted one loses its row entirely.
            $qb->expr()->like('fc.path', $qb->createNamedParameter('files/%'))
        ));
    }

    /**
     * Locate the page rows that belong to a file id.
     *
     * Used by CacheCleanupListener, which learns from CacheEntryRemovedEvent
     * that a file is permanently gone and has only its id to go on.
     *
     * Returns every matching row, not one. A page is one row per language and
     * an index can hold more than one row for the same file after a partial
     * reindex; the caller cleans up all of them rather than guessing which is
     * the real one.
     *
     * A file id of 0 or less never matches. `file_id` is nullable for rows
     * written before 1.3.0, and letting an empty id through would turn one
     * unrelated deletion into a query that matches those rows and wipes the
     * comments of pages that were never touched.
     *
     * @return array<array> index rows, empty when the id is unknown
     */
    public function findByFileId(int $fileId): array {
        if ($fileId <= 0) {
            return [];
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from(self::TABLE)
                ->where($qb->expr()->orX(
                    // The JSON file's own id, for a page removed file-by-file.
                    $qb->expr()->eq(
                        'file_id',
                        $qb->createNamedParameter($fileId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
                    ),
                    // The page FOLDER's id. A page is deleted as a folder and
                    // the removal event reports only that folder, never the
                    // JSON inside it (verified on NC34) — and by the time the
                    // event fires the JSON's filecache row is already gone, so
                    // the link cannot be resolved through the filecache. It is
                    // recorded on the page row itself at index time instead.
                    $qb->expr()->eq(
                        'folder_id',
                        $qb->createNamedParameter($fileId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
                    )
                ));

            $result = $qb->executeQuery();
            $rows = $result->fetchAll();
            $result->closeCursor();

            return $rows ?: [];
        } catch (\Exception $e) {
            $this->logger->warning('IntraVox: findByFileId failed', [
                'fileId' => $fileId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Locate a page by uniqueId without knowing its language.
     *
     * This is the lookup that replaces the cross-language tree walk. Resolving
     * a link is exactly the moment the language is unknown — that is what made
     * locateAcrossLanguages() read every page file in every language folder,
     * and why a miss cost a full O(pages x languages) sweep.
     *
     * $preferredLanguage breaks ties rather than filtering: a uniqueId is
     * unique per language, so in a healthy index at most one row matches
     * anyway. Two rows mean two genuinely different pages that share an id —
     * possible via `occ intravox:import --language` seeding one folder from
     * another — and then the reader's own language is the right one to serve.
     * Ordering makes that deterministic instead of leaving it to row order,
     * which is what the filesystem walk depended on.
     *
     * @return array|null the index row, or null when the id is unknown
     */
    public function findByUniqueId(string $uniqueId, ?string $preferredLanguage = null): ?array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('p.*')
                ->from(self::TABLE, 'p')
                ->where($qb->expr()->eq('p.unique_id', $qb->createNamedParameter($uniqueId)));

            $this->whereFileIsLive($qb, 'p');

            $result = $qb->executeQuery();
            $rows = $result->fetchAll();
            $result->closeCursor();

            if (empty($rows)) {
                return null;
            }
            if ($preferredLanguage !== null) {
                foreach ($rows as $row) {
                    if (($row['language'] ?? null) === $preferredLanguage) {
                        return $row;
                    }
                }
            }
            // Deterministic fallback: lowest language code, so repeated reads
            // of a duplicated id always resolve to the same page.
            usort($rows, fn($a, $b) => strcmp((string)$a['language'], (string)$b['language']));
            return $rows[0];
        } catch (\Exception $e) {
            // A failing index must degrade to "unknown", letting the caller
            // fall back to the filesystem rather than turning a read into a
            // hard error.
            $this->logger->warning('IntraVox: index lookup failed for ' . $uniqueId, [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Every language version in a translation group.
     *
     * This is the query the language switcher and the "also available in X"
     * notice are built on: one indexed lookup answers "which languages does
     * this page exist in", where before there was no way to ask at all.
     *
     * Returns rows ordered by language so the switcher is stable between page
     * loads. An unknown or empty group yields an empty array — a page that is
     * not linked to anything is a group of one, which is the normal state for
     * every page until an editor links it.
     *
     * @return array<int, array> index rows, one per language
     */
    public function findByTranslationGroup(string $translationGroup): array {
        if ($translationGroup === '') {
            return [];
        }
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('p.*')
                ->from(self::TABLE, 'p')
                ->where($qb->expr()->eq(
                    'p.translation_group',
                    $qb->createNamedParameter($translationGroup)
                ))
                ->orderBy('p.language', 'ASC');

            $this->whereFileIsLive($qb, 'p');

            $result = $qb->executeQuery();
            $rows = $result->fetchAll();
            $result->closeCursor();
            return $rows;
        } catch (\Exception $e) {
            // A failing index must not break the page it decorates.
            $this->logger->warning('IntraVox: translation-group lookup failed', [
                'translationGroup' => $translationGroup,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Check if index has any entries (to know if initial population is needed).
     *
     * Deliberately NOT filtered on liveness, unlike the listing methods: this
     * answers "has the index been populated at all", and a row whose page sits
     * in the trashbin still proves that it has. Filtering here would report an
     * empty index and trigger a needless rebuild.
     */
    public function hasEntries(string $language): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id'))
            ->from(self::TABLE)
            ->where($qb->expr()->eq('language', $qb->createNamedParameter($language)));

        $result = $qb->executeQuery();
        $count = (int) $result->fetchOne();
        $result->closeCursor();
        return $count > 0;
    }

    /**
     * Clear all entries for a language (for full re-index).
     */
    public function clearLanguage(string $language): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('language', $qb->createNamedParameter($language)));
        $qb->executeStatement();
    }

    /**
     * Repoint every indexed path under $oldPrefix at $newPrefix.
     *
     * A move relocates a page AND everything nested inside it, so one move
     * invalidates the indexed `path` of the whole subtree — not just the page
     * that was dragged. Rewriting the prefix in a single statement keeps that
     * O(1) in queries regardless of how large the subtree is; walking the
     * descendants to re-index them one by one would reintroduce exactly the
     * filesystem traversal the index exists to avoid.
     *
     * Matches `$oldPrefix` itself and anything below it, and nothing else:
     * the LIKE is anchored with a trailing slash so `/IntraVox/en/news` cannot
     * drag `/IntraVox/en/newsletter` along with it.
     *
     * @return int rows updated (0 is normal for an unindexed subtree)
     */
    public function repathSubtree(string $oldPrefix, string $newPrefix): int {
        // Callers pass absolute paths; rows store relative ones.
        $oldPrefix = rtrim($this->toRelativePath($oldPrefix), '/');
        $newPrefix = rtrim($this->toRelativePath($newPrefix), '/');
        if ($oldPrefix === '' || $oldPrefix === $newPrefix) {
            return 0;
        }

        try {
            // Portable across the DB platforms Nextcloud supports: read the
            // affected rows, rewrite in PHP, write back. SUBSTRING/CONCAT
            // spellings differ per platform, and a subtree is small enough
            // that a read-modify-write is not worth a platform matrix.
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'path')
                ->from(self::TABLE)
                ->where($qb->expr()->orX(
                    $qb->expr()->eq('path', $qb->createNamedParameter($oldPrefix)),
                    $qb->expr()->like(
                        'path',
                        $qb->createNamedParameter(
                            $this->db->escapeLikeParameter($oldPrefix . '/') . '%'
                        )
                    )
                ));
            $result = $qb->executeQuery();
            $rows = $result->fetchAll();
            $result->closeCursor();

            $updated = 0;
            foreach ($rows as $row) {
                $suffix = substr((string)$row['path'], strlen($oldPrefix));
                $update = $this->db->getQueryBuilder();
                $update->update(self::TABLE)
                    ->set('path', $update->createNamedParameter($newPrefix . $suffix))
                    ->where($update->expr()->eq(
                        'id',
                        $update->createNamedParameter($row['id'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
                    ));
                $updated += $update->executeStatement();
            }
            return $updated;
        } catch (\Exception $e) {
            $this->logger->warning('IntraVox: Failed to repath index subtree', [
                'oldPrefix' => $oldPrefix,
                'newPrefix' => $newPrefix,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Drop every entry whose path sits at or below $prefix.
     *
     * Deleting a page deletes its descendants with it; removePage() only knows
     * about the one uniqueId, so without this the children linger as rows
     * pointing at folders that no longer exist.
     *
     * @return int rows removed
     */
    public function removeSubtree(string $prefix): int {
        // Callers pass absolute paths; rows store relative ones.
        $prefix = rtrim($this->toRelativePath($prefix), '/');
        if ($prefix === '') {
            return 0;
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete(self::TABLE)
                ->where($qb->expr()->orX(
                    $qb->expr()->eq('path', $qb->createNamedParameter($prefix)),
                    $qb->expr()->like(
                        'path',
                        $qb->createNamedParameter(
                            $this->db->escapeLikeParameter($prefix . '/') . '%'
                        )
                    )
                ));
            return $qb->executeStatement();
        } catch (\Exception $e) {
            $this->logger->warning('IntraVox: Failed to remove index subtree', [
                'prefix' => $prefix,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /** Drop every entry, for a full rebuild across all languages. */
    public function clearAll(): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE);
        $qb->executeStatement();
    }

    /**
     * Total number of indexed pages, for reporting after a rebuild.
     *
     * Counts every row, trashed pages included — it reports what the rebuild
     * wrote, not what is currently visible.
     */
    public function countAll(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id'))->from(self::TABLE);
        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }
}
