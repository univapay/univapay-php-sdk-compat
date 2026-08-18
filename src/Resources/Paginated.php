<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources;

use Univapay\Compat\Errors\UnivapayNoMoreItemsError;
use Univapay\Compat\Utility\FunctionalUtils as fp;

/**
 * Port of the old SDK's `Resources\Paginated`. Old `fromResponse()` closed over a `jsonableClass`
 * + `Requests\RequestContext` and refetched pages through `Utility\RequesterUtils::
 * executeGetPaginated()`, which reconstructed a raw GET against that context. The new transport
 * engine's generated `Apis\*` controllers already know their own routes and take the query as
 * positional arguments rather than a URL-addressed query string, so refetching a page is now a
 * plain `callable $fetcher` closure supplied by the caller (in practice, `Support\ListDispatcher`
 * closing over the specific endpoint + already-hydrated item parser -- see its class doc) instead
 * of a context object this class would need to know how to call back into. Everything else --
 * cursor math, direction flip, the "replay against the ORIGINAL query" semantics, and
 * `UnivapayNoMoreItemsError` -- is a byte-for-byte port of the old control flow.
 *
 * `$items`/`$hasMore` stay PUBLIC, matching old-SDK parity exactly (consumer code reads
 * `$page->items` / `$page->hasMore` directly).
 */
final class Paginated
{
    /** @var array */
    public $items;

    /** @var bool */
    public $hasMore;

    /** @var array */
    private $query;

    /** @var callable */
    private $fetcher;

    /**
     * @param array $items Already-hydrated items (e.g. ported `Charge`/`Store`/... instances with
     *        a public `$id`, per old-SDK parity) -- this class never parses raw response bodies
     *        itself, unlike old `fromResponse()`, since raw-body hydration now happens in the
     *        caller via the ported `Jsonable` schema parser (see plan "raw-body hydration").
     * @param bool $hasMore
     * @param array $query The snake_case query this page was fetched with. Stored AS GIVEN --
     *        never merged with response data or mutated in place -- because `getNext()`/
     *        `getPrevious()` both replay against this ORIGINAL query (only overlaying the one key
     *        they need to change), exactly like old `Paginated::getNext()`/`reverse()` did against
     *        `$this->query`. Keys the caller never set are simply absent here (old code's
     *        `FunctionalUtils::stripNulls()` already dropped them before construction), so a
     *        replay never re-sends a parameter the original caller left unset.
     * @param callable $fetcher `function(array $query): Paginated` -- refetches a page for a
     *        (possibly modified) query built from `$this->query`. Never invoked with anything
     *        other than a full copy of `$this->query` plus/minus the single key `getNext()`/
     *        `reverse()` changes.
     */
    public function __construct(array $items, bool $hasMore, array $query, callable $fetcher)
    {
        $this->items = $items;
        $this->hasMore = $hasMore;
        $this->query = $query;
        $this->fetcher = $fetcher;
    }

    /**
     * @return Paginated
     * @throws UnivapayNoMoreItemsError
     */
    public function getNext(): Paginated
    {
        if (!$this->hasMore) {
            throw new UnivapayNoMoreItemsError();
        }
        $last = end($this->items);
        $newQuery = ['cursor' => $last->id] + $this->query;
        return call_user_func($this->fetcher, $newQuery);
    }

    /**
     * @return Paginated
     * @throws UnivapayNoMoreItemsError
     */
    public function getPrevious(): Paginated
    {
        if (!array_key_exists('cursor', $this->query)) {
            throw new UnivapayNoMoreItemsError();
        }

        $previousPage = $this->reverse()->getNext();
        if (empty($previousPage->items)) {
            throw new UnivapayNoMoreItemsError();
        }
        return $previousPage->reverse();
    }

    /**
     * @return Paginated A synthetic, not-yet-fetched page: same items reversed, `cursor_direction`
     *         flipped in the query, `hasMore` forced true so a subsequent `getNext()` always
     *         attempts the fetch (mirrors old `Paginated::reverse()` exactly).
     */
    private function reverse(): Paginated
    {
        $currentDirection = fp::getOrElse($this->query, 'cursor_direction', 'desc');
        $newQuery = ['cursor_direction' => self::otherDirection($currentDirection)] + $this->query;
        return new Paginated(array_reverse($this->items), true, $newQuery, $this->fetcher);
    }

    /**
     * @param string $direction
     * @return string
     */
    private static function otherDirection($direction): string
    {
        return $direction === 'asc' ? 'desc' : 'asc';
    }
}
