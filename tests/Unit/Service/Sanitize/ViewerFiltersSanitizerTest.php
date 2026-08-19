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
 * Round-trip guard for viewerFilters.
 *
 * A config key the sanitizer does not enumerate is dropped on the first save
 * with no error anywhere — the failure mode that silently ate showPagination
 * for several releases. viewerFilters has far more surface than that key did,
 * so it gets a test rather than a hope.
 */
class ViewerFiltersSanitizerTest extends TestCase {
	private function sanitize(array $raw, string $pattern = '/^[a-z][a-z0-9_]{0,63}$/i'): array {
		$sanitizer = new PageShapeSanitizer(
			$this->createMock(IConfig::class),
			$this->createMock(LoggerInterface::class),
			new HtmlSanitizer(),
			new UrlSanitizer(),
			new ColorSanitizer(),
		);

		return $sanitizer->sanitizeViewerFilters($raw, $pattern);
	}

	public function testFullConfigSurvivesRoundTrip(): void {
		$result = $this->sanitize([
			'enabled' => true,
			'facets' => [
				['field' => 'role', 'label' => 'Rol', 'limit' => 20, 'collapsed' => false],
				['field' => 'gebouw', 'label' => 'Gebouw', 'limit' => 10, 'collapsed' => true],
			],
			'searchFields' => ['displayName', 'role'],
			'searchEnabled' => true,
			'layout' => 'sidebar',
		]);

		$this->assertTrue($result['enabled']);
		$this->assertCount(2, $result['facets']);
		$this->assertSame('role', $result['facets'][0]['field']);
		$this->assertSame('Rol', $result['facets'][0]['label']);
		$this->assertSame(20, $result['facets'][0]['limit']);
		$this->assertFalse($result['facets'][0]['collapsed']);
		$this->assertTrue($result['facets'][1]['collapsed']);
		$this->assertSame(['displayName', 'role'], $result['searchFields']);
		$this->assertTrue($result['searchEnabled']);
		$this->assertSame('sidebar', $result['layout']);
	}

	public function testMissingConfigYieldsDisabledDefault(): void {
		foreach ([null, 'nonsense', 42, []] as $raw) {
			$result = $this->sanitize(is_array($raw) ? $raw : (array)[]);
			$this->assertFalse($result['enabled']);
			$this->assertSame([], $result['facets']);
		}
	}

	public function testBareFieldNamesAreAccepted(): void {
		$result = $this->sanitize([
			'enabled' => true,
			'facets' => ['role', 'gebouw'],
		]);

		$this->assertCount(2, $result['facets']);
		$this->assertSame('role', $result['facets'][0]['field']);
		$this->assertSame(8, $result['facets'][0]['limit'], 'a bare name gets the default limit');
	}

	public function testMalformedFieldNamesAreDropped(): void {
		$result = $this->sanitize([
			'enabled' => true,
			'facets' => ['role', '1nvalid', 'drop table', 'ok_field!', '', 'gebouw'],
		]);

		$this->assertSame(['role', 'gebouw'], array_column($result['facets'], 'field'));
	}

	public function testPhotoStoryPatternRestrictsToExifFields(): void {
		$result = $this->sanitize(
			['enabled' => true, 'facets' => ['exif_camera', 'role', 'exif_lens']],
			'/^exif_[a-z_]+$/'
		);

		$this->assertSame(['exif_camera', 'exif_lens'], array_column($result['facets'], 'field'));
	}

	public function testFacetCountIsCapped(): void {
		$fields = [];
		for ($i = 0; $i < 40; $i++) {
			$fields[] = 'field_' . $i;
		}

		$result = $this->sanitize(['enabled' => true, 'facets' => $fields]);

		$this->assertCount(12, $result['facets']);
	}

	public function testSearchFieldCountIsCapped(): void {
		$fields = [];
		for ($i = 0; $i < 30; $i++) {
			$fields[] = 'search_' . $i;
		}

		$result = $this->sanitize(['enabled' => true, 'searchFields' => $fields]);

		$this->assertCount(8, $result['searchFields']);
	}

	public function testLimitIsClamped(): void {
		$result = $this->sanitize([
			'enabled' => true,
			'facets' => [
				['field' => 'tiny', 'limit' => 1],
				['field' => 'huge', 'limit' => 9999],
			],
		]);

		$this->assertSame(5, $result['facets'][0]['limit']);
		$this->assertSame(100, $result['facets'][1]['limit']);
	}

	public function testLayoutFallsBackToSidebar(): void {
		$this->assertSame('sidebar', $this->sanitize(['layout' => 'wharrgarbl'])['layout']);
		$this->assertSame('top', $this->sanitize(['layout' => 'top'])['layout']);
		$this->assertSame('sidebar', $this->sanitize([])['layout']);
	}

	public function testEnabledRequiresLiteralTrue(): void {
		// A truthy string must not silently enable a viewer-facing feature.
		$this->assertFalse($this->sanitize(['enabled' => 'yes'])['enabled']);
		$this->assertFalse($this->sanitize(['enabled' => 1])['enabled']);
		$this->assertTrue($this->sanitize(['enabled' => true])['enabled']);
	}

	public function testDuplicateSearchFieldsAreCollapsed(): void {
		$result = $this->sanitize([
			'searchFields' => ['role', 'role', 'displayName'],
		]);

		$this->assertSame(['role', 'displayName'], array_values($result['searchFields']));
	}
}
