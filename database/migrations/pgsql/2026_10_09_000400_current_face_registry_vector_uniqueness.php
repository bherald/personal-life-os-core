<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    public function up(): void
    {
        $connection = DB::connection($this->connection);
        $this->assertIndexDefinition($connection, 'idx_face_embeddings_frf_unique', 'legacy');
        $this->assertIndexDefinition($connection, 'idx_face_embeddings_frf_current_unique', 'current');

        // Establish the narrower invariant before removing the historical one.
        // Index creation itself fails closed if two current rows already exist.
        $connection->statement('
            CREATE UNIQUE INDEX IF NOT EXISTS idx_face_embeddings_frf_current_unique
            ON face_embeddings(file_registry_face_id)
            WHERE file_registry_face_id IS NOT NULL
              AND source_current IS TRUE
              AND superseded_at IS NULL
        ');
        $this->assertIndexDefinition($connection, 'idx_face_embeddings_frf_current_unique', 'current');

        $this->dropOwnedIndex($connection, 'idx_face_embeddings_frf_unique');
    }

    public function down(): void
    {
        $connection = DB::connection($this->connection);
        $this->assertIndexDefinition($connection, 'idx_face_embeddings_frf_current_unique', 'current');
        $this->assertIndexDefinition($connection, 'idx_face_embeddings_frf_unique', 'legacy');
        $historicalDuplicates = $connection->selectOne('
            SELECT file_registry_face_id
            FROM face_embeddings
            WHERE file_registry_face_id IS NOT NULL
            GROUP BY file_registry_face_id
            HAVING COUNT(*) > 1
            LIMIT 1
        ');

        if ($historicalDuplicates) {
            throw new RuntimeException('Cannot restore global face-vector uniqueness while retained vector generations share a registry face ID.');
        }

        $connection->statement('
            CREATE UNIQUE INDEX IF NOT EXISTS idx_face_embeddings_frf_unique
            ON face_embeddings(file_registry_face_id)
            WHERE file_registry_face_id IS NOT NULL
        ');
        $this->assertIndexDefinition($connection, 'idx_face_embeddings_frf_unique', 'legacy');
        $this->dropOwnedIndex($connection, 'idx_face_embeddings_frf_current_unique');
    }

    private function assertIndexDefinition(object $connection, string $name, string $kind): void
    {
        if ($connection->getDriverName() === 'pgsql') {
            $index = $connection->selectOne('
                SELECT table_relation.relname AS owner_table,
                    CASE WHEN idx.indexrelid IS NOT NULL THEN 1 ELSE 0 END AS is_index,
                    CASE WHEN idx.indisunique THEN 1 ELSE 0 END AS is_unique,
                    CASE WHEN idx.indisvalid THEN 1 ELSE 0 END AS is_valid,
                    CASE WHEN idx.indisready THEN 1 ELSE 0 END AS is_ready,
                    idx.indnatts AS attribute_count, idx.indnkeyatts AS key_attribute_count,
                    pg_get_indexdef(idx.indexrelid, 1, true) AS key_expression,
                    pg_get_expr(idx.indpred, idx.indrelid, true) AS predicate
                FROM pg_index idx
                JOIN pg_class relation_index ON relation_index.oid = idx.indexrelid
                JOIN pg_class table_relation ON table_relation.oid = idx.indrelid
                WHERE idx.indrelid = to_regclass(?) AND relation_index.relname = ?
            ', ['face_embeddings', $name]);
            // A same-named index on another table in this schema also makes
            // CREATE INDEX IF NOT EXISTS a no-op. Check the relation owner,
            // even when the requested index is absent from face_embeddings.
            $namedRelation = $connection->selectOne('
                SELECT relation_index.relname, relation_index.relkind,
                    owner_relation.relname AS owner_table
                FROM pg_class target_relation
                JOIN pg_namespace target_namespace ON target_namespace.oid = target_relation.relnamespace
                JOIN pg_class relation_index ON relation_index.relnamespace = target_namespace.oid
                LEFT JOIN pg_index owner_index ON owner_index.indexrelid = relation_index.oid
                LEFT JOIN pg_class owner_relation ON owner_relation.oid = owner_index.indrelid
                WHERE target_relation.oid = to_regclass(?) AND relation_index.relname = ?
            ', ['face_embeddings', $name]);
            if ($namedRelation !== null && ($namedRelation->owner_table ?? null) !== 'face_embeddings') {
                throw new RuntimeException('Face vector uniqueness index name belongs to another relation: '.$name);
            }
            if ($index === null) {
                return;
            }
            $valid = (bool) $index->is_unique && (bool) $index->is_valid && (bool) $index->is_ready
                && (int) $index->attribute_count === 1 && (int) $index->key_attribute_count === 1
                && $this->normalize((string) $index->key_expression) === 'file_registry_face_id';
            $predicate = $this->normalize((string) ($index->predicate ?? ''));
        } else {
            $index = $connection->selectOne("SELECT sql, tbl_name AS owner_table FROM sqlite_master WHERE type = 'index' AND name = ?", [$name]);
            if ($index === null) {
                return;
            }
            if ($index->owner_table !== 'face_embeddings') {
                throw new RuntimeException('Face vector uniqueness index name belongs to another relation: '.$name);
            }
            $definition = $this->normalize((string) $index->sql);
            $where = strpos($definition, 'where');
            $prefix = $where === false ? $definition : substr($definition, 0, $where);
            $valid = str_starts_with($prefix, 'createuniqueindex')
                && str_contains($prefix, 'onface_embeddings')
                && str_ends_with($prefix, 'file_registry_face_id');
            $predicate = $where === false ? '' : substr($definition, $where + strlen('where'));
        }

        $expected = $kind === 'legacy'
            ? ['file_registry_face_idisnotnull']
            : ['file_registry_face_idisnotnullandsource_currentandsuperseded_atisnull',
                'file_registry_face_idisnotnullandsource_currentistrueandsuperseded_atisnull'];
        if (! $valid || ! in_array($predicate, $expected, true)) {
            throw new RuntimeException('Face vector uniqueness index name is already bound to a different definition: '.$name.'; predicate='.$predicate);
        }
    }

    private function normalize(string $sql): string
    {
        return strtolower((string) preg_replace('/[\\s()"`;]/', '', $sql));
    }

    private function dropOwnedIndex(object $connection, string $name): void
    {
        if ($connection->getDriverName() !== 'pgsql') {
            $connection->statement('DROP INDEX IF EXISTS '.$name);

            return;
        }
        $index = $connection->selectOne('
            SELECT format(\'%I.%I\', index_namespace.nspname, relation_index.relname) AS qualified_name
            FROM pg_class target_relation
            JOIN pg_namespace target_namespace ON target_namespace.oid = target_relation.relnamespace
            JOIN pg_class relation_index ON relation_index.relnamespace = target_namespace.oid
            JOIN pg_namespace index_namespace ON index_namespace.oid = relation_index.relnamespace
            JOIN pg_index owner_index ON owner_index.indexrelid = relation_index.oid
                AND owner_index.indrelid = target_relation.oid
            WHERE target_relation.oid = to_regclass(?) AND relation_index.relname = ?
        ', ['face_embeddings', $name]);
        if ($index !== null) {
            $connection->statement('DROP INDEX IF EXISTS '.$index->qualified_name);
        }
    }
};
