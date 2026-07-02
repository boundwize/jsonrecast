<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Parser;

final class TokenType
{
    public const LEFT_BRACE = 'LeftBrace';

    public const RIGHT_BRACE = 'RightBrace';

    public const LEFT_BRACKET = 'LeftBracket';

    public const RIGHT_BRACKET = 'RightBracket';

    public const COLON = 'Colon';

    public const COMMA = 'Comma';

    public const STRING = 'String';

    public const NUMBER = 'Number';

    public const TRUE = 'True';

    public const FALSE = 'False';

    public const NULL = 'Null';

    public const WHITESPACE = 'Whitespace';

    public const END_OF_FILE = 'EndOfFile';

    private function __construct()
    {
    }
}
