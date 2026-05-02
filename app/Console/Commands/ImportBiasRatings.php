<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportBiasRatings extends Command
{
    protected $signature = 'bias:import {--fresh : Truncate table before import}';
    protected $description = 'Import AllSides bias ratings from CSV';

    public function handle()
    {
        $url = 'https://raw.githubusercontent.com/favstats/AllSideR/master/data/allsides_data.csv';

        $this->info('Downloading AllSides bias ratings...');

        $response = Http::timeout(60)->get($url);

        if (!$response->successful()) {
            $this->error('Failed to download CSV from GitHub');
            return 1;
        }

        $csv = $response->body();
        $lines = explode("\n", $csv);
        $header = str_getcsv(array_shift($lines));

        if ($this->option('fresh')) {
            $this->warn('Truncating bias_ratings table...');
            DB::statement('TRUNCATE TABLE bias_ratings');
        }

        $this->info('Importing ' . count($lines) . ' records...');
        $bar = $this->output->createProgressBar(count($lines));

        $imported = 0;
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
                $existing = DB::selectOne("SELECT id FROM bias_ratings WHERE news_source = ? LIMIT 1", [$data['news_source']]);

                if ($existing) {
                    DB::update("
                        UPDATE bias_ratings SET
                            rating = ?, rating_num = ?, type = ?, agree = ?, disagree = ?,
                            perc_agree = ?, url = ?, editorial_review = ?, blind_survey = ?,
                            third_party_analysis = ?, independent_research = ?, confidence_level = ?,
                            twitter = ?, wiki = ?, facebook = ?, screen_name = ?, updated_at = NOW()
                        WHERE id = ?
                    ", [
                        $data['rating'] ?: null,
                        is_numeric($data['rating_num']) ? (int)$data['rating_num'] : null,
                        $data['type'] ?: null,
                        is_numeric($data['agree']) ? (int)$data['agree'] : null,
                        is_numeric($data['disagree']) ? (int)$data['disagree'] : null,
                        is_numeric($data['perc_agree']) ? (float)$data['perc_agree'] : null,
                        $data['url'] ?: null,
                        $data['editorial_review'] === '1' || $data['editorial_review'] === 'checked' ? 1 : 0,
                        $data['blind_survey'] === '1' ? 1 : 0,
                        $data['third_party_analysis'] === '1' ? 1 : 0,
                        $data['independent_research'] === '1' ? 1 : 0,
                        $data['confidence_level'] ?: null,
                        $data['twitter'] ?: null,
                        $data['wiki'] ?: null,
                        $data['facebook'] ?: null,
                        $data['screen_name'] ?: null,
                        $existing->id,
                    ]);
                } else {
                    DB::insert("
                        INSERT INTO bias_ratings (
                            news_source, rating, rating_num, type, agree, disagree,
                            perc_agree, url, editorial_review, blind_survey,
                            third_party_analysis, independent_research, confidence_level,
                            twitter, wiki, facebook, screen_name, created_at, updated_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ", [
                        $data['news_source'],
                        $data['rating'] ?: null,
                        is_numeric($data['rating_num']) ? (int)$data['rating_num'] : null,
                        $data['type'] ?: null,
                        is_numeric($data['agree']) ? (int)$data['agree'] : null,
                        is_numeric($data['disagree']) ? (int)$data['disagree'] : null,
                        is_numeric($data['perc_agree']) ? (float)$data['perc_agree'] : null,
                        $data['url'] ?: null,
                        $data['editorial_review'] === '1' || $data['editorial_review'] === 'checked' ? 1 : 0,
                        $data['blind_survey'] === '1' ? 1 : 0,
                        $data['third_party_analysis'] === '1' ? 1 : 0,
                        $data['independent_research'] === '1' ? 1 : 0,
                        $data['confidence_level'] ?: null,
                        $data['twitter'] ?: null,
                        $data['wiki'] ?: null,
                        $data['facebook'] ?: null,
                        $data['screen_name'] ?: null,
                    ]);
                }
                $imported++;
            } catch (\Exception $e) {
                $this->warn("\nError importing {$data['news_source']}: " . $e->getMessage());
                $skipped++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Imported: {$imported}");
        if ($skipped > 0) {
            $this->warn("Skipped: {$skipped}");
        }

        $this->newLine();
        $this->info('Sample ratings:');
        $samples = DB::select("SELECT * FROM bias_ratings WHERE news_source IN (?, ?, ?, ?, ?)", [
            'NPR Online News', 'Fox Online News', 'CNN (Web News)', 'BBC News', 'Associated Press',
        ]);

        foreach ($samples as $sample) {
            $this->line("  {$sample->news_source}: {$sample->rating} (confidence: {$sample->confidence_level})");
        }

        return 0;
    }
}
