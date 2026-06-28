<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\NodePath;

use Boundwize\JsonRecast\NodePath\NodeJsonPath;
use Boundwize\JsonRecast\NodePath\NodeJsonPathSegment;
use PHPUnit\Framework\TestCase;

final class NodeJsonPathTest extends TestCase
{
    public function testItExposesSegmentsAndDepth(): void
    {
        $nodeJsonPath = (new NodeJsonPath())
            ->childObjectKey('items')
            ->childArrayIndex(0)
            ->childObjectKey('name');

        $this->assertSame(3, $nodeJsonPath->depth());
        $this->assertCount(3, $nodeJsonPath->segments());
        $this->assertFalse($nodeJsonPath->isRoot());
        $this->assertInstanceOf(NodeJsonPathSegment::class, $nodeJsonPath->last());
    }

    public function testItMatchesObjectKeys(): void
    {
        $nodeJsonPath = (new NodeJsonPath())
            ->childObjectKey('autoload')
            ->childObjectKey('psr-4');

        $this->assertTrue($nodeJsonPath->matchesObjectKeys(['autoload', 'psr-4']));
        $this->assertFalse($nodeJsonPath->matchesObjectKeys(['autoload']));
        $this->assertFalse($nodeJsonPath->matchesObjectKeys(['autoload', 'files']));
        $this->assertFalse($nodeJsonPath->childArrayIndex(0)->matchesObjectKeys(['autoload', 'psr-4', '0']));
    }

    public function testItMatchesMixedSegments(): void
    {
        $nodeJsonPath = (new NodeJsonPath())
            ->childObjectKey('items')
            ->childArrayIndex(0)
            ->childObjectKey('name');

        $this->assertTrue($nodeJsonPath->matches(['items', 0, 'name']));
        $this->assertFalse($nodeJsonPath->matches(['items', 0]));
        $this->assertFalse($nodeJsonPath->matches(['packages', 0, 'name']));
        $this->assertFalse($nodeJsonPath->matches(['items', 1, 'name']));
        $this->assertFalse((new NodeJsonPath())->childObjectKey('items')->matches([0]));
    }
}
