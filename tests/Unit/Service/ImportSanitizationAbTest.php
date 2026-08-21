<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service;

use OCA\IntraVox\Service\Sanitize\ColorSanitizer;
use OCA\IntraVox\Service\Sanitize\HtmlSanitizer;
use OCA\IntraVox\Service\Sanitize\PageShapeSanitizer;
use OCA\IntraVox\Service\Sanitize\UrlSanitizer;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A/B the page sanitizer over the real demo data. (IMP-SAN)
 *
 * IMP-SAN routes imported page bodies through validateAndSanitizePage(), which
 * is a strict WHITELIST: any key it does not know is dropped. That is the point
 * on a hostile import, but it is also the risk — if the whitelist is narrower
 * than the shape real pages actually use, importing a legitimate export would
 * silently destroy content.
 *
 * The plan calls for exactly this check whenever stored JSON is touched: run
 * the transform over the 78 demo pages and assert nothing is lost. This is the
 * precedent from PR-20, which caught a bug all 534 unit tests missed — and it
 * earned its keep again here: the first version of IMP-SAN dropped `language`
 * from all 78 pages and `isTemplate` + `description` from all seven templates,
 * which is what makes a template a template.
 *
 * The comparison is deep-equality, not byte-equality: validateAndSanitizePage()
 * REBUILDS the array, so 'title' ends up before 'uniqueId' and the JSON bytes
 * differ while the data is identical. Key order in a page file is not
 * meaningful — nothing reads these by position.
 */
class ImportSanitizationAbTest extends TestCase {

	private function sanitizer(): PageShapeSanitizer {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);
		$config->method('getSystemValue')->willReturnArgument(1);

		return new PageShapeSanitizer(
			$config,
			$this->createMock(LoggerInterface::class),
			new HtmlSanitizer($config),
			new UrlSanitizer(),
			new ColorSanitizer(),
		);
	}

	/**
	 * The real safety property. The sanitizer NORMALISES as well as strips: it
	 * HTML-escapes text ("A & B" -> "A &amp; B"), clamps out-of-range values
	 * (columns: 5 -> 4) and drops empty optional fields. Those are all correct,
	 * but they mean a round trip is not byte-identical.
	 *
	 * What must hold is that applying it twice equals applying it once. If it
	 * were not idempotent, "&" would become "&amp;" then "&amp;amp;", and every
	 * export/import cycle would visibly corrupt the text on the page.
	 */
	public function testSanitizingIsIdempotentAcrossTheDemoCorpus(): void {
		$sanitizer = $this->sanitizer();
		$notIdempotent = [];
		$checked = 0;

		foreach ($this->demoPageFiles() as $file) {
			$original = json_decode((string)file_get_contents($file), true);
			if (!is_array($original) || !isset($original['layout'])) {
				continue;
			}

			$checked++;
			$once = $this->sanitizeAsImportDoes($sanitizer, $original);
			$twice = $this->sanitizeAsImportDoes($sanitizer, $once);

			if ($this->normalize($once) !== $this->normalize($twice)) {
				$notIdempotent[] = basename(dirname($file)) . '/' . basename($file);
			}
		}

		$this->assertGreaterThan(50, $checked);
		$this->assertSame(
			[],
			$notIdempotent,
			"Re-importing an already-imported page must not change it again:\n"
			. implode("\n", $notIdempotent)
		);
	}

	/** @return list<string> */
	private function demoPageFiles(): array {
		$root = \dirname(__DIR__, 3) . '/demo-data';
		if (!is_dir($root)) {
			return [];
		}

		$files = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
		foreach ($iterator as $entry) {
			if (!$entry->isFile() || $entry->getExtension() !== 'json') {
				continue;
			}
			// Only page bodies: navigation/footer/manifest have their own shapes.
			if (in_array($entry->getFilename(), ['navigation.json', 'footer.json', 'manifest.json'], true)) {
				continue;
			}
			$files[] = $entry->getPathname();
		}

		sort($files);
		return $files;
	}

	/**
	 * Mirrors ImportService::sanitizeImportedContent(): the body goes through the
	 * whitelist, the structural fields import owns are carried across. Kept in
	 * step with that method by testImportServiceUsesTheSamePreservedFields below.
	 *
	 * @param array<string,mixed> $content
	 * @return array<string,mixed>
	 */
	private function sanitizeAsImportDoes(PageShapeSanitizer $sanitizer, array $content): array {
		$sanitized = $sanitizer->validateAndSanitizePage($content);

		if (isset($content['language']) && is_string($content['language'])
			&& preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $content['language'])
		) {
			$sanitized['language'] = $content['language'];
		}

		if (isset($content['isTemplate'])) {
			$sanitized['isTemplate'] = (bool)$content['isTemplate'];
		}

		if (isset($content['description']) && is_string($content['description'])) {
			$sanitized['description'] = mb_substr(strip_tags($content['description']), 0, 500);
		}

		foreach (['created', 'modified'] as $timestampField) {
			if (isset($content[$timestampField]) && is_int($content[$timestampField])) {
				$sanitized[$timestampField] = $content[$timestampField];
			}
		}

		foreach (['createdBy', 'sourcePageId'] as $idField) {
			if (isset($content[$idField]) && is_string($content[$idField])) {
				$sanitized[$idField] = mb_substr(strip_tags($content[$idField]), 0, 128);
			}
		}

		return $sanitized;
	}

	/**
	 * Recursively sort keys so two structures compare equal regardless of the
	 * order the sanitizer happens to rebuild them in.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private function normalize(mixed $value): mixed {
		if (!is_array($value)) {
			return $value;
		}

		$normalized = array_map(fn ($item) => $this->normalize($item), $value);

		// Only associative arrays get sorted; list order (rows, widgets) is real.
		if (!array_is_list($normalized)) {
			ksort($normalized);
		}

		return $normalized;
	}

	/**
	 * No page may lose a structural field or a widget. This is the check that
	 * caught the first version of IMP-SAN destroying `language`, `isTemplate`
	 * and `description`.
	 *
	 * Deliberately not a byte or deep comparison: the sanitizer legitimately
	 * normalises (HTML-escapes text, clamps columns to the 1-4 range, drops
	 * empty optional strings). Those are corrections, not losses. What would be
	 * a bug is content disappearing, so that is what is asserted — every
	 * top-level key survives, and every row keeps every widget.
	 */
	public function testSanitizingEveryDemoPagePreservesItsContent(): void {
		$sanitizer = $this->sanitizer();
		$files = $this->demoPageFiles();

		$this->assertNotEmpty($files, 'demo-data should contain page JSON');

		$losses = [];
		$compared = 0;

		foreach ($files as $file) {
			$original = json_decode((string)file_get_contents($file), true);
			if (!is_array($original) || !isset($original['layout'])) {
				continue; // not a page body
			}

			$compared++;
			$sanitized = $this->sanitizeAsImportDoes($sanitizer, $original);
			$name = basename(dirname($file)) . '/' . basename($file);

			$droppedKeys = array_diff(array_keys($original), array_keys($sanitized));
			if ($droppedKeys !== []) {
				$losses[] = $name . ': lost top-level ' . implode(', ', $droppedKeys);
			}

			$before = $this->widgetTypesPerRow($original);
			$after = $this->widgetTypesPerRow($sanitized);
			if ($before !== $after) {
				$losses[] = sprintf('%s: widgets changed %s -> %s', $name, json_encode($before), json_encode($after));
			}
		}

		$this->assertGreaterThan(50, $compared, 'expected the full demo corpus');
		$this->assertSame([], $losses, "Import must not drop content:\n" . implode("\n", $losses));
	}

	/**
	 * The widget type of every widget, per row — a fingerprint of the page body
	 * that ignores cosmetic normalisation but changes the moment a widget is
	 * dropped or reordered.
	 *
	 * @param array<string,mixed> $page
	 * @return list<list<string>>
	 */
	private function widgetTypesPerRow(array $page): array {
		$rows = [];
		foreach ($page['layout']['rows'] ?? [] as $row) {
			$types = [];
			foreach ($row['widgets'] ?? [] as $widget) {
				$types[] = (string)($widget['type'] ?? '?');
			}
			$rows[] = $types;
		}

		return $rows;
	}

}
