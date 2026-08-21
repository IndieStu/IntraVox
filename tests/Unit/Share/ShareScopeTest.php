<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Share;

use OCA\IntraVox\Share\ShareScope;
use PHPUnit\Framework\TestCase;

/**
 * The share scope parse, which used to be copied into four endpoints. (F6)
 *
 * The rules decide how much of the tree an anonymous visitor sees, so they are
 * pinned here rather than left to four copies that can drift apart.
 */
class ShareScopeTest extends TestCase {

	public function testStripsTheFilesPrefix(): void {
		$scope = ShareScope::fromScopePath('files/nl/afdeling');

		$this->assertNotNull($scope);
		$this->assertSame('nl', $scope->language);
		$this->assertSame('afdeling', $scope->relativePath);
		$this->assertSame('nl/afdeling', $scope->scopePath);
	}

	public function testWorksWithoutTheFilesPrefix(): void {
		$scope = ShareScope::fromScopePath('nl/afdeling/hr');

		$this->assertNotNull($scope);
		$this->assertSame('nl', $scope->language);
		$this->assertSame('afdeling/hr', $scope->relativePath);
	}

	public function testALanguageRootShareHasNoRelativePath(): void {
		$scope = ShareScope::fromScopePath('files/en');

		$this->assertNotNull($scope);
		$this->assertSame('en', $scope->language);
		$this->assertSame('', $scope->relativePath);
		$this->assertTrue($scope->isLanguageRoot());
	}

	public function testASectionShareIsNotALanguageRoot(): void {
		$this->assertFalse(ShareScope::fromScopePath('files/en/docs')->isLanguageRoot());
	}

	/** Three-letter codes are real language folders too. */
	public function testAcceptsAThreeLetterLanguageCode(): void {
		$scope = ShareScope::fromScopePath('files/nds/pagina');

		$this->assertNotNull($scope);
		$this->assertSame('nds', $scope->language);
	}

	/** @return array<string,array{0:?string}> */
	public static function unusableScopes(): array {
		return [
			'null' => [null],
			'empty' => [''],
			'only the prefix' => ['files/'],
			'only slashes' => ['///'],
			'one-letter language' => ['files/n/page'],
			'four-letter language' => ['files/abcd/page'],
		];
	}

	/**
	 * Anything that does not name a usable language folder yields null, and every
	 * caller turns that into "show nothing".
	 *
	 * @dataProvider unusableScopes
	 */
	public function testUnusableScopeYieldsNull(?string $path): void {
		$this->assertNull(ShareScope::fromScopePath($path));
	}

	/** Trailing slashes must not produce an empty trailing segment. */
	public function testTolerantOfSurroundingSlashes(): void {
		$scope = ShareScope::fromScopePath('files/nl/afdeling/');

		$this->assertNotNull($scope);
		$this->assertSame('nl', $scope->language);
		$this->assertSame('afdeling', $scope->relativePath);
	}

	/**
	 * The old inline parse: this is what the four copies computed, and the value
	 * object must agree with it on every input they could see.
	 */
	public function testMatchesTheInlineParseItReplaces(): void {
		$inputs = [
			'files/nl', 'files/nl/afdeling', 'files/nl/afdeling/hr',
			'nl', 'nl/afdeling', 'files/en/docs', 'files/nds/x',
			'files/n/page', 'files/abcd/page', 'files/', '',
		];

		foreach ($inputs as $input) {
			// Verbatim reproduction of the code that lived in ApiController.
			$relPath = $input;
			if (str_starts_with($relPath, 'files/')) {
				$relPath = substr($relPath, 6);
			}
			$segments = explode('/', $relPath);
			$legacyLanguage = $segments[0] ?? null;
			$legacyOk = $legacyLanguage !== null
				&& strlen($legacyLanguage) >= 2
				&& strlen($legacyLanguage) <= 3;

			$scope = ShareScope::fromScopePath($input);

			$this->assertSame(
				$legacyOk,
				$scope !== null,
				"accept/reject differs from the old parse for: " . var_export($input, true)
			);

			if ($legacyOk) {
				$this->assertSame($legacyLanguage, $scope->language, "language differs for: $input");
			}
		}
	}
}
