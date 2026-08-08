<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Printer\Helper;

use Boundwize\JsonRecast\Parser\NumberLexemeScanner;
use RuntimeException;

use function is_string;
use function json_encode;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Scalar spellings every printer emits identically: a scalar has no layout of
 * its own, so the encoded text does not depend on how the printer treats the
 * surrounding formatting.
 *
 * @internal
 */
final readonly class ScalarEncodeHelper
{
    /**
     * @param positive-int $maximumDepth
     */
    public static function encodeString(string $value, int $maximumDepth): string
    {
        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            $maximumDepth,
        );

        if (! is_string($encoded)) {
            throw new RuntimeException('Unable to encode JSON string.');
        }

        return $encoded;
    }

    public static function encodeNumber(string $rawValue): string
    {
        if (! NumberLexemeScanner::isValidLexeme($rawValue)) {
            throw new RuntimeException('Unable to encode JSON number.');
        }

        return $rawValue;
    }
}
