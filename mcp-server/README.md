# PLOS Workflow MCP Server

Model Context Protocol server for the legacy PLOS workflow-only tool surface.
The primary public PLOS MCP package lives in `mcp-servers/plos`.

## Features

- **9 Tools** for workflow management via Claude Code
- **Direct database access** to MySQL workflow database
- **Artisan command execution** with whitelist security
- **Audit logging** to storage/logs/mcp.log
- **Input validation** with Zod schemas
- **Resources** for documentation access

## Available Tools

1. `workflow_list` - List all workflows
2. `workflow_get` - Get workflow details by name
3. `workflow_run` - Execute a workflow
4. `execution_list` - List execution history
5. `execution_get` - Get execution details
6. `artisan_execute` - Run whitelisted artisan commands
7. `node_create` - Create new workflow nodes
8. `schedule_list` - List scheduled tasks
9. `system_diagnostics` - System health check

## Installation

```bash
# Install dependencies
npm ci

# Configure environment
cp .env.example .env
# Edit .env with your database credentials

# Build
npm run build
```

This legacy workspace has its own `package-lock.json` and intentionally keeps a
small dependency surface: MCP SDK, dotenv, Express, MySQL, ws, Zod, TypeScript,
and Node types. The root `package.json` owns separate external MCP helper
packages such as Graphlit or browser/file servers; do not add those binaries
back here unless this workspace imports them directly.

## Configuration for MCP Clients

Add an entry to a project `.mcp.json` file or to your MCP client's user-level
settings. Replace every placeholder value before use and never commit real
database credentials.

```json
{
  "mcpServers": {
    "plos-workflow": {
      "command": "node",
      "args": ["/path/to/plos/mcp-server/dist/index.js"],
      "env": {
        "DB_HOST": "127.0.0.1",
        "DB_PORT": "3306",
        "DB_USER": "plos",
        "DB_PASSWORD": "change-me",
        "DB_NAME": "plos",
        "PROJECT_ROOT": "/path/to/plos"
      }
    }
  }
}
```

`key:generate`, `passport:install`, migrations, and cache-clearing commands are
intentionally blocked through this legacy MCP surface. Run setup/install steps
directly in an operator shell, then use MCP for routine workflow inspection and
safe whitelisted actions.

## Testing

```bash
# Test database connection
node dist/index.js

# Should output: "PLOS Workflow MCP Server running"
```

## Security Features

- Command whitelist (only safe commands allowed)
- Command blacklist (dangerous commands blocked)
- Input validation with Zod
- Audit logging for all operations
- Table whitelist for database queries

## Whitelisted Commands

- `workflow:list`, `workflow:run`, `workflow:create`, `workflow:export`, `workflow:import`
- `make:node`
- `schedule:list`
- `db:show`
- `route:list`

## Blacklisted Commands

- `migrate`, `migrate:fresh`, `migrate:reset`, `migrate:rollback`
- `db:wipe`
- `queue:clear`, `cache:clear`, `config:clear`
- `key:generate`, `passport:install`

## Architecture

```
mcp-server/
├── src/
│   ├── index.ts              # Main server entry point
│   ├── types.ts              # TypeScript interfaces
│   ├── handlers/
│   │   └── tools.ts          # Tool request handlers
│   ├── integrations/
│   │   ├── database.ts       # MySQL integration
│   │   └── artisan.ts        # Artisan command executor
│   └── security/
│       ├── logger.ts         # Audit logging
│       └── validator.ts      # Input validation
└── dist/                     # Compiled JavaScript
```

## Usage Examples

### Via Claude Code

Once configured, you can interact with your workflows through Claude Code:

```
You: "List all workflows"
Claude: [Uses workflow_list tool]

You: "Run the morning_weather workflow"
Claude: [Uses workflow_run tool with name: "morning_weather"]

You: "Show me the last 10 executions"
Claude: [Uses execution_list tool with limit: 10]

You: "Create a new node called EmailSender"
Claude: [Uses node_create tool with name: "EmailSender"]
```

## Troubleshooting

### Database Connection Failed
- Check MySQL is running: `sudo systemctl status mysql`
- Verify credentials in `.env`
- Test connection: `mysql -u plos -p plos -e "SELECT 1;"`

### Command Not Allowed
- Check if command is in whitelist (see above)
- Review audit log: `tail -f ../storage/logs/mcp.log`

### Server Won't Start
- Rebuild: `npm run build`
- Check Node.js version: `node --version` (requires v20+)
- Review error logs

## Development

```bash
# Watch mode
npm run watch

# Run directly
npm start
```

## License

MIT
