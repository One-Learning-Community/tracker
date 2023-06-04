<?php

namespace PragmaRX\Tracker\Data\Repositories;

class SystemClass extends Repository
{
    public function getCacheProvider()
    {
        return app('tracker.cache.local');
    }
}
