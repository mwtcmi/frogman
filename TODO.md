# Frogman — TODO

## High Priority

- [ ] **Token-based auth for HTTP API** — Currently the HTTP tool endpoint relies on FreePBX session auth (localhost bypasses). Add bearer token support using the FreePBX API module's OAuth2 flow so external clients can authenticate without a browser session.
- [ ] **Per-tool input schemas** — Each tool's `inputSchema` in the MCP server is generic (`params: object`). Generate specific JSON Schema per tool from a `schema()` method on each tool class, improving autocomplete and validation for MCP clients.
- [ ] **Reload gating on write tools** — Write tools return `needs_reload: true` but don't trigger a reload. Consider a post-action hint system or batch reload after multiple writes.

## Medium Priority

- [ ] **Module install/upgrade/remove tools** — Deferred from Phase 5. Gate behind a higher permission level. Wrap `fwconsole ma install/upgrade/remove` with confirm gates.
- [ ] **Voicemail tools** — `fm_get_voicemail(ext)`, `fm_list_voicemails(ext)` for reading voicemail status and message counts.
- [ ] **Queue tools** — `fm_list_queues`, `fm_get_queue(id)`, `fm_queue_add_member`, `fm_queue_remove_member`, `fm_queue_stats`.
- [ ] **IVR tools** — `fm_list_ivrs`, `fm_get_ivr(id)` for reading IVR configuration.
- [ ] **Time condition tools** — `fm_list_time_conditions`, `fm_toggle_time_condition(id)`.
- [ ] **Parking lot tools** — `fm_list_parking_lots`, `fm_get_parked_calls`.
- [ ] **Firewall tools** — Read-only views of firewall status and rules.

## Low Priority / Future

- [ ] **WebSocket transport for MCP** — Alternative to stdio for persistent connections from web-based chat UIs.
- [ ] **Batch tool execution** — Accept an array of tool calls in a single request, execute sequentially, return combined results.
- [ ] **Tool rate limiting** — Add configurable rate limits per tool/user to prevent abuse.
- [ ] **Saved query variables** — The `param_spec` field exists but `fm_run_saved_query` doesn't yet substitute variables into the query. Add GraphQL variable support.
- [ ] **Session management** — The `fm_sessions` table exists but nothing creates/manages sessions yet. Wire into the chat layer.
- [ ] **Job queue** — The `fm_jobs` table exists but nothing uses it. For long-running operations (backup, bulk extension creation), queue the job and poll for status.
- [ ] **Admin page enhancements** — The admin page (`page.frogman.php`) is a stub. Add: audit log viewer, tool catalog browser, saved query manager, session list.
- [ ] **i18n** — All user-facing strings should use `_()` for translation. Currently mostly done but not fully covered.

## Known Issues

- **UpdateExtension is destructive** — Uses the same delete+recreate pattern as FreePBX's own GraphQL mutation. This means extension-specific settings from other modules (voicemail, follow-me, etc.) may not survive an update. Consider a more surgical update approach.
- **Backup tool requires pre-existing backup definition** — `fm_backup_create` can't create new backup definitions, only run existing ones. The backup module's BMO doesn't expose a simple "create backup" method.
- **Console command deprecation warning** — FreePBX logs a deprecation notice about the Console directory. Harmless but noisy.

## Tech Debt

- [ ] **RunSavedQuery hardcodes API credentials** — The `fm_run_saved_query` tool has the test API app client_id/secret hardcoded. Should read from kvstore or config.
- [ ] **Error handling consistency** — Some tools throw exceptions, others return error arrays. Standardize on exceptions (the registry catches them).
- [ ] **Tool descriptions as param documentation** — Tool params are documented in the description string. Add a formal `params()` method returning structured param definitions.
