# Database Schema Files

This directory contains public, schema-only database dumps for PLOS fresh installs.

## Files

### mysql-schema.sql
Complete schema for the **plos** MySQL database. It includes the `migrations` table rows needed to mark the historical baseline as loaded, but no application data.

**Connection Details:**
- Database: `plos`
- User: `plos`
- Tables: generated from the current application schema.

**Key Tables:**
- `workflows` - Workflow definitions
- `workflow_nodes` - Node configurations
- `workflow_runs` - Execution history
- `node_executions` - Individual node execution logs
- `conversations` - AI chat conversations
- `chat_messages` - Chat message history
- `rag_documents` - (deprecated, moved to PostgreSQL)
- `bias_ratings` - News source bias ratings
- `email_templates` - Email automation templates
- OAuth tables (5 tables)
- System tables (cache, jobs, sessions, etc.)

### pgsql-schema.sql
Complete schema for the **plos_rag** PostgreSQL database with pgvector extension.
Regenerate and load with PostgreSQL 16+ client tools so pgvector types and
modern `pg_dump` guard directives are understood.

**Connection Details:**
- Database: `plos_rag`
- User: `plos_rag`
- Tables: generated from the current RAG/GraphRAG schema.

**Key Tables:**
- `rag_documents` - RAG document storage with vector embeddings (768 dimensions)
- `migrations` - Migration tracking

**Extensions:**
- pgvector - Vector similarity search

## Usage

### Restoring MySQL Schema

```bash
MYSQL_PWD="${DB_PASSWORD}" mysql -u "${DB_USERNAME:-plos}" "${DB_DATABASE:-plos}" < database/schema/mysql-schema.sql
```

### Restoring PostgreSQL Schema

```bash
PGPASSWORD="${RAG_DB_PASSWORD}" psql -U "${RAG_DB_USERNAME:-plos_rag}" -h "${RAG_DB_HOST:-127.0.0.1}" "${RAG_DB_DATABASE:-plos_rag}" < database/schema/pgsql-schema.sql
```

### Generating New Schema Dumps

To regenerate these schema files from your current database:

```bash
# MySQL Schema
MYSQL_PWD="${DB_PASSWORD}" mysqldump -u "${DB_USERNAME:-plos}" \
  --no-data \
  --skip-comments \
  --skip-add-drop-table \
  "${DB_DATABASE:-plos}" > database/schema/mysql-schema.sql

# PostgreSQL Schema
PGPASSWORD="${RAG_DB_PASSWORD}" pg_dump \
  -U "${RAG_DB_USERNAME:-plos_rag}" \
  -h "${RAG_DB_HOST:-127.0.0.1}" \
  -d "${RAG_DB_DATABASE:-plos_rag}" \
  --schema-only \
  --no-owner \
  --no-privileges > database/schema/pgsql-schema.sql
```

## Laravel Schema Management

Use the MySQL dump with Laravel's `migrate --schema-path` option:

```bash
php artisan migrate --schema-path=database/schema/mysql-schema.sql
```

Load the PostgreSQL/RAG schema directly with `psql` before enabling RAG jobs:

```bash
PGPASSWORD="${RAG_DB_PASSWORD}" psql -U "${RAG_DB_USERNAME:-plos_rag}" -h "${RAG_DB_HOST:-127.0.0.1}" "${RAG_DB_DATABASE:-plos_rag}" < database/schema/pgsql-schema.sql
```

The RAG database is owned by a separate PostgreSQL role and connection, so the
public bootstrap loads it with `psql` rather than Laravel's MySQL-oriented
`migrate --schema-path` command. For bare-metal installs, create the
`${RAG_DB_DATABASE:-plos_rag}` database and `${RAG_DB_USERNAME:-plos_rag}` role
before loading this file.

## Database Architecture

### MySQL (plos)
Primary application database storing:
- Workflow definitions and execution state
- AI conversation history
- Email automation configurations
- OAuth authentication
- System configurations
- Cache, sessions, and jobs

### PostgreSQL (plos_rag)
Specialized RAG database with vector search:
- Document embeddings (768-dimensional vectors)
- Semantic search via pgvector
- Full-text search indexes
- Source document tracking
- Joplin note synchronization

## Notes

- Schema files contain structure only, except for migration bookkeeping rows
  required to mark the schema baseline as loaded.
- AUTO_INCREMENT values are preserved from source database
- Foreign key constraints are included
- Indexes are fully defined
- Character set: utf8mb4 (MySQL), UTF8 (PostgreSQL)
- Timezone handling: UTC

## Version Info

Last updated: 2026-04-26
Laravel Version: 12.x
MySQL Version: Compatible with 8.0+
PostgreSQL Version: 16.x with pgvector extension
