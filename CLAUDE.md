# CLAUDE.md — Frogman Development Guide

## What is this project?

Frogman is a FreePBX module (`/var/www/html/admin/modules/frogman/`) that provides chat-driven headless control of FreePBX. It exposes 211 tools through a registry pattern accessible via CLI, HTTP API, GraphQL, and MCP.

## Hard rules — do not violate

1. **This is a proper FreePBX module following BMO conventions.** Don't invent patterns — look at existing modules (core, announcement, api) for reference.
2. **Never modify other modules' code or files.** Everything stays in the `frogman/` directory.
3. **Database rules:** `oc_*` tables are ours to read/write freely. Other modules' tables: read OK, write only through BMO or GraphQL.
4. **No arbitrary code execution.** The tool surface is a fixed allowlist. No user-supplied PHP, SQL, or shell.
5. **Write tools require `confirm: true`** or they return a dry-run preview.
6. **Audit everything.** Every tool execution gets an intent record before and outcome record after.

## Tool routing hierarchy

1. GraphQL named operations (preferred for cross-module CRUD)
2. Direct DB reads (diagnostics/reporting)
3. BMO PHP calls (fallback)
4. fwconsole wrappers (system ops only)
5. Direct DB writes (oc_* tables only)

## How to add a new tool

1. Create `Tools/MyTool.php` extending `AbstractTool`
2. Implement: `name()`, `description()`, `validate($params)`, `requiredPermission()`, `execute($params, $context)`
3. The tool auto-registers — the BMO class scans `Tools/*.php` on load
4. Test via: `fwconsole frogman:tool my_tool_name '{"param":"value"}'`
5. It will automatically appear in the HTTP catalog and MCP server

## Key files

- `Frogman.class.php` — BMO class, audit log, tool registry, HTTP endpoints
- `Tools/AbstractTool.php` — base class all tools extend
- `Api/Gql/Frogman.php` — GraphQL types, queries, mutations
- `Console/Tool.class.php` — CLI harness (`fwconsole frogman:tool`)
- `mcp-server.php` — MCP protocol server (stdio JSON-RPC)
- `module.xml` — module metadata, dependencies, and database schema (Doctrine-style)

## After making changes

```bash
# Sync files to server
rsync -avz ./ root@YOUR_HOST:/var/www/html/admin/modules/frogman/

# On server:
fwconsole chown
fwconsole reload  # only if config changed
```

## Testing

```bash
# List all tools
fwconsole frogman:tool

# Run a read tool
fwconsole frogman:tool fm_list_extensions '{}'

# Run a write tool (dry-run)
fwconsole frogman:tool fm_add_extension '{"ext":"1002","name":"Test"}'

# Run a write tool (execute)
fwconsole frogman:tool fm_add_extension '{"ext":"1002","name":"Test","confirm":true}'

# Check audit log
fwconsole frogman:tool fm_audit_search '{"limit":5}'
```
