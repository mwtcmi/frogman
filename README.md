# Frogman 🐸

**Headless PBX control through MCP and HTTP API.** Any AI, bot, or app connects and manages FreePBX through 246 tools. No GraphQL needed.

Connect via MCP and ask "why can't extension 101 make calls?" — Frogman runs live diagnostics, searches its built-in knowledge base, and hands the AI everything it needs to answer.

Connect via HTTP API with token auth and build dashboards, bots, or integrations on any platform.

Also includes a web console and CLI chat for direct access. Built entirely on FreePBX's native interfaces (BMO, AMI, fwconsole). Every action is validated, permission-gated, audit-logged, and requires confirmation before making changes.

## Requirements

- FreePBX 17.x
- Asterisk 20+ (tested on 22.8.2)
- PHP 8.2+
- API module (for GraphQL) 17.0.1+

## Installation

Pick whichever path fits — all three install the same module.

### Option 1: Module Admin GUI (recommended)

1. Download the latest tarball from the [Releases page](https://github.com/mwtcmi/frogman/releases/latest) (`frogman-x.x.x.tgz`).
2. In FreePBX, go to **Admin → Module Admin**.
3. Click **Upload modules**, choose the `.tgz`, and click **Upload**.
4. Find **Frogman** in the module list, set the action to **Install**, click **Process**, then **Confirm**.
5. When the red **Apply Config** bar appears, click it.

### Option 2: One-line CLI install

Grabs the latest release URL from the GitHub API and installs it — no version to update:

```bash
URL=$(curl -s https://api.github.com/repos/mwtcmi/frogman/releases/latest | grep browser_download_url | grep '\.tgz' | cut -d'"' -f4)
fwconsole ma downloadinstall "$URL"
fwconsole reload
```

Or grab the URL by hand from the [latest release page](https://github.com/mwtcmi/frogman/releases/latest) and pass it directly to `fwconsole ma downloadinstall`.

### Option 3: Manual install (developers)

```bash
# Copy the module to your FreePBX modules directory
cp -r frogman /var/www/html/admin/modules/

# Install the module
fwconsole ma installlocal frogman

# Set permissions and apply
fwconsole chown
fwconsole reload
```

Use `installlocal` (not `install`) — `install` hits online repos and fails because Frogman isn't published to FreePBX's standard repos.

### Verify

```bash
fwconsole ma list | grep frogman
# | frogman | x.x.x | Enabled | AGPLv3+ | Unknown |
```

`Unknown` in the Signature column is expected — it means the module is signed, just not with one of Sangoma's two hardcoded keys. It is not a verification failure.

### Post-Install (Optional)

To enable service management tools (start/stop/restart FreePBX, fix permissions, system update, etc.) from the chat console, run once as root:

```bash
echo 'asterisk ALL=(root) NOPASSWD: /usr/sbin/fwconsole' > /etc/sudoers.d/frogman
chmod 440 /etc/sudoers.d/frogman
```

This is optional — all other tools work without it. Without this, service tools will show instructions on how to enable.

## Architecture

Frogman is the MCP server — the AI interface to the PBX. Frogman is the FreePBX module that provides the 246 tools it exposes. Together, they have two interfaces:

- **MCP Server** — the core product. Any AI connects via MCP and uses 246 tools to control, diagnose, and troubleshoot the PBX. This is where RAG, reasoning, and intelligent support happen.
- **Web Console & CLI** — a human-friendly chat interface using pattern matching. Same tools, no AI required. Useful for quick tasks without an MCP client.

### Tool Routing Hierarchy

Tools internally route by this priority:

1. **BMO PHP calls** — preferred for all operations
2. **AMI commands** — live call control, channel operations
3. **Direct DB reads** — diagnostics and reporting
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

Contributors: see [CONTRIBUTING.md](./CONTRIBUTING.md) for the full rules, why they exist, and how to add a tool.

## Tool Catalog (246 tools)

### Extensions (7)

| Tool | Description |
|------|-------------|
| `fm_list_extensions` | List all extensions with optional tech/search filters |
| `fm_get_extension` | Full details for a single extension |
| `fm_get_extension_health` | Config + SIP registration + recent CDR |
| `fm_add_extension` | Create a new PJSIP extension + linked User Manager user **[confirm]** |
| `fm_update_extension` | Update extension name, secret, or CID **[confirm]** |
| `fm_set_extension_email` | Set/update email on the linked User Manager user + voicemail-to-email **[confirm]** |
| `fm_disable_extension` | Delete an extension **[confirm]** |

### Ring Groups (4)

| Tool | Description |
|------|-------------|
| `fm_list_ringgroups` | List all ring groups |
| `fm_get_ringgroup` | Ring group details + member list |
| `fm_ringgroup_add_member` | Add member to ring group **[confirm]** |
| `fm_ringgroup_remove_member` | Remove member from ring group **[confirm]** |

### Trunks (2)

| Tool | Description |
|------|-------------|
| `fm_list_trunks` | List all configured trunks |
| `fm_get_trunk_status` | Trunk config + PJSIP registration status |

### Calls & CDR (2)

| Tool | Description |
|------|-------------|
| `fm_list_active_calls` | Active calls via AMI |
| `fm_get_cdr` | Query call detail records with filters |

### Follow Me (2)

| Tool | Description |
|------|-------------|
| `fm_set_followme` | Configure Follow Me for an extension **[confirm]** — chat: `set followme on 1001 to 1001,5551234567`, `set follow me` (guided wizard), or `set follow me 1001` (wizard with ext pre-filled) |
| `fm_clear_followme` | Remove Follow Me **[confirm]** |

### Call Forward & DND (5)

| Tool | Description |
|------|-------------|
| `fm_get_call_forward` | Get call forwarding status for an extension |
| `fm_set_call_forward` | Set call forwarding (CF/CFB/CFU) **[confirm]** |
| `fm_clear_call_forward` | Clear call forwarding **[confirm]** |
| `fm_get_dnd` | Get Do Not Disturb status |
| `fm_toggle_dnd` | Toggle Do Not Disturb **[confirm]** |

### Voicemail (2)

| Tool | Description |
|------|-------------|
| `fm_list_voicemail` | List all voicemail boxes |
| `fm_get_voicemail` | Voicemail box details and message count |

### Queues (2)

| Tool | Description |
|------|-------------|
| `fm_list_queues` | List all call queues |
| `fm_get_queue` | Queue details by ID |

### Conferences (2)

| Tool | Description |
|------|-------------|
| `fm_list_conferences` | List all conference rooms |
| `fm_get_conference` | Conference room details |

### IVRs (2)

| Tool | Description |
|------|-------------|
| `fm_list_ivrs` | List all IVRs |
| `fm_get_ivr` | IVR details by ID |

### Announcements (1)

| Tool | Description |
|------|-------------|
| `fm_list_announcements` | List all announcements |

### Time Conditions & Day-Night (4)

| Tool | Description |
|------|-------------|
| `fm_list_time_conditions` | List all time conditions with current state |
| `fm_toggle_time_condition` | Toggle a time condition override **[confirm]** |
| `fm_list_daynight` | List all day/night call flow controls |
| `fm_toggle_daynight` | Toggle a day/night call flow **[confirm]** |

### Routes (3)

| Tool | Description |
|------|-------------|
| `fm_list_inbound_routes` | List all inbound routes (DIDs) |
| `fm_list_outbound_routes` | List all outbound routes |
| `fm_get_outbound_route` | Outbound route details by ID |

### Misc Destinations (3)

| Tool | Description |
|------|-------------|
| `fm_list_misc_dests` | List all misc destinations |
| `fm_add_misc_dest` | Create a misc destination **[confirm]** |
| `fm_remove_misc_dest` | Remove a misc destination **[confirm]** |

### Blacklist (3)

| Tool | Description |
|------|-------------|
| `fm_list_blacklist` | List all blacklisted numbers |
| `fm_add_blacklist` | Add a number to the blacklist **[confirm]** |
| `fm_remove_blacklist` | Remove a number from the blacklist **[confirm]** |

### Dialplan (5)

| Tool | Description |
|------|-------------|
| `fm_dialplan_show` | List custom dialplan contexts |
| `fm_dialplan_get_context` | Show contents of a custom context |
| `fm_dialplan_templates` | List available dialplan templates |
| `fm_dialplan_apply` | Generate and apply a dialplan template **[confirm]** |
| `fm_dialplan_remove` | Remove a custom dialplan context **[confirm]** |

### Paging & Parking (2)

| Tool | Description |
|------|-------------|
| `fm_list_paging` | List all paging/intercom groups |
| `fm_list_parking` | List parking lots and parked calls |

### Feature Codes, MOH & Recordings (3)

| Tool | Description |
|------|-------------|
| `fm_list_feature_codes` | List all feature codes with status |
| `fm_list_moh` | List music on hold categories |
| `fm_list_recordings` | List all system recordings |

### System (8)

| Tool | Description |
|------|-------------|
| `fm_reload` | Apply config changes (checks active calls first) **[confirm]** |
| `fm_module_list` | List FreePBX modules. Optional filters: `status`, `license` (commercial/gpl/gpl2/gpl3/agpl/other), `all`. Chat shows a clickable summary by default — chat: `list modules`, `list modules commercial`, `list all modules` |
| `fm_check_upgrades` | Query online repos for module upgrades (~10s, network call). Per-row "⬆️ Upgrade" chip and bottom "⬆️ Upgrade all" chip — chat: `check for upgrades` |
| `fm_module_status` | Detailed status of a specific module |
| `fm_get_asterisk_info` | Asterisk uptime, version, channels, registrations |
| `fm_get_firewall_status` | Firewall and intrusion detection status |
| `fm_get_sip_settings` | SIP/PJSIP settings — external IP, NAT, ports |
| `fm_repair_userman_links` | Restore default-group + assigned wiring on User Manager users so UCP login works **[confirm]** — chat: `repair userman`, `fix ucp logins`, `repair userman 1001` |
| `fm_delete_userman_user` | Delete a User Manager row by uid or username (extension is NOT removed) **[confirm]** — chat: `delete userman user <username>` / `delete userman user <uid>` |

### Backups (5)

| Tool | Level | Description |
|------|-------|-------------|
| `fm_list_backup_jobs` | read | List configured backup jobs with schedule, destinations, retention, email — chat: `list backup jobs` |
| `fm_backup_status` | read | Per-job health: last successful run, in-flight, next/previous scheduled tick, missed-run inference — chat: `backup status`, `backup status for <name>` |
| `fm_list_backup_runs` | read | Real run history (success/running/failed) + synthetic `failed_inferred` rows for missed scheduled ticks — chat: `list backup runs`, `list backup runs for <name>`, `list failed backup jobs` |
| `fm_get_backup` | read | Full config of one backup job by ID — chat: `show backup <id>` |
| `fm_backup_create` | admin | Run a backup job by ID **[confirm]** |

### Live Call Control (7) — via AMI

| Tool | Level | Description |
|------|-------|-------------|
| `fm_originate_call` | write | Click-to-call: ring an extension, connect to destination |
| `fm_hangup_call` | write | Hang up a specific channel |
| `fm_transfer_call` | write | Transfer a live call to another extension |
| `fm_park_call` | write | Park a live call |
| `fm_monitor_call` | write | Start recording a live call |
| `fm_stop_monitor_call` | write | Stop recording a live call |
| `fm_mute_call` | write | Mute or unmute a channel |

### Queue Agent Control (4) — via AMI

| Tool | Level | Description |
|------|-------|-------------|
| `fm_queue_add_agent` | write | Add agent to queue dynamically |
| `fm_queue_remove_agent` | write | Remove agent from queue |
| `fm_queue_pause_agent` | write | Pause or unpause a queue agent |
| `fm_queue_status` | read | Real-time queue status |

### Conference Control (4) — via AMI

| Tool | Level | Description |
|------|-------|-------------|
| `fm_conference_participants` | read | List participants in a live conference |
| `fm_conference_kick` | write | Kick a participant |
| `fm_conference_mute` | write | Mute or unmute a participant |
| `fm_conference_lock` | write | Lock or unlock a conference room |

### PJSIP & Diagnostics (7) — via AMI

| Tool | Level | Description |
|------|-------|-------------|
| `fm_pjsip_qualify` | read | Ping/qualify a PJSIP endpoint |
| `fm_pjsip_registrations` | read | List all inbound and outbound SIP registrations |
| `fm_pjsip_endpoint_details` | read | Deep endpoint health check — auth, transport, codecs, contacts, qualify |
| `fm_pjsip_show_channels` | read | Active SIP channels with codec/media stats |
| `fm_extension_states` | read | BLF/presence state for all extensions |
| `fm_rotate_logs` | admin | Rotate Asterisk log files |

### SIP Troubleshooting (3)

| Tool | Level | Description |
|------|-------|-------------|
| `fm_sip_trace` | admin | Time-bounded SIP trace capture (start/stop/status, max 30s) |
| `fm_diagnose_extension` | read | Composite diagnostic — endpoint + qualify + active calls + CDR + summary |
| `fm_diagnose_trunk` | read | Composite diagnostic — registration + qualify + routes + CDR + summary |

### Asterisk Database (2) — via AMI

| Tool | Level | Description |
|------|-------|-------------|
| `fm_astdb_get` | read | Read a value from the Asterisk database |
| `fm_astdb_put` | admin | Write a value to the Asterisk database |

### Services & Infrastructure (11)

| Tool | Level | Description |
|------|-------|-------------|
| `fm_start` | admin | Start FreePBX and Asterisk |
| `fm_stop` | admin | Stop FreePBX and Asterisk |
| `fm_restart` | admin | Restart FreePBX and Asterisk |
| `fm_enable_trunk` | write | Enable a trunk |
| `fm_disable_trunk` | write | Disable a trunk |
| `fm_validate` | admin | Run security validation scan |
| `fm_chown` | admin | Fix file ownership/permissions |
| `fm_get_external_ip` | read | Get public IP address |
| `fm_sync_userman` | admin | Sync User Manager with external directory |
| `fm_system_update` | admin | Check for and apply system updates |
| `fm_update_activation` | admin | Refresh system activation and license from Sangoma portal |

### Notifications & Sounds (3)

| Tool | Level | Description |
|------|-------|-------------|
| `fm_list_notifications` | read | List system notifications |
| `fm_delete_notification` | admin | Delete a notification |
| `fm_list_sounds` | read | List installed sound/language packs |

### PM2, Certificates & Context (3)

| Tool | Level | Description |
|------|-------|-------------|
| `fm_pm2_manage` | admin | Restart or stop a PM2 process |
| `fm_update_certificates` | admin | Update/renew all SSL certificates |
| `fm_show_context` | read | Show any Asterisk dialplan context |

### Saved Queries (4)

| Tool | Level | Description |
|------|-------|-------------|
| `fm_save_query` | write | Save a named GraphQL query (validates parse) |
| `fm_run_saved_query` | read | Execute a saved query |
| `fm_list_saved_queries` | read | List all saved queries |
| `fm_delete_saved_query` | write | Delete a saved query |

### Audit & Permissions (3)

| Tool | Level | Description |
|------|-------|-------------|
| `fm_audit_search` | read | Search the audit log |
| `fm_list_permissions` | admin | List user permission levels |
| `fm_set_permission` | admin | Set a user's permission level |

### Dashboard, Search, Knowledge Base & Visualization (4)

| Tool | Level | Description |
|------|-------|-------------|
| `fm_system_dashboard` | read | Quick system status — calls, extensions, notifications, uptime |
| `fm_search` | read | Search across extensions, ring groups, queues, IVRs, trunks |
| `fm_search_docs` | read | Search knowledge base — troubleshooting guides, how-to articles |
| `fm_trace_call_flow` | read | Trace call flow path for a DID or extension — Mermaid.js diagram |

## Chat Phrases (web console / CLI chat)

The chat parser maps natural-language phrases to tools. A non-exhaustive sample:

| Phrase | Routes to |
|--------|-----------|
| `add ext 1010 name "Jane"` | `fm_add_extension` |
| `add ext 1010 name "Jane" email jane@x.com` | `fm_add_extension` (with email + userman user creation) |
| `add ext 1010 name "Jane" with voicemail` | `fm_add_extension` then voicemail follow-up |
| `set email 1010 jane@x.com` / `email 1010 jane@x.com` | `fm_set_extension_email` |
| `enable voicemail 1010` / `disable voicemail 1010` | `fm_enable_voicemail` / `fm_disable_voicemail` |
| `list extensions`, `list ring groups`, `list trunks` | corresponding `fm_list_*` |
| `who's on the phone` / `live calls` | `fm_whos_on_the_phone` |
| `status`, `dashboard`, `how's the pbx` | `fm_system_dashboard` |
| `disk space`, `sys info` | corresponding read tools |
| `stats`, `peak hours`, `failed calls`, `busiest extensions` | CDR analytics |
| `update activation` / `refresh license` | `fm_update_activation` |
| `list certificates`, `update certificates` | `fm_list_certificates` / `fm_update_certificates` |
| `trace flow 1010` / `where does 5551234 go` | `fm_trace_call_flow` |
| `did map` / `inbound map` / `where do my dids go` | `fm_did_destination_map` (Mermaid flowchart of every DID and where it lands) |
| `search jane` / `find queue 600` | `fm_search` |
| `kb voicemail setup` / `how do i add a trunk` / `troubleshoot one-way audio` | `fm_search_docs` (knowledge base) |
| Question ending in `?` | falls through to KB search |
| `yes` / `no` | confirms or cancels a pending action / follow-up |
| `skip` / `cancel` | aborts a free-text input prompt |

Type `help` in the chat to see the full list.

Follow-up chains: after `add extension`, the parser asks about voicemail; after voicemail enables, asks about email (with a clickable Skip and free-text input); after email, asks to apply changes. Click the `✅ Yes` / `❌ No` / `⏭ Skip` pills or just type the answer.

## Access Methods

### CLI

```bash
# Interactive chat console (same commands as the web console)
fwconsole frogman:chat

# List all tools
fwconsole frogman:tool

# Run a tool
fwconsole frogman:tool fm_list_extensions '{}'
fwconsole frogman:tool fm_get_extension '{"ext":"1001"}'

# Write tools require confirm
fwconsole frogman:tool fm_add_extension '{"ext":"1002","name":"Jane","confirm":true}'
```

### HTTP API

See **[INTEGRATION.md](INTEGRATION.md)** for the full integration guide — authentication, token management, and examples for bots and web apps.

```bash
# Tool catalog (requires auth for remote, localhost bypasses)
curl http://localhost/admin/ajax.php?module=frogman&command=catalog

# Execute a tool
curl -X POST \
  -H "Content-Type: application/json" \
  -d '{"tool":"fm_list_extensions","params":{}}' \
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

#### Other MCP clients

The same SSH-transport config works for any stdio-MCP client — Frogman doesn't care which one's on the other end of the pipe. Point your client's MCP config at:

```
command: ssh
args:    root@YOUR_FREEPBX_HOST php /var/www/html/admin/modules/frogman/mcp-server.php
```

Tested with **Claude Desktop**, **Claude Code**, **Cursor**, and **Windsurf**. Any other MCP-compatible client (OpenClaw, Continue, etc.) should work — the protocol is the same.

For non-MCP integrations (LangChain, OpenAI function calling, Slack/Teams/Telegram bots, custom dashboards), use the HTTP API instead — see **[INTEGRATION.md](INTEGRATION.md)** for token auth, tool catalog, and per-language examples.

### Knowledge Base (RAG)

Frogman ships with curated troubleshooting and how-to documentation in `docs/`. The `fm_search_docs` tool searches these articles by keyword and returns relevant sections.

Through MCP, this enables RAG (Retrieval-Augmented Generation):

1. **You ask:** "Why doesn't extension 101 work?"
2. **AI runs diagnostics:** `fm_diagnose_extension` — checks registration, calls, CDR
3. **AI searches docs:** `fm_search_docs` — finds relevant troubleshooting articles
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
├── Frogman.class.php         # BMO class — audit log, tool registry, HTTP endpoints
├── install.php                # Post-install hooks (minimal)
├── uninstall.php              # Cleanup hooks (minimal)
├── page.frogman.php           # Admin GUI page (display=frogman)
├── mcp-server.php             # MCP protocol server (stdio JSON-RPC)
├── mcp-config.example.json    # Claude Desktop config example
├── Api/
│   └── Gql/
│       └── Frogman.php       # GraphQL types, queries, mutations
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
