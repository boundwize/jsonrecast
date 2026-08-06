<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Guard;

use Boundwize\JsonRecast\Guard\NewlineGuard;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class NewlineGuardTest extends TestCase
{
    public function testItCannotBeConstructedForState(): void
    {
        $reflectionClass = new ReflectionClass(NewlineGuard::class);
        $constructor     = $reflectionClass->getConstructor();

        $this->assertInstanceOf(ReflectionMethod::class, $constructor);

        $newlineGuard = $reflectionClass->newInstanceWithoutConstructor();
        $constructor->invoke($newlineGuard);

        $this->assertInstanceOf(NewlineGuard::class, $newlineGuard);
    }

    public function testItAcceptsSupportedNewlineSequences(): void
    {
        $this->assertSame("\n", NewlineGuard::validateNewline("\n"));
        $this->assertSame("\r\n", NewlineGuard::validateNewline("\r\n"));
        $this->assertSame("\r", NewlineGuard::validateNewline("\r"));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNewlineProvider(): iterable
    {
        yield 'plain text' => ['x'];
        yield 'empty string' => [''];
        yield 'newline with trailing text' => ["\nX"];
        yield 'text between newline characters' => ["\rX\n"];
        yield 'doubled newline' => ["\n\n"];
    }

    #[DataProvider('invalidNewlineProvider')]
    public function testItRejectsUnsupportedNewline(string $newline): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Newline must be "\n", "\r\n", or "\r".');

        NewlineGuard::validateNewline($newline);
    }
}
