<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every `new SomeIntraVoxClass(...)` in lib/ passes enough arguments.
 *
 * This exists because `POST /api/import/confluence/html` was dead on main and
 * nothing noticed. ApiController did `new ConfluenceHtmlImporter($this->logger)`
 * while the constructor had grown a second required parameter. PHP resolves that
 * at call time, so the file parsed, the unit suite passed, and the endpoint threw
 * ArgumentCountError on the first real request — which extends Error, not
 * Exception, so it sailed past the handler's catch and surfaced as an uncaught
 * fatal instead of the careful error envelope next to it.
 *
 * The obvious test — "instantiate every importer" — would NOT have caught it.
 * The class was fine; the call site was wrong. What matters is that the two agree,
 * and that is what this checks.
 *
 * Deliberately lexical, in the same spirit as scripts/check-security-markers.js:
 * a real static analyser would do this better, but there is none in the project
 * and this covers the specific failure that already happened once.
 *
 * Scope and honest limits: it only resolves classes reachable from the file's own
 * `use` statements or written as a fully-qualified OCA\IntraVox name, and it skips
 * any call whose argument list contains a first-class callable (`...`) or a spread,
 * where counting arguments statically is not meaningful.
 */
class ConstructorArityTest extends TestCase {
    private const LIB = __DIR__ . '/../../lib';

    public function testEveryConstructorCallPassesEnoughArguments(): void {
        $problems = [];

        foreach ($this->phpFiles(self::LIB) as $file) {
            $source = file_get_contents($file);
            $uses = $this->useMap($source);

            foreach ($this->constructorCalls($source) as $call) {
                $fqn = $this->resolve($call['class'], $uses);
                if ($fqn === null) {
                    continue; // not one of ours, or dynamically named
                }

                $required = $this->requiredParamCount($fqn);
                if ($required === null) {
                    continue; // class not loadable in the stub environment
                }

                if ($call['args'] < $required) {
                    $rel = substr($file, strlen(dirname(self::LIB)) + 1);
                    $problems[] = sprintf(
                        '%s: new %s(...) passes %d argument(s), constructor requires %d',
                        $rel,
                        $call['class'],
                        $call['args'],
                        $required
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $problems,
            "A constructor call passes too few arguments. PHP only raises this when the line runs,\n"
            . "so the endpoint is dead until someone calls it:\n  " . implode("\n  ", $problems)
        );
    }

    /** The detector must be able to see a real mismatch, or the empty result above means nothing. */
    public function testTheDetectorRecognisesAnUnderfilledCall(): void {
        $sample = <<<'PHP'
        <?php
        namespace OCA\IntraVox\Controller;
        use OCA\IntraVox\Tests\Unit\NeedsTwoArgumentsFixture;
        class Sample {
            public function run() {
                $x = new NeedsTwoArgumentsFixture($this->logger);
            }
        }
        PHP;

        $uses = $this->useMap($sample);
        $calls = $this->constructorCalls($sample);

        $this->assertCount(1, $calls);
        $this->assertSame(1, $calls[0]['args']);
        $this->assertSame(
            NeedsTwoArgumentsFixture::class,
            $this->resolve($calls[0]['class'], $uses)
        );
        $this->assertSame(2, $this->requiredParamCount(NeedsTwoArgumentsFixture::class));
    }

    /** @return iterable<string> */
    private function phpFiles(string $dir): iterable {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                yield $f->getPathname();
            }
        }
    }

    /** @return array<string,string> alias => fully-qualified name */
    private function useMap(string $source): array {
        preg_match_all('/^use\s+([\\\\\w]+)(?:\s+as\s+(\w+))?\s*;/mi', $source, $m, PREG_SET_ORDER);
        $map = [];
        foreach ($m as $hit) {
            $fqn = ltrim($hit[1], '\\');
            $alias = $hit[2] ?? substr($fqn, (int)strrpos($fqn, '\\') + 1);
            $map[$alias] = $fqn;
        }
        return $map;
    }

    /**
     * Every `new Name(...)` with the number of top-level arguments.
     *
     * @return list<array{class:string,args:int}>
     */
    private function constructorCalls(string $source): array {
        $calls = [];
        $offset = 0;

        while (preg_match('/\bnew\s+(\\\\?[\w\\\\]+)\s*\(/', $source, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $class = $m[1][0];
            $open = $m[0][1] + strlen($m[0][0]) - 1;
            $offset = $open + 1;

            $args = $this->countArguments($source, $open);
            if ($args === null) {
                continue;
            }
            $calls[] = ['class' => $class, 'args' => $args];
        }

        return $calls;
    }

    /** Count top-level arguments starting at the opening parenthesis, or null if unparseable. */
    private function countArguments(string $source, int $open): ?int {
        $depth = 0;
        $count = 0;
        $seenToken = false;
        $len = strlen($source);
        $quote = null;

        for ($i = $open; $i < $len; $i++) {
            $c = $source[$i];

            if ($quote !== null) {
                if ($c === '\\') { $i++; continue; }
                if ($c === $quote) { $quote = null; }
                continue;
            }

            if ($c === "'" || $c === '"') { $quote = $c; $seenToken = true; continue; }

            if ($c === '(' || $c === '[') { $depth++; if ($depth > 1) { $seenToken = true; } continue; }

            if ($c === ')' || $c === ']') {
                $depth--;
                if ($depth === 0) {
                    return $seenToken ? $count + 1 : 0;
                }
                continue;
            }

            if ($depth === 1) {
                if ($c === ',') { $count++; continue; }
                // A spread or first-class callable makes a static count meaningless.
                if ($c === '.' && substr($source, $i, 3) === '...') { return null; }
                if (!ctype_space($c)) { $seenToken = true; }
            }
        }

        return null;
    }

    private function resolve(string $name, array $uses): ?string {
        if (str_starts_with($name, '\\')) {
            $fqn = ltrim($name, '\\');
            return str_starts_with($fqn, 'OCA\\IntraVox\\') ? $fqn : null;
        }

        $head = explode('\\', $name)[0];
        if (isset($uses[$head])) {
            $rest = substr($name, strlen($head));
            $fqn = $uses[$head] . $rest;
            return str_starts_with($fqn, 'OCA\\IntraVox\\') ? $fqn : null;
        }

        return null;
    }

    private function requiredParamCount(string $fqn): ?int {
        // class_exists() autoloads, and a class whose PARENT is missing in the
        // stub environment (Symfony's Command, for one) throws rather than
        // returning false. Anything we cannot load is simply out of scope.
        try {
            if (!class_exists($fqn)) {
                return null;
            }
            $ctor = (new \ReflectionClass($fqn))->getConstructor();
        } catch (\Throwable) {
            return null;
        }
        return $ctor === null ? 0 : $ctor->getNumberOfRequiredParameters();
    }
}

/** Fixture for the self-test above; two required parameters. */
class NeedsTwoArgumentsFixture {
    public function __construct(public mixed $one, public mixed $two) {
    }
}
