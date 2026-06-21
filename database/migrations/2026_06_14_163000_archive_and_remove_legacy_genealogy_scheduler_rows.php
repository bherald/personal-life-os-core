<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RETIREMENT_GROUP = 'genealogy_legacy_agents_2026_06_14';

    private const JOB_NAMES = [
        'genealogy_auto_research',
        'genealogy_research_colonial_fan',
        'genealogy_newspaper_research',
        'genealogy_analyst',
        'genealogy_media_enrichment_batch',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $this->ensureArchiveTables();

        DB::transaction(function (): void {
            $jobs = DB::table('scheduled_jobs')
                ->whereIn('name', self::JOB_NAMES)
                ->orderBy('id')
                ->get();

            foreach ($jobs as $job) {
                $this->archiveJob($job);

                if (Schema::hasTable('scheduled_job_runs')) {
                    DB::table('scheduled_job_runs')
                        ->where('scheduled_job_id', $job->id)
                        ->orderBy('id')
                        ->chunkById(200, function ($runs) use ($job): void {
                            foreach ($runs as $run) {
                                $this->archiveRun($job, $run);
                            }
                        });
                }
            }

            DB::table('scheduled_jobs')
                ->whereIn('name', self::JOB_NAMES)
                ->delete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs') || ! Schema::hasTable('retired_scheduled_jobs')) {
            return;
        }

        DB::transaction(function (): void {
            $jobColumns = array_flip(Schema::getColumnListing('scheduled_jobs'));

            $archives = DB::table('retired_scheduled_jobs')
                ->where('retirement_group', self::RETIREMENT_GROUP)
                ->orderBy('original_scheduled_job_id')
                ->get();

            foreach ($archives as $archive) {
                $payload = json_decode((string) $archive->job_payload, true);
                if (! is_array($payload)) {
                    continue;
                }

                $row = array_intersect_key($payload, $jobColumns);
                if (! isset($row['id'], $row['name'])) {
                    continue;
                }

                $existsById = DB::table('scheduled_jobs')->where('id', $row['id'])->exists();
                $existsByName = DB::table('scheduled_jobs')->where('name', $row['name'])->exists();

                if (! $existsById && ! $existsByName) {
                    DB::table('scheduled_jobs')->insert($row);
                }
            }

            if (! Schema::hasTable('scheduled_job_runs') || ! Schema::hasTable('retired_scheduled_job_runs')) {
                return;
            }

            $runColumns = array_flip(Schema::getColumnListing('scheduled_job_runs'));

            DB::table('retired_scheduled_job_runs')
                ->where('retirement_group', self::RETIREMENT_GROUP)
                ->orderBy('original_run_id')
                ->chunkById(200, function ($archives) use ($runColumns): void {
                    foreach ($archives as $archive) {
                        $payload = json_decode((string) $archive->run_payload, true);
                        if (! is_array($payload)) {
                            continue;
                        }

                        $row = array_intersect_key($payload, $runColumns);
                        if (! isset($row['id'], $row['scheduled_job_id'])) {
                            continue;
                        }

                        $jobExists = DB::table('scheduled_jobs')->where('id', $row['scheduled_job_id'])->exists();
                        $runExists = DB::table('scheduled_job_runs')->where('id', $row['id'])->exists();

                        if ($jobExists && ! $runExists) {
                            DB::table('scheduled_job_runs')->insert($row);
                        }
                    }
                }, 'original_run_id');
        });
    }

    private function ensureArchiveTables(): void
    {
        if (! Schema::hasTable('retired_scheduled_jobs')) {
            Schema::create('retired_scheduled_jobs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('original_scheduled_job_id')->unique();
                $table->string('name')->index();
                $table->string('command', 500)->nullable();
                $table->string('retirement_group')->index();
                $table->text('retired_reason');
                $table->json('job_payload');
                $table->timestamp('archived_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('retired_scheduled_job_runs')) {
            Schema::create('retired_scheduled_job_runs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('original_run_id')->unique();
                $table->unsignedInteger('original_scheduled_job_id')->index();
                $table->string('job_name')->index();
                $table->string('status', 32)->nullable()->index();
                $table->timestamp('started_at')->nullable()->index();
                $table->timestamp('completed_at')->nullable();
                $table->string('retirement_group')->index();
                $table->json('run_payload');
                $table->timestamp('archived_at')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    private function archiveJob(object $job): void
    {
        $now = now();

        DB::table('retired_scheduled_jobs')->updateOrInsert(
            ['original_scheduled_job_id' => (int) $job->id],
            [
                'name' => (string) $job->name,
                'command' => $job->command,
                'retirement_group' => self::RETIREMENT_GROUP,
                'retired_reason' => 'Legacy broad Genea scheduler lane fully removed after queue-driven FT4 work replaced it. Archived before deletion because scheduled_job_runs cascades on scheduled_jobs deletes.',
                'job_payload' => json_encode((array) $job, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'archived_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    private function archiveRun(object $job, object $run): void
    {
        $now = now();

        DB::table('retired_scheduled_job_runs')->updateOrInsert(
            ['original_run_id' => (int) $run->id],
            [
                'original_scheduled_job_id' => (int) $job->id,
                'job_name' => (string) $job->name,
                'status' => $run->status,
                'started_at' => $run->started_at,
                'completed_at' => $run->completed_at,
                'retirement_group' => self::RETIREMENT_GROUP,
                'run_payload' => json_encode((array) $run, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'archived_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }
};
