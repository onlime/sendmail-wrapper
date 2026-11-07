<?php

declare(strict_types=1);

arch('strict types')
    ->expect('Onlime\SendmailWrapper')
    ->toUseStrictTypes();

arch('no debugging functions')
    ->expect(['dd', 'dump', 'var_dump', 'print_r', 'ray'])
    ->not->toBeUsed();
