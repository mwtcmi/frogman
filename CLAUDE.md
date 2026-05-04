# CLAUDE.md — Frogman Development Guide

## What is this project?

Frogman is a FreePBX module (`/var/www/html/admin/modules/frogman/`) that provides chat-driven headless control of FreePBX. It exposes 212 tools through a registry pattern accessible via CLI, HTTP API, GraphQL, and MCP.

## Hard rules — do not violate

1. **This is a proper FreePBX module following BMO conventions.** Don't invent patterns — look at existing modules (core, announcement, api) for reference.
2. **Never modify other modules' code or files.** Everything stays in the `frogman/` directory.
3. **Database rules:** `oc_*` tables are ours (used internally for audit log, sessions, etc.). **Frogman never writes directly to another module's DB tables — ever.** Reads of other modules' tables are OK when no BMO method exposes the data efficiently. All writes outside `oc_*` go through BMO (or GraphQL if BMO is missing the operation).
4. **No arbitrary code execution.** The tool surface is a fixed allowlist. No user-supplied PHP, SQL, or shell.
5. **Write tools require `confirm: true`** or they return a dry-run preview.
6. **Audit everything.** Every tool execution gets an intent record before and outcome record after.

## Tool routing hierarchy

Pick the highest item that does the job. **BMO first** for any FreePBX-object management — it's in-process, version-stable, and hooks into FreePBX's own audit/permission wiring.

1. **BMO PHP calls** (`\FreePBX::ModuleName()->method()`) — first choice for any FreePBX-object CRUD/management. Read the target module's `<ModuleName>.class.php` to see what's exposed before reaching for anything else.
2. **AMI** (`\FreePBX::astman`) — only for Asterisk runtime state with no BMO equivalent (live channels, DPMA endpoint state, qualify, NOTIFY-driven actions).
3. **GraphQL named operations** — only when crossing module boundaries is required and no module's BMO exposes the operation cleanly.
4. **Direct DB reads** — only when no BMO method returns the needed data efficiently (bulk inventory, reporting joins). **Reads only — never write to another module's tables.**
5. **fwconsole wrappers** — last resort for system operations, hardened via the FwconsoleCmd allowlist pattern.

## How to add a new tool

1. Create `Tools/MyTool.php` extending `AbstractTool`
2. Implement: `name()`, `description()`, `validate($params)`, `requiredPermission()`, `execute($params, $context)`
3. The tool auto-registers — the BMO class scans `Tools/*.php` on load
4. Test via: `fwconsole frogman:tool my_tool_name '{"param":"value"}'`
5. It will automatically appear in the HTTP catalog and MCP server

### Tool naming — brand/vendor scope must be explicit

A generic name (`fm_list_phones`, `fm_diagnose_phone`) MUST work across every brand of phone the system supports — Sangoma, Yealink, Polycom, Cisco, Grandstream, Algo, etc. If a tool only works on one brand or one vendor protocol, the brand/protocol goes in the tool name and the chat alias.

- DPMA/Sangoma-specific: `fm_list_sangoma_phones`, `fm_diagnose_sangoma_phone`, `fm_reboot_sangoma_phone`. Chat: `list sangoma phones`, `diagnose sangoma 1005`.
- Cross-brand (works via EPM regardless of vendor): `fm_list_phones`, `fm_phone_rebuild_config`. Chat: `list phones`, `rebuild phone configs`.

Decision rule: ask "does this work for a Yealink phone too?" If no → vendor name in the tool name.

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
