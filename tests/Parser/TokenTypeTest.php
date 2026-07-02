<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Parser;

use Boundwize\JsonRecast\Parser\TokenType;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class TokenTypeTest extends TestCase
{
    public function testTokenTypeIsNotInstantiable(): void
    {
        $reflectionClass = new ReflectionClass(TokenType::class);
        $constructor     = $reflectionClass->getConstructor();

        $this->assertTrue($reflectionClass->isFinal());
        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate());
        $this->assertNull($constructor->invoke($reflectionClass->newInstanceWithoutConstructor()));
    }
}
