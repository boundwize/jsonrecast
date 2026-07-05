<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Attribute;

final class NodeAttributes
{
    public const START_OFFSET = 'startOffset';

    public const END_OFFSET = 'endOffset';

    public const ORIGINAL_TEXT = 'originalText';

    public const SOURCE = 'source';

    public const NEWLINE = 'newline';

    public const INDENT = 'indent';

    public const TRAILING_NEWLINE = 'trailingNewline';

    private function __construct()
    {
    }
}
