# PHPStan report: generated clients at levels 3, 4 and 5

Context: [janephp/janephp#1041](https://github.com/janephp/janephp/issues/1041) — what would PHPStan report today if the generated clients were analysed, and how does the error count evolve between levels 3, 4 and 5?

## Methodology

- Base: `next` at `e81c2d84` (2026-08-27).
- Tool: PHPStan **2.2.2** (the version pinned by `castor qa:phpstan`), PHP 8.4.19.
- Corpus: all **162** committed `src/Component/*/Tests/fixtures/*/expected` directories — **23,628 PHP files**. These are byte-identical to what the generators produce (`generated/` is asserted equal to `expected/` by the test suite), so the numbers hold for `generated/` as well.
- Because fixtures re-use the same namespaces (e.g. `Jane\Component\OpenApi3\Tests\Expected\...`), analysing them in one PHPStan run produces bogus duplicate-class errors. Each fixture was therefore analysed in **its own PHPStan invocation** (same config: repo `vendor/autoload.php` as `--autoload-file`, no baseline, no ignores) and the results were aggregated.
- The small committed test client `src/Component/OpenApi3/Tests/client/generated` (24 files) was analysed separately and is reported as an addendum.

## Headline numbers

| | Level 3 | Level 4 | Level 5 |
|---|---:|---:|---:|
| **Total errors** | **2,666** | **8,565** | **8,739** |
| Fixtures affected (of 162) | 134 | 152 | 152 |
| Distinct error identifiers | 9 | 19 | 20 |
| Delta vs previous level | — | +5,899 | +174 |

### Errors per component

| Component | Level 3 | Level 4 | Level 5 |
|---|---:|---:|---:|
| JsonSchema (29 fixtures) | 1 | 97 | 101 |
| OpenApi2 (33 fixtures) | 553 | 723 | 782 |
| OpenApi3 (83 fixtures) | 2,051 | 7,611 | 7,722 |
| OpenApi31 (17 fixtures) | 61 | 134 | 134 |

## Errors by identifier

| Identifier | Level 3 | Level 4 | Level 5 | Appears at |
|---|---:|---:|---:|---|
| `identical.alwaysTrue` | 0 | 4,556 | 4,556 | level 4 |
| `return.missing` | 1,791 | 1,791 | 1,791 | level 3 |
| `identical.alwaysFalse` | 0 | 1,122 | 1,122 | level 4 |
| `parameter.defaultValue` | 288 | 288 | 288 | level 3 |
| `phpstan.parse` | 207 | 207 | 207 | level 0 (syntax) |
| `argument.type` | 0 | 0 | 174 | level 5 |
| `varTag.misplaced` | 150 | 150 | 150 | level 3 |
| `new.static` | 135 | 135 | 135 | level 3 |
| `elseif.alwaysFalse` | 0 | 95 | 95 | level 4 |
| `phpDoc.parseError` | 68 | 68 | 68 | level 3 |
| `nullsafe.neverNull` | 0 | 54 | 54 | level 4 |
| `instanceof.alwaysFalse` | 0 | 50 | 50 | level 4 |
| `method.tentativeReturnType` | 20 | 20 | 20 | level 3 |
| `trait.unused` | 0 | 16 | 16 | level 4 |
| `method.notFound` | 6 | 6 | 6 | level 3 |
| `if.alwaysFalse` | 0 | 3 | 3 | level 4 |
| `property.defaultValue` | 1 | 1 | 1 | level 3 |
| `notIdentical.alwaysTrue` | 0 | 1 | 1 | level 4 |
| `property.onlyWritten` | 0 | 1 | 1 | level 4 |
| `booleanAnd.leftAlwaysFalse` | 0 | 1 | 1 | level 4 |

## What each level adds

**Level 3 (2,666 errors)** — return types, property/parameter types. Almost entirely five systematic generator patterns:

- `return.missing` (1,791, 67% of the level): every endpoint's `transformResponseBody()` is inferred as `should return null` (the PHPDoc-conditional `@return` shape) but has code paths without a `return` statement.
- `parameter.defaultValue` (288): `array $queryParameters = []` conflicts with a non-optional array-shape PHPDoc (shapes with required keys, e.g. `array{origin: string, ...}`).
- `phpstan.parse` (207): **genuine syntax errors in committed fixtures** — see "Real bugs" below.
- `varTag.misplaced` (150): generated `@var` PHPDoc placed above methods (no effect).
- `new.static` (135): `new static()` in generated `Client::create()`.

**Level 4 (+5,899 → 8,565)** — dead-code analysis. The jump is the largest of the three levels and comes almost entirely from always-true/always-false comparisons in generated normalizers and endpoints:

- `identical.alwaysTrue` (4,556) / `identical.alwaysFalse` (1,122) / `elseif.alwaysFalse` (95) / `instanceof.alwaysFalse` (50) / `if.alwaysFalse` (3): the normalizer null/type-check chains (`if ($data === null) ... elseif (is_array($data)) ...` over values PHPStan has already narrowed, `\` checks against `mixed`).
- `nullsafe.neverNull` (54): `?->` on non-nullable expressions.
- `trait.unused` (16): each fixture's generated `Runtime\Client\EndpointTrait` is unused inside its own fixture scope (an artifact of per-fixture analysis more than a real finding).

**Level 5 (+174 → 8,739)** — argument type checking. The smallest step:

- `argument.type` (174): mostly `mixed` from denormalized data passed into typed model setters (e.g. `setOnlyNull() expects null, mixed given`), plus some union mismatches in `docker-api` and `issue-669`.

## Concentration

A handful of large real-world specs dominate the totals (level 5 shown):

| Fixture | Level 3 | Level 4 | Level 5 |
|---|---:|---:|---:|
| OpenApi3/issue-445 | 238 | 3,886 | 3,915 |
| OpenApi3/github | 671 | 1,478 | 1,495 |
| OpenApi3/issue-669 | 692 | 1,425 | 1,480 |
| OpenApi2/docker-api | 233 | 386 | 441 |
| OpenApi3/issue-337 | 187 | 306 | 307 |
| OpenApi2/issue-770 | 206 | 206 | 206 |
| All other 156 fixtures | 439 | 878 | 895 |

The top 6 fixtures account for 84% of all errors at level 3 and 90% at levels 4 and 5; the median fixture has 2–3.

## Real bugs surfaced (independent of the level discussion)

1. **`OpenApi2/issue-770` expected code does not parse** (205 errors, `php -l` fails): path templates with regex constraints leak into parameter names — `public function addClusterRestoreById(string $id:.+, ...)`. Every consumer of such a spec gets an uncompilable client today.
2. **`OpenApi3/referenced-request-bodies` generates `class Parent`**, a PHP reserved word — the file cannot be loaded.
3. `phpDoc.parseError` (68): spec descriptions containing `*` / markdown are injected verbatim into `@param array{...}` shapes (e.g. the `github` fixture), producing invalid PHPDoc that tools then ignore.

## Addendum: `src/Component/OpenApi3/Tests/client/generated`

The small committed test client shows the same patterns in miniature: **2 errors at level 3** (`new.static`, `return.missing`), **6 at levels 4 and 5** (adding `identical.alwaysFalse` ×2, `booleanOr.alwaysFalse`, `function.alreadyNarrowedType`).

## Takeaways

- The level 3 → 4 step is where the bulk lands (+5,899, ×3.2); level 4 → 5 is almost free (+174, +2%).
- The volume is not 8,739 independent problems: ~15 generator code patterns explain >99% of all errors. Fixing a single pattern in the generators (e.g. the `transformResponseBody` return paths, or the normalizer comparison chains) removes hundreds to thousands of errors at once across all fixtures.
- Analysing `expected/` is equivalent to analysing `generated/` (the test suite asserts equality), with the advantage of being stable and reviewable in diffs.
- The two parse-error bugs (issue-770 parameter names, `Parent` class name) are worth fixing regardless of which PHPStan level is adopted — level 0 would already catch them.
