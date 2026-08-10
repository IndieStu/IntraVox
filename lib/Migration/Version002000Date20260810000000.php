<?php
declare(strict_types=1);

namespace OCA\IntraVox\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add `translation_group` to the page index (2.0 multilingual model).
 *
 * Pages in different languages were entirely independent: nothing linked the
 * Dutch and German version of the same subject, and uniqueId is unique only
 * PER language. That is why there could be no language switcher, no "also
 * available in German" notice, and no way to ask which languages a page exists
 * in — the relationship simply did not exist in the data.
 *
 * A translation group is a shared id across the language versions of one page.
 * Deliberately symmetric: no source page, no derived translations. Every
 * version is equal, so removing one language shrinks the group instead of
 * orphaning anything — the failure mode that leaves SharePoint's source-pointer
 * model with dangling references and a spinning language menu.
 *
 * Nullable on purpose. A page without a group is a group of one, which is
 * exactly what every existing page is until an editor links it to another.
 * Nothing has to be backfilled for the app to keep working; `occ
 * intravox:reindex` picks the value up from the page files when it is there.
 *
 * Additive and idempotent: adds one nullable column and one index, and touches
 * no existing data.
 */
class Version002000Date20260810000000 extends SimpleMigrationStep {

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

        if (!$table->hasColumn('translation_group')) {
            $table->addColumn('translation_group', Types::STRING, [
                'notnull' => false,
                'length' => 64,
            ]);
            $changed = true;
        }

        // Answers "which languages does this page exist in?" without scanning:
        // one indexed lookup per group. NOT unique — a group holds one row per
        // language by design.
        if (!$table->hasIndex('ivox_page_transgroup')) {
            $table->addIndex(['translation_group'], 'ivox_page_transgroup');
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
