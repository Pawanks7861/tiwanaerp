<?php

namespace App\Providers;

use App\Integrations\Tally\TallySyncSubscriber;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class TallyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::subscribe(TallySyncSubscriber::class);
    }
}
