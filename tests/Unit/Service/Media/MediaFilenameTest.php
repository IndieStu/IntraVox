<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service\Media;

use OCA\IntraVox\Service\Locator\PageLocator;
use OCA\IntraVox\Service\Media\PageMediaService;
use OCA\IntraVox\Service\Sanitize\MediaSanitizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A stored upload's extension comes from the bytes, not the filename. (UP-1)
 *
 * generatedMediaFilename() took the extension from pathinfo() on the
 * CLIENT-SUPPLIED name, while only the img_/vid_ prefix honoured the sniffed
 * mime type. A genuine PNG uploaded as "evil.php" was therefore stored as
 * "img_<uniqid>.php": real image bytes under a name a misconfigured web server
 * may hand to an interpreter, and one that defeats any extension-based rule
 * downstream. A name with no extension produced a trailing bare dot.
 *
 * validateUpload() sniffs and allowlists the type before this runs, so the mime
 * is authoritative and the client name now contributes nothing at all.
 */
class MediaFilenameTest extends TestCase {

	private function service(): PageMediaService {
		return new PageMediaService(
			$this->createMock(PageLocator::class),
			// MediaSanitizer is final (deliberately — it is a security boundary),
			// so a real one is used. generatedMediaFilename() never calls it.
			new MediaSanitizer($this->createMock(LoggerInterface::class)),
			$this->createMock(LoggerInterface::class),
		);
	}

	private function extensionOf(string $filename): string {
		return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function hostileNames(): array {
		return [
			'php' => ['evil.php', 'image/png'],
			'phtml' => ['evil.phtml', 'image/png'],
			'php5' => ['shell.php5', 'image/jpeg'],
			'html' => ['x.html', 'image/png'],
			'htaccess' => ['.htaccess', 'image/png'],
			'double extension' => ['photo.png.php', 'image/png'],
			'no extension' => ['noext', 'image/png'],
			'svg claimed on a png' => ['x.svg', 'image/png'],
			'php on a video' => ['clip.php', 'video/mp4'],
		];
	}

	/**
	 * The regression. Fails on the pre-fix code, which echoed the hostile
	 * extension straight into the stored name.
	 *
	 * @dataProvider hostileNames
	 */
	public function testHostileClientNameCannotDictateTheStoredExtension(
		string $originalName,
		string $mimeType,
	): void {
		$stored = $this->service()->generatedMediaFilename($originalName, $mimeType);
		$hostile = $this->extensionOf($originalName);

		$this->assertNotSame(
			$hostile,
			$this->extensionOf($stored),
			"the client-supplied extension must not survive: $originalName -> $stored"
		);
		$this->assertMatchesRegularExpression(
			'/^(img|vid)_[0-9a-f.]+\.(jpg|png|gif|webp|svg|mp4|webm|ogv)$/',
			$stored,
			"stored name must be fully generated: $stored"
		);
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function mimeToExtension(): array {
		return [
			['image/jpeg', 'jpg'],
			['image/png', 'png'],
			['image/gif', 'gif'],
			['image/webp', 'webp'],
			['image/svg+xml', 'svg'],
			['video/mp4', 'mp4'],
			['video/webm', 'webm'],
			['video/ogg', 'ogv'],
		];
	}

	/** @dataProvider mimeToExtension */
	public function testExtensionFollowsTheSniffedMime(string $mimeType, string $expected): void {
		// The client name is deliberately misleading in every case.
		$stored = $this->service()->generatedMediaFilename('whatever.exe', $mimeType);

		$this->assertSame($expected, $this->extensionOf($stored));
	}

	public function testVideosGetTheVideoPrefixAndImagesTheImagePrefix(): void {
		$this->assertStringStartsWith('vid_', $this->service()->generatedMediaFilename('a.png', 'video/mp4'));
		$this->assertStringStartsWith('img_', $this->service()->generatedMediaFilename('a.mp4', 'image/png'));
	}

	/** A name is never left ending in a bare dot. */
	public function testGeneratedNameAlwaysHasAnExtension(): void {
		foreach (['noext', '', '.', 'trailing.'] as $name) {
			$stored = $this->service()->generatedMediaFilename($name, 'image/png');

			$this->assertStringEndsWith('.png', $stored);
			$this->assertStringNotContainsString('..', $stored);
		}
	}

	/**
	 * Unknown types cannot reach this method — validateUpload() allowlists first
	 * — but if one ever does it must fail closed rather than produce a bare dot.
	 */
	public function testUnknownMimeFallsBackToASafeExtension(): void {
		$stored = $this->service()->generatedMediaFilename('x.php', 'application/x-php');

		$this->assertStringEndsWith('.bin', $stored);
	}
}
