# Security policy

## Reporting a vulnerability

If you think you've found a security issue in Frogman, please **don't open a public issue or post it on a forum**. Use GitHub's private vulnerability report form instead:

**https://github.com/mwtcmi/frogman/security/advisories/new**

That opens a private draft only the maintainer can see. From there I can collaborate with you on a fix, request a CVE if appropriate, and coordinate the disclosure timing.

If GitHub's form doesn't work for you, email me at `mr.mikewhite@me.com` with `[frogman-security]` in the subject and I'll pick it up from there.

## What counts as a security issue

Anything that lets a caller exceed their granted permission level, expose secrets, execute arbitrary code or shell, escape the tool allowlist, or take a destructive action without the documented `confirm:true` gate.

If you're not sure whether something is a security issue or a regular bug, err on the side of reporting it privately — I'd rather have a duplicate of a public issue than miss a real vulnerability.

## What to expect

- I'll acknowledge the report within 48 hours.
- I'll triage and give you a rough fix timeline within a week.
- When the patch is ready, I'll publish the advisory with credit to you (unless you'd rather stay anonymous) and tag a release with the fix.

## Scope

In scope:
- The Frogman module itself (`/var/www/html/admin/modules/frogman/`)
- The HTTP API, GraphQL surface, MCP server, and CLI harness
- The tool registry and individual tools under `Tools/`

Out of scope:
- Vulnerabilities in FreePBX core, Asterisk, or other FreePBX modules — those should go to the FreePBX security team
- Issues that require local root on the PBX host to exploit (you're already inside)
- Theoretical issues with no realistic exploit path against a default Frogman install
