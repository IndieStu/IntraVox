<?php
declare(strict_types=1);

namespace OCA\IntraVox\Exception;

/**
 * Thrown when a move would relocate a page from one language folder into
 * another.
 *
 * Language folders are independent content trees, not translations of one
 * another: nothing links a page in en/ to a page in de/, and uniqueIds are only
 * unique per language (the ivox_page_uid_lang index is composite). Moving a page
 * across that boundary therefore does not "translate" it — it silently removes
 * it from one intranet and inserts it into another, taking its whole subtree
 * along. There is no undo.
 *
 * The refusal is deliberate rather than a limitation to be lifted later. Once
 * pages resolve across language folders (issues #90 and #92), movePage() can
 * FIND a page outside the user's own language, so the destination has to be
 * constrained explicitly or the move would quietly succeed in the wrong tree.
 *
 * Extends \RuntimeException, so BulkOperationService's per-page catch reports it
 * through addFailed() with this message and keeps going, rather than aborting
 * the remaining pages in a bulk move.
 *
 * The message names BOTH languages: reporters of #90 were sent chasing a bare
 * "Page not found", and the whole point here is that the user learns why.
 */
final class CrossLanguageMoveException extends \RuntimeException {
}
