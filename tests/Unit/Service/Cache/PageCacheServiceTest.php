<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service\Cache;

use OCA\IntraVox\Service\Cache\PageCacheService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

/**
 * The cache owner's own contract.
 *
 * Most of this was previously untested: the deferred-clear counter lived on
 * PageService as two private fields, exercised only indirectly through
 * BulkOperationService. It is also the part most likely to break subtly — a
 * miscounted nesting level means either a cache that is never flushed (stale
 * pages) or one flushed per item (the 100× distributed clear this exists to
 * prevent).
 */
class PageCacheServiceTest extends TestCase {

    private function withDistributed(?ICache &$cache = null): PageCacheService {
        $cache = $this->createMock(ICache::class);
        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('isAvailable')->willReturn(true);
        $factory->method('createDistributed')->willReturn($cache);
        return new PageCacheService($factory);
    }

    public function testWorksWithoutAnyDistributedBackend(): void {
        $service = new PageCacheService();

        $this->assertFalse($service->isDistributedAvailable());
        $this->assertNull($service->getDistributed('anything'));

        // Must not throw: an instance without Redis/APCu is a supported state.
        $service->setDistributed('k', 'v', 60);
        $service->clearExpensive();

        $this->addToAssertionCount(1);
    }

    public function testRequestCacheRoundTrip(): void {
        $service = new PageCacheService();

        $service->setPageData('page-1', ['title' => 'One']);
        $this->assertSame(['title' => 'One'], $service->getPageData('page-1'));
        $this->assertNull($service->getPageData('page-2'));
    }

    /**
     * Folder-path lookups cache their MISSES as null, so "absent" and "cached
     * as null" are different answers. A nullable getter alone cannot express
     * that, which is why the service has an explicit has/get pair.
     */
    public function testFolderPathDistinguishesAbsentFromCachedNull(): void {
        $service = new PageCacheService();

        $this->assertFalse($service->hasFolderPath('en/nope'));

        $service->setFolderPath('en/nope', null);

        $this->assertTrue($service->hasFolderPath('en/nope'), 'a cached miss is still a cache hit');
        $this->assertNull($service->getFolderPath('en/nope'));
    }

    public function testClearRequestForOnePageLeavesOthersAlone(): void {
        $service = new PageCacheService();
        $service->setPageData('page-1', ['t' => 1]);
        $service->setPageData('page-2', ['t' => 2]);

        $service->clearRequest('page-1');

        $this->assertNull($service->getPageData('page-1'));
        $this->assertSame(['t' => 2], $service->getPageData('page-2'));
    }

    public function testClearExpensiveDropsTreesAndDistributed(): void {
        $service = $this->withDistributed($distributed);
        $distributed->expects($this->once())->method('clear');

        $service->setTree('grouphash_en', ['tree' => [], 'time' => 1]);

        $this->assertTrue($service->clearExpensive(), 'an unsuppressed clear reports that it ran');
        $this->assertNull($service->getTree('grouphash_en'));
    }

    /**
     * The whole point of the counter: a 100-item bulk operation must clear the
     * distributed cache once, not 100 times.
     */
    public function testDeferredBatchClearsDistributedExactlyOnce(): void {
        $service = $this->withDistributed($distributed);
        $distributed->expects($this->once())->method('clear');

        $service->beginDeferred();
        for ($i = 0; $i < 100; $i++) {
            $this->assertFalse($service->clearExpensive(), 'a suppressed clear reports that it did not run');
        }
        $this->assertTrue($service->endDeferred(), 'closing the batch owes the caller one flush');
    }

    public function testNestedBatchesOnlyFlushOnTheOutermostEnd(): void {
        $service = $this->withDistributed($distributed);
        $distributed->expects($this->once())->method('clear');

        $service->beginDeferred();
        $service->beginDeferred();
        $service->clearExpensive();

        $this->assertFalse($service->endDeferred(), 'an inner end must not flush');
        $this->assertTrue($service->isDeferring(), 'still inside the outer batch');

        $this->assertTrue($service->endDeferred(), 'the outer end flushes');
        $this->assertFalse($service->isDeferring());
    }

    /**
     * A batch in which nothing asked for a clear must not flush on close —
     * otherwise every read-only bulk operation would wipe the caches.
     */
    public function testBatchWithoutAnyClearDoesNotFlush(): void {
        $service = $this->withDistributed($distributed);
        $distributed->expects($this->never())->method('clear');

        $service->beginDeferred();
        $this->assertFalse($service->endDeferred());
    }

    /**
     * Request-level entries are NOT deferred: a mutation mid-batch must still
     * see a truthful filesystem view, which is why only the expensive half is
     * suppressed.
     */
    public function testRequestCacheIsClearedEvenWhileDeferring(): void {
        $service = new PageCacheService();
        $service->setPageData('page-1', ['t' => 1]);

        $service->beginDeferred();
        $service->clearRequest();

        $this->assertNull($service->getPageData('page-1'));
    }

    /**
     * Guard against an unbalanced endDeferred() driving the counter negative,
     * which would leave the cache permanently "deferring" from the next
     * begin onwards.
     */
    public function testUnbalancedEndDoesNotCorruptTheCounter(): void {
        $service = new PageCacheService();

        $service->endDeferred();
        $service->endDeferred();

        $this->assertFalse($service->isDeferring());

        $service->beginDeferred();
        $this->assertTrue($service->isDeferring(), 'a later batch must still suppress correctly');
        $service->clearExpensive();
        $this->assertTrue($service->endDeferred());
    }
}
