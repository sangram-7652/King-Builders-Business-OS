<?php

declare(strict_types=1);

use Tests\TestCase;

/*
| ---------------------------------------------------------------------------
| Test Case bindings
| ---------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)->in('Unit');

pest()->extend(TestCase::class)
    ->beforeEach(fn () => $this->withoutVite())
    ->in('Feature');
