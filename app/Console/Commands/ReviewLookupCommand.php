<?php

namespace App\Console\Commands;

use App\Services\Review\ReviewLookupService;
use Illuminate\Console\Command;

final class ReviewLookupCommand extends Command
{
    protected $signature = 'review:lookup {reference : Exact review reference or known alias}';

    protected $description = 'Read one Review Desk item by reference';

    public function handle(ReviewLookupService $lookup): int
    {
        $item = $lookup->lookup((string) $this->argument('reference'));
        $this->line(json_encode($item, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
