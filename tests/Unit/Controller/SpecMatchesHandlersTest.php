<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * The spec does not promise things the handlers contradict.
 *
 * check-openapi.js proves an operation EXISTS. It cannot see whether it is TRUE,
 * and the four defects that opened this release all had a path, an operationId,
 * tags and a 200 — they passed every coverage rule while being wrong. A documented
 * endpoint that lies is worse than an undocumented one: the missing entry sends
 * you to read the code, the wrong entry lets you trust it.
 *
 * Two classes are checkable statically and both had real instances:
 *
 *  - A handler that returns 201 under an operation declaring only 200. A generated
 *    client matching strictly on 200 fails on every create call. Four endpoints
 *    were in this state.
 *  - A query parameter documented that no handler reads. GET /api/share/{token}/news
 *    advertised `sort`; the code reads sortBy and sortOrder, so anyone following the
 *    spec sorted nothing and got no error saying so.
 *
 * What this deliberately does NOT check is response body shape, which is where the
 * remaining risk lives — only a contract test against a running server can see
 * that. This is the static half; it is cheap and it holds the line.
 */
class SpecMatchesHandlersTest extends TestCase {
    private const VERBS = ['get', 'post', 'put', 'delete', 'patch'];

    private array $spec;
    /** @var array<string,string> */
    private array $controllers = [];

    protected function setUp(): void {
        parent::setUp();
        $root = __DIR__ . '/../../../';
        $this->spec = json_decode(file_get_contents($root . 'openapi.json'), true);

        foreach (glob($root . 'lib/Controller/*.php') as $file) {
            $this->controllers[basename($file, '.php')] = file_get_contents($file);
        }
    }

    /** The body of one controller method, bounded by the next one. */
    private function methodBody(string $controller, string $method): ?string {
        $source = $this->controllers[$controller] ?? null;
        if ($source === null) {
            return null;
        }

        $start = strpos($source, 'public function ' . $method . '(');
        if ($start === false) {
            return null;
        }
        $next = strpos($source, "\n    public function ", $start + 10);

        return substr($source, $start, $next === false ? 6000 : $next - $start);
    }

    /** @return list<array{verb:string,path:string,op:array}> */
    private function operations(): array {
        $out = [];
        foreach ($this->spec['paths'] as $path => $item) {
            foreach ($item as $verb => $op) {
                if (in_array($verb, self::VERBS, true) && is_array($op)) {
                    $out[] = ['verb' => strtoupper($verb), 'path' => $path, 'op' => $op];
                }
            }
        }

        return $out;
    }

    public function testNoOperationDeclaresOnly200WhereTheHandlerReturns201(): void {
        $wrong = [];

        foreach ($this->operations() as ['verb' => $verb, 'path' => $path, 'op' => $op]) {
            if ($verb !== 'POST') {
                continue;
            }
            // json_decode() turns numeric object keys into ints, so "201" arrives
            // as 201 and a strict string comparison silently never matches — which
            // made this guard report two already-correct endpoints.
            $codes = array_map('strval', array_keys($op['responses'] ?? []));
            if (in_array('201', $codes, true)) {
                continue;
            }

            foreach ($this->handlersFor($verb, $path) as [$controller, $method]) {
                $body = $this->methodBody($controller, $method);
                if ($body !== null && str_contains($body, 'Http::STATUS_CREATED')) {
                    $wrong[] = "{$verb} {$path} → {$controller}::{$method} returns 201";
                }
            }
        }

        $this->assertSame([], $wrong, "Handlers return 201 where the spec promises only 200:\n  " . implode("\n  ", $wrong));
    }

    public function testEveryDocumentedQueryParameterIsReadBySomeHandler(): void {
        $phantom = [];

        foreach ($this->operations() as ['verb' => $verb, 'path' => $path, 'op' => $op]) {
            foreach ($op['parameters'] ?? [] as $param) {
                if (($param['in'] ?? '') !== 'query') {
                    continue;
                }
                $name = $param['name'];

                $found = false;
                foreach ($this->handlersFor($verb, $path) as [$controller, $method]) {
                    $body = $this->methodBody($controller, $method);
                    if ($body === null) {
                        continue;
                    }
                    // Read imperatively, or declared as a typed method argument.
                    if (str_contains($body, "getParam('{$name}'")
                        || preg_match('/\$' . preg_quote($name, '/') . '\b/', explode('{', $body, 2)[0] ?? '')) {
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $phantom[] = "{$verb} {$path} documents '{$name}'";
                }
            }
        }

        $this->assertSame([], $phantom, "Documented query parameters no handler reads:\n  " . implode("\n  ", $phantom));
    }

    /** @return list<array{0:string,1:string}> controller class + method for a documented path */
    private function handlersFor(string $verb, string $path): array {
        $routes = json_decode(shell_exec(
            'cd ' . escapeshellarg(__DIR__ . '/../../../')
            . " && node -e \"const p=require('./scripts/lib/route-parser.js');console.log(JSON.stringify(p.parseRoutes()))\""
        ) ?: '[]', true);

        $norm = static fn (string $u): string => preg_replace('/\{[^}]*\}/', '{}', rtrim($u, '/')) ?: '/';
        $want = $verb . ' ' . $norm($path);

        $out = [];
        foreach ($routes as $r) {
            foreach ([$r['url'], $r['ocsUrl'] ?? null] as $candidate) {
                if ($candidate !== null && $r['verb'] . ' ' . $norm($candidate) === $want) {
                    [$ctrl, $method] = explode('#', $r['name']);
                    $method = preg_replace_callback('/_([a-z])/', static fn ($m) => strtoupper($m[1]), $method);
                    $out[] = [ucfirst($ctrl) . 'Controller', $method];
                }
            }
        }

        return $out;
    }
}
