<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Controller;

use OCA\IntraVox\Controller\Shared\SharePathTrait;
use PHPUnit\Framework\TestCase;

/**
 * Serving a media path must accept the filenames the app lets you store. (#101)
 *
 * sanitizePath() guarded step 7 with an allowlist, `^[a-zA-Z0-9/_\-\.]+$`,
 * which refused every accented letter and every space. Since the picker
 * thumbnail, the rendered image widget and the News tile all resolve Shared
 * library media through this one method, "Übersicht.png" was blank in all
 * three at once — the exact report in issue #101.
 *
 * It also contradicted the storage side: PageShapeSanitizer::sanitizePath()
 * accepts unicode deliberately and PathSanitizerSpecTest pins
 * `afdeling/café.jpg`, so a src could be saved that could never be served.
 *
 * The traversal defences are unchanged and re-asserted below, because the
 * point of the fix is that they — not the allowlist — are what makes this
 * method safe. If a later cleanup reintroduces a character allowlist, the
 * unicode cases here fail before anyone ships it.
 */
class SharePathUnicodeTest extends TestCase {

	/** @var object A minimal holder for the trait under test. */
	private object $subject;

	protected function setUp(): void {
		$this->subject = new class {
			use SharePathTrait;

			public function call(string $path): string {
				return $this->sanitizePath($path);
			}
		};
	}

	// ---------- what it must now accept ----------

	/**
	 * @dataProvider legitimateNames
	 */
	public function testAcceptsLegitimateFilename(string $path, string $expected, string $why): void {
		$this->assertSame($expected, $this->subject->call($path), $why);
	}

	public static function legitimateNames(): array {
		return [
			// The reporter's own example, and the German names that made this
			// surface: an intranet Shared library is filled through the Files
			// app, so these are the normal case, not the exotic one.
			'reported example'   => ['image with äüö.png', 'image with äüö.png', 'the filename from issue #101'],
			'german umlaut'      => ['Übersicht.png', 'Übersicht.png', 'leading umlaut'],
			'sharp s'            => ['Größe.jpg', 'Größe.jpg', 'ß is a letter, not punctuation'],
			'french accent'      => ['café.jpg', 'café.jpg', 'matches the storage-side spec test'],
			'accent in subfolder' => ['afdeling/café.jpg', 'afdeling/café.jpg', 'PathSanitizerSpecTest pins this exact string'],
			'plain space'        => ['Team foto.jpg', 'Team foto.jpg', 'spaces were refused too, not just accents'],
			'space in subfolder' => ['Over ons/Team foto.jpg', 'Over ons/Team foto.jpg', 'folder names have spaces as well'],
			'parentheses'        => ['foto (1).png', 'foto (1).png', 'what a browser names a second download'],
			'ampersand'          => ['Q&A.png', 'Q&A.png', 'legal in a filename'],
			'cyrillic'           => ['изображение.png', 'изображение.png', 'non-latin scripts are filenames too'],
			'cjk'                => ['写真.jpg', '写真.jpg', 'non-latin scripts are filenames too'],
			'emoji'              => ['team 🎉.png', 'team 🎉.png', 'printable, therefore not our business'],
			'ascii still works'  => ['normal_file.png', 'normal_file.png', 'the old allowlist cases must not regress'],
		];
	}

	public function testEmptyPathStaysEmpty(): void {
		// The news widget uses "" for "all pages" — a value, not an error.
		$this->assertSame('', $this->subject->call(''));
	}

	// ---------- what it must still refuse ----------

	/**
	 * @dataProvider hostilePaths
	 */
	public function testStillRefusesHostilePath(string $path, string $why): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->subject->call($path);
	}

	public static function hostilePaths(): array {
		return [
			'traversal mid-path'  => ['images/../../etc/passwd', 'climbs out halfway'],
			'encoded traversal'   => ['%2e%2e/%2e%2e/etc/passwd', 'smuggled past the router'],
			'null byte'           => ["images/foto.jpg\0.php", 'truncates the extension check'],
			'nested hidden file'  => ['foo/.env', 'dotfiles are not page media'],
			'double slash'        => ['images//foto.jpg', 'empty segment'],
			'single dot segment'  => ['images/./foto.jpg', 'no-op segment used to smuggle'],
			'php file'            => ['shell.php', 'executable'],
			'phtml file'          => ['shell.phtml', 'executable'],
			'phar file'           => ['payload.phar', 'executable'],
			'uppercase php'       => ['SHELL.PHP', 'extension test is case-insensitive'],
			'php in subdirectory' => ['uploads/shell.php', 'depth does not help'],
			'shell script'        => ['run.sh', 'executable'],
		];
	}

	/**
	 * Traversal at the very start or end is neutralized, not refused.
	 *
	 * Step 5 does `trim($path, '/.')`, which eats leading "../" and trailing
	 * "/.." before the traversal check on step 6 ever sees them. What comes
	 * out is a folder-relative path, so the lookup stays inside the scoped
	 * folder and simply misses — safe, but "cleaned" rather than rejected,
	 * which is the opposite of what the docblock claims and of how the
	 * storage-side PageShapeSanitizer behaves.
	 *
	 * This predates issue #101 and is deliberately left alone here: tightening
	 * it changes the contract for every caller of the trait, which is not a
	 * patch-release change. Pinned so the behaviour is visible rather than
	 * folklore, and so a later fix has to update this test on purpose.
	 *
	 * @dataProvider neutralizedPaths
	 */
	public function testEdgeTraversalIsNeutralizedIntoAFolderRelativePath(string $path, string $expected): void {
		$this->assertSame($expected, $this->subject->call($path));
	}

	public static function neutralizedPaths(): array {
		return [
			'leading traversal'   => ['../../etc/passwd', 'etc/passwd'],
			'trailing traversal'  => ['images/..', 'images'],
			'backslash traversal' => ['..\\..\\windows\\system32', 'windows/system32'],
			'root-level dotfile'  => ['.env', 'env'],
			'root-level dotdir'   => ['.git/config', 'git/config'],
			'unicode + traversal' => ['../Übersicht.png', 'Übersicht.png'],
		];
	}

	/**
	 * A name is looked up as it was received, not as urldecode() rewrites it.
	 *
	 * Step 2 decoded a second time and kept the result, so "foto+1.png" became
	 * "foto 1.png" and 404'd. The decode is now a detection step only. Same
	 * defect class as #101: a filename the app stores happily and cannot serve.
	 */
	public function testDoesNotDecodeALiteralFilenameASecondTime(): void {
		$this->assertSame('foto+1.png', $this->subject->call('foto+1.png'));
		$this->assertSame('Q&A + FAQ.png', $this->subject->call('Q&A + FAQ.png'));
		$this->assertSame('100%.png', $this->subject->call('100%.png'));
	}

	/**
	 * Dropping the reassignment must not drop the smuggling check with it.
	 */
	public function testStillRefusesEncodedTraversalAfterTheDecodeChange(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->subject->call('images/%2e%2e/%2e%2e/etc/passwd');
	}

	/**
	 * The characters the new rule replaces the allowlist with.
	 *
	 * A control character in a filename has no legitimate use and can split a
	 * log line or a header, so these stay refused even though they are not
	 * traversal.
	 *
	 * @dataProvider controlCharacters
	 */
	public function testRefusesControlCharacters(string $path, string $why): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->subject->call($path);
	}

	public static function controlCharacters(): array {
		return [
			'newline'         => ["foto\n.png", 'splits log lines and headers'],
			'carriage return' => ["foto\r.png", 'header injection'],
			'tab'             => ["foto\t.png", 'no legitimate use'],
			'escape'          => ["foto\x1B.png", 'terminal escape sequence'],
			'delete'          => ["foto\x7F.png", 'no legitimate use'],
			'vertical tab'    => ["foto\x0B.png", 'no legitimate use'],
		];
	}

	/**
	 * A unicode name that is ALSO a traversal must be refused for the
	 * traversal, not accepted because the letters are now allowed.
	 *
	 * This is the failure mode the fix could plausibly introduce: widening the
	 * character rule must not widen the path rule with it.
	 */
	public function testUnicodeDoesNotSmuggleTraversal(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->subject->call('bilder/../../Übersicht.png');
	}

	public function testUnicodeDoesNotSmuggleAnExecutable(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->subject->call('Übersicht.php');
	}

	/**
	 * NFD input is normalized to NFC, so the two spellings of "ü" resolve to
	 * the same stored name.
	 *
	 * Nextcloud stores NFC; a macOS client sends NFD. Without this the same
	 * file would be reachable under one spelling and 404 under the other.
	 */
	public function testNormalizesDecomposedUnicodeToComposed(): void {
		if (!class_exists('Normalizer')) {
			$this->markTestSkipped('intl extension not available');
		}

		$decomposed = "u\u{0308}bersicht.png"; // u + combining diaeresis
		$composed = "\u{00FC}bersicht.png";    // ü

		$this->assertSame($composed, $this->subject->call($decomposed));
		$this->assertSame($composed, $this->subject->call($composed));
	}
}
