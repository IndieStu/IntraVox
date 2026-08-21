<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service\Sanitize;

use OCA\IntraVox\Service\Sanitize\ColorSanitizer;
use OCA\IntraVox\Service\Sanitize\HtmlSanitizer;
use OCA\IntraVox\Service\Sanitize\PageShapeSanitizer;
use OCA\IntraVox\Service\Sanitize\UrlSanitizer;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * sanitizePath() and sanitizeFolderPath(). (F7 / QG-5)
 *
 * These decide which paths reach the filesystem from widget config, so the
 * refusals below are the whole point: each throws rather than returning a
 * "cleaned" string, because silently repairing a hostile path is how a
 * traversal ends up half-blocked.
 *
 * sanitizeFolderPath() exists because "/" is a meaningful value for the story
 * widgets — the whole drive — and sanitizePath() would collapse it to "" (no
 * folder selected). That difference is worth pinning: it is the kind of
 * special case a later cleanup quietly removes.
 */
class PathSanitizerSpecTest extends TestCase {

	private PageShapeSanitizer $sanitizer;

	protected function setUp(): void {
		parent::setUp();

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn(string $app, string $key, $default = null) => $default
		);

		$this->sanitizer = new PageShapeSanitizer(
			$config,
			$this->createMock(LoggerInterface::class),
			new HtmlSanitizer(),
			new UrlSanitizer(),
			new ColorSanitizer(),
		);
	}

	// ---------- what it accepts ----------

	public function testKeepsAnOrdinaryRelativePath(): void {
		$this->assertSame('images/foto.jpg', $this->sanitizer->sanitizePath('images/foto.jpg'));
	}

	public function testStripsLeadingAndTrailingSlashes(): void {
		$this->assertSame('images/foto.jpg', $this->sanitizer->sanitizePath('/images/foto.jpg/'));
	}

	public function testConvertsBackslashesToForwardSlashes(): void {
		$this->assertSame('images/foto.jpg', $this->sanitizer->sanitizePath('images\\foto.jpg'));
	}

	public function testEmptyPathIsAllowed(): void {
		// The news widget uses "" for "all pages", so this is a value, not an error.
		$this->assertSame('', $this->sanitizer->sanitizePath(''));
		$this->assertSame('', $this->sanitizer->sanitizePath('/'));
	}

	public function testAcceptsUnicodeFilenames(): void {
		$this->assertSame('afdeling/café.jpg', $this->sanitizer->sanitizePath('afdeling/café.jpg'));
	}

	// ---------- what it refuses ----------

	/** @dataProvider hostilePaths */
	public function testRefusesHostilePath(string $path, string $why): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->sanitizer->sanitizePath($path);
	}

	public static function hostilePaths(): array {
		return [
			'parent traversal'        => ['../../etc/passwd', 'climbs out of the tree'],
			'traversal mid-path'      => ['images/../../etc/passwd', 'climbs out halfway'],
			'backslash traversal'     => ['..\\..\\windows\\system32', 'same climb, other separator'],
			'null byte'               => ["images/foto.jpg\0.php", 'truncates the extension check'],
			'hidden file'             => ['.env', 'dotfiles are not page media'],
			'hidden dir'              => ['.git/config', 'dotfiles are not page media'],
			'double slash'            => ['images//foto.jpg', 'empty segment'],
			'single dot segment'      => ['images/./foto.jpg', 'no-op segment used to smuggle'],
			'php file'                => ['shell.php', 'executable'],
			'phtml file'              => ['shell.phtml', 'executable'],
			'phar file'               => ['payload.phar', 'executable'],
			'php5 file'               => ['legacy.php5', 'executable'],
			'phps file'               => ['source.phps', 'executable'],
			'pht file'                => ['odd.pht', 'executable'],
			'uppercase php'           => ['SHELL.PHP', 'extension test is case-insensitive'],
			'php in subdirectory'     => ['uploads/shell.php', 'depth does not help'],
		];
	}

	/**
	 * A traversal must not be repairable into something that looks safe.
	 *
	 * The method throws rather than returning a stripped path, so a caller
	 * cannot accidentally proceed with a partially-cleaned value.
	 */
	public function testTraversalThrowsRatherThanReturningACleanedPath(): void {
		try {
			$this->sanitizer->sanitizePath('images/../../../etc/passwd');
			$this->fail('expected an exception');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringContainsStringIgnoringCase('traversal', $e->getMessage());
		}
	}

	// ---------- sanitizeFolderPath: root is a value ----------

	public function testFolderPathKeepsRootAsRoot(): void {
		// The distinction this method exists for: "/" means the whole drive,
		// while "" means nothing selected. sanitizePath() cannot tell them apart.
		$this->assertSame('/', $this->sanitizer->sanitizeFolderPath('/'));
		$this->assertSame('/', $this->sanitizer->sanitizeFolderPath('\\'));
		$this->assertSame('/', $this->sanitizer->sanitizeFolderPath('  /  '));
	}

	public function testPlainSanitizePathCollapsesRootToEmpty(): void {
		// The behaviour that makes the wrapper necessary. If this ever changes,
		// sanitizeFolderPath() is redundant — and that should be a decision.
		$this->assertSame('', $this->sanitizer->sanitizePath('/'));
	}

	public function testFolderPathDelegatesForEverythingElse(): void {
		$this->assertSame('Photos/2026', $this->sanitizer->sanitizeFolderPath('/Photos/2026/'));
	}

	public function testFolderPathStillRefusesTraversal(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->sanitizer->sanitizeFolderPath('/Photos/../../etc');
	}

	public function testFolderPathStillRefusesNullBytes(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->sanitizer->sanitizeFolderPath("/Photos\0/x");
	}
}
