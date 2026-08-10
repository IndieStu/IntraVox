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
    public function indexPage(array $pageData, string $language, string $path, ?int $fileId = null): void {
        $uniqueId = $pageData['uniqueId'] ?? '';
        if (empty($uniqueId)) {
            return;
        }

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
                    ]);
                $qb->executeStatement();
            }
        } catch (\Exception $e) {
            $this->logger->warning('IntraVox: Failed to index page ' . $uniqueId, ['error' => $e->getMessage()]);
        }
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
        $qb->select('unique_id', 'title', 'path', 'status', 'modified_at', 'parent_id', 'file_id')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('language', $qb->createNamedParameter($language)))
            ->orderBy('title', 'ASC');

        if ($status !== null) {
            $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
        }

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
        $qb->select('unique_id', 'title', 'path', 'status', 'modified_at')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('language', $qb->createNamedParameter($language)))
            ->andWhere($qb->expr()->iLike('title', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($query) . '%')))
            ->orderBy('modified_at', 'DESC')
            ->setMaxResults($limit);

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
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($uniqueId)))
            ->andWhere($qb->expr()->eq('language', $qb->createNamedParameter($language)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row ?: null;
    }

    /**
     * Check if index has any entries (to know if initial population is needed).
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
        $oldPrefix = rtrim($oldPrefix, '/');
        $newPrefix = rtrim($newPrefix, '/');
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
        $prefix = rtrim($prefix, '/');
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

    /** Total number of indexed pages, for reporting after a rebuild. */
    public function countAll(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id'))->from(self::TABLE);
        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }
}
