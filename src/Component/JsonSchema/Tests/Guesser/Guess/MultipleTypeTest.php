<?php

namespace Jane\Component\JsonSchema\Tests\Guesser\Guess;

use Jane\Component\JsonSchema\Guesser\Guess\MultipleType;
use Jane\Component\JsonSchema\Guesser\Guess\ObjectType;
use Jane\Component\JsonSchema\Guesser\Guess\Type;
use Jane\Component\JsonSchema\JsonSchema\Model\JsonSchema;
use PHPUnit\Framework\TestCase;

class MultipleTypeTest extends TestCase
{
    public function testDocTypeHintDeduplicatesSameTypedBranches(): void
    {
        $type = new MultipleType(new JsonSchema());
        $type->addType(new Type(new JsonSchema(), Type::TYPE_STRING));
        $type->addType(new Type(new JsonSchema(), Type::TYPE_STRING));

        self::assertSame('string', $type->getDocTypeHint('Jane\Test'));
    }

    public function testDocTypeHintKeepsDistinctBranchesInOrder(): void
    {
        $type = new MultipleType(new JsonSchema());
        $type->addType(new Type(new JsonSchema(), Type::TYPE_STRING));
        $type->addType(new Type(new JsonSchema(), Type::TYPE_INTEGER));
        $type->addType(new Type(new JsonSchema(), Type::TYPE_STRING));
        $type->addType(new Type(new JsonSchema(), Type::TYPE_NULL));

        self::assertSame('string|int|null', $type->getDocTypeHint('Jane\Test'));
    }

    public function testDocTypeHintDeduplicatesObjectBranchesResolvingToSameClass(): void
    {
        $type = new MultipleType(new JsonSchema());
        $type->addType(new ObjectType(new JsonSchema(), 'Foo', 'Jane\Test'));
        $type->addType(new ObjectType(new JsonSchema(), 'Foo', 'Jane\Test'));
        $type->addType(new ObjectType(new JsonSchema(), 'Bar', 'Jane\Test'));

        self::assertSame('\\Jane\\Test\\Model\\Foo|\\Jane\\Test\\Model\\Bar', $type->getDocTypeHint('Jane\Test'));
    }
}
