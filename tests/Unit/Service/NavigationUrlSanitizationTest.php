<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service;

use OCA\IntraVox\Service\NavigationService;
use OCA\IntraVox\Service\Sanitize\UrlSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Navigation URLs must survive a scheme allowlist, not just filter_var. (NAV-1)
 *
 * validateNavigationItems() used filter_var($url, FILTER_SANITIZE_URL), which
 * only strips characters that are ILLEGAL IN A URL. It does not look at the
 * scheme at all, so "javascript:alert(1)" passed through unchanged — and
 * "java\tscript:alert(1)" was actively normalised INTO a working payload by
 * having its tab removed.
 *
 * That matters because navigation.json is admin-editable and Navigation.vue
 * binds the value straight into :href (getItemUrl returns item.url verbatim),
 * so a javascript: URL is stored XSS firing on click for every visitor.
 *
 * The instance is built without the 8-arg constructor, in the house style of
 * the PageService tests: only urlSanitizer is needed for these paths.
 */
class NavigationUrlSanitizationTest extends TestCase {

	private function makeService(): NavigationService {
		$service = (new \ReflectionClass(NavigationService::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(NavigationService::class, 'urlSanitizer'))
			->setValue($service, new UrlSanitizer());

		return $service;
	}

	/** @return array<string,string> */
	public static function dangerousUrls(): array {
		return [
			'javascript' => ['javascript:alert(1)'],
			'javascript mixed case' => ['JaVaScRiPt:alert(1)'],
			// filter_var REMOVES the tab, turning this into a working payload.
			'javascript with tab' => ["java\tscript:alert(1)"],
			'javascript with leading space' => ['  javascript:alert(1)'],
			'javascript with newline' => ["\njavascript:alert(1)"],
			'data html' => ['data:text/html,<script>alert(1)</script>'],
			'vbscript' => ['vbscript:msgbox(1)'],
			'file' => ['file:///etc/passwd'],
		];
	}

	/** @return array<string,string> */
	public static function legitimateUrls(): array {
		return [
			'https' => ['https://example.com/page'],
			'http' => ['http://intranet.local'],
			'root relative' => ['/apps/intravox/page/home'],
			'anchor' => ['#section'],
			'mailto' => ['mailto:info@example.com'],
			'tel' => ['tel:+31612345678'],
			'sms' => ['sms:+31612345678'],
		];
	}

	/**
	 * @dataProvider dangerousUrls
	 * Fails on the pre-fix code: filter_var returns the payload unchanged.
	 */
	public function testDangerousSchemeIsRejectedOnSave(string $payload): void {
		$result = $this->validate([['title' => 'Evil', 'url' => $payload]]);

		$this->assertNull($result[0]['url'], "must not store: $payload");
	}

	/** @dataProvider legitimateUrls */
	public function testLegitimateUrlIsPreservedOnSave(string $url): void {
		$result = $this->validate([['title' => 'Fine', 'url' => $url]]);

		$this->assertSame($url, $result[0]['url']);
	}

	/**
	 * The read path matters just as much: navigation.json files written before
	 * the allowlist existed still hold their payloads, and getNavigation()
	 * returns them through normalizeNavigationItems() without ever re-saving.
	 *
	 * @dataProvider dangerousUrls
	 */
	public function testDangerousSchemeIsStrippedWhenReadingStoredNavigation(string $payload): void {
		$normalized = $this->makeService()->normalizeNavigationItems([
			['title' => 'Legacy', 'url' => $payload],
		]);

		$this->assertNull($normalized[0]['url'], "stored payload must not be served: $payload");
	}

	/** Nested items are rendered too, so the guard must recurse. */
	public function testDangerousSchemeIsStrippedInChildrenOnRead(): void {
		$normalized = $this->makeService()->normalizeNavigationItems([
			[
				'title' => 'Parent',
				'url' => '/ok',
				'children' => [
					['title' => 'Child', 'url' => 'javascript:alert(1)'],
					[
						'title' => 'Child2',
						'url' => 'https://example.com',
						'children' => [
							['title' => 'Grandchild', 'url' => 'data:text/html,<script>alert(1)</script>'],
						],
					],
				],
			],
		]);

		$this->assertSame('/ok', $normalized[0]['url']);
		$this->assertNull($normalized[0]['children'][0]['url']);
		$this->assertSame('https://example.com', $normalized[0]['children'][1]['url']);
		$this->assertNull($normalized[0]['children'][1]['children'][0]['url']);
	}

	/** A non-string url (array/int from hand-edited JSON) must not blow up. */
	public function testNonStringUrlBecomesNull(): void {
		$normalized = $this->makeService()->normalizeNavigationItems([
			['title' => 'Weird', 'url' => ['nested' => 'array']],
			['title' => 'Numeric', 'url' => 42],
		]);

		$this->assertNull($normalized[0]['url']);
		$this->assertNull($normalized[1]['url']);
	}

	/** An item without a url key keeps not having one. */
	public function testItemWithoutUrlIsUntouched(): void {
		$normalized = $this->makeService()->normalizeNavigationItems([
			['title' => 'Page link', 'uniqueId' => 'page-123'],
		]);

		$this->assertArrayNotHasKey('url', $normalized[0]);
		$this->assertSame('page-123', $normalized[0]['uniqueId']);
	}

	/** Drive the private write-path validator. */
	private function validate(array $items): array {
		$method = new \ReflectionMethod(NavigationService::class, 'validateNavigationItems');

		return $method->invoke($this->makeService(), $items, 1);
	}
}
