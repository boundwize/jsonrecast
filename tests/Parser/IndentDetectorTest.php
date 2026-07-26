<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Parser;

use Boundwize\JsonRecast\Parser\IndentDetector;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class IndentDetectorTest extends TestCase
{
    public function testIndentDetectorIsNotInstantiable(): void
    {
        $reflectionClass = new ReflectionClass(IndentDetector::class);
        $constructor     = $reflectionClass->getConstructor();

        $this->assertTrue($reflectionClass->isFinal());
        $this->assertInstanceOf(ReflectionMethod::class, $constructor);
        $this->assertTrue($constructor->isPrivate());
        $this->assertNull($constructor->invoke($reflectionClass->newInstanceWithoutConstructor()));
    }
}
