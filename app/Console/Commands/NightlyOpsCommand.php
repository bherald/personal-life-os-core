<?php

namespace App\Console\Commands;

use App\Controllers\NotificationController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;

class NightlyOpsCommand extends Command
{
    protected $signature = 'ops:nightly
                            {--dry-run : Show output without sending}';

    protected $description = 'Nightly ops health summary via Pushover';

    private function getStorageRoot(): string
    {
        return rtrim(config('services.storage.root', '/srv/nextcloud'), '/');
    }

    public function handle(): int
    {
        try {
            $message = $this->buildMessage();

            if ($this->option('dry-run')) {
                $this->line($message);

                return 0;
            }

            $notifier = app(NotificationController::class);
            $notifier->send('pushover', [
                'title' => 'PLOS Nightly Ops',
                'message' => $message,
                'format_type' => 'monospace',
                'priority' => 0,
                'sound' => 'none',
                'source_group' => 'daily_digests',
            ]);

            $this->info('Nightly ops notification sent.');
        } catch (\Exception $e) {
            Log::error('Nightly ops failed', ['error' => $e->getMessage()]);
            $this->error('Failed: '.$e->getMessage());

            return 1;
        }

        return 0;
    }

    private function buildMessage(): string
    {
        $date = now()->format('M d g:i A');
        $lines = ["PLOS Nightly Ops — {$date}"];

        // System load + memory
        $load = sys_getloadavg();
        $mem = $this->getMemory();
        $lines[] = sprintf(
            'Load: %.1f %.1f %.1f | Mem: %sG/%sG',
            $load[0], $load[1], $load[2],
            $mem['used_gb'], $mem['total_gb']
        );

        // GPU VRAM
        $gpu = $this->getGpuStatus();
        if ($gpu) {
            $lines[] = sprintf(
                'GPU: %s%% VRAM: %sG/%sG',
                $gpu['util'], $gpu['mem_used'], $gpu['mem_total']
            );
        } else {
            $lines[] = 'GPU: unavailable';
        }

        // Disk
        $rootPct = $this->diskPct('/');
        $storagePct = $this->diskPct($this->getStorageRoot());
        $lines[] = sprintf('Disk: / %d%% | Storage %d%%', $rootPct, $storagePct);

        // Backups
        $lines[] = $this->getBackupLine();

        // Pipeline
        $pipeline = $this->getPipelineStatus();
        $lines[] = sprintf('Pipeline: %sK files', round($pipeline['total'] / 1000));
        $lines[] = sprintf(
            '  Thumb:%s EXIF:%s Face:%s AI:%s',
            $this->shortNum($pipeline['thumbs']),
            $this->shortNum($pipeline['exif']),
            $this->shortNum($pipeline['faces']),
            $this->shortNum($pipeline['ai'])
        );
        $lines[] = sprintf(
            '  RAG:%s Write:%s',
            $this->shortNum($pipeline['rag']),
            $this->shortNum($pipeline['writeback'])
        );

        // Queues
        $queueParts = [];
        foreach ($this->getActiveQueueDepths() as $queueName => $depth) {
            if ($depth > 0) {
                $queueParts[] = "{$queueName}:{$depth}";
            }
        }
        $lines[] = 'Queue: '.(! empty($queueParts) ? implode(' ', $queueParts) : 'clear');

        // Agents (24h)
        $agentRuns = $this->safeQuery(
            fn ($sql) => (int) (DB::selectOne($sql)?->c ?? 0),
            'SELECT COUNT(*) as c FROM agent_episodes WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)'
        );
        $agentErrors = $this->safeQuery(
            fn ($sql) => (int) (DB::selectOne($sql)?->c ?? 0),
            "SELECT COUNT(*) as c FROM agent_episodes WHERE event_type = 'error' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $lines[] = sprintf('Agents: %d runs (24h) %d errors', $agentRuns, $agentErrors);

        // System errors (24h)
        $sysErrors = $this->safeQuery(
            fn ($sql) => (int) (DB::selectOne($sql)?->c ?? 0),
            'SELECT COUNT(*) as c FROM system_errors WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)'
        );
        $lines[] = sprintf('Errors: %d (24h)', $sysErrors);

        $msg = implode("\n", $lines);

        // Pushover monospace limit is 1024 chars
        if (strlen($msg) > 1024) {
            $msg = substr($msg, 0, 1021).'...';
        }

        return $msg;
    }

    private function getMemory(): array
    {
        $meminfo = @file_get_contents('/proc/meminfo');
        if (! $meminfo) {
            return ['total_gb' => '?', 'used_gb' => '?'];
        }

        preg_match('/MemTotal:\s+(\d+)/', $meminfo, $total);
        preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $avail);

        $totalKb = (int) ($total[1] ?? 0);
        $availKb = (int) ($avail[1] ?? 0);
        $usedKb = $totalKb - $availKb;

        return [
            'total_gb' => round($totalKb / 1048576, 0),
            'used_gb' => round($usedKb / 1048576, 0),
        ];
    }

    private function getGpuStatus(): ?array
    {
        $output = Process::timeout(5)->run([
            'nvidia-smi',
            '--query-gpu=utilization.gpu,memory.used,memory.total',
            '--format=csv,noheader,nounits',
        ])->output();
        if (! $output) {
            return null;
        }

        $parts = array_map('trim', explode(',', trim($output)));
        if (count($parts) < 3) {
            return null;
        }

        return [
            'util' => $parts[0],
            'mem_used' => round($parts[1] / 1024, 1),
            'mem_total' => round($parts[2] / 1024, 1),
        ];
    }

    private function diskPct(string $path): int
    {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if (! $total || $total == 0) {
            return 0;
        }

        return (int) round(($total - $free) / $total * 100);
    }

    private function getBackupLine(): string
    {
        $backupPath = storage_path('backups');
        $latestMysql = null;
        $latestPg = null;

        if (is_dir($backupPath)) {
            $files = scandir($backupPath);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $mtime = filemtime($backupPath.'/'.$file);
                $ageH = round((time() - $mtime) / 3600, 0);

                if (str_starts_with($file, 'mysql_backup') && ($latestMysql === null || $ageH < $latestMysql)) {
                    $latestMysql = $ageH;
                }
                if (str_starts_with($file, 'postgres_backup') && ($latestPg === null || $ageH < $latestPg)) {
                    $latestPg = $ageH;
                }
            }
        }

        $mysqlStr = $latestMysql !== null ? "{$latestMysql}h" : 'NONE';
        $pgStr = $latestPg !== null ? "{$latestPg}h" : 'NONE';
        $healthy = ($latestMysql !== null && $latestMysql < 25) && ($latestPg !== null && $latestPg < 25);

        return sprintf('Backups: MySQL %s | PG %s [%s]', $mysqlStr, $pgStr, $healthy ? 'OK' : 'STALE');
    }

    private function getPipelineStatus(): array
    {
        $q = fn (string $sql) => (int) (DB::selectOne($sql)?->c ?? 0);

        $total = $this->safeQuery($q, "SELECT COUNT(*) as c FROM file_registry WHERE status = 'active'");

        $thumbs = $this->safeQuery($q,
            "SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND thumbnail_generated_at IS NULL AND thumbnail_error IS NULL
             AND extension IN ('jpg','jpeg','png','gif','bmp','webp','tiff','tif','heic','heif','mp4','mov','avi','mkv','webm','wmv','m4v','flv','pdf','doc','docx','xls','xlsx','ppt','pptx','odt','ods','odp')");

        $exif = $this->safeQuery($q,
            "SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND (exif_checked IS NULL OR exif_checked = 0) AND extension IN ('jpg','jpeg','png','gif','bmp','webp','tiff','tif','heic','heif')");

        $faces = $this->safeQuery($q,
            "SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND face_scan_at IS NULL AND extension IN ('jpg','jpeg','png','gif','bmp','webp','tiff','tif','heic','heif')");

        $ai = $this->safeQuery($q,
            "SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND ai_analyzed_at IS NULL
             AND (ai_analysis_version IS NULL OR (ai_analysis_version != 'skipped' AND ai_analysis_version NOT LIKE 'fail:%'))
             AND extension IN ('jpg','jpeg','png','gif','bmp','webp','tiff','tif','heic','heif','pdf','doc','docx','rtf','odt','txt','xls','xlsx','csv','ppt','pptx')");

        $rag = $this->safeQuery($q,
            "SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND rag_indexed_at IS NULL
             AND (extension IN ('pdf','doc','docx','txt','rtf','odt','md','csv','html','htm','xls','xlsx','ppt','pptx')
                  OR (extension IN ('jpg','jpeg','png','gif','bmp','webp','tiff','tif','heic','heif') AND ai_description IS NOT NULL))");

        $writeback = $this->safeQuery($q,
            "SELECT COUNT(*) as c FROM file_registry
             WHERE status = 'active'
             AND extension IN ('jpg','jpeg','png','gif','bmp','webp','tiff','tif','heic','heif')
             AND date_taken IS NOT NULL
             AND date_taken_source NOT LIKE 'exif_%'
             AND (exif_written IS NULL OR exif_written = 0)
             AND date_taken_confidence >= 0.3");

        return [
            'total' => $total,
            'thumbs' => $thumbs,
            'exif' => $exif,
            'faces' => $faces,
            'ai' => $ai,
            'rag' => $rag,
            'writeback' => $writeback,
        ];
    }

    private function safeQuery(callable $fn, string $sql): int
    {
        try {
            return $fn($sql);
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function queueLen(string $queueName): int
    {
        try {
            $connection = config('queue.connections.redis.connection', 'default');
            $redis = Redis::connection($connection);

            return (int) ($redis->llen("queues:{$queueName}") ?? 0);
        } catch (\Exception $e) {
            return -1;
        }
    }

    /**
     * @return array<string, int>
     */
    private function getActiveQueueDepths(): array
    {
        $queueNames = array_values(array_unique(array_filter([
            config('queue.connections.redis.queue', 'default'),
            'high',
            'default',
            'low',
            'long-running',
            'workflow',
            'speculative',
        ])));

        $depths = [];
        foreach ($queueNames as $queueName) {
            $depths[$queueName] = $this->queueLen($queueName);
        }

        return $depths;
    }

    private function shortNum(int $n): string
    {
        if ($n >= 1000) {
            return round($n / 1000, 1).'K';
        }

        return (string) $n;
    }
}
