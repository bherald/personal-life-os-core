<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportMBFCRatings extends Command
{
    protected $signature = 'mbfc:import
                            {--file= : Path to local MBFC CSV file (optional, uses GitHub if not provided)}
                            {--fresh : Truncate MBFC entries before import (keeps AllSides data)}
                            {--merge : Merge with existing AllSides entries where possible}';

    protected $description = 'Import Media Bias/Fact Check ratings - adds ~3000 sources with factual accuracy ratings';

    /**
     * GitHub source for MBFC data (idiap research dataset)
     * Contains: source (domain), bias (rating), factual_reporting
     */
    private const GITHUB_CSV_URL = 'https://raw.githubusercontent.com/idiap/Factual-Reporting-and-Political-Bias-Web-Interactions/main/data/mbfc.csv';

    /**
     * Map MBFC bias labels to our rating system
     */
    private const BIAS_MAP = [
        'left' => ['rating' => 'left', 'rating_num' => -2],
        'left-center' => ['rating' => 'left-center', 'rating_num' => -1],
        'neutral' => ['rating' => 'center', 'rating_num' => 0],
        'center' => ['rating' => 'center', 'rating_num' => 0],
        'right-center' => ['rating' => 'right-center', 'rating_num' => 1],
        'right' => ['rating' => 'right', 'rating_num' => 2],
        'pro-science' => ['rating' => 'center', 'rating_num' => 0],
        'satire' => ['rating' => 'center', 'rating_num' => 0],
        'conspiracy-pseudoscience' => ['rating' => 'right', 'rating_num' => 2],
        'questionable' => ['rating' => 'right', 'rating_num' => 2],
    ];

    /**
     * Map MBFC factual ratings to credibility scores (0-100)
     */
    private const FACTUAL_MAP = [
        'very high' => ['factual' => 'Very High', 'score' => 95],
        'high' => ['factual' => 'High', 'score' => 80],
        'mostly factual' => ['factual' => 'High', 'score' => 75],
        'mixed' => ['factual' => 'Mixed', 'score' => 50],
        'low' => ['factual' => 'Low', 'score' => 25],
        'very low' => ['factual' => 'Very Low', 'score' => 10],
    ];

    public function handle()
    {
        $this->info('🔍 MBFC Import - Media Bias/Fact Check Ratings');
        $this->newLine();

        // Get CSV data
        $csv = $this->getCsvData();
        if (! $csv) {
            return 1;
        }

        $lines = explode("\n", $csv);
        $header = str_getcsv(array_shift($lines));

        // Validate header
        if (! in_array('source', $header) || ! in_array('bias', $header)) {
            $this->error('Invalid CSV format - expected columns: source, bias, factual_reporting');

            return 1;
        }

        // Handle fresh import (only removes MBFC entries, keeps AllSides)
        if ($this->option('fresh')) {
            $this->warn('Removing existing MBFC-only entries...');
            $deleted = DB::delete('DELETE FROM bias_ratings WHERE data_source = ?', ['mbfc']);
            $this->info("Removed {$deleted} MBFC-only entries");
        }

        $this->info('Importing '.count($lines).' MBFC records...');
        $bar = $this->output->createProgressBar(count($lines));

        $imported = 0;
        $merged = 0;
        $skipped = 0;

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            $row = str_getcsv($line);
            if (count($row) < count($header)) {
                $skipped++;
                $bar->advance();

                continue;
            }

            $data = array_combine($header, $row);

            try {
                $result = $this->processEntry($data);
                if ($result === 'imported') {
                    $imported++;
                } elseif ($result === 'merged') {
                    $merged++;
                } else {
                    $skipped++;
                }
            } catch (\Exception $e) {
                $this->warn("\nError processing {$data['source']}: ".$e->getMessage());
                $skipped++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("✓ Imported: {$imported}");
        if ($merged > 0) {
            $this->info("✓ Merged with AllSides: {$merged}");
        }
        if ($skipped > 0) {
            $this->warn("⚠ Skipped: {$skipped}");
        }

        // Show statistics
        $this->showStats();

        return 0;
    }

    private function getCsvData(): ?string
    {
        $file = $this->option('file');

        if ($file) {
            if (! file_exists($file)) {
                $this->error("File not found: {$file}");

                return null;
            }
            $this->info("Reading from local file: {$file}");

            return file_get_contents($file);
        }

        $this->info('Downloading MBFC data from GitHub (idiap research dataset)...');
        $this->info('Source: '.self::GITHUB_CSV_URL);
        $this->newLine();

        $response = Http::timeout(120)->get(self::GITHUB_CSV_URL);

        if (! $response->successful()) {
            $this->error('Failed to download CSV from GitHub (HTTP '.$response->status().')');

            return null;
        }

        return $response->body();
    }

    private function processEntry(array $data): string
    {
        $domain = strtolower(trim($data['source'] ?? ''));
        $biasRaw = strtolower(trim($data['bias'] ?? ''));
        $factualRaw = strtolower(trim($data['factual_reporting'] ?? 'mixed'));

        if (empty($domain) || empty($biasRaw)) {
            return 'skipped';
        }

        // Map bias rating
        $biasData = self::BIAS_MAP[$biasRaw] ?? ['rating' => 'center', 'rating_num' => 0];

        // Map factual rating
        $factualData = self::FACTUAL_MAP[$factualRaw] ?? ['factual' => 'Mixed', 'score' => 50];

        // Generate a readable source name from domain
        $sourceName = $this->domainToSourceName($domain);

        // Check if source exists in AllSides data
        $existing = DB::selectOne('
            SELECT id FROM bias_ratings
            WHERE news_source = ? OR url LIKE ? OR screen_name = ?
            LIMIT 1
        ', [$sourceName, "%{$domain}%", $domain]);

        if ($existing) {
            // Merge MBFC data into existing AllSides entry
            DB::update('
                UPDATE bias_ratings
                SET data_source = ?, mbfc_factual_rating = ?, mbfc_credibility_score = ?, updated_at = NOW()
                WHERE id = ?
            ', ['both', $factualData['factual'], $factualData['score'], $existing->id]);

            return 'merged';
        }

        // Upsert new MBFC entry
        $existingByName = DB::selectOne('SELECT id FROM bias_ratings WHERE news_source = ? LIMIT 1', [$sourceName]);

        if ($existingByName) {
            DB::update('
                UPDATE bias_ratings SET
                    rating = ?, rating_num = ?, type = ?, url = ?, screen_name = ?,
                    data_source = ?, mbfc_factual_rating = ?, mbfc_credibility_score = ?, updated_at = NOW()
                WHERE id = ?
            ', [
                $biasData['rating'], $biasData['rating_num'], 'News Media',
                "https://{$domain}", $domain, 'mbfc',
                $factualData['factual'], $factualData['score'], $existingByName->id,
            ]);
        } else {
            DB::insert('
                INSERT INTO bias_ratings (
                    news_source, rating, rating_num, type, url, screen_name,
                    data_source, mbfc_factual_rating, mbfc_credibility_score, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ', [
                $sourceName, $biasData['rating'], $biasData['rating_num'], 'News Media',
                "https://{$domain}", $domain, 'mbfc',
                $factualData['factual'], $factualData['score'],
            ]);
        }

        return 'imported';
    }

    private function domainToSourceName(string $domain): string
    {
        // Remove common prefixes
        $domain = preg_replace('/^(www\.|m\.|mobile\.|feeds?\.)/', '', $domain);

        // Extract name from domain
        $parts = explode('.', $domain);
        $name = $parts[0];

        // Title case and clean up
        $name = ucwords(str_replace(['-', '_'], ' ', $name));

        return $name;
    }

    private function showStats(): void
    {
        $this->newLine();
        $this->info('📊 Database Statistics:');

        $total = DB::selectOne('SELECT COUNT(*) as cnt FROM bias_ratings')->cnt;
        $allsides = DB::selectOne('SELECT COUNT(*) as cnt FROM bias_ratings WHERE data_source = ?', ['allsides'])->cnt;
        $mbfc = DB::selectOne('SELECT COUNT(*) as cnt FROM bias_ratings WHERE data_source = ?', ['mbfc'])->cnt;
        $both = DB::selectOne('SELECT COUNT(*) as cnt FROM bias_ratings WHERE data_source = ?', ['both'])->cnt;
        $manual = DB::selectOne('SELECT COUNT(*) as cnt FROM bias_ratings WHERE data_source = ?', ['manual'])->cnt;

        $this->table(
            ['Source', 'Count'],
            [
                ['AllSides Only', $allsides],
                ['MBFC Only', $mbfc],
                ['Both Sources', $both],
                ['Manual Ratings', $manual],
                ['Total', $total],
            ]
        );

        // Show sample with MBFC data
        $this->newLine();
        $this->info('Sample entries with MBFC data:');
        $samples = DB::select('SELECT * FROM bias_ratings WHERE mbfc_factual_rating IS NOT NULL ORDER BY mbfc_credibility_score DESC LIMIT 5');

        foreach ($samples as $sample) {
            $emoji = $this->getEmoji($sample->rating);
            $this->line("  {$emoji} {$sample->news_source}: {$sample->rating} | Factual: {$sample->mbfc_factual_rating} | Credibility: {$sample->mbfc_credibility_score}%");
        }
    }

    private function getEmoji(string $rating): string
    {
        return match ($rating) {
            'left' => '⬅️',
            'left-center' => '↙️',
            'center' => '⬆️',
            'right-center' => '↗️',
            'right' => '➡️',
            default => '⚖️',
        };
    }
}
