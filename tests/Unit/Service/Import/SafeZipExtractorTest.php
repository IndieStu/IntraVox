<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service\Import;

use OCA\IntraVox\Service\Import\SafeZipExtractor;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ZipArchive;

/**
 * The single ZIP extraction path. (ZIP-1)
 *
 * ImportService and ConfluenceHtmlImporter each carried their own copy of the
 * ZIP-Slip check. They had already drifted — different permissions, different
 * cleanup, different skip rules — and neither had ANY limit on uncompressed
 * size, so a zip bomb could fill the volume through either one. Both now
 * delegate here.
 */
class SafeZipExtractorTest extends TestCase {

	private string $workDir;

	protected function setUp(): void {
		parent::setUp();
		$this->workDir = sys_get_temp_dir() . '/ivox_zip_test_' . bin2hex(random_bytes(8));
		mkdir($this->workDir, 0700, true);
	}

	protected function tearDown(): void {
		$this->removeTree($this->workDir);
		parent::tearDown();
	}

	private function removeTree(string $dir): void {
		if (!is_dir($dir)) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($items as $item) {
			$item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
		}
		@rmdir($dir);
	}

	private function extractor(): SafeZipExtractor {
		return new SafeZipExtractor($this->createMock(LoggerInterface::class));
	}

	/** @param array<string,string> $entries */
	private function makeZip(array $entries): ZipArchive {
		$path = $this->workDir . '/archive_' . bin2hex(random_bytes(4)) . '.zip';
		$zip = new ZipArchive();
		$this->assertTrue($zip->open($path, ZipArchive::CREATE) === true);
		foreach ($entries as $name => $content) {
			$zip->addFromString($name, $content);
		}
		$zip->close();

		$reopened = new ZipArchive();
		$this->assertTrue($reopened->open($path) === true);

		return $reopened;
	}

	private function destination(): string {
		$dest = $this->workDir . '/dest_' . bin2hex(random_bytes(4));
		mkdir($dest, 0700, true);

		return $dest;
	}

	public function testExtractsOrdinaryEntries(): void {
		$dest = $this->destination();
		$zip = $this->makeZip([
			'page.json' => '{"a":1}',
			'sub/nested.txt' => 'hello',
		]);

		$written = $this->extractor()->extract($zip, $dest);

		$this->assertSame(2, $written);
		$this->assertSame('{"a":1}', file_get_contents($dest . '/page.json'));
		$this->assertSame('hello', file_get_contents($dest . '/sub/nested.txt'));
	}

	/** @return array<string,array{0:string}> */
	public static function traversalNames(): array {
		return [
			'parent traversal' => ['../escaped.txt'],
			'deep traversal' => ['a/../../escaped.txt'],
			'absolute path' => ['/etc/evil.txt'],
			'windows separator' => ['..\\escaped.txt'],
			'drive letter' => ['C:/evil.txt'],
		];
	}

	/**
	 * The regression this class exists for: nothing may be written outside the
	 * destination directory.
	 *
	 * @dataProvider traversalNames
	 */
	public function testTraversalIsRefused(string $name): void {
		$dest = $this->destination();
		$zip = $this->makeZip([$name => 'pwned']);

		$this->expectException(\RuntimeException::class);

		try {
			$this->extractor()->extract($zip, $dest);
		} finally {
			$this->assertFileDoesNotExist(dirname($dest) . '/escaped.txt');
			$this->assertFileDoesNotExist($this->workDir . '/escaped.txt');
		}
	}

	public function testMacosxAndAppleDoubleEntriesAreSkipped(): void {
		$dest = $this->destination();
		$zip = $this->makeZip([
			'__MACOSX/junk.txt' => 'x',
			'._sidecar' => 'x',
			'real.txt' => 'keep',
		]);

		$written = $this->extractor()->extract($zip, $dest);

		$this->assertSame(1, $written);
		$this->assertFileExists($dest . '/real.txt');
		$this->assertFileDoesNotExist($dest . '/._sidecar');
	}

	/** Neither original extractor had any size ceiling at all. */
	public function testArchiveExceedingTheTotalSizeLimitIsRefused(): void {
		$dest = $this->destination();

		// Highly compressible, so the archive stays small while the declared
		// uncompressed total is large — exactly the zip-bomb shape.
		$oneMb = str_repeat('A', 1024 * 1024);
		$entries = [];
		for ($i = 0; $i < 12; $i++) {
			$entries["bomb_$i.txt"] = $oneMb;
		}
		$zip = $this->makeZip($entries);

		// Well under the real 500 MB ceiling, so shrink it for the test.
		$extractor = new class ($this->createMock(LoggerInterface::class)) extends SafeZipExtractor {
			public const MAX_TOTAL_SIZE = 5 * 1024 * 1024;
		};

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('too large when uncompressed');
		$extractor->extract($zip, $dest);
	}

	public function testArchiveWithTooManyEntriesIsRefused(): void {
		$dest = $this->destination();
		$entries = [];
		for ($i = 0; $i < 30; $i++) {
			$entries["f$i.txt"] = 'x';
		}
		$zip = $this->makeZip($entries);

		$extractor = new class ($this->createMock(LoggerInterface::class)) extends SafeZipExtractor {
			public const MAX_ENTRIES = 10;
		};

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('too many entries');
		$extractor->extract($zip, $dest);
	}

	/** Nothing is written before the size verdict. */
	public function testOversizedArchiveWritesNothing(): void {
		$dest = $this->destination();
		$entries = [];
		for ($i = 0; $i < 30; $i++) {
			$entries["f$i.txt"] = 'x';
		}
		$zip = $this->makeZip($entries);

		$extractor = new class ($this->createMock(LoggerInterface::class)) extends SafeZipExtractor {
			public const MAX_ENTRIES = 10;
		};

		try {
			$extractor->extract($zip, $dest);
			$this->fail('expected refusal');
		} catch (\RuntimeException) {
			$this->assertSame(
				['.', '..'],
				array_values(array_diff(scandir($dest) ?: [], [])),
				'the destination must still be empty'
			);
		}
	}

	public function testMissingDestinationIsRefused(): void {
		$zip = $this->makeZip(['a.txt' => 'x']);

		$this->expectException(\RuntimeException::class);
		$this->extractor()->extract($zip, $this->workDir . '/does-not-exist');
	}

	/** Directory entries are created, not written as files. */
	public function testDirectoryEntriesAreCreated(): void {
		$dest = $this->destination();
		$zip = $this->makeZip(['folder/' => '', 'folder/file.txt' => 'x']);

		$this->extractor()->extract($zip, $dest);

		$this->assertDirectoryExists($dest . '/folder');
		$this->assertFileExists($dest . '/folder/file.txt');
	}
}
