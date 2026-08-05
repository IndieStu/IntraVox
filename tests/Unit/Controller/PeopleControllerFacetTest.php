<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Controller;

use OCA\IntraVox\Controller\PeopleController;
use OCA\IntraVox\Service\PublicShareService;
use OCA\IntraVox\Service\UserService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Controller-level guarantees for viewer-side faceting.
 *
 * The public-share assertions are the important ones: a facet panel on a
 * share link is a browsable directory of the organisation, so the endpoint
 * must refuse the parameters outright rather than depending on the frontend
 * not to send them.
 */
class PeopleControllerFacetTest extends TestCase {
	private UserService $userService;
	private PublicShareService $publicShareService;
	private PeopleController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->userService = $this->createMock(UserService::class);
		$this->publicShareService = $this->createMock(PublicShareService::class);

		$this->controller = new PeopleController(
			'intravox',
			$this->createMock(IRequest::class),
			$this->userService,
			$this->publicShareService,
			$this->createMock(LoggerInterface::class)
		);
	}

	private function emptyResult(): array {
		return [
			'users' => [],
			'total' => 0,
			'hasMore' => false,
			'facets' => [],
			'meta' => ['approximate' => false, 'scanned' => 0, 'cap' => 5000],
		];
	}

	public function testFacetParamsReachTheServiceWhenLoggedIn(): void {
		$this->userService->expects($this->once())
			->method('queryFaceted')
			->with(
				$this->anything(),
				'AND',
				$this->callback(static function (array $refinements): bool {
					return count($refinements) === 1
						&& $refinements[0]['field'] === 'role'
						&& $refinements[0]['value'] === ['Manager'];
				}),
				['role', 'gebouw'],
				'jansen'
			)
			->willReturn($this->emptyResult());

		$response = $this->controller->getPeople(
			null,
			'[]',
			'AND',
			'displayName',
			'asc',
			50,
			0,
			'[{"field":"role","op":"in","value":["Manager"]}]',
			'role,gebouw',
			'jansen'
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	/**
	 * Decision: no viewer filtering on public shares. Enforced by test rather
	 * than by hope — getPeopleByShare() delegates to getPeople(), so without
	 * an explicit null-out this would leak the moment someone appends the
	 * query parameters by hand.
	 */
	public function testPublicShareIgnoresRefineFacetsAndQuery(): void {
		// A request that DOES carry viewer parameters. Without this the mocked
		// IRequest returns null for everything, and the test would pass even
		// if getPeopleByShare() forwarded whatever it was given — verified by
		// mutation.
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn(string $key, $default = null) => match ($key) {
				'refine' => '[{"field":"role","op":"in","value":["Manager"]}]',
				'facets' => 'role,gebouw',
				'q' => 'jansen',
				'searchFields' => 'displayName',
				default => $default,
			}
		);

		$controller = new PeopleController(
			'intravox',
			$request,
			$this->userService,
			$this->publicShareService,
			$this->createMock(LoggerInterface::class)
		);

		$this->publicShareService->method('getShareByToken')->willReturn($this->createMock(\OCP\Share\IShare::class));

		// The faceted path must never be entered.
		$this->userService->expects($this->never())->method('queryFaceted');
		$this->userService->method('getUsersByFilters')->willReturn(['users' => [], 'total' => 0]);

		$response = $controller->getPeopleByShare(
			'sometoken',
			null,
			'[{"fieldName":"gebouw","operator":"equals","value":"Noord"}]',
			'AND',
			'displayName',
			'asc',
			50,
			0
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		$this->assertArrayNotHasKey('facets', $data, 'a public share must not return facets');
	}

	public function testPublicShareStillServesItsEditorConfiguredList(): void {
		$this->publicShareService->method('getShareByToken')->willReturn($this->createMock(\OCP\Share\IShare::class));
		$this->userService->method('getUsersByFilters')->willReturn([
			'users' => [['uid' => 'u1', 'displayName' => 'Anne']],
			'total' => 1,
		]);

		$response = $this->controller->getPeopleByShare(
			'sometoken',
			null,
			'[{"fieldName":"gebouw","operator":"equals","value":"Noord"}]'
		);

		$data = $response->getData();
		$this->assertSame(1, $data['total']);
		$this->assertCount(1, $data['users']);
	}

	public function testInvalidRefineJsonIsRejected(): void {
		$response = $this->controller->getPeople(
			null, '[]', 'AND', 'displayName', 'asc', 50, 0,
			'{not json',
			'role'
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testOversizedRefinePayloadIsRejected(): void {
		$huge = json_encode(array_fill(0, 5000, ['field' => 'role', 'op' => 'in', 'value' => str_repeat('x', 50)]));

		$response = $this->controller->getPeople(
			null, '[]', 'AND', 'displayName', 'asc', 50, 0,
			$huge,
			'role'
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	/**
	 * An operator we do not recognise must drop the row, never widen the set.
	 */
	public function testUnknownRefineOperatorIsDropped(): void {
		$this->userService->expects($this->once())
			->method('queryFaceted')
			->with(
				$this->anything(),
				$this->anything(),
				$this->identicalTo([]),
				$this->anything()
			)
			->willReturn($this->emptyResult());

		$this->controller->getPeople(
			null, '[]', 'AND', 'displayName', 'asc', 50, 0,
			'[{"field":"role","op":"drop_table","value":"x"}]',
			'role'
		);
	}

	public function testMalformedFacetFieldNamesAreDropped(): void {
		$this->userService->expects($this->once())
			->method('queryFaceted')
			->with(
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->identicalTo(['role'])
			)
			->willReturn($this->emptyResult());

		$this->controller->getPeople(
			null, '[]', 'AND', 'displayName', 'asc', 50, 0,
			null,
			'role, 1nvalid, drop table, ok_field!'
		);
	}

	public function testRefinementCountIsCapped(): void {
		$rows = [];
		for ($i = 0; $i < 40; $i++) {
			$rows[] = ['field' => 'f' . $i, 'op' => 'in', 'value' => ['x']];
		}

		$this->userService->expects($this->once())
			->method('queryFaceted')
			->with(
				$this->anything(),
				$this->anything(),
				$this->callback(static fn(array $r): bool => count($r) <= 12),
				$this->anything()
			)
			->willReturn($this->emptyResult());

		$this->controller->getPeople(
			null, '[]', 'AND', 'displayName', 'asc', 50, 0,
			json_encode($rows),
			'role'
		);
	}

	/**
	 * Manual selection is an explicit list of users; there is nothing to
	 * facet over, so that path must stay on its original code.
	 */
	public function testManualModeIsUnaffectedByFacetParams(): void {
		$this->userService->expects($this->never())->method('queryFaceted');
		$this->userService->method('getUserProfiles')->willReturn([['uid' => 'u1', 'displayName' => 'Anne']]);

		$response = $this->controller->getPeople(
			'u1', null, 'AND', 'displayName', 'asc', 50, 0,
			'[{"field":"role","op":"in","value":["Manager"]}]',
			'role'
		);

		$this->assertSame(1, $response->getData()['total']);
	}

	/**
	 * Without any viewer parameters the endpoint must behave exactly as it
	 * did before this feature existed.
	 */
	public function testLegacyFilterModeIsUnchanged(): void {
		$this->userService->expects($this->never())->method('queryFaceted');
		$this->userService->expects($this->once())
			->method('getUsersByFilters')
			->willReturn(['users' => [['uid' => 'u1']], 'total' => 1]);

		$response = $this->controller->getPeople(
			null,
			'[{"fieldName":"role","operator":"equals","value":"Manager"}]'
		);

		$data = $response->getData();
		$this->assertSame(1, $data['total']);
		$this->assertArrayNotHasKey('facets', $data);
	}
}
