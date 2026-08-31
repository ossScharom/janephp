<?php

declare(strict_types=1);

/*
 * Grouping and baseline plumbing for the PHPStan analysis of generated code
 * (see docs/contributing/adrs/0008-phpstan-on-generated-code.md).
 *
 * The `expected/` trees cannot be handed to PHPStan as one path list: above
 * roughly 4 000 files a run is OOM-killed (exit 137) rather than reporting
 * anything, on a GitHub runner as much as locally. The fixtures are packed
 * into groups below that cap, each analysed by its own PHPStan process.
 *
 * Packing by size is only sound because no two fixtures declare the same
 * class: every fixture generates into its own namespace, and
 * ExpectedNamespaceUniquenessTest guards that invariant. With duplicate names
 * in one process, PHPStan resolves references against an arbitrary
 * declaration and the results become meaningless.
 *
 * Usable both as a script (`php tools/phpstan-generated.php groups`) and as a
 * library (`require`d by castor.php and the uniqueness test).
 */

namespace Jane\Tools\PhpstanGenerated;

const MAX_FILES_PER_GROUP = 4000;

function rootDirectory(): string
{
    return \dirname(__DIR__);
}

/**
 * The committed `expected/` trees, relative to the repository root.
 *
 * @return list<string>
 */
function fixtureDirectories(): array
{
    $directories = [];

    foreach (glob(rootDirectory() . '/src/Component/*/Tests/fixtures/*/expected', \GLOB_ONLYDIR) ?: [] as $directory) {
        $relative = substr($directory, \strlen(rootDirectory()) + 1);

        // Deliberately partial trees, which report their own missing runtime
        // classes as false positives. Those templates live in
        // src/Component/*/Generator/Runtime/data and are analysed with src/.
        if (str_contains($relative, '/runtime-boilerplate/')) {
            continue;
        }

        $directories[] = $relative;
    }

    sort($directories);

    return $directories;
}

function countPhpFiles(string $directory): int
{
    $files = 0;

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator(rootDirectory() . '/' . $directory, \FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && 'php' === $file->getExtension()) {
            ++$files;
        }
    }

    return $files;
}

/**
 * Every class-like name a fixture tree declares, fully qualified.
 *
 * Textual, not reflective, on purpose: some trees are known-invalid PHP
 * (`.known-invalid-php` markers) and would not survive parsing.
 *
 * @return list<string>
 */
function declaredClasses(string $directory): array
{
    $classes = [];

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator(rootDirectory() . '/' . $directory, \FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || 'php' !== $file->getExtension()) {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        $namespace = preg_match('/^namespace\s+([^;\s]+)\s*;/m', $contents, $matches) ? $matches[1] . '\\' : '';

        if (preg_match_all('/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+([A-Za-z_]\w*)/m', $contents, $matches)) {
            foreach ($matches[1] as $name) {
                $classes[] = $namespace . $name;
            }
        }
    }

    return $classes;
}

/**
 * Pack the fixtures into groups that PHPStan can each analyse in one run:
 * first-fit-decreasing on file count, capped at MAX_FILES_PER_GROUP. A fixture
 * larger than the cap has no choice but to run alone.
 *
 * @return list<list<string>>
 */
function groups(): array
{
    $sizes = [];

    foreach (fixtureDirectories() as $directory) {
        $sizes[$directory] = countPhpFiles($directory);
    }

    arsort($sizes);

    $groups = [];

    foreach ($sizes as $directory => $size) {
        foreach ($groups as $index => $group) {
            if ($group['files'] + $size <= MAX_FILES_PER_GROUP) {
                $groups[$index]['files'] += $size;
                $groups[$index]['directories'][] = $directory;

                continue 2;
            }
        }

        $groups[] = ['files' => $size, 'directories' => [$directory]];
    }

    return array_map(
        static function (array $group): array {
            sort($group['directories']);

            return $group['directories'];
        },
        $groups
    );
}

/**
 * Concatenate per-group baselines into the committed one.
 *
 * Entry bodies are copied verbatim, never parsed: PHPStan writes multi-line
 * messages as NEON block strings ('''), inside which blank lines are
 * significant. Reformatting or re-indenting them silently breaks those
 * regexes, and the entries then stop matching the errors they were meant to
 * cover.
 *
 * @param list<string> $baselines
 */
function mergeBaselines(array $baselines, string $target): void
{
    $body = '';

    foreach ($baselines as $baseline) {
        $contents = file_get_contents($baseline);
        $position = strpos($contents, 'ignoreErrors:');

        if (false === $position) {
            continue;
        }

        $entries = substr($contents, $position + \strlen('ignoreErrors:'));

        // A group with no findings writes `ignoreErrors: []`.
        if ('' === trim($entries) || str_starts_with(ltrim($entries, " \t"), '[]')) {
            continue;
        }

        $body .= rtrim($entries, "\n");
    }

    file_put_contents($target, "parameters:\n\tignoreErrors:" . ('' === $body ? ' []' : $body) . "\n");
}

if (\PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $command = $argv[1] ?? 'groups';

    if ('groups' === $command) {
        $groups = groups();
        $total = 0;

        foreach ($groups as $index => $directories) {
            $files = array_sum(array_map(countPhpFiles(...), $directories));
            $total += $files;

            printf("group %d — %d fixture(s), %d file(s)\n", $index + 1, \count($directories), $files);

            foreach ($directories as $directory) {
                printf("    %s\n", $directory);
            }
        }

        printf("\n%d group(s), %d fixture(s), %d file(s)\n", \count($groups), \count(fixtureDirectories()), $total);

        exit(0);
    }

    if ('merge-baseline' === $command) {
        if ($argc < 3) {
            fwrite(\STDERR, "usage: php tools/phpstan-generated.php merge-baseline <target> [<part> …]\n");

            exit(1);
        }

        mergeBaselines(\array_slice($argv, 3), $argv[2]);

        exit(0);
    }

    fwrite(\STDERR, sprintf("unknown command \"%s\": expected \"groups\" or \"merge-baseline\"\n", $command));

    exit(1);
}
