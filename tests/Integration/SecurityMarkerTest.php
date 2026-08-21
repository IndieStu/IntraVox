<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Integration;

use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\ARateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\UserRateLimit;

/**
 * Do our security markers actually fire? (MARK-1)
 *
 * Every rate limit in the app was dead. 28 controller methods carried
 * #[AnonRateThrottle] / #[UserRateThrottle], naming classes Nextcloud does not
 * have — the real ones are AnonRateLimit and UserRateLimit. PHP instantiates
 * attributes lazily, so the bogus class was never resolved and never errored.
 * RateLimitingMiddleware filters with getAttributes(AnonRateLimit::class,
 * IS_INSTANCEOF) and therefore never matched a single one.
 *
 * This belongs in the Integration suite, not Unit: the Unit bootstrap stubs OCP
 * away, so "does this OCP attribute class exist" is exactly the question it
 * cannot answer. Here we run inside a real Nextcloud.
 */
class SecurityMarkerTest extends IntegrationTestCase {

	private const CONTROLLER_DIR = __DIR__ . '/../../lib/Controller';

	/**
	 * The regression itself: no source file may name an attribute class that
	 * does not exist. Fails on the pre-fix tree with 28 hits.
	 */
	public function testEveryAttributeMarkerResolvesToARealClass(): void {
		$missing = [];

		foreach ($this->attributeUsages() as [$file, $line, $name]) {
			$fqcn = 'OCP\\AppFramework\\Http\\Attribute\\' . $name;
			if (!class_exists($fqcn)) {
				$missing[] = sprintf('%s:%d uses #[%s], which does not exist', $file, $line, $name);
			}
		}

		$this->assertSame([], $missing, "Attributes that can never fire:\n" . implode("\n", $missing));
	}

	/**
	 * The two names that caused the bug must resolve to nothing — proving the
	 * test above is meaningful and not just asserting on an empty list.
	 */
	public function testThrottleNamesAreNotAttributeClasses(): void {
		$this->assertFalse(
			class_exists('OCP\\AppFramework\\Http\\Attribute\\AnonRateThrottle'),
			'AnonRateThrottle is an annotation name; if it ever becomes a class this test needs revisiting'
		);
		$this->assertFalse(class_exists('OCP\\AppFramework\\Http\\Attribute\\UserRateThrottle'));

		// ...while the real ones do, and are rate limits.
		$this->assertTrue(is_subclass_of(AnonRateLimit::class, ARateLimit::class));
		$this->assertTrue(is_subclass_of(UserRateLimit::class, ARateLimit::class));
	}

	/**
	 * The share endpoints are the anonymous surface, so their limiter must be
	 * present and constructible — not merely spelled correctly.
	 */
	public function testShareEndpointsCarryAWorkingAnonRateLimit(): void {
		$reflection = new \ReflectionClass(\OCA\IntraVox\Controller\PublicShareController::class);

		foreach (['getPageByShare', 'getNavigationByShare', 'getNewsByShare'] as $method) {
			$attributes = $reflection->getMethod($method)
				->getAttributes(AnonRateLimit::class, \ReflectionAttribute::IS_INSTANCEOF);

			$this->assertNotEmpty($attributes, "$method has no AnonRateLimit the middleware can find");

			// newInstance() is what proves the class truly resolves; the broken
			// attributes only failed here, and the middleware never called it.
			$limit = $attributes[0]->newInstance();
			$this->assertGreaterThan(0, $limit->getLimit());
			$this->assertGreaterThan(0, $limit->getPeriod());
		}
	}

	/**
	 * BruteForceMiddleware does `if (hasAnnotation) ... else attribute`, so a
	 * malformed @BruteForceProtection docblock silently beats a correct
	 * attribute. ControllerMethodReflector splits parameters on '=' without
	 * stripping quotes, so action="x" became "x" WITH the quotes (21 chars)
	 * while registerAttempt() used x (19). No annotation may survive.
	 */
	public function testNoLegacyBruteForceAnnotationShadowsTheAttribute(): void {
		$offenders = [];

		foreach ($this->controllerSources() as $file => $source) {
			foreach (explode("\n", $source) as $index => $line) {
				if (preg_match('/^\s*\*\s*@BruteForceProtection\((.*)\)/', $line, $m)) {
					$offenders[] = sprintf('%s:%d: @BruteForceProtection(%s)', $file, $index + 1, $m[1]);
				}
			}
		}

		$this->assertSame(
			[],
			$offenders,
			"A @BruteForceProtection annotation takes precedence over the attribute:\n" . implode("\n", $offenders)
		);
	}

	/**
	 * The share endpoint must still carry brute-force protection — as an
	 * attribute, whose action string is not mangled by the annotation parser.
	 */
	public function testShareEndpointBruteForceActionMatchesTheRegisteredAction(): void {
		$attributes = (new \ReflectionClass(\OCA\IntraVox\Controller\PublicShareController::class))
			->getMethod('getPageByShare')
			->getAttributes(BruteForceProtection::class);

		$this->assertNotEmpty($attributes, 'getPageByShare lost its brute-force protection');

		$action = $attributes[0]->newInstance()->getAction();

		// registerShareBruteForceAttempt() registers exactly this string.
		$this->assertSame('intravox_share_page', $action);
		$this->assertStringNotContainsString('"', $action, 'a quoted action never matches the registered one');
	}

	/** @return iterable<array{0:string,1:int,2:string}> */
	private function attributeUsages(): iterable {
		foreach ($this->controllerSources() as $file => $source) {
			foreach (explode("\n", $source) as $index => $line) {
				if (preg_match('/^\s*#\[(\w+)/', $line, $m)) {
					yield [$file, $index + 1, $m[1]];
				}
			}
		}
	}

	/** @return array<string,string> */
	private function controllerSources(): array {
		$sources = [];
		$dir = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::CONTROLLER_DIR));

		foreach ($dir as $entry) {
			if ($entry->isFile() && $entry->getExtension() === 'php') {
				$sources[$entry->getFilename()] = (string)file_get_contents($entry->getPathname());
			}
		}

		ksort($sources);
		return $sources;
	}
}
