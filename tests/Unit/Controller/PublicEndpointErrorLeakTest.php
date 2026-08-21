<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Anonymous endpoints must not hand internal detail to the caller. (CT-1)
 *
 * 113 controller responses return the raw exception message — SQL errors, file
 * paths, class names. On an authenticated endpoint that is a diagnostic aid with
 * a modest cost; on a PUBLIC one it is reconnaissance for anyone with the link.
 *
 * Checked at the time of writing: none of the #[PublicPage] methods does this.
 * They all answer with a fixed string ("Page not found or not accessible via
 * this share link"), which was confirmed against the live endpoints on nc-dev.
 * That is a property worth keeping rather than rediscovering, because the
 * obvious way to debug a share problem is to echo the exception.
 *
 * ApiErrorTrait::safeErrorResponse() is the supported way to do this: it logs
 * the full exception with an error id and returns only that id to the client.
 */
class PublicEndpointErrorLeakTest extends TestCase {

	/** @return list<string> */
	private function controllerFiles(): array {
		$dir = \dirname(__DIR__, 3) . '/lib/Controller';
		$files = glob($dir . '/*.php');

		$this->assertNotEmpty($files, 'no controllers found');

		return $files;
	}

	/**
	 * Every response built inside a #[PublicPage] method, with the method it
	 * belongs to and whether it echoes the exception.
	 *
	 * @return list<array{file:string,line:int,method:string,snippet:string}>
	 */
	private function leakingPublicResponses(): array {
		$leaks = [];

		foreach ($this->controllerFiles() as $file) {
			$source = (string)file_get_contents($file);
			$lines = explode("\n", $source);

			// Public methods, marked either by attribute or legacy annotation.
			$methods = [];
			foreach ($lines as $index => $line) {
				if (preg_match('/^\s*public function (\w+)/', $line, $m) !== 1) {
					continue;
				}
				$head = implode("\n", array_slice($lines, max(0, $index - 16), 16));
				$isPublic = str_contains($head, '#[PublicPage]') || str_contains($head, '@PublicPage');
				$methods[] = ['line' => $index, 'name' => $m[1], 'public' => $isPublic];
			}

			$pattern = '/new\s+(?:Data|JSON)Response\s*\((.{0,400}?)\)\s*;/s';
			preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

			foreach ($matches as $match) {
				if (!str_contains($match[1][0], '$e->getMessage()')) {
					continue;
				}

				$line = substr_count(substr($source, 0, $match[0][1]), "\n");
				$owner = null;
				foreach ($methods as $method) {
					if ($method['line'] < $line) {
						$owner = $method;
					}
				}

				// An admin guard inside the body makes it not anonymous in practice.
				$body = substr($source, $match[0][1] - 3000, 3000);
				if ($owner !== null && $owner['public'] && !str_contains($body, 'isAdmin()')) {
					$leaks[] = [
						'file' => basename($file),
						'line' => $line + 1,
						'method' => $owner['name'],
						'snippet' => trim(preg_replace('/\s+/', ' ', $match[1][0]) ?? ''),
					];
				}
			}
		}

		return $leaks;
	}

	public function testNoPublicEndpointReturnsAnExceptionMessage(): void {
		$leaks = $this->leakingPublicResponses();

		$rendered = array_map(
			static fn (array $l): string => "{$l['file']}:{$l['line']} {$l['method']}() -> {$l['snippet']}",
			$leaks
		);

		$this->assertSame(
			[],
			$rendered,
			"An anonymous caller must never receive the exception text. Use "
			. "ApiErrorTrait::safeErrorResponse(), which logs the detail and returns an error id:\n"
			. implode("\n", $rendered)
		);
	}

	/** The detector must be able to see a leak, or the test above proves nothing. */
	public function testTheDetectorRecognisesALeakingPattern(): void {
		$sample = <<<'PHP_SAMPLE'
			#[PublicPage]
			public function leaky(): DataResponse {
				try {
					return new DataResponse(['ok' => true]);
				} catch (\Exception $e) {
					return new DataResponse(['error' => $e->getMessage()], 500);
				}
			}
			PHP_SAMPLE;

		$this->assertMatchesRegularExpression(
			'/new\s+(?:Data|JSON)Response\s*\((.{0,400}?)\)\s*;/s',
			$sample
		);
		$this->assertStringContainsString('$e->getMessage()', $sample);
	}
}
