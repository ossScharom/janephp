# Paginated endpoints

Most APIs paginate their list endpoints, but the OpenAPI specification has no standard way to
describe pagination: every API expresses it with its own query parameters (`page`, `offset` /
`limit`, `cursor`, ...) and its own response envelope. Jane therefore cannot guess the pagination
contract from your specification alone — but it ships a runtime helper, the `Paginator`, that
turns any generated list endpoint into a lazy iterator over *all* its items.

The `Paginator` lives in `jane-php/open-api-runtime`, which every generated client already depends
on, so you can use it with existing clients without regenerating anything. You describe your API's
pagination with two or three small closures; pages are then fetched one at a time, only while you
iterate.

## Page-number pagination

For APIs paginated with a page number (`?page=1`, `?page=2`, ...):

```php
use Jane\Component\OpenApiRuntime\Client\Pagination\Paginator;

$pets = Paginator::forPageNumber(
    fn (int $page) => $client->listPets(['page' => $page]),
    fn ($response) => $response->getItems(),
);

foreach ($pets as $pet) {
    // Each new page is fetched lazily when the previous one is consumed.
}
```

By default the iteration stops on the first empty page, which costs one extra request after the
last non-empty page. When the response exposes the total number of pages (or a "has more" flag),
give it as a third closure to avoid that request:

```php
$pets = Paginator::forPageNumber(
    fn (int $page) => $client->listPets(['page' => $page]),
    fn ($response) => $response->getItems(),
    fn ($response, int $page) => $page < $response->getTotalPages(),
);
```

An optional `startPage` argument (default `1`) lets you start from another page.

## Offset / limit pagination

For APIs paginated with an offset and a limit (`?offset=0&limit=50`, `?offset=50&limit=50`, ...):

```php
$pets = Paginator::forOffset(
    fn (int $offset, int $limit) => $client->listPets(['offset' => $offset, 'limit' => $limit]),
    fn ($response) => $response->getItems(),
    limit: 50,
);
```

The iteration stops as soon as a page holds fewer items than the limit. An optional `startOffset`
argument (default `0`) lets you resume from a given offset.

## Cursor pagination

For APIs where each response carries an opaque cursor pointing to the next page:

```php
$pets = Paginator::forCursor(
    fn (?string $cursor) => $client->listPets(['cursor' => $cursor]),
    fn ($response) => $response->getItems(),
    fn ($response) => $response->getMeta()->getNextCursor(),
);
```

The first call receives `null` (or the optional `initialCursor` argument), and the iteration stops
when the cursor extracted from a response is `null`.

## Custom strategies

The three strategies above are built on a generic one, `Paginator::fromCallable()`, which you can
use directly for anything more exotic (e.g. link headers, or a next-page URL in the body). Your
closure receives the current cursor and returns a `Page` — the items of that page plus the cursor
of the next one, `null` meaning the last page:

```php
use Jane\Component\OpenApiRuntime\Client\Pagination\Page;
use Jane\Component\OpenApiRuntime\Client\Pagination\Paginator;

$pets = Paginator::fromCallable(function (?string $url) use ($client) {
    $response = $client->listPets(['pageUrl' => $url]);

    return new Page($response->getItems(), $response->getLinks()->getNext());
});
```

## Good to know

* The paginator implements `IteratorAggregate`: nothing is fetched until you start iterating, and
  stopping early (e.g. `break`) stops fetching pages.
* Iterating the same paginator again re-fetches the pages, so you always see fresh data; call
  `iterator_to_array($paginator, false)` if you need everything in memory at once.
* The closures receive the full response, so you keep access to any page-level metadata while
  extracting items or the next cursor.
* If two consecutive pages are requested with the same cursor (a misconfigured strategy would
  otherwise loop forever), the paginator throws a
  `Jane\Component\OpenApiRuntime\Client\Exception\PaginationLoopException`.
