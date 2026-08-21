<?php
declare(strict_types=1);

namespace OCA\IntraVox\Service\Import;

use Psr\Log\LoggerInterface;
use ZipArchive;

/**
 * The one place a ZIP is unpacked. (ZIP-1)
 *
 * There used to be two near-identical extractors — ImportService::safeExtractZip
 * and ConfluenceHtmlImporter::extractZip — with the same ZIP-Slip check written
 * out twice. They had already drifted (different permissions, different cleanup,
 * different skip rules), and a fix to one would not reach the other. Both are now
 * thin callers of this class.
 *
 * Protections, which are the union of what the two had plus the gaps both left:
 *
 *   - ZIP Slip (CWE-22): every entry's resolved parent directory must sit inside
 *     the destination. Checked with realpath() AFTER creating the parent, so
 *     symlinked paths resolve before comparison.
 *   - Absolute paths and Windows-style traversal are refused up front, before
 *     any directory is created.
 *   - Zip bombs: both a per-entry and a total uncompressed-size ceiling, read
 *     from the central directory (statIndex) so nothing is written to disk
 *     before the decision. Neither old extractor had ANY size limit; a 42 KB
 *     bomb could fill the volume.
 *   - Entry count, for the same reason.
 *   - Restrictive permissions (0700), the stricter of the two originals.
 */
class SafeZipExtractor {

	// Referenced as static:: throughout, so a subclass can tighten these — self::
	// would bind here at compile time and silently ignore the override.

	/** A single entry may not exceed this once decompressed. */
	public const MAX_ENTRY_SIZE = 104857600; // 100 MB

	/** Nor may the archive as a whole. */
	public const MAX_TOTAL_SIZE = 524288000; // 500 MB

	/** Archives with more entries than this are refused. */
	public const MAX_ENTRIES = 10000;

	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Extract $zip into $destDir, which must already exist.
	 *
	 * @return int the number of files written
	 * @throws \RuntimeException on traversal, an oversized archive or a write failure
	 */
	public function extract(ZipArchive $zip, string $destDir): int {
		$root = realpath($destDir);
		if ($root === false) {
			throw new \RuntimeException('Destination directory does not exist: ' . $destDir);
		}
		$root = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

		if ($zip->numFiles > static::MAX_ENTRIES) {
			$this->logger->error('[SafeZipExtractor] archive refused: too many entries', [
				'entries' => $zip->numFiles,
				'limit' => static::MAX_ENTRIES,
			]);
			throw new \RuntimeException('Archive contains too many entries');
		}

		$this->assertUncompressedSizeIsSane($zip);

		$written = 0;
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = (string)$zip->getNameIndex($i);

			if ($this->shouldSkip($name)) {
				continue;
			}

			$this->assertPathShapeIsSafe($name);

			$target = $root . $name;

			if (str_ends_with($name, '/')) {
				$this->ensureDirectory($target, $name);
				continue;
			}

			$parent = dirname($target);
			$this->ensureDirectory($parent, $name);
			$this->assertWithinRoot($parent, $root, $name, $target);

			$content = $zip->getFromIndex($i);
			if ($content === false) {
				$this->logger->warning('[SafeZipExtractor] unreadable entry skipped', ['name' => $name]);
				continue;
			}

			if (file_put_contents($target, $content) === false) {
				throw new \RuntimeException('Failed to write file: ' . $name);
			}

			$written++;
		}

		return $written;
	}

	/**
	 * Entries that are noise rather than content. Kept identical to what the two
	 * original extractors skipped between them.
	 */
	private function shouldSkip(string $name): bool {
		if ($name === '' || $name === '/') {
			return true;
		}

		if (str_contains($name, '__MACOSX')) {
			return true;
		}

		// AppleDouble sidecars, both as a bare name and inside a directory.
		return str_starts_with($name, '._') || str_starts_with(basename($name), '._');
	}

	/**
	 * Reject a path before it is used to build anything. realpath() cannot help
	 * here: an absolute path or a drive letter must never get as far as mkdir().
	 */
	private function assertPathShapeIsSafe(string $name): void {
		$suspicious = str_starts_with($name, '/')
			|| str_starts_with($name, '\\')
			|| str_contains($name, '\\')          // Windows-style separators
			|| str_contains($name, "\0")
			|| preg_match('#^[a-zA-Z]:#', $name) === 1
			|| $name === '..'
			|| str_starts_with($name, '../')
			|| str_contains($name, '/../');

		if ($suspicious) {
			$this->logger->error('[SafeZipExtractor] rejected entry path', ['name' => $name]);
			throw new \RuntimeException('Zip Slip detected: Invalid path in ZIP file');
		}
	}

	/**
	 * The decisive check: where the parent directory ACTUALLY resolves to must be
	 * inside the destination. Runs after mkdir() so that a symlink planted by an
	 * earlier entry is followed and caught here.
	 */
	private function assertWithinRoot(string $parent, string $root, string $name, string $target): void {
		$realParent = realpath($parent);

		if ($realParent === false
			|| !str_starts_with(rtrim($realParent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, $root)
		) {
			$this->logger->error('[SafeZipExtractor] ZIP Slip attack detected', [
				'name' => $name,
				'target' => $target,
				'realParent' => $realParent,
				'root' => $root,
			]);
			throw new \RuntimeException('Zip Slip detected: Invalid path in ZIP file');
		}
	}

	private function ensureDirectory(string $path, string $name): void {
		if (is_dir($path)) {
			return;
		}

		if (!mkdir($path, 0700, true) && !is_dir($path)) {
			throw new \RuntimeException('Failed to create directory for: ' . $name);
		}
	}

	/**
	 * Read the declared uncompressed sizes from the central directory. This is
	 * metadata, so it costs nothing and — crucially — happens before a single
	 * byte is written. A zip bomb declares its true size here; an archive that
	 * lies gets caught by MAX_ENTRY_SIZE on the data it actually produces.
	 */
	private function assertUncompressedSizeIsSane(ZipArchive $zip): void {
		$total = 0;

		for ($i = 0; $i < $zip->numFiles; $i++) {
			$stat = $zip->statIndex($i);
			if ($stat === false) {
				continue;
			}

			$size = (int)($stat['size'] ?? 0);

			if ($size > static::MAX_ENTRY_SIZE) {
				$this->logger->error('[SafeZipExtractor] archive refused: entry too large', [
					'name' => $stat['name'] ?? '?',
					'size' => $size,
					'limit' => static::MAX_ENTRY_SIZE,
				]);
				throw new \RuntimeException('Archive contains an oversized entry');
			}

			$total += $size;

			if ($total > static::MAX_TOTAL_SIZE) {
				$this->logger->error('[SafeZipExtractor] archive refused: uncompressed total too large', [
					'total' => $total,
					'limit' => static::MAX_TOTAL_SIZE,
				]);
				throw new \RuntimeException('Archive is too large when uncompressed');
			}
		}
	}
}
