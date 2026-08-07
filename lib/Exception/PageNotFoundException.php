<?php
declare(strict_types=1);

namespace OCA\IntraVox\Exception;

/**
 * Thrown when a page cannot be located by uniqueId or legacy id.
 *
 * Controllers map this to HTTP 404 Not Found. Like ForbiddenException it
 * extends \RuntimeException (not \InvalidArgumentException) on purpose, so the
 * existing InvalidArgumentException -> 400 catch arms cannot swallow it: a
 * missing page must read as "not found", never "bad request".
 *
 * Reporters of issue #90 saw the confusing pair "Request failed with status
 * code 400" and "Page not found: page-…" precisely because the two were
 * conflated.
 */
final class PageNotFoundException extends \RuntimeException {
}
