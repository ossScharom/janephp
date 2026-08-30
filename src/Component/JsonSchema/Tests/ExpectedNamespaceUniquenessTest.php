<?php

namespace Jane\Component\JsonSchema\Tests;

use PHPUnit\Framework\TestCase;

/**
 * No two fixtures may declare the same fully-qualified class name: every
 * fixture generates into its own namespace (its config appends a segment
 * derived from the fixture directory name).
 *
 * Tooling relies on this invariant. PHPStan analyses the `expected/` trees in
 * batches (see phpstan-generated.neon), and with duplicate class names in one
 * process it resolves references against an arbitrary declaration, making the
 * results meaningless. It cannot be derived from the directory layout: before
 * namespaces were made unique, the OpenApi2 issue-770 fixture generated into
 * the OpenApi3 test namespace.
 */
class ExpectedNamespaceUniquenessTest extends TestCase
{
    public function testNoClassIsDeclaredByTwoFixtures(): void
    {
        require_once __DIR__ . '/../../../../tools/phpstan-generated.php';

        $declaredIn = [];
        $collisions = [];

        foreach (\Jane\Tools\PhpstanGenerated\fixtureDirectories() as $directory) {
            foreach (\Jane\Tools\PhpstanGenerated\declaredClasses($directory) as $class) {
                if (isset($declaredIn[$class])) {
                    $collisions[] = \sprintf('%s declared by both %s and %s', $class, $declaredIn[$class], $directory);
                } else {
                    $declaredIn[$class] = $directory;
                }
            }
        }

        $this->assertSame(
            [],
            $collisions,
            "Fixtures must not share class names — give each a unique 'namespace' in its config:\n" . implode("\n", $collisions)
        );

        $this->assertNotSame([], $declaredIn, 'No fixture classes found: the expected/ trees should not be empty');
    }
}
