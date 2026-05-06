<?php

namespace ArtOfWifi\StatamicIndexnow\Listeners;

use ArtOfWifi\StatamicIndexnow\Http\Controllers\IndexNowUtilityController;
use Statamic\Events\EntrySaved;

class ClearEntriesCache
{
    public function handle(EntrySaved $event): void
    {
        IndexNowUtilityController::clearCache();
    }
}
