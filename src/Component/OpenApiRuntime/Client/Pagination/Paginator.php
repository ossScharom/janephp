<?php

namespace Jane\Component\OpenApiRuntime\Client\Pagination;

use Jane\Component\OpenApiRuntime\Client\Exception\PaginationLoopException;

/**
 * Lazily iterates over every item of a paginated endpoint.
 *
 * OpenAPI does not standardize pagination, so the caller describes it with
 * small closures: one calling the endpoint for a given cursor (page number,
 * offset or opaque cursor) and one extracting the items from the response.
 * Pages are fetched one at a time, only while iterating.
 *
 *     $pets = Paginator::forPageNumber(
 *         fn (int $page) => $client->listPets(['page' => $page]),
 *         fn (PetsPage $response) => $response->getItems(),
 *     );
 *
 *     foreach ($pets as $pet) {
 *         // A new page is fetched each time the previous one is consumed.
 *     }
 */
final class Paginator implements \IteratorAggregate
{
    /** @param \Closure(mixed): Page $fetchPage */
    private function __construct(
        private readonly \Closure $fetchPage,
        private readonly mixed $initialCursor,
    ) {
    }

    /**
     * Generic strategy, the building block of the ones below: $fetchPage is
     * called with the current cursor (starting with $initialCursor) and
     * returns a Page whose next cursor drives the iteration; a page with a
     * `null` next cursor is the last one.
     *
     * @param callable(mixed): Page $fetchPage
     */
    public static function fromCallable(callable $fetchPage, mixed $initialCursor = null): self
    {
        return new self($fetchPage(...), $initialCursor);
    }

    /**
     * Page-number pagination (`?page=1`, `?page=2`, ...).
     *
     * Without $hasNextPage the iteration stops on the first empty page, which
     * costs one extra request after the last non-empty page. Provide
     * $hasNextPage when the response exposes the total number of pages (or a
     * "has more" flag) to avoid it.
     *
     * @param callable(int): mixed              $fetchPage   executes the endpoint for the given page number and returns the response
     * @param callable(mixed, int): iterable    $getItems    extracts the items of a response (receives the response and the page number)
     * @param (callable(mixed, int): bool)|null $hasNextPage whether another page should be fetched (receives the response and the page number)
     */
    public static function forPageNumber(callable $fetchPage, callable $getItems, ?callable $hasNextPage = null, int $startPage = 1): self
    {
        return new self(static function (mixed $page) use ($fetchPage, $getItems, $hasNextPage): Page {
            $response = $fetchPage($page);
            $items = self::materialize($getItems($response, $page));
            $hasNext = null !== $hasNextPage ? (bool) $hasNextPage($response, $page) : [] !== $items;

            return new Page($items, $hasNext ? $page + 1 : null);
        }, $startPage);
    }

    /**
     * Offset/limit pagination (`?offset=0&limit=50`, `?offset=50&limit=50`, ...).
     *
     * The iteration stops as soon as a page holds fewer than $limit items. When
     * the total number of items is an exact multiple of $limit this costs one
     * extra request returning an empty page.
     *
     * @param callable(int, int): mixed      $fetchPage executes the endpoint for the given offset and limit and returns the response
     * @param callable(mixed, int): iterable $getItems  extracts the items of a response (receives the response and the offset)
     */
    public static function forOffset(callable $fetchPage, callable $getItems, int $limit, int $startOffset = 0): self
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException(\sprintf('Pagination limit must be at least 1, %d given.', $limit));
        }

        return new self(static function (mixed $offset) use ($fetchPage, $getItems, $limit): Page {
            $response = $fetchPage($offset, $limit);
            $items = self::materialize($getItems($response, $offset));

            return new Page($items, \count($items) < $limit ? null : $offset + $limit);
        }, $startOffset);
    }

    /**
     * Cursor pagination: each response carries an opaque cursor pointing to the
     * next page, a `null` cursor meaning the last page was reached.
     *
     * @param callable(mixed): mixed           $fetchPage     executes the endpoint for the given cursor ($initialCursor on the first call) and returns the response
     * @param callable(mixed, mixed): iterable $getItems      extracts the items of a response (receives the response and the cursor)
     * @param callable(mixed, mixed): mixed    $getNextCursor extracts the next cursor of a response, `null` when there is no next page
     */
    public static function forCursor(callable $fetchPage, callable $getItems, callable $getNextCursor, mixed $initialCursor = null): self
    {
        return new self(static function (mixed $cursor) use ($fetchPage, $getItems, $getNextCursor): Page {
            $response = $fetchPage($cursor);

            return new Page(self::materialize($getItems($response, $cursor)), $getNextCursor($response, $cursor));
        }, $initialCursor);
    }

    public function getIterator(): \Generator
    {
        $cursor = $this->initialCursor;

        while (true) {
            $page = ($this->fetchPage)($cursor);

            foreach ($page->getItems() as $item) {
                yield $item;
            }

            if (!$page->hasNextPage()) {
                return;
            }

            $nextCursor = $page->getNextCursor();
            if ($nextCursor === $cursor) {
                throw new PaginationLoopException(\sprintf('Pagination is looping: two consecutive pages were requested with the same cursor (%s).', \is_scalar($nextCursor) ? var_export($nextCursor, true) : get_debug_type($nextCursor)));
            }

            $cursor = $nextCursor;
        }
    }

    /** @return array<int, mixed> */
    private static function materialize(iterable $items): array
    {
        return \is_array($items) ? array_values($items) : iterator_to_array($items, false);
    }
}
