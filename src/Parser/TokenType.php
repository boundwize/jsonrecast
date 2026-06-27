<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Parser;

enum TokenType
{
    case LeftBrace;
    case RightBrace;
    case LeftBracket;
    case RightBracket;
    case Colon;
    case Comma;
    case String;
    case Number;
    case True;
    case False;
    case Null;
    case Whitespace;
    case EndOfFile;
}
