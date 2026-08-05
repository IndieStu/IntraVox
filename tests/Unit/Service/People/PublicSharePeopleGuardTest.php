<?php

declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service\People;

use OCA\IntraVox\Service\People\PublicSharePeopleGuard;
use PHPUnit\Framework\TestCase;

/**
 * A People widget that survives in an overlooked container is the entire
 * leak this guard exists to prevent, so every place a widget can live is
 * asserted separately rather than trusting one representative case.
 */
class PublicSharePeopleGuardTest extends TestCase {
	private function page(array $layout): array {
		return ['uniqueId' => 'p1', 'title' => 'Docs', 'layout' => $layout];
	}

	private function widgets(array $result, string $path): array {
		$layout = $result['page']['layout'];

		return match ($path) {
			'row0' => $layout['rows'][0]['widgets'],
			'row1' => $layout['rows'][1]['widgets'],
			'left' => $layout['sideColumns']['left']['widgets'],
			'right' => $layout['sideColumns']['right']['widgets'],
			'header' => $layout['headerRow']['widgets'],
		};
	}

	public function testRemovesPeopleWidgetFromARow(): void {
		$result = PublicSharePeopleGuard::strip($this->page([
			'rows' => [
				['widgets' => [
					['type' => 'text', 'id' => 'w1'],
					['type' => 'people', 'id' => 'w2'],
					['type' => 'image', 'id' => 'w3'],
				]],
			],
		]));

		$this->assertSame(1, $result['removed']);
		$this->assertSame(['text', 'image'], array_column($this->widgets($result, 'row0'), 'type'));
	}

	public function testRemovesFromBothSideColumns(): void {
		$result = PublicSharePeopleGuard::strip($this->page([
			'sideColumns' => [
				'left' => ['widgets' => [['type' => 'people'], ['type' => 'links']]],
				'right' => ['widgets' => [['type' => 'people']]],
			],
		]));

		$this->assertSame(2, $result['removed']);
		$this->assertSame(['links'], array_column($this->widgets($result, 'left'), 'type'));
		$this->assertSame([], $this->widgets($result, 'right'));
	}

	public function testRemovesFromHeaderRow(): void {
		$result = PublicSharePeopleGuard::strip($this->page([
			'headerRow' => ['enabled' => true, 'widgets' => [['type' => 'people'], ['type' => 'heading']]],
		]));

		$this->assertSame(1, $result['removed']);
		$this->assertSame(['heading'], array_column($this->widgets($result, 'header'), 'type'));
	}

	public function testRemovesFromEveryContainerAtOnce(): void {
		$result = PublicSharePeopleGuard::strip($this->page([
			'headerRow' => ['widgets' => [['type' => 'people']]],
			'rows' => [
				['widgets' => [['type' => 'people'], ['type' => 'text']]],
				['widgets' => [['type' => 'people']]],
			],
			'sideColumns' => [
				'left' => ['widgets' => [['type' => 'people']]],
				'right' => ['widgets' => [['type' => 'people']]],
			],
		]));

		$this->assertSame(5, $result['removed'], 'a widget left in any container is the leak');

		$json = json_encode($result['page']);
		$this->assertStringNotContainsString('"people"', $json);
	}

	/**
	 * Gaps in the array would serialise to a JSON object rather than a list
	 * and break the frontend's v-for.
	 */
	public function testRemainingWidgetsAreReindexed(): void {
		$result = PublicSharePeopleGuard::strip($this->page([
			'rows' => [
				['widgets' => [
					['type' => 'people'],
					['type' => 'text'],
					['type' => 'people'],
					['type' => 'image'],
				]],
			],
		]));

		$widgets = $this->widgets($result, 'row0');

		$this->assertSame([0, 1], array_keys($widgets));
		$this->assertStringContainsString('[', json_encode($widgets));
	}

	public function testPageWithoutPeopleWidgetsIsUntouched(): void {
		$page = $this->page([
			'rows' => [['widgets' => [['type' => 'text'], ['type' => 'file-story']]]],
		]);

		$result = PublicSharePeopleGuard::strip($page);

		$this->assertSame(0, $result['removed']);
		$this->assertSame($page, $result['page']);
	}

	public function testOtherWidgetTypesAreNotAffected(): void {
		$result = PublicSharePeopleGuard::strip($this->page([
			'rows' => [['widgets' => [
				['type' => 'news'],
				['type' => 'calendar'],
				['type' => 'photo-story'],
				['type' => 'file-story'],
				['type' => 'feed'],
			]]],
		]));

		$this->assertSame(0, $result['removed']);
		$this->assertCount(5, $this->widgets($result, 'row0'));
	}

	public function testMalformedLayoutsDoNotThrow(): void {
		foreach ([
			[],
			['layout' => null],
			['layout' => 'nonsense'],
			['layout' => ['rows' => 'nope']],
			['layout' => ['rows' => [null, 'bad']]],
			['layout' => ['sideColumns' => ['left' => 'bad']]],
			['layout' => ['headerRow' => 42]],
		] as $page) {
			$result = PublicSharePeopleGuard::strip(is_array($page) ? $page : []);
			$this->assertIsArray($result['page']);
			$this->assertIsInt($result['removed']);
		}
	}

	public function testCountReportsWithoutMutating(): void {
		$page = $this->page([
			'rows' => [['widgets' => [['type' => 'people'], ['type' => 'people'], ['type' => 'text']]]],
		]);

		$this->assertSame(2, PublicSharePeopleGuard::countGuardedWidgets($page));
		// The caller's array is untouched — strip() returns a copy.
		$this->assertCount(3, $page['layout']['rows'][0]['widgets']);
	}

	public function testPageMetadataSurvives(): void {
		$result = PublicSharePeopleGuard::strip($this->page([
			'rows' => [['widgets' => [['type' => 'people']]]],
			'columns' => 2,
		]));

		$this->assertSame('p1', $result['page']['uniqueId']);
		$this->assertSame('Docs', $result['page']['title']);
		$this->assertSame(2, $result['page']['layout']['columns']);
	}
}
