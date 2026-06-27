<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Attribute;

use Boundwize\JsonRecast\Attribute\NodeAttributes;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class NodeAttributesTest extends TestCase
{
    public function testItCannotBeConstructedForState(): void
    {
        $reflectionClass = new ReflectionClass(NodeAttributes::class);
        $constructor     = $reflectionClass->getConstructor();

        $this->assertInstanceOf(ReflectionMethod::class, $constructor);

        $nodeAttributes = $reflectionClass->newInstanceWithoutConstructor();
        $constructor->invoke($nodeAttributes);

        $this->assertInstanceOf(NodeAttributes::class, $nodeAttributes);
    }
}
