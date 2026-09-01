<?php

declare(strict_types=1);

arch('no debug statements leak into the codebase')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die', 'exit'])
    ->not->toBeUsed();

arch('strict types everywhere in app/')
    ->expect('App')
    ->toUseStrictTypes();

arch('shared concern helpers are traits')
    ->expect('App\Support\Concerns')
    ->toBeTraits();

arch('enum helpers live under Concerns')
    ->expect('App\Enums\Concerns\HasLabel')
    ->toBeTrait();
