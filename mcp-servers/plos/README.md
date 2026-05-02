# PLOS MCP Server

Primary MCP server for operating a PLOS instance from Claude Code or another MCP
client. It exposes guarded database reads, selected operational commands, setup
diagnostics, job/agent health, log search, and local Ollama helpers.

This package is intended for trusted local or LAN use. It can inspect live PLOS
state and some tools can modify configuration or clear caches only when explicit
confirmation fields are supplied.

## Tools

- `plos_schema` reads `docs/schema-reference.md` or live table metadata.
- `plos_query` runs guarded SQL. Reads are default; writes require `allowWrite`.
- `plos_health`, `plos_job_status`, `plos_job_diagnostic`, and
  `plos_agent_status` summarize operational state.
- `plos_log_search` searches Laravel logs.
- `plos_config` reads and writes selected non-secret `system_configs` keys.
- `plos_artisan` runs a small allowlist of Laravel commands.
- `plos_cache_clear` clears Laravel caches and requires confirmation for Redis
  flushes or worker restarts.
- `plos_tinker` and `plos_ssh` provide operator tools with command filtering.
- `plos_decompose` routes large prompts through the PLOS AI service.
- `ollama_*` tools delegate summarization, error triage, diff explanation, code
  description, commit drafting, and content drafting to local Ollama models.

## Install

```bash
cd mcp-servers/plos
npm install
cp .env.example .env
npm run build
npm test
```

Edit `.env` before starting the server. Use strong local database credentials,
do not commit `.env`, and leave `SKIP_SSH_TUNNEL=true` when the databases are
reachable directly from the MCP host. Set it to `false` only when the server
must open SSH tunnels to a remote PLOS instance.

## Claude Code

Use a project `.mcp.json` or your MCP client's user settings. Prefer a project
entry with absolute paths:

```json
{
  "mcpServers": {
    "plos": {
      "command": "node",
      "args": ["/path/to/plos/mcp-servers/plos/dist/index.js"],
      "env": {
        "PROJECT_ROOT": "/path/to/plos",
        "PLOS_REMOTE_PROJECT_ROOT": "/path/to/plos",
        "PLOS_REMOTE_HOST": "127.0.0.1",
        "PLOS_REMOTE_USER": "plos",
        "PLOS_REMOTE_SSH_KEY": "",
        "SCHEMA_REFERENCE_PATH": "/path/to/plos/docs/schema-reference.md",
        "SKIP_SSH_TUNNEL": "true",
        "MYSQL_HOST": "127.0.0.1",
        "MYSQL_PORT": "3306",
        "MYSQL_USER": "plos",
        "MYSQL_PASSWORD": "replace-me",
        "MYSQL_DATABASE": "plos",
        "PGSQL_HOST": "127.0.0.1",
        "PGSQL_PORT": "5432",
        "PGSQL_USER": "plos_rag",
        "PGSQL_PASSWORD": "replace-me",
        "PGSQL_DATABASE": "plos_rag",
        "OLLAMA_HOST": "http://127.0.0.1:11434"
      }
    }
  }
}
```

If you keep the values in `mcp-servers/plos/.env`, the `env` block can be much
smaller. The explicit form above is easier to debug during a public install.

## Safety Model

- SQL is read-only by default, rejects multiple statements, blocks destructive
  DDL/DML, and auto-limits unbounded reads.
- Config writes require `confirm_write=true`, only allow selected sections, and
  reject secret-like keys.
- Cache clearing requires `confirm_disruptive=true` for Redis flushes or worker
  restarts.
- Shell and tinker tools apply command filtering and timeouts, but still belong
  on trusted machines only.
- Logs are written to `LOG_FILE`, defaulting to `/tmp/plos-mcp.log`.

## Development

```bash
npm run build
npm test
npm start
```

The server uses stdio for MCP transport. Do not write diagnostic logs to stdout
or stderr from tool code; use `src/util/logger.ts`.
