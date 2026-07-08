<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Guard;

use Boundwize\JsonRecast\Guard\MaximumDepthGuard;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class MaximumDepthGuardTest extends TestCase
{
    public function testItCannotBeConstructedForState(): void
    {
        $reflectionClass = new ReflectionClass(MaximumDepthGuard::class);
        $constructor     = $reflectionClass->getConstructor();

        $this->assertInstanceOf(ReflectionMethod::class, $constructor);

        $maximumDepthGuard = $reflectionClass->newInstanceWithoutConstructor();
        $constructor->invoke($maximumDepthGuard);

        $this->assertInstanceOf(MaximumDepthGuard::class, $maximumDepthGuard);
    }

    public function testItValidatesMaximumDepth(): void
    {
        MaximumDepthGuard::validateMaximumDepth(1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum depth must be greater than 0.');

        MaximumDepthGuard::validateMaximumDepth(0);
    }

    public function testItDetectsExceededDepth(): void
    {
        $this->assertFalse(MaximumDepthGuard::isExceeded(2, 1));
        $this->assertTrue(MaximumDepthGuard::isExceeded(2, 2));
    }

    public function testItGuardsMaximumDepth(): void
    {
        MaximumDepthGuard::guardMaximumDepth(2, 1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(MaximumDepthGuard::EXCEEDED_MESSAGE);

        MaximumDepthGuard::guardMaximumDepth(2, 2);
    }
}
