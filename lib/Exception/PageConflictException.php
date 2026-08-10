<?php
declare(strict_types=1);

namespace OCA\IntraVox\Exception;

/**
 * Thrown when a page has changed on disk since the editor loaded it.
 *
 * Page JSON is written whole: putContent() replaces the entire document, so a
 * save built on stale content does not merge, it erases. Two editors on the
 * same page meant the second save silently discarded everything the first had
 * written — not a field here or there, the whole document.
 *
 * PageLockService already prevents the common case, but it cannot cover all of
 * it: locks expire after 15 minutes without a heartbeat, so a browser left open
 * over lunch comes back holding content that is no longer current, with no lock
 * to stop it. This is the backstop for exactly that.
 *
 * Controllers map this to HTTP 409 Conflict — the editor is told their copy is
 * out of date and can reload, which is recoverable. A silent overwrite is not.
 */
final class PageConflictException extends \RuntimeException {
}
