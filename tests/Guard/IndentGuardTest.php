<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Guard;

use Boundwize\JsonRecast\Guard\IndentGuard;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class IndentGuardTest extends TestCase
{
    public function testItCannotBeConstructedForState(): void
    {
        $reflectionClass = new ReflectionClass(IndentGuard::class);
        $constructor     = $reflectionClass->getConstructor();

        $this->assertInstanceOf(ReflectionMethod::class, $constructor);

        $indentGuard = $reflectionClass->newInstanceWithoutConstructor();
        $constructor->invoke($indentGuard);

        $this->assertInstanceOf(IndentGuard::class, $indentGuard);
    }

    public function testItAcceptsWhitespaceOnlyIndent(): void
    {
        $this->assertSame('', IndentGuard::validateIndent(''));
        $this->assertSame('  ', IndentGuard::validateIndent('  '));
        $this->assertSame("\t", IndentGuard::validateIndent("\t"));
        $this->assertSame("\t  ", IndentGuard::validateIndent("\t  "));
    }

    public function testItRejectsNonWhitespaceIndent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Indent must contain only spaces or tabs.');

        IndentGuard::validateIndent('x');
    }

    public function testItRejectsNewlineIndent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Indent must contain only spaces or tabs.');

        IndentGuard::validateIndent("  \n");
    }
}
