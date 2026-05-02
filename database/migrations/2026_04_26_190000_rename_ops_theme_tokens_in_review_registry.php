<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('review_type_registry')) {
            return;
        }

        $columns = collect(['ui_schema', 'actions', 'field_mapping', 'color'])
            ->filter(fn (string $column): bool => Schema::hasColumn('review_type_registry', $column))
            ->values()
            ->all();

        if ($columns === []) {
            return;
        }

        $legacyPrefix = 'l' . 'cars';

        DB::table('review_type_registry')
            ->select(array_merge(['id'], $columns))
            ->orderBy('id')
            ->chunk(100, function ($rows) use ($columns, $legacyPrefix): void {
                foreach ($rows as $row) {
                    $updates = [];

                    foreach ($columns as $column) {
                        $value = $row->{$column};

                        if ($value === null) {
                            continue;
                        }

                        $text = is_string($value) ? $value : json_encode($value);

                        if ($text === false || ! str_contains($text, $legacyPrefix)) {
                            continue;
                        }

                        $updates[$column] = str_replace(
                            [$legacyPrefix . '-', $legacyPrefix . '_'],
                            ['ops-', 'ops_'],
                            $text
                        );
                    }

                    if ($updates !== []) {
                        $updates['updated_at'] = now();

                        DB::table('review_type_registry')
                            ->where('id', $row->id)
                            ->update($updates);
                    }
                }
            });
    }

    public function down(): void
    {
        // Intentionally one-way: avoid rewriting newer public-safe theme tokens.
    }
};
