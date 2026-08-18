<?php

namespace Univapay\Compat\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Errors\UnivapayNoMoreItemsError;
use Univapay\Compat\Resources\Paginated;

/**
 * Cursor math with a fake fetcher -- no Bridge/ApiCaller/HTTP involved, matching the old SDK's own
 * `Paginated` unit-testability (it never needed a live server either, just the cursor arithmetic).
 * See `Paginated`'s class doc for why the ctor takes `callable $fetcher` instead of a
 * jsonableClass + RequestContext.
 */
class PaginatedTest extends TestCase
{
    private function item($id)
    {
        return (object) ['id' => $id];
    }

    public function testGetNextUsesTheLastItemsIdAsCursorAndKeepsOtherQueryKeys()
    {
        $seenQueries = [];
        $fetcher = function (array $query) use (&$seenQueries) {
            $seenQueries[] = $query;
            return new Paginated([$this->item('c2')], false, $query, function () {
            });
        };

        $page = new Paginated(
            [$this->item('c0'), $this->item('c1')],
            true,
            ['limit' => 5, 'mode' => 'live'],
            $fetcher
        );

        $next = $page->getNext();

        $this->assertSame(['cursor' => 'c1', 'limit' => 5, 'mode' => 'live'], $seenQueries[0]);
        $this->assertSame(['c2'], array_map(function ($i) {
            return $i->id;
        }, $next->items));
    }

    public function testGetNextThrowsNoMoreItemsWhenHasMoreIsFalse()
    {
        $page = new Paginated([$this->item('c0')], false, [], function (array $q) {
            $this->fail('fetcher should not be invoked when hasMore is false');
        });

        $this->expectException(UnivapayNoMoreItemsError::class);
        $page->getNext();
    }

    public function testGetPreviousThrowsNoMoreItemsWhenTheOriginalQueryHasNoCursor()
    {
        // No 'cursor' key at all means this is the FIRST page -- old SDK's own semantics.
        $page = new Paginated([$this->item('c0')], true, ['limit' => 5], function () {
            $this->fail('fetcher should not be invoked');
        });

        $this->expectException(UnivapayNoMoreItemsError::class);
        $page->getPrevious();
    }

    public function testGetPreviousFlipsCursorDirectionOnTheOriginalQueryAndRestoresItemOrder()
    {
        $seenQueries = [];
        $fetcher = function (array $query) use (&$seenQueries) {
            $seenQueries[] = $query;
            // The reversed fetch: server would return items in the FLIPPED direction, most
            // recent-before-cursor first -- here that is c1 (just before original cursor c2),
            // then c0.
            return new Paginated([$this->item('c1'), $this->item('c0')], true, $query, function () {
            });
        };

        // Original page: query has cursor_direction 'asc' explicitly plus an original cursor
        // 'c2' (i.e. this is itself a subsequent page, not page 1).
        $page = new Paginated(
            [$this->item('c3'), $this->item('c4')],
            true,
            ['cursor' => 'c2', 'cursor_direction' => 'asc', 'limit' => 2],
            $fetcher
        );

        $previous = $page->getPrevious();

        // reverse() flips cursor_direction against the ORIGINAL query (not the response), and
        // getNext() on the reversed state then overlays a fresh 'cursor' on top of THAT: the
        // reversed items are [c4, c3], so end() is 'c3' -- the last item of the ORIGINAL page,
        // not the original query's own 'cursor' value ('c2').
        $this->assertSame('desc', $seenQueries[0]['cursor_direction']);
        $this->assertSame('c3', $seenQueries[0]['cursor']);
        $this->assertSame(2, $seenQueries[0]['limit']);

        // previousPage->reverse() restores original item order.
        $this->assertSame(['c0', 'c1'], array_map(function ($i) {
            return $i->id;
        }, $previous->items));
    }

    public function testReverseDefaultsCursorDirectionToDescWhenUnset()
    {
        $seenQueries = [];
        $fetcher = function (array $query) use (&$seenQueries) {
            $seenQueries[] = $query;
            return new Paginated([$this->item('c0')], true, $query, function () {
            });
        };

        $page = new Paginated([$this->item('c1')], true, ['cursor' => 'c2'], $fetcher);
        $page->getPrevious();

        // No cursor_direction was ever set by the user -- old default is 'desc', so reverse()
        // flips to 'asc'.
        $this->assertSame('asc', $seenQueries[0]['cursor_direction']);
    }

    public function testGetPreviousThrowsNoMoreItemsWhenTheReversedFetchComesBackEmpty()
    {
        $fetcher = function (array $query) {
            return new Paginated([], true, $query, function () {
            });
        };

        $page = new Paginated([$this->item('c1')], true, ['cursor' => 'c2'], $fetcher);

        $this->expectException(UnivapayNoMoreItemsError::class);
        $page->getPrevious();
    }

    public function testReplayPassesNullForUserUnsetParamsBecauseTheyWereNeverInTheStoredQuery()
    {
        // Simulates the mixin having already run FunctionalUtils::stripNulls() before
        // constructing the first page -- unset filters are simply ABSENT keys, never explicit
        // nulls, so a getNext() replay's overlay ('cursor' => ...) is the only key ever added.
        $seenQueries = [];
        $fetcher = function (array $query) use (&$seenQueries) {
            $seenQueries[] = $query;
            return new Paginated([], false, $query, function () {
            });
        };

        $page = new Paginated([$this->item('only')], true, ['limit' => 10], $fetcher);
        $page->getNext();

        $this->assertArrayNotHasKey('mode', $seenQueries[0]);
        $this->assertArrayNotHasKey('email', $seenQueries[0]);
        $this->assertSame(['cursor' => 'only', 'limit' => 10], $seenQueries[0]);
    }

    public function testOriginalQueryIsPreservedAcrossMultipleGetNextCalls()
    {
        $queries = [];
        $fetcher = function (array $query) use (&$queries, &$fetcher) {
            $queries[] = $query;
            $nextId = 'i' . (count($queries) + 1);
            return new Paginated([$this->item($nextId)], true, $query, $fetcher);
        };

        $page = new Paginated([$this->item('i1')], true, ['limit' => 3, 'mode' => 'test'], $fetcher);

        $page2 = $page->getNext();
        $page2->getNext();

        // Both replays overlay 'cursor' onto the SAME original ['limit' => 3, 'mode' => 'test'] --
        // never onto a query mutated by the previous hop.
        $this->assertSame(['cursor' => 'i1', 'limit' => 3, 'mode' => 'test'], $queries[0]);
        $this->assertSame(['cursor' => 'i2', 'limit' => 3, 'mode' => 'test'], $queries[1]);
    }
}
