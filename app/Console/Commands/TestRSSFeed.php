<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Nodes\RSSFeedReader;

class TestRSSFeed extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rss:test {url} {--limit=5 : Number of articles to fetch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test an RSS feed and display results';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $url = $this->argument('url');
        $limit = $this->option('limit');

        $this->info('🔍 Testing RSS Feed');
        $this->info('URL: ' . $url);
        $this->info('Limit: ' . $limit);
        $this->newLine();

        // Create RSS reader
        $reader = new RSSFeedReader([
            'feed_url' => $url,
            'limit' => $limit,
            'timeout' => 10,
            'include_content' => false
        ]);

        // Execute
        $this->info('Fetching feed...');
        $result = $reader->execute([]);

        // Display results
        if (isset($result['error']) && $result['error']) {
            $this->error('❌ Error: ' . $result['error']);
            return 1;
        }

        $this->info('✅ Success!');
        $this->newLine();

        // Display metadata
        if (isset($result['meta'])) {
            $this->line('📊 Metadata:');
            foreach ($result['meta'] as $key => $value) {
                $this->line("  {$key}: {$value}");
            }
            $this->newLine();
        }

        // Display formatted data
        if (isset($result['data'])) {
            $this->line('📰 Articles:');
            $this->line(str_repeat('=', 80));
            $this->line($result['data']);
        }

        return 0;
    }
}
