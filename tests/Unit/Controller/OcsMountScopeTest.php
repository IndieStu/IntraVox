<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * The spec's OCS mount claim matches appinfo/routes.php.
 *
 * openapi.json used to declare two top-level servers, which in OpenAPI means
 * every path answers on both. That was false: only the routes in the 'ocs' block
 * are mounted at /ocs/v2.php/apps/intravox, and everything else 404s there with
 * an OCS error envelope.
 *
 * It was the contract test that found it, on its very first run — aimed at
 * /ocs/v2.php/apps/intravox/api/health, which does not exist. No static check
 * could have: the path is real, the operation is well-formed, and the server
 * list is valid OpenAPI. Only asking a running server exposed it.
 *
 * The fix moved the OCS servers[] onto exactly the operations that live in that
 * block. This test keeps the two lists in step, because the failure mode is
 * quiet in both directions: a route added to the ocs block without the override
 * is undocumented as external, and an override left on a route that moved out
 * sends integrators at a 404.
 *
 * The contract-test harness also selects Tier A by the presence of this
 * override, so a drift here silently changes what gets verified.
 */
class OcsMountScopeTest extends TestCase {
    /** Paths in the 'ocs' block of appinfo/routes.php. */
    private function ocsRoutePaths(): array {
        $source = file_get_contents(__DIR__ . '/../../../appinfo/routes.php');

        $start = strpos($source, "'ocs' => [");
        $this->assertNotFalse($start, "The 'ocs' block must exist");

        // Bound on the next top-level key rather than a byte count.
        $end = strpos($source, "'routes' => [", $start);
        $block = substr($source, $start, $end === false ? strlen($source) : $end - $start);

        preg_match_all("/'url'\s*=>\s*'([^']+)'/", $block, $m);

        return array_values(array_unique($m[1]));
    }

    /** Paths whose operations carry an OCS servers[] override in the spec. */
    private function specOcsPaths(): array {
        $out = [];
        foreach ($this->specOcsOperations() as $key) {
            $out[explode(' ', $key, 2)[1]] = true;
        }

        return array_keys($out);
    }

    /**
     * "VERB /path" for every operation carrying the OCS override.
     *
     * Per operation, not per path: a path with several verbs stays "marked" when
     * only one of them has the override, so a path-level comparison passes while
     * the sibling verb has silently lost its external mount. Every route in the
     * ocs block is a single verb on its path, so the counts must line up too.
     */
    private function specOcsOperations(): array {
        $spec = json_decode(file_get_contents(__DIR__ . '/../../../openapi.json'), true);
        $verbs = ['get', 'post', 'put', 'delete', 'patch'];

        $out = [];
        foreach ($spec['paths'] as $path => $item) {
            foreach ($item as $verb => $op) {
                if (!in_array($verb, $verbs, true) || !is_array($op) || !isset($op['servers'])) {
                    continue;
                }
                foreach ($op['servers'] as $server) {
                    if (str_contains($server['url'], '/ocs/v2.php/')) {
                        $out[] = strtoupper($verb) . ' ' . $path;
                    }
                }
            }
        }

        sort($out);

        return $out;
    }

    /** "VERB /path" for every entry in the ocs block. */
    private function ocsRouteOperations(): array {
        $source = file_get_contents(__DIR__ . '/../../../appinfo/routes.php');

        $start = strpos($source, "'ocs' => [");
        $this->assertNotFalse($start, "The 'ocs' block must exist");
        $end = strpos($source, "'routes' => [", $start);
        $block = substr($source, $start, $end === false ? strlen($source) : $end - $start);

        preg_match_all(
            "/'url'\s*=>\s*'([^']+)'\s*,\s*'verb'\s*=>\s*'([A-Z]+)'/",
            $block,
            $m,
            PREG_SET_ORDER
        );

        $out = array_map(fn($hit) => $hit[2] . ' ' . $hit[1], $m);
        sort($out);

        return $out;
    }

    public function testTheSpecMarksExactlyTheOperationsInTheOcsBlock(): void {
        $this->assertSame(
            $this->ocsRouteOperations(),
            $this->specOcsOperations(),
            "Every operation in the 'ocs' block needs the OCS servers[] override, and nothing else may have it"
        );
    }

    /**
     * /api/health is not on the OCS mount.
     *
     * Called out by name because it is the one that got this wrong: it was
     * tagged 'External API', which read as "part of the external surface" and
     * pointed the contract test at a URL that returns 404.
     */
    public function testHealthIsNotClaimedOnTheOcsMount(): void {
        $this->assertNotContains(
            '/api/health',
            $this->ocsRoutePaths(),
            'If health moves into the ocs block, the spec and this test must follow'
        );
        $this->assertNotContains('/api/health', $this->specOcsPaths());

        $spec = json_decode(file_get_contents(__DIR__ . '/../../../openapi.json'), true);
        $this->assertStringContainsString(
            'APP MOUNT ONLY',
            $spec['paths']['/api/health']['get']['description'],
            'The probe URL is worth stating; a 404 on the obvious guess is not self-explanatory'
        );
    }

    /** A single top-level server, so no path inherits a mount it does not have. */
    public function testTheDocumentDeclaresOneDefaultMount(): void {
        $spec = json_decode(file_get_contents(__DIR__ . '/../../../openapi.json'), true);

        $this->assertCount(
            1,
            $spec['servers'],
            'Two top-level servers means every path answers on both, which is not true here'
        );
        $this->assertStringNotContainsString('/ocs/v2.php/', $spec['servers'][0]['url']);
    }
}
