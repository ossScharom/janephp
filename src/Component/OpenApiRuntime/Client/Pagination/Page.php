<?php

namespace Jane\Component\OpenApiRuntime\Client\Pagination;

/**
 * One page of a paginated endpoint: its items and the cursor pointing to the
 * next page. A `null` cursor means the page is the last one.
 */
final class Page
{
    public function __construct(
        private readonly iterable $items,
        private readonly mixed $nextCursor = null,
    ) {
    }

    public function getItems(): iterable
    {
        return $this->items;
    }

    public function getNextCursor(): mixed
    {
        return $this->nextCursor;
    }

    public function hasNextPage(): bool
    {
        return null !== $this->nextCursor;
    }
}
