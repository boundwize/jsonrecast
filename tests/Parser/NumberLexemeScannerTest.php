<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Parser;

use Boundwize\JsonRecast\Parser\NumberLexemeScanner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class NumberLexemeScannerTest extends TestCase
{
    public function testItCannotBeConstructedForState(): void
    {
        $reflectionClass = new ReflectionClass(NumberLexemeScanner::class);
        $constructor     = $reflectionClass->getConstructor();

        $this->assertInstanceOf(ReflectionMethod::class, $constructor);

        $numberLexemeScanner = $reflectionClass->newInstanceWithoutConstructor();
        $constructor->invoke($numberLexemeScanner);

        $this->assertInstanceOf(NumberLexemeScanner::class, $numberLexemeScanner);
    }

    public function testItScansNumberInsideLargerSource(): void
    {
        $numberLexemeScanResult = NumberLexemeScanner::scan('[-0.5e+10,2]', 1);

        $this->assertNull($numberLexemeScanResult->errorMessage);
        $this->assertSame(9, $numberLexemeScanResult->endOffset);
    }

    public function testItReportsOffendingOffsetOnError(): void
    {
        $numberLexemeScanResult = NumberLexemeScanner::scan('[01]', 1);

        $this->assertSame('Leading zero is not allowed in JSON number.', $numberLexemeScanResult->errorMessage);
        $this->assertSame(2, $numberLexemeScanResult->endOffset);
    }

    public function testItValidatesCompleteLexemesOnly(): void
    {
        $this->assertTrue(NumberLexemeScanner::isValidLexeme('-0.5e+10'));
        $this->assertFalse(NumberLexemeScanner::isValidLexeme('1,2'));
    }
}
