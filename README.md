# Frogman 🐸

**Frogman is an MCP server for FreePBX.** It gives any AI — Claude, OpenClaw, or any MCP-compatible client — full control of a PBX through 211 tools.

Connect via MCP and ask "why can't extension 101 make calls?" — Frogman runs live diagnostics, searches its knowledge base, and hands the AI everything it needs to answer.

Also includes a web console, CLI chat, and HTTP API for humans and bots. Built entirely on FreePBX's native interfaces (BMO, GraphQL, AMI, fwconsole). Every action is validated, permission-gated, audit-logged, and requires confirmation before making changes.

## Requirements

- FreePBX 17.x
- Asterisk 20+ (tested on 22.8.2)
- PHP 8.2+
- API module (for GraphQL) 17.0.1+

## Installation

```bash
# Copy the module to your FreePBX modules directory
cp -r frogman /var/www/html/admin/modules/

# Install the module
fwconsole ma install frogman

# Set permissions
fwconsole chown

# Apply config
fwconsole reload
```

Verify it's installed:

```bash
fwconsole ma list | grep frogman
# | frogman | 1.4.0 | Enabled | AGPLv3+ | Unsigned |
```

### Post-Install (Optional)

To enable service management tools (start/stop/restart FreePBX, fix permissions, system update, etc.) from the chat console, run once as root:

```bash
echo 'asterisk ALL=(root) NOPASSWD: /usr/sbin/fwconsole' > /etc/sudoers.d/frogman
chmod 440 /etc/sudoers.d/frogman
```

This is optional — all other tools work without it. Without this, service tools will show instructions on how to enable.

## Architecture

Frogman is the MCP server — the AI interface to the PBX. Frogman is the FreePBX module that provides the 210 tools it exposes. Together, they have two interfaces:

- **MCP Server** — the core product. Any AI connects via MCP and uses 210 tools to control, diagnose, and troubleshoot the PBX. This is where RAG, reasoning, and intelligent support happen.
- **Web Console & CLI** — a human-friendly chat interface using pattern matching. Same tools, no AI required. Useful for quick tasks without an MCP client.

### Tool Routing Hierarchy

Tools internally route by this priority:

1. **GraphQL named operations** — preferred for cross-module CRUD
2. **Direct DB reads** — for diagnostics/reporting
3. **BMO PHP calls** — fallback where GraphQL coverage is missing
4. **fwconsole wrappers** — system ops only (reload, restart, module admin, backup)
5. **Direct DB writes** — `oc_*` tables only

### Database

Frogman owns five tables (prefixed `oc_*`):

| Table | Purpose |
|-------|---------|
| `oc_audit_log` | Full audit trail of every tool execution |
| `oc_sessions` | Chat session tracking |
| `oc_saved_queries` | Saved GraphQL queries |
| `oc_jobs` | Async job queue (future use) |
| `oc_aliases` | Command aliases |

Reads from other modules' tables are fine. Writes to other modules go through BMO or GraphQL — never direct SQL.

### Security Model

- **No arbitrary code generation.** The tool surface is a fixed allowlist of PHP methods.
- **Input validation** on every tool before execution.
- **Permission gating** via FreePBX User Manager.
- **Audit logging** — intent recorded before execution, outcome recorded after.
- **Confirmation required** — all mutating operations return a dry-run preview unless `confirm: true` is passed.
- **No user-supplied PHP, SQL, or shell** is ever executed.

## Tool Catalog (210 tools)

### Extensions (6)

| Tool | Description |
|------|-------------|
| `oc_list_extensions` | List all extensions with optional tech/search filters |
| `oc_get_extension` | Full details for a single extension |
| `oc_get_extension_health` | Config + SIP registration + recent CDR |
| `oc_add_extension` | Create a new PJSIP extension **[confirm]** |
| `oc_update_extension` | Update extension name, secret, or CID **[confirm]** |
| `oc_disable_extension` | Delete an extension **[confirm]** |

### Ring Groups (4)

| Tool | Description |
|------|-------------|
| `oc_list_ringgroups` | List all ring groups |
| `oc_get_ringgroup` | Ring group details + member list |
| `oc_ringgroup_add_member` | Add member to ring group **[confirm]** |
| `oc_ringgroup_remove_member` | Remove member from ring group **[confirm]** |

### Trunks (2)

| Tool | Description |
|------|-------------|
| `oc_list_trunks` | List all configured trunks |
| `oc_get_trunk_status` | Trunk config + PJSIP registration status |

### Calls & CDR (2)

| Tool | Description |
|------|-------------|
| `oc_list_active_calls` | Active calls via AMI |
| `oc_get_cdr` | Query call detail records with filters |

### Follow Me (2)

| Tool | Description |
|------|-------------|
| `oc_set_followme` | Configure Follow Me for an extension **[confirm]** |
| `oc_clear_followme` | Remove Follow Me **[confirm]** |

### Call Forward & DND (5)

| Tool | Description |
|------|-------------|
| `oc_get_call_forward` | Get call forwarding status for an extension |
| `oc_set_call_forward` | Set call forwarding (CF/CFB/CFU) **[confirm]** |
| `oc_clear_call_forward` | Clear call forwarding **[confirm]** |
| `oc_get_dnd` | Get Do Not Disturb status |
| `oc_toggle_dnd` | Toggle Do Not Disturb **[confirm]** |

### Voicemail (2)

| Tool | Description |
|------|-------------|
| `oc_list_voicemail` | List all voicemail boxes |
| `oc_get_voicemail` | Voicemail box details and message count |

### Queues (2)

| Tool | Description |
|------|-------------|
| `oc_list_queues` | List all call queues |
| `oc_get_queue` | Queue details by ID |

### Conferences (2)

| Tool | Description |
|------|-------------|
| `oc_list_conferences` | List all conference rooms |
| `oc_get_conference` | Conference room details |

### IVRs (2)

| Tool | Description |
|------|-------------|
| `oc_list_ivrs` | List all IVRs |
| `oc_get_ivr` | IVR details by ID |

### Announcements (1)

| Tool | Description |
|------|-------------|
| `oc_list_announcements` | List all announcements |

### Time Conditions & Day-Night (4)

| Tool | Description |
|------|-------------|
| `oc_list_time_conditions` | List all time conditions with current state |
| `oc_toggle_time_condition` | Toggle a time condition override **[confirm]** |
| `oc_list_daynight` | List all day/night call flow controls |
| `oc_toggle_daynight` | Toggle a day/night call flow **[confirm]** |

### Routes (3)

| Tool | Description |
|------|-------------|
| `oc_list_inbound_routes` | List all inbound routes (DIDs) |
| `oc_list_outbound_routes` | List all outbound routes |
| `oc_get_outbound_route` | Outbound route details by ID |

### Misc Destinations (3)

| Tool | Description |
|------|-------------|
| `oc_list_misc_dests` | List all misc destinations |
| `oc_add_misc_dest` | Create a misc destination **[confirm]** |
| `oc_remove_misc_dest` | Remove a misc destination **[confirm]** |

### Blacklist (3)

| Tool | Description |
|------|-------------|
| `oc_list_blacklist` | List all blacklisted numbers |
| `oc_add_blacklist` | Add a number to the blacklist **[confirm]** |
| `oc_remove_blacklist` | Remove a number from the blacklist **[confirm]** |

### Dialplan (5)

| Tool | Description |
|------|-------------|
| `oc_dialplan_show` | List custom dialplan contexts |
| `oc_dialplan_get_context` | Show contents of a custom context |
| `oc_dialplan_templates` | List available dialplan templates |
| `oc_dialplan_apply` | Generate and apply a dialplan template **[confirm]** |
| `oc_dialplan_remove` | Remove a custom dialplan context **[confirm]** |

### Paging & Parking (2)

| Tool | Description |
|------|-------------|
| `oc_list_paging` | List all paging/intercom groups |
| `oc_list_parking` | List parking lots and parked calls |

### Feature Codes, MOH & Recordings (3)

| Tool | Description |
|------|-------------|
| `oc_list_feature_codes` | List all feature codes with status |
| `oc_list_moh` | List music on hold categories |
| `oc_list_recordings` | List all system recordings |

### System (7)

| Tool | Description |
|------|-------------|
| `oc_reload` | Apply config changes (checks active calls first) **[confirm]** |
| `oc_backup_create` | Run a backup job by ID **[confirm]** |
| `oc_module_list` | List all FreePBX modules |
| `oc_module_status` | Detailed status of a specific module |
| `oc_get_asterisk_info` | Asterisk uptime, version, channels, registrations |
| `oc_get_firewall_status` | Firewall and intrusion detection status |
| `oc_get_sip_settings` | SIP/PJSIP settings — external IP, NAT, ports |

### Live Call Control (7) — via AMI

| Tool | Level | Description |
|------|-------|-------------|
| `oc_originate_call` | write | Click-to-call: ring an extension, connect to destination |
| `oc_hangup_call` | write | Hang up a specific channel |
| `oc_transfer_call` | write | Transfer a live call to another extension |
| `oc_park_call` | write | Park a live call |
| `oc_monitor_call` | write | Start recording a live call |
| `oc_stop_monitor_call` | write | Stop recording a live call |
| `oc_mute_call` | write | Mute or unmute a channel |

### Queue Agent Control (4) — via AMI

| Tool | Level | Description |
|------|-------|-------------|
| `oc_queue_add_agent` | write | Add agent to queue dynamically |
| `oc_queue_remove_agent` | write | Remove agent from queue |
| `oc_queue_pause_agent` | write | Pause or unpause a queue agent |
| `oc_queue_status` | read | Real-time queue status |

### Conference Control (4) — via AMI

| Tool | Level | Description |
|------|-------|-------------|
| `oc_conference_participants` | read | List participants in a live conference |
| `oc_conference_kick` | write | Kick a participant |
| `oc_conference_mute` | write | Mute or unmute a participant |
| `oc_conference_lock` | write | Lock or unlock a conference room |

### PJSIP & Diagnostics (7) — via AMI

| Tool | Level | Description |
|------|-------|-------------|
| `oc_pjsip_qualify` | read | Ping/qualify a PJSIP endpoint |
| `oc_pjsip_registrations` | read | List all inbound and outbound SIP registrations |
| `oc_pjsip_endpoint_details` | read | Deep endpoint health check — auth, transport, codecs, contacts, qualify |
| `oc_pjsip_show_channels` | read | Active SIP channels with codec/media stats |
| `oc_extension_states` | read | BLF/presence state for all extensions |
| `oc_rotate_logs` | admin | Rotate Asterisk log files |

### SIP Troubleshooting (3)

| Tool | Level | Description |
|------|-------|-------------|
| `oc_sip_trace` | admin | Time-bounded SIP trace capture (start/stop/status, max 30s) |
| `oc_diagnose_extension` | read | Composite diagnostic — endpoint + qualify + active calls + CDR + summary |
| `oc_diagnose_trunk` | read | Composite diagnostic — registration + qualify + routes + CDR + summary |

### Asterisk Database (2) — via AMI

| Tool | Level | Description |
|------|-------|-------------|
| `oc_astdb_get` | read | Read a value from the Asterisk database |
| `oc_astdb_put` | admin | Write a value to the Asterisk database |

### Services & Infrastructure (11)

| Tool | Level | Description |
|------|-------|-------------|
| `oc_start` | admin | Start FreePBX and Asterisk |
| `oc_stop` | admin | Stop FreePBX and Asterisk |
| `oc_restart` | admin | Restart FreePBX and Asterisk |
| `oc_enable_trunk` | write | Enable a trunk |
| `oc_disable_trunk` | write | Disable a trunk |
| `oc_validate` | admin | Run security validation scan |
| `oc_chown` | admin | Fix file ownership/permissions |
| `oc_get_external_ip` | read | Get public IP address |
| `oc_sync_userman` | admin | Sync User Manager with external directory |
| `oc_system_update` | admin | Check for and apply system updates |
| `oc_update_activation` | admin | Refresh system activation and license from Sangoma portal |

### Notifications & Sounds (3)

| Tool | Level | Description |
|------|-------|-------------|
| `oc_list_notifications` | read | List system notifications |
| `oc_delete_notification` | admin | Delete a notification |
| `oc_list_sounds` | read | List installed sound/language packs |

### PM2, Certificates & Context (3)

| Tool | Level | Description |
|------|-------|-------------|
| `oc_pm2_manage` | admin | Restart or stop a PM2 process |
| `oc_update_certificates` | admin | Update/renew all SSL certificates |
| `oc_show_context` | read | Show any Asterisk dialplan context |

### Saved Queries (4)

| Tool | Level | Description |
|------|-------|-------------|
| `oc_save_query` | write | Save a named GraphQL query (validates parse) |
| `oc_run_saved_query` | read | Execute a saved query |
| `oc_list_saved_queries` | read | List all saved queries |
| `oc_delete_saved_query` | write | Delete a saved query |

### Audit & Permissions (3)

| Tool | Level | Description |
|------|-------|-------------|
| `oc_audit_search` | read | Search the audit log |
| `oc_list_permissions` | admin | List user permission levels |
| `oc_set_permission` | admin | Set a user's permission level |

### Dashboard, Search, Knowledge Base & Visualization (4)

| Tool | Level | Description |
|------|-------|-------------|
| `oc_system_dashboard` | read | Quick system status — calls, extensions, notifications, uptime |
| `oc_search` | read | Search across extensions, ring groups, queues, IVRs, trunks |
| `oc_search_docs` | read | Search knowledge base — troubleshooting guides, how-to articles |
| `oc_trace_call_flow` | read | Trace call flow path for a DID or extension — Mermaid.js diagram |

## Access Methods

### CLI

```bash
# Interactive chat console (same commands as the web console)
fwconsole frogman:chat

# List all tools
fwconsole frogman:tool

# Run a tool
fwconsole frogman:tool oc_list_extensions '{}'
fwconsole frogman:tool oc_get_extension '{"ext":"1001"}'

# Write tools require confirm
fwconsole frogman:tool oc_add_extension '{"ext":"1002","name":"Jane","confirm":true}'
```

### HTTP API

See **[INTEGRATION.md](INTEGRATION.md)** for the full integration guide — authentication, token management, and examples for bots and web apps.

```bash
# Tool catalog (requires auth for remote, localhost bypasses)
curl http://localhost/admin/ajax.php?module=frogman&command=catalog

# Execute a tool
curl -X POST \
  -H "Content-Type: application/json" \
  -d '{"tool":"oc_list_extensions","params":{}}' \
  http://localhost/admin/ajax.php?module=frogman&command=tool
```

### GraphQL

Frogman registers queries and mutations under the `frogman_*` namespace:

**Queries:** `frogmanAuditLog`, `frogmanSavedQueries`, `frogmanSessions`, `frogmanAliases`

**Mutations:** `addFrogmanAlias`, `removeFrogmanAlias`, `addFrogmanSavedQuery`, `removeFrogmanSavedQuery`

**Scopes:** `read:frogman`, `write:frogman`

### Web Console

The built-in web console at `Admin > Frogman` provides a chat interface for controlling the PBX with natural language.

**Conversational flows** — write operations ask for confirmation, then offer related follow-ups:

```
You:  create extension 1010 for Mike White
Bot:  Would create extension 1010 (Mike White) as PJSIP.
      Reply yes to confirm or no to cancel.

You:  yes
Bot:  Extension 1010 (Mike White) created successfully.
      Would you also like to enable voicemail for 1010?

You:  yes
Bot:  Voicemail enabled on extension 1010.
      Would you like to apply the changes now?

You:  yes
Bot:  Configuration reload completed.
```

**Combo commands** — chain related actions in a single command:

```
create extension 1010 and voicemail for Mike White
create extension 1010 for Mike White with voicemail
create extension 1010 for Mike White and forward to 5551234
create extension 1010 for Mike White and ringgroup 600
```

**Destructive actions** get a warning:

```
You:  delete extension 1010
Bot:  ⚠️ This will delete extension 1010 (Mike White). This removes the device and user config.
      Reply yes to confirm or no to cancel.
```

**Other features:**
- Up/down arrow key command history
- Quick view buttons in the sidebar (alphabetical)
- SIP Troubleshooting section with one-click diagnostics
- Whimsical error messages instead of generic "Error:"

### Discord Bot

Control your PBX from Discord with natural language. Type `!create extension 1005 for Bob Smith`, reply `yes` to confirm.

See **[DISCORD.md](DISCORD.md)** for the full setup guide.

### MCP Server (Claude Desktop / Claude Code)

The MCP server runs as a standalone PHP process and proxies tool calls to the FreePBX ajax endpoint. This is where the real power is — any AI can control and diagnose the PBX through MCP.

```bash
# Run directly
php /var/www/html/admin/modules/frogman/mcp-server.php

# Claude Desktop config (~/.claude/claude_desktop_config.json)
{
  "mcpServers": {
    "frogman": {
      "command": "ssh",
      "args": ["root@YOUR_FREEPBX_HOST", "php",
               "/var/www/html/admin/modules/frogman/mcp-server.php"]
    }
  }
}
```

### Knowledge Base (RAG)

Frogman ships with curated troubleshooting and how-to documentation in `docs/`. The `oc_search_docs` tool searches these articles by keyword and returns relevant sections.

Through MCP, this enables RAG (Retrieval-Augmented Generation):

1. **You ask:** "Why doesn't extension 101 work?"
2. **AI runs diagnostics:** `oc_diagnose_extension` — checks registration, calls, CDR
3. **AI searches docs:** `oc_search_docs` — finds relevant troubleshooting articles
4. **AI reasons over both** and gives you an actionable answer

No LLM runs on the PBX — Frogman handles the retrieval, the AI handles the reasoning. The knowledge base is 40KB of markdown files with zero server overhead.

In the web console, users can also search docs directly:
```
how to fix NAT
troubleshoot one way audio
kb registration failure
docs firewall
```

## File Structure

```
frogman/
├── module.xml                 # Module metadata, dependencies, DB schema
├── Openclaw.class.php         # BMO class — audit log, tool registry, HTTP endpoints
├── install.php                # Post-install hooks (minimal)
├── uninstall.php              # Cleanup hooks (minimal)
├── page.frogman.php           # Admin GUI page (display=frogman)
├── mcp-server.php             # MCP protocol server (stdio JSON-RPC)
├── mcp-config.example.json    # Claude Desktop config example
├── Api/
│   └── Gql/
│       └── Openclaw.php       # GraphQL types, queries, mutations
├── Console/
│   ├── Tool.class.php         # fwconsole frogman:tool command
│   └── Chat.class.php         # fwconsole frogman:chat interactive console
├── Tools/
│   ├── AbstractTool.php       # Base class for all tools
│   ├── GetExtension.php       # ... (210 tool implementations)
│   └── ...
├── docs/                          # Knowledge base articles (markdown)
├── views/
│   └── main.php               # Admin page view
├── assets/
│   ├── css/
│   └── js/
└── i18n/
```

## AI Disclosure

Frogman is developed collaboratively with AI assistance. AI is used for code generation, refactoring, testing, debugging, documentation, and architecture decisions. All code is reviewed, tested, and deployed by a human developer.

## Contributing

Frogman welcomes contributions — including vibe-coded ones. If you used AI to help write your PR, that's great. Just make sure it works, follows the existing patterns, and you've tested it.

See [CLAUDE.md](CLAUDE.md) for codebase conventions and how to add new tools.

## Disclaimer

This software is experimental and provided as-is with no warranty of any kind. Use at your own risk.

## License

Copyright (c) 2026 Sangoma Technologies  
Licensed under the Affero General Public License (AGPL).  
See [LICENSE](LICENSE) for the full license text.

This project uses FreePBX, Asterisk, and Frogman.  
FreePBX and Asterisk are registered trademarks of Sangoma Technologies.
