<?php
declare(strict_types=1);

namespace OCA\IntraVox\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Index `file_id` on the page index, so a file id can be resolved to a page.
 *
 * Needed by CacheCleanupListener, which reacts to CacheEntryRemovedEvent to
 * clean up comments once a page is permanently gone. That event carries a file
 * id and nothing else, so the listener has to look the page up by it.
 *
 * The event fires for EVERY file removed from the filecache anywhere in
 * Nextcloud, not just for IntraVox pages — and the overwhelming majority of
 * those lookups match nothing. Without an index each one is a full table scan
 * of the page index, so the cost lands on installations that barely use
 * IntraVox. One index turns that into a miss on a b-tree.
 *
 * NOT unique: the same page exists once per language, and `file_id` is
 * nullable for rows written before 1.3.0.
 *
 * Additive and idempotent: adds one index, touches no data.
 */
class Version002302Date20260823010000 extends SimpleMigrationStep {

    private const TABLE = 'intravox_page_index';

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable(self::TABLE)) {
            // The index table arrives in 1.3.0; nothing to extend on an install
            // that has not reached it yet.
            return null;
        }

        $table = $schema->getTable(self::TABLE);
        $changed = false;

        // The id of the page's FOLDER, alongside file_id (its JSON file).
        // Deleting a page deletes the folder, and the removal event reports
        // only the folder id — so without this the cleanup never matched.
        if (!$table->hasColumn('folder_id')) {
            $table->addColumn('folder_id', Types::BIGINT, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $changed = true;
        }

        if (!$table->hasIndex('ivox_page_fileid')) {
            $table->addIndex(['file_id'], 'ivox_page_fileid');
            $changed = true;
        }

        if (!$table->hasIndex('ivox_page_folderid')) {
            $table->addIndex(['folder_id'], 'ivox_page_folderid');
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
