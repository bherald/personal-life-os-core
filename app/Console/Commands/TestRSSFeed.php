<?php

namespace App\Console\Commands;

use App\Nodes\RSSFeedReader;
use Illuminate\Console\Command;

class TestRSSFeed extends Command
{
    private const RUNTIME_POLICY_SOURCE = 'system_configs.rss.reader_runtime_policy';

    protected $signature = 'rss:test
        {url : RSS or Atom feed URL to fetch}
        {--limit= : Number of articles to fetch; defaults to the RSS reader runtime policy}
        {--timeout= : Fetch timeout in seconds; defaults to the RSS reader runtime policy}';

    protected $description = 'Fetch and display an RSS feed without persisting articles or health state';

    public function handle(): int
    {
        $limit = $this->positiveIntegerOption('limit');
        if ($limit === false) {
            $this->error('--limit must be a positive integer.');

            return self::INVALID;
        }

        $timeout = $this->positiveIntegerOption('timeout');
        if ($timeout === false) {
            $this->error('--timeout must be a positive integer.');

            return self::INVALID;
        }

        $url = trim((string) $this->argument('url'));
        if ($url === '') {
            $this->error('url must not be empty.');

            return self::INVALID;
        }
        $readerConfig = [
            'feed_url' => $url,
            'include_content' => false,
            'persist_articles' => false,
            'track_health' => false,
        ];
        if ($limit !== null) {
            $readerConfig['limit'] = $limit;
        }
        if ($timeout !== null) {
            $readerConfig['timeout'] = $timeout;
        }

        $displayUrl = $this->displayUrl($url);
        $this->info('Testing RSS feed');
        $this->line('URL: '.$displayUrl);
        $this->line('Policy: '.self::RUNTIME_POLICY_SOURCE);
        $this->line('Requested limit: '.($limit ?? 'table default'));
        $this->line('Requested timeout: '.($timeout !== null ? $timeout.'s' : 'table default'));
        $this->line('Writes: article persistence off; feed health tracking off; normal fetch telemetry active');
        $this->newLine();
        $this->info('Fetching feed...');

        /** @var RSSFeedReader $reader */
        $reader = app()->makeWith(RSSFeedReader::class, ['config' => $readerConfig]);
        $result = $reader->execute([]);

        if (($result['error'] ?? null) !== null && $result['error'] !== '') {
            $this->error('Error: '.str_replace($url, $displayUrl, (string) $result['error']));

            return self::FAILURE;
        }

        $this->info('Success');
        $this->newLine();

        $meta = is_array($result['meta'] ?? null) ? $result['meta'] : [];
        if ($meta !== []) {
            $this->line('Metadata:');
            foreach ($meta as $key => $value) {
                if ($key === 'feed_url' && is_string($value)) {
                    $value = $this->displayUrl($value);
                }
                $this->line(sprintf('  %s: %s', $key, $this->displayValue($value)));
            }
            $this->newLine();
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $formatted = $data['formatted_text'] ?? null;
        if (is_string($formatted) && $formatted !== '') {
            $this->line('Articles:');
            $this->line(str_repeat('=', 80));
            $this->line(str_replace($url, $displayUrl, $formatted));
        }

        return self::SUCCESS;
    }

    private function positiveIntegerOption(string $name): int|false|null
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
    }

    private function displayValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function displayUrl(string $url): string
    {
        if (str_starts_with(strtolower($url), 'data:')) {
            return 'data://[inline-feed]';
        }

        $withoutSecrets = preg_replace('/[?#].*$/', '', $url) ?? $url;

        return preg_replace('#^([a-z][a-z0-9+.-]*://)[^/@]+@#i', '$1', $withoutSecrets) ?? $withoutSecrets;
    }
}
