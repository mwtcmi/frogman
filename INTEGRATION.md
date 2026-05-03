# Frogman Integration Guide

## Overview

Frogman exposes 211 tools via HTTP API. Bots, web apps, and integrations can execute these tools remotely. Tools cannot be created or modified via the API — only executed based on your permission level.

## Authentication

### Localhost (no auth needed)
Requests from `127.0.0.1` or `::1` bypass authentication. This includes:
- Web console (browser on the PBX)
- MCP server (runs on the PBX)
- Discord bot (runs on the PBX)
- CLI (`fwconsole frogman:tool`)

### Remote Access (API token required)
Remote clients must include an `X-Frogman-Token` header.

**Generate a token:**
```bash
# From CLI
fwconsole frogman:tool fm_create_api_token '{"username":"mybot","description":"My bot","level":"read","confirm":true}'

# From the web console
create token for mybot with read
```

**Token levels:**
- `read` — list, get, search, diagnose tools only
- `write` — read + create, update, delete PBX objects
- `admin` — full access including system management

**Save the token** — it cannot be retrieved again. Only a masked preview is shown in `list tokens`.

**Revoke a token:**
```bash
# List tokens to find the ID
fwconsole frogman:tool fm_list_api_tokens '{}'

# Revoke by ID
fwconsole frogman:tool fm_revoke_api_token '{"id":"1","confirm":true}'
```

## Endpoints

### Execute a Tool
```
POST /admin/ajax.php?module=frogman&command=tool
Content-Type: application/json
X-Frogman-Token: <your-token>

{"tool": "fm_list_extensions", "params": {}}
```

### Tool Catalog
```
GET /admin/ajax.php?module=frogman&command=catalog
X-Frogman-Token: <your-token>
```

Returns all available tools with names, descriptions, and parameter info.

### Chat (natural language)
```
POST /admin/ajax.php?module=frogman&command=chat
Content-Type: application/json
X-Frogman-Token: <your-token>

{"message": "list extensions", "session_id": "mybot-session-1"}
```

Returns formatted text responses. Supports confirmations via `session_id`.

## Examples

### List Extensions
```bash
curl -X POST \
  -H "Content-Type: application/json" \
  -H "X-Frogman-Token: YOUR_TOKEN" \
  -d '{"tool": "fm_list_extensions", "params": {}}' \
  https://your-pbx/admin/ajax.php?module=frogman&command=tool
```

Response:
```json
{
  "status": "success",
  "auditId": 123,
  "data": {
    "count": 8,
    "extensions": [
      {"extension": "101", "name": "Miles Davis", "tech": "pjsip"},
      {"extension": "102", "name": "John Coltrane", "tech": "pjsip"}
    ]
  }
}
```

### Create an Extension
```bash
curl -X POST \
  -H "Content-Type: application/json" \
  -H "X-Frogman-Token: YOUR_TOKEN" \
  -d '{"tool": "fm_add_extension", "params": {"ext": "200", "name": "New User", "confirm": true}}' \
  https://your-pbx/admin/ajax.php?module=frogman&command=tool
```

Write tools require `"confirm": true` in params or they return a dry-run preview.

### Diagnose an Extension
```bash
curl -X POST \
  -H "Content-Type: application/json" \
  -H "X-Frogman-Token: YOUR_TOKEN" \
  -d '{"tool": "fm_diagnose_extension", "params": {"ext": "101"}}' \
  https://your-pbx/admin/ajax.php?module=frogman&command=tool
```

### Search the Knowledge Base
```bash
curl -X POST \
  -H "Content-Type: application/json" \
  -H "X-Frogman-Token: YOUR_TOKEN" \
  -d '{"tool": "fm_search_docs", "params": {"query": "NAT one way audio"}}' \
  https://your-pbx/admin/ajax.php?module=frogman&command=tool
```

### Export as CSV
```bash
curl -X POST \
  -H "Content-Type: application/json" \
  -H "X-Frogman-Token: YOUR_TOKEN" \
  -d '{"tool": "fm_export", "params": {"type": "extensions"}}' \
  https://your-pbx/admin/ajax.php?module=frogman&command=tool
```

The response includes a download URL for the CSV file.

### Use the Chat Interface
```bash
curl -X POST \
  -H "Content-Type: application/json" \
  -H "X-Frogman-Token: YOUR_TOKEN" \
  -d '{"message": "diagnose ext 101", "session_id": "bot-1"}' \
  https://your-pbx/admin/ajax.php?module=frogman&command=chat
```

The chat endpoint returns human-readable formatted text. Use `session_id` to maintain conversation state (confirmations, follow-ups).

## Response Format

### Success
```json
{
  "status": "success",
  "auditId": 123,
  "data": { ... }
}
```

### Error
```json
{
  "status": "error",
  "auditId": 124,
  "message": "Extension 999 not found"
}
```

### Not Authenticated
```json
{
  "status": "error",
  "message": "Not authenticated. Provide X-Frogman-Token header or connect from localhost."
}
```

## Security Notes

- Bots can only **execute** existing tools — they cannot create, modify, or delete tools
- Token levels control what tools can be executed: `read` (view only), `write` (create/modify PBX objects), `admin` (full access)
- All tool executions are audit-logged with timestamp, tool name, params, and user
- Write tools require explicit `"confirm": true` — without it, they return a preview
- API tokens can be revoked instantly via `fm_revoke_api_token`
- The raw token is only shown once at creation — save it securely
- Use HTTPS in production
