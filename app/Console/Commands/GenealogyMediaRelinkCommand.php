<?php

namespace App\Console\Commands;

use App\Services\Genealogy\GenealogyTreeRootResolver;
use App\Services\NextcloudFileApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class GenealogyMediaRelinkCommand extends Command
{
    protected $signature = 'genealogy:media-relink
        {--tree= : Required tree ID}
        {--ids= : Comma-separated exact media IDs, at most 20}
        {--apply : Apply only after reviewing the dry run}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Repair exact genealogy media paths when a relocated file matches retained hash evidence';

    public function handle(NextcloudFileApiService $files, GenealogyTreeRootResolver $roots): int
    {
        $treeId = filter_var($this->option('tree'), FILTER_VALIDATE_INT);
        $ids = array_map('trim', explode(',', (string) $this->option('ids')));
        if (! $treeId || $treeId < 1 || $ids === [''] || count($ids) > 20
            || count(array_unique($ids)) !== count($ids)
            || count(array_filter($ids, static fn (string $id): bool => ! ctype_digit($id) || (int) $id < 1)) > 0) {
            return $this->finish(['status' => 'invalid_input', 'error' => 'Require one tree and 1-20 unique positive media IDs.'], self::INVALID);
        }

        $root = rtrim($roots->mediaRoot($treeId), '/').'/';
        $apply = (bool) $this->option('apply');
        try {
            $result = DB::transaction(function () use ($treeId, $ids, $root, $files, $apply): array {
                $planned = [];
                foreach ($ids as $id) {
                    $media = DB::table('genealogy_media')->where('tree_id', $treeId)->where('id', (int) $id)->lockForUpdate()->first();
                    if (! $media || (int) $media->file_exists !== 0 || ! is_string($media->nextcloud_path)
                        || ! is_string($media->local_filename) || $media->local_filename === '') {
                        throw new RuntimeException("Media {$id}: preimage missing or not marked absent.");
                    }
                    $old = DB::table('file_registry')->where('filename', $media->local_filename)
                        ->where('current_path', $media->nextcloud_path)->where('status', 'deleted')->first();
                    if (! $old || ! is_string($old->content_hash) || ! preg_match('/^[a-f0-9]{64}$/', $old->content_hash)
                        || (int) $old->file_size !== (int) $media->file_size) {
                        throw new RuntimeException("Media {$id}: deleted predecessor has no exact hash and size evidence.");
                    }
                    $candidates = DB::table('file_registry')->where('filename', $media->local_filename)
                        ->where('status', 'active')->where('current_path', 'like', $root.'%')->get();
                    if ($candidates->count() !== 1 || ! str_starts_with((string) $candidates[0]->current_path, $root)) {
                        throw new RuntimeException("Media {$id}: expected one active file under the tree root.");
                    }
                    $target = $candidates[0];
                    $oldInfo = $files->getFileInfo($media->nextcloud_path);
                    $newInfo = $files->getFileInfo($target->current_path);
                    $hash = $files->computeFileHash($target->current_path);
                    if (($oldInfo['success'] ?? false) || ! ($newInfo['success'] ?? false) || ! ($hash['success'] ?? false)
                        || (int) ($newInfo['size'] ?? -1) !== (int) $media->file_size
                        || (int) ($hash['size'] ?? -1) !== (int) $media->file_size
                        || ($hash['hash'] ?? null) !== $old->content_hash) {
                        throw new RuntimeException("Media {$id}: live file evidence differs from the retained predecessor.");
                    }
                    if ($apply) {
                        $updated = DB::table('genealogy_media')->where('tree_id', $treeId)->where('id', (int) $id)
                            ->where('nextcloud_path', $media->nextcloud_path)->where('updated_at', $media->updated_at)
                            ->where('file_exists', 0)->update([
                                'nextcloud_path' => $target->current_path,
                                'file_exists' => 1,
                                'updated_at' => now(),
                            ]);
                        if ($updated !== 1) {
                            throw new RuntimeException("Media {$id}: concurrent row change.");
                        }
                    }
                    $planned[] = ['id' => (int) $id, 'from' => $media->nextcloud_path, 'to' => $target->current_path,
                        'sha256' => $hash['hash']];
                }

                return $planned;
            });
        } catch (\Throwable $e) {
            return $this->finish(['status' => 'blocked', 'tree_id' => $treeId, 'applied' => false,
                'error' => $e->getMessage()], self::FAILURE);
        }

        return $this->finish(['status' => $apply ? 'applied' : 'dry_run', 'tree_id' => $treeId,
            'applied' => $apply, 'items' => $result], self::SUCCESS);
    }

    private function finish(array $payload, int $code): int
    {
        $payload['schema'] = 'plos.genealogy_media_relink.v1';
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line(($payload['status'] ?? 'unknown').': '.json_encode($payload, JSON_UNESCAPED_SLASHES));
        }

        return $code;
    }
}
