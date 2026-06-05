# FreePBX 16 compatibility

Frogman was built for FreePBX 17 (Debian 12 / PHP 8.2 / Asterisk 20). As of
**v2.4.1** it also installs and runs on **FreePBX 16** (Sangoma OS 7 / PHP 7.4 /
Asterisk 18). This document records exactly what was changed and what still
needs to be verified on a live FreePBX 16 system.

## What was changed (statically verifiable)

These changes are version-neutral — the module continues to work on 17.

### 1. Install gates — `module.xml` `<depends>`

| Tag | Before | After |
| --- | --- | --- |
| framework `<version>` | `17.0.1` | `ge 16.0` |
| `<module>` | `api ge 17.0.1` | `api ge 16.0` |

FreePBX framework's `<depends><version>` accepts an operator prefix
(`lt le gt ge == = eq != ne`) and defaults to `ge`. `ge 16.0` therefore means
"framework ≥ 16.0", which is satisfied on both 16 and 17. The `api` module on
the `release/16.0` branch is the `16.0.x` line (e.g. `16.0.18`), so `api ge 16.0`
resolves correctly there and on 17.

`<supported>` was extended to list both `16.0` and `17.0`. Note: on FreePBX 16
the `<supported>` block is repository/display metadata — the actual hard install
gate is `<depends>`, which is why the depends change above is the load-bearing one.

### 2. PHP 7.4 source compatibility

FreePBX 16 ships PHP 7.4; FreePBX 17 ships PHP 8.2. A PHP 8-only construct
fatals the file when it is `require`d — in `Frogman.class.php` that takes down
the whole module; in a `Tools/*.php` file it takes down only that tool.

An exhaustive grep sweep across all 262 PHP files found **only two** PHP 8.0+
constructs, both `match()` expressions, now rewritten as PHP 7.4 array lookups
(semantically identical, `?? default` replacing the `default =>` arm):

- `Frogman.class.php` — `fm_extension_states` chat-output icon map
- `Tools/ExtensionStateList.php` — Asterisk hint state → label map

No other 8.x-only syntax is present: no enums, attributes (`#[...]`), nullsafe
`?->`, constructor property promotion, union / `mixed` / `never` types,
first-class callable syntax `(...)`, or `??=`.

## What still needs verifying on a live FreePBX 16 box (runtime)

The module now **loads** and its tools **parse** on PHP 7.4. What a static sweep
cannot prove is that every one of the 246 tools' BMO / GraphQL / AMI calls
resolve on FreePBX 16 and Asterisk 18. A BMO method that exists on 17 but not 16
only fatals **when that specific tool runs**, so these are per-tool risks, not
load blockers.

Most-likely divergence points (the author left "FreePBX 17" breadcrumb comments
at each):

| File | Concern | Assessment |
| --- | --- | --- |
| `Tools/CreateAdmin.php` | `Userman->addUser()` return shape and `Core->addAMPUser()` arity | `addUser` has returned the `['status','type','message']` shape since well before 16. Extra positional args to `addAMPUser` are silently ignored by PHP, so an 8-arg call against a shorter 16 signature no-ops the trailing args rather than fatalling. **Low risk — verify by creating a test admin.** |
| `Tools/DeleteUsermanUser.php` | `Userman->deleteUserByID()` return shape | Same stable Userman shape. **Low risk.** |
| `Tools/GetExtension.php` | `Core->getUser()` / `Core->getDevice()` | Both are long-standing, stable Core BMO methods present on 16. **Not a real divergence.** |

Other things worth a smoke test on 16 / Asterisk 18, because they exercise
runtime surfaces that differ across Asterisk major versions:

- **AMI / dialplan tools** (`fm_ami_command`, `fm_dialplan_show`,
  `fm_extension_states`, `fm_confbridge_*`, live-channel tools) — Asterisk 18
  vs 20 command output and `PJSIP` hint formats. `ExtensionStateList` already
  parses `PJSIP/...  State: Presence:` hint lines; confirm the format matches
  on your 18 build.
- **Queue CRUD** (`queues_add()` / `queues_del()` legacy functions) — confirm
  the function signatures match on the 16 `queues` module.
- **GraphQL** named operations — confirm the schema names used exist in the
  16.0 `api` module.

### Recommended verification

Install on a FreePBX 16 test box and exercise the common paths:

```bash
fwconsole ma install frogman
fwconsole frogman:chat        # try: list extensions, show extension <n>, extension states
```

Then run a representative write tool in dry-run, and the audit/posture
read-only tools, watching `/var/log/asterisk/freepbx.log` for BMO "method does
not exist" fatals. Any tool that fatals is a localized fix (swap the missing
16-era BMO call), not a module-wide problem.
