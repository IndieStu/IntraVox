<?php
declare(strict_types=1);

namespace OCA\IntraVox\Controller\Shared;

/**
 * Calendar-widget request parsing, shared by the authenticated API and the
 * share endpoints. (F6d)
 *
 * Body is verbatim from CalendarController.
 */
trait CalendarRequestTrait {

    /**
     * Parse and validate external ICS URLs from request parameter.
     *
     * HTTPS only and capped at five: this list drives outbound fetches, so it
     * is an SSRF surface rather than a display preference.
     *
     * @return string[] Valid HTTPS URLs (max 5)
     */
    private function parseExternalIcsUrls(string $param): array {
        if (empty($param)) {
            return [];
        }

        $urls = json_decode($param, true);
        if (!is_array($urls)) {
            return [];
        }

        $valid = [];
        foreach (array_slice($urls, 0, 5) as $url) {
            if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https') {
                $valid[] = $url;
            }
        }

        return $valid;
    }
}
