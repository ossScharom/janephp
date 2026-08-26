<?php

namespace Jane\Component\OpenApiRuntime\Tests\Client\Pagination;

use Jane\Component\OpenApiRuntime\Client\Exception\PaginationLoopException;
use Jane\Component\OpenApiRuntime\Client\Pagination\Page;
use Jane\Component\OpenApiRuntime\Client\Pagination\Paginator;
use PHPUnit\Framework\TestCase;

class PaginatorTest extends TestCase
{
    public function testPageNumberStopsOnFirstEmptyPage(): void
    {
        $pages = [1 => ['a', 'b'], 2 => ['c'], 3 => []];
        $fetchedPages = [];

        $paginator = Paginator::forPageNumber(
            static function (int $page) use ($pages, &$fetchedPages): array {
                $fetchedPages[] = $page;

                return $pages[$page];
            },
            static fn (array $response): array => $response,
        );

        $this->assertSame(['a', 'b', 'c'], iterator_to_array($paginator, false));
        $this->assertSame([1, 2, 3], $fetchedPages);
    }

    public function testPageNumberUsesHasNextPageCallback(): void
    {
        $pages = [1 => ['a', 'b'], 2 => ['c']];
        $fetchedPages = [];

        $paginator = Paginator::forPageNumber(
            static function (int $page) use ($pages, &$fetchedPages): array {
                $fetchedPages[] = $page;

                return $pages[$page];
            },
            static fn (array $response): array => $response,
            static fn (array $response, int $page): bool => $page < 2,
        );

        $this->assertSame(['a', 'b', 'c'], iterator_to_array($paginator, false));
        $this->assertSame([1, 2], $fetchedPages, 'The has-next-page callback should avoid the extra empty fetch');
    }

    public function testPageNumberHonorsStartPage(): void
    {
        $fetchedPages = [];

        $paginator = Paginator::forPageNumber(
            static function (int $page) use (&$fetchedPages): array {
                $fetchedPages[] = $page;

                return $page < 5 ? ['item-' . $page] : [];
            },
            static fn (array $response): array => $response,
            startPage: 3,
        );

        $this->assertSame(['item-3', 'item-4'], iterator_to_array($paginator, false));
        $this->assertSame([3, 4, 5], $fetchedPages);
    }

    public function testOffsetStopsOnShortPage(): void
    {
        $items = ['a', 'b', 'c', 'd', 'e'];
        $calls = [];

        $paginator = Paginator::forOffset(
            static function (int $offset, int $limit) use ($items, &$calls): array {
                $calls[] = [$offset, $limit];

                return \array_slice($items, $offset, $limit);
            },
            static fn (array $response): array => $response,
            limit: 2,
        );

        $this->assertSame($items, iterator_to_array($paginator, false));
        $this->assertSame([[0, 2], [2, 2], [4, 2]], $calls, 'A page shorter than the limit should be the last fetch');
    }

    public function testOffsetFetchesOneExtraPageOnExactMultiple(): void
    {
        $items = ['a', 'b', 'c', 'd'];
        $calls = [];

        $paginator = Paginator::forOffset(
            static function (int $offset, int $limit) use ($items, &$calls): array {
                $calls[] = [$offset, $limit];

                return \array_slice($items, $offset, $limit);
            },
            static fn (array $response): array => $response,
            limit: 2,
        );

        $this->assertSame($items, iterator_to_array($paginator, false));
        $this->assertSame([[0, 2], [2, 2], [4, 2]], $calls);
    }

    public function testOffsetHonorsStartOffset(): void
    {
        $items = ['a', 'b', 'c', 'd'];

        $paginator = Paginator::forOffset(
            static fn (int $offset, int $limit): array => \array_slice($items, $offset, $limit),
            static fn (array $response): array => $response,
            limit: 2,
            startOffset: 2,
        );

        $this->assertSame(['c', 'd'], iterator_to_array($paginator, false));
    }

    public function testOffsetRejectsInvalidLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Paginator::forOffset(
            static fn (int $offset, int $limit): array => [],
            static fn (array $response): array => $response,
            limit: 0,
        );
    }

    public function testCursorFollowsCursorsUntilNull(): void
    {
        $pages = [
            '' => ['items' => ['a', 'b'], 'next' => 'cursor-1'],
            'cursor-1' => ['items' => ['c'], 'next' => 'cursor-2'],
            'cursor-2' => ['items' => ['d'], 'next' => null],
        ];
        $fetchedCursors = [];

        $paginator = Paginator::forCursor(
            static function (?string $cursor) use ($pages, &$fetchedCursors): array {
                $fetchedCursors[] = $cursor;

                return $pages[$cursor ?? ''];
            },
            static fn (array $response): array => $response['items'],
            static fn (array $response): ?string => $response['next'],
        );

        $this->assertSame(['a', 'b', 'c', 'd'], iterator_to_array($paginator, false));
        $this->assertSame([null, 'cursor-1', 'cursor-2'], $fetchedCursors);
    }

    public function testFromCallableUsesPagesDirectly(): void
    {
        $paginator = Paginator::fromCallable(
            static fn (?int $cursor): Page => match ($cursor) {
                null => new Page(['a'], 2),
                2 => new Page(new \ArrayIterator(['b', 'c'])),
                default => self::fail('Unexpected cursor'),
            },
        );

        $this->assertSame(['a', 'b', 'c'], iterator_to_array($paginator, false));
    }

    public function testPagesAreFetchedLazily(): void
    {
        $fetchCount = 0;

        $paginator = Paginator::forPageNumber(
            static function (int $page) use (&$fetchCount): array {
                ++$fetchCount;

                return ['item-' . $page];
            },
            static fn (array $response): array => $response,
        );

        $this->assertSame(0, $fetchCount, 'No page should be fetched before iterating');

        foreach ($paginator as $item) {
            break;
        }

        $this->assertSame(1, $fetchCount, 'Only the first page should be fetched when iteration stops early');
    }

    public function testPaginatorIsReusable(): void
    {
        $fetchCount = 0;

        $paginator = Paginator::forPageNumber(
            static function (int $page) use (&$fetchCount): array {
                ++$fetchCount;

                return $page < 2 ? ['a'] : [];
            },
            static fn (array $response): array => $response,
        );

        $this->assertSame(['a'], iterator_to_array($paginator, false));
        $this->assertSame(['a'], iterator_to_array($paginator, false));
        $this->assertSame(4, $fetchCount, 'Each iteration should fetch the pages again');
    }

    public function testThrowsWhenPaginationLoops(): void
    {
        $paginator = Paginator::forCursor(
            static fn (?string $cursor): array => ['items' => ['a'], 'next' => 'same-cursor'],
            static fn (array $response): array => $response['items'],
            static fn (array $response): ?string => $response['next'],
        );

        $this->expectException(PaginationLoopException::class);

        iterator_to_array($paginator, false);
    }

    public function testItemKeysDoNotCollideAcrossPages(): void
    {
        $pages = [1 => ['a', 'b'], 2 => ['c', 'd'], 3 => []];

        $paginator = Paginator::forPageNumber(
            static fn (int $page): array => $pages[$page],
            static fn (array $response): array => $response,
        );

        $this->assertSame(['a', 'b', 'c', 'd'], iterator_to_array($paginator));
    }

    public function testPageExposesItemsAndCursor(): void
    {
        $page = new Page(['a'], 'next');
        $this->assertSame(['a'], $page->getItems());
        $this->assertSame('next', $page->getNextCursor());
        $this->assertTrue($page->hasNextPage());

        $lastPage = new Page(['b']);
        $this->assertNull($lastPage->getNextCursor());
        $this->assertFalse($lastPage->hasNextPage());
    }
}
