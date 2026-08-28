<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service;

use OCA\IntraVox\Service\PretixService;
use PHPUnit\Framework\TestCase;

final class PretixServiceTest extends TestCase {
    public function testSelectsNearestFutureActiveOccurrenceAndIgnoresPastAndInactive(): void {
        $now = new \DateTimeImmutable('2026-08-28T10:00:00+02:00');
        $result = PretixService::selectNextOccurrence([
            ['id' => 1, 'active' => true, 'is_public' => true, 'date_from' => '2026-08-27T09:00:00+02:00'],
            ['id' => 2, 'active' => false, 'is_public' => true, 'date_from' => '2026-08-29T09:00:00+02:00'],
            ['id' => 3, 'active' => true, 'is_public' => true, 'date_from' => '2026-09-10T09:00:00+02:00'],
            ['id' => 4, 'active' => true, 'is_public' => true, 'date_from' => '2026-08-30T09:00:00+02:00'],
        ], $now);

        self::assertSame(4, $result['id']);
    }

    public function testOngoingOccurrenceIsStillSelected(): void {
        $now = new \DateTimeImmutable('2026-08-28T10:00:00+02:00');
        $result = PretixService::selectNextOccurrence([
            ['id' => 5, 'active' => true, 'is_public' => true, 'date_from' => '2026-08-27T09:00:00+02:00', 'date_to' => '2026-08-29T17:00:00+02:00'],
        ], $now);
        self::assertSame(5, $result['id']);
    }

    public function testReturnsNullWithoutFutureOccurrence(): void {
        self::assertNull(PretixService::selectNextOccurrence([], new \DateTimeImmutable()));
    }

    public function testCapacityMetricsHandleAvailableSoldOutAndUnlimitedQuotas(): void {
        self::assertSame([
            'capacity' => 20, 'registered' => 12, 'available' => 8, 'soldOut' => false,
        ], PretixService::capacityMetrics(['size' => 20, 'available_number' => 8, 'closed' => false]));
        self::assertSame([
            'capacity' => 20, 'registered' => 20, 'available' => 0, 'soldOut' => true,
        ], PretixService::capacityMetrics(['size' => 20, 'available_number' => 0, 'closed' => false]));
        self::assertSame([
            'capacity' => null, 'registered' => null, 'available' => null, 'soldOut' => false,
        ], PretixService::capacityMetrics(['size' => null, 'available_number' => null, 'closed' => false]));
    }
}
