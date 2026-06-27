<?php

declare(strict_types=1);

use Boundwize\JsonRecast\JsonRecast;
use Boundwize\Pyrameter\Config\PyrameterConfig;
use Boundwize\Pyrameter\TestKind;

return PyrameterConfig::defaults()
    ->usesClass(JsonRecast::class, TestKind::Functional)
    ->targetShape(
        unit: ['min' => 80],
        functional: ['max' => 20],
        integration: ['max' => 0],
        e2e: ['max' => 0],
    )
    ->failOnViolation();
