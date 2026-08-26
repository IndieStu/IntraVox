<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Controller;

use OCA\IntraVox\Controller\ApiController;
use PHPUnit\Framework\TestCase;

/**
 * Paging the listing is keyset, and survives the list changing underneath it.
 *
 * This is the whole reason D2 chose a cursor over limit/offset. An offset counts
 * rows, so a page created or deleted between two requests shifts everything after
 * it: the caller silently skips a row or receives one twice, and nothing in the
 * response admits it. The caller most likely to hit that — a migration walking a
 * large intranet — is also the least likely to notice.
 *
 * The cursor is the sort key of the last row served, (title, uniqueId), which is
 * the order listPages() now guarantees. The next page is everything strictly
 * greater. Comparing values rather than positions is what makes the mid-walk
 * mutation harmless, and that is what the tests below actually check — not that
 * paging returns the right count, but that it returns the right ROWS while the
 * data moves.
 *
 * Exercised through reflection on the private helper: instantiating ApiController
 * takes sixteen collaborators and none of them participate in this logic.
 */
class CursorPaginationTest extends TestCase {
    private ApiController $controller;
    private \ReflectionMethod $paged;

    protected function setUp(): void {
        parent::setUp();
        $class = new \ReflectionClass(ApiController::class);
        $this->controller = $class->newInstanceWithoutConstructor();
        $this->paged = $class->getMethod('pagedListing');
    }

    /** @param list<string> $titles */
    private function pages(array $titles): array {
        return array_map(
            static fn (string $t): array => ['title' => $t, 'uniqueId' => 'page-' . strtolower($t)],
            $titles
        );
    }

    private function page(array $pages, ?int $limit, ?string $cursor = null): array {
        return $this->paged->invoke($this->controller, $pages, $limit, $cursor)->getData();
    }

    public function testWalkingTheWholeListVisitsEveryRowExactlyOnce(): void {
        $all = $this->pages(['Anna', 'Bert', 'Carla', 'Dirk', 'Eva']);

        $seen = [];
        $cursor = null;
        for ($guard = 0; $guard < 10; $guard++) {
            $body = $this->page($all, 2, $cursor);
            foreach ($body['items'] as $item) {
                $seen[] = $item['title'];
            }
            $cursor = $body['nextCursor'];
            if ($cursor === null) {
                break;
            }
        }

        $this->assertSame(['Anna', 'Bert', 'Carla', 'Dirk', 'Eva'], $seen);
    }

    /**
     * The case an offset gets wrong.
     *
     * A row before the cursor disappears between two requests. With OFFSET the
     * whole tail shifts up by one and the caller never sees the row that moved
     * into the gap. With a keyset the answer does not depend on how many rows came
     * before.
     */
    public function testDeletingAnEarlierRowMidWalkDoesNotSkipAnything(): void {
        $all = $this->pages(['Anna', 'Bert', 'Carla', 'Dirk', 'Eva']);

        $first = $this->page($all, 2);
        $this->assertSame(['Anna', 'Bert'], array_column($first['items'], 'title'));

        // 'Anna' is deleted before the caller asks for the next page.
        $shrunk = array_values(array_filter($all, static fn (array $p): bool => $p['title'] !== 'Anna'));

        $second = $this->page($shrunk, 2, $first['nextCursor']);

        $this->assertSame(
            ['Carla', 'Dirk'],
            array_column($second['items'], 'title'),
            'Keyset paging must continue after Bert regardless of what happened before it'
        );
    }

    public function testInsertingARowBeforeTheCursorDoesNotRepeatAnything(): void {
        $all = $this->pages(['Bert', 'Carla', 'Dirk']);

        $first = $this->page($all, 1);
        $this->assertSame(['Bert'], array_column($first['items'], 'title'));

        $grown = $this->pages(['Anna', 'Bert', 'Carla', 'Dirk']);
        $second = $this->page($grown, 2, $first['nextCursor']);

        $this->assertSame(['Carla', 'Dirk'], array_column($second['items'], 'title'));
        $this->assertNotContains('Anna', array_column($second['items'], 'title'));
    }

    /** Deleting the very row the cursor names must not strand the walk. */
    public function testDeletingTheCursorRowItselfIsHarmless(): void {
        $all = $this->pages(['Anna', 'Bert', 'Carla']);
        $first = $this->page($all, 1);

        $without = array_values(array_filter($all, static fn (array $p): bool => $p['title'] !== 'Anna'));
        $second = $this->page($without, 5, $first['nextCursor']);

        $this->assertSame(['Bert', 'Carla'], array_column($second['items'], 'title'));
    }

    public function testTheLastPageReportsNoMore(): void {
        $body = $this->page($this->pages(['Anna', 'Bert']), 10);

        $this->assertFalse($body['hasMore']);
        $this->assertNull($body['nextCursor'], 'A finished walk must terminate a while-nextCursor loop');
    }

    public function testAnExplicitPageSizeCannotExceedTheCeiling(): void {
        $many = $this->pages(array_map(static fn (int $i): string => sprintf('P%04d', $i), range(1, 600)));

        $body = $this->page($many, 5000);

        $this->assertCount(500, $body['items'], 'Paging must not become a way around the listing cap');
    }

    public function testAGarbledCursorIsRefusedRatherThanIgnored(): void {
        $response = $this->paged->invoke($this->controller, $this->pages(['Anna']), 10, 'not-a-cursor!!');

        $this->assertSame(400, $response->getStatus());
    }
}
