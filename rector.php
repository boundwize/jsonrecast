<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\PHPUnit\PHPUnit60\Rector\ClassMethod\AddDoesNotPerformAssertionToNonAssertingTestRector;

return RectorConfig::configure()
    ->withSkip([
        '**/Fixtures/**',
        AddDoesNotPerformAssertionToNonAssertingTestRector::class,
    ])
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withRootFiles()
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        typeDeclarationDocblocks: true,
        privatization: true,
        naming: true,
        phpunitCodeQuality: true
    )
    ->withComposerBased(phpunit: true)
    ->withImportNames(removeUnusedImports: true);
