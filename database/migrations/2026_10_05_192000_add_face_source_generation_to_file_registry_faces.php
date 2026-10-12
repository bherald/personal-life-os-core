<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const GENERATION_INDEX_WITH_FACE_INDEX = 'uk_face_file_generation_index';

    private const GENERATION_INDEX_WITHOUT_FACE_INDEX = 'uk_face_file_generation_pair';

    public function up(): void
    {
        $indexes = $this->uniqueIndexes();
        $legacyIdentity = $this->legacyIdentityFromIndexes($indexes);
        $generationIndex = in_array('face_index', $legacyIdentity, true)
            ? self::GENERATION_INDEX_WITH_FACE_INDEX
            : self::GENERATION_INDEX_WITHOUT_FACE_INDEX;

        Schema::table('file_registry_faces', function (Blueprint $table): void {
            if (! Schema::hasColumn('file_registry_faces', 'face_index')) {
                $table->unsignedSmallInteger('face_index')->default(0);
            }
            if (! Schema::hasColumn('file_registry_faces', 'source_current')) {
                $table->boolean('source_current')->default(true);
            }
            if (! Schema::hasColumn('file_registry_faces', 'superseded_at')) {
                $table->timestamp('superseded_at')->nullable();
            }
            if (! Schema::hasColumn('file_registry_faces', 'source_generation_key')) {
                $table->string('source_generation_key', 64)->default('legacy');
            }
        });

        if (! array_key_exists($generationIndex, $indexes)) {
            $generationColumns = ['file_registry_id', 'source_generation_key', 'face_index', 'person_name'];
            Schema::table('file_registry_faces', fn (Blueprint $table) => $table->unique($generationColumns, $generationIndex));
        }

        foreach ($indexes as $name => $columns) {
            if (in_array($columns, [
                ['file_registry_id', 'face_index', 'person_name'],
                ['file_registry_id', 'person_name'],
            ], true)) {
                Schema::table('file_registry_faces', fn (Blueprint $table) => $table->dropUnique($name));
            }
        }
    }

    public function down(): void
    {
        $indexes = $this->uniqueIndexes();
        $generationIndex = isset($indexes[self::GENERATION_INDEX_WITHOUT_FACE_INDEX])
            ? self::GENERATION_INDEX_WITHOUT_FACE_INDEX
            : self::GENERATION_INDEX_WITH_FACE_INDEX;

        if (Schema::hasColumn('file_registry_faces', 'source_generation_key')) {
            $historical = DB::selectOne('SELECT 1 AS historical FROM file_registry_faces WHERE source_current = 0 OR superseded_at IS NOT NULL LIMIT 1');
            if ($historical) {
                throw new RuntimeException('Cannot roll back face generations while superseded face evidence exists; preserving lifecycle fields and indexes.');
            }

            $legacyIdentity = $generationIndex === self::GENERATION_INDEX_WITHOUT_FACE_INDEX
                ? ['file_registry_id', 'person_name']
                : ['file_registry_id', 'face_index', 'person_name'];
            $identityColumns = implode(', ', $legacyIdentity);
            $collision = DB::selectOne("SELECT 1 AS collision FROM file_registry_faces GROUP BY {$identityColumns} HAVING COUNT(*) > 1 LIMIT 1");

            if ($collision) {
                throw new RuntimeException('Cannot roll back face generations while multiple generations share a face identity; preserving the generation key and unique index.');
            }
        }

        $indexes = $this->uniqueIndexes();
        $legacyIdentity = $generationIndex === self::GENERATION_INDEX_WITHOUT_FACE_INDEX
            ? ['file_registry_id', 'person_name']
            : ['file_registry_id', 'face_index', 'person_name'];
        $legacyIndexName = in_array('face_index', $legacyIdentity, true)
            ? 'uk_face_file_person_index'
            : 'uk_face_file_person';

        if (! array_key_exists($legacyIndexName, $indexes)) {
            Schema::table('file_registry_faces', fn (Blueprint $table) => $table->unique($legacyIdentity, $legacyIndexName));
        }

        if (array_key_exists($generationIndex, $indexes)) {
            Schema::table('file_registry_faces', fn (Blueprint $table) => $table->dropUnique($generationIndex));
        }

        Schema::table('file_registry_faces', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                ['source_current', 'superseded_at', 'source_generation_key'],
                fn (string $column): bool => Schema::hasColumn('file_registry_faces', $column)
            ));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    /** @return array<string, list<string>> */
    private function uniqueIndexes(): array
    {
        $indexes = [];
        foreach (Schema::getIndexes('file_registry_faces') as $index) {
            if (! ($index['unique'] ?? false)) {
                continue;
            }
            $indexes[$index['name']] = $index['columns'];
        }

        return $indexes;
    }

    /** @param array<string, list<string>> $indexes
     * @return list<string>
     */
    private function legacyIdentityFromIndexes(array $indexes): array
    {
        foreach ($indexes as $columns) {
            if ($columns === ['file_registry_id', 'face_index', 'person_name']) {
                return $columns;
            }
            if ($columns === ['file_registry_id', 'person_name']) {
                return $columns;
            }
        }

        return Schema::hasColumn('file_registry_faces', 'face_index')
            ? ['file_registry_id', 'face_index', 'person_name']
            : ['file_registry_id', 'person_name'];
    }
};
