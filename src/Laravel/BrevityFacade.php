<?php

declare(strict_types=1);

namespace Vaslv\Brevity\Laravel;

use Illuminate\Support\Facades\Facade;

class BrevityFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'brevity.client';
    }
}
