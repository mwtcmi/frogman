<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
// Schema is defined in module.xml <database> blocks.
// This file is reserved for feature codes, kvstore defaults, or data migrations.
// Migrations are written to be idempotent so install.php is safe to re-run.
//
// IMPORTANT: do NOT assign to `$db` in this file. FreePBX's install runner
// `require`s install.php from inside _runscripts() in modulefunctions.class.php,
// and a bare `$db = ...` assignment leaks into the parent scope. The parent
// then re-fetches `global $db` and finds the BMO Database object instead of
// the legacy DB class — BMO's escapeSimple returns PDO::quote() (already
// quoted), and modulefunctions wraps the result in quotes again. Result:
// `UPDATE modules SET version=''1.6.1'' WHERE modulename=''frogman''`,
// SQL syntax error, install fails partway. Use a local-named variable.

$frogmanDb = FreePBX::Database();

// GHSA-9xf5-9ghq-p6cw — hash existing plaintext API tokens in oc_api_tokens.
// Stored format: `sha256$<64-hex-hash>` (71 chars). The prefix makes the migration
// idempotent (re-running skips already-prefixed rows) and self-describing, so
// auth code can tell at a glance whether a row has been hashed.
//
// The ALTER is defensive: module.xml declares VARCHAR(80) but Doctrine's reconciler
// can miss column-width bumps on some FreePBX versions, and an unwidened column
// would silently truncate the stored hash. Idempotent MODIFY is cheap insurance.
try {
	$frogmanDb->query("ALTER TABLE oc_api_tokens MODIFY COLUMN token VARCHAR(80) NOT NULL DEFAULT ''");
	$frogmanDb->query("UPDATE oc_api_tokens SET token = CONCAT('sha256\$', SHA2(token, 256)) WHERE token != '' AND token NOT LIKE 'sha256\$%'");
	if (function_exists('out')) {
		out(_("Frogman: ensured all API tokens are stored hashed (GHSA-9xf5-9ghq-p6cw)."));
	}
} catch (\Throwable $e) {
	if (function_exists('out')) {
		out(_("Frogman: token hash migration failed — ") . $e->getMessage());
	}
	throw $e;
}

// GHSA-3p65-2prr-cfvf — scrub plaintext sensitive values from historical
// oc_audit_log entries. New writes go through Frogman::redactSensitive() in the
// audit methods; this one-shot scan covers anything written before the upgrade.
// Idempotent — values already redacted to [REDACTED] stay that way.
$frogmanSensitiveKeys = ['password', 'secret', 'token', 'vmpwd', 'umpassword', 'umpwd', 'api_key', 'apikey'];

$redactArray = function($data) use (&$redactArray, $frogmanSensitiveKeys) {
	if (!is_array($data)) return $data;
	$redactSet = array_flip($frogmanSensitiveKeys);
	foreach ($data as $key => $value) {
		if (is_string($key) && isset($redactSet[strtolower($key)])) {
			$data[$key] = '[REDACTED]';
		} elseif (is_array($value)) {
			$data[$key] = $redactArray($value);
		}
	}
	return $data;
};

try {
	$offset = 0;
	$batchSize = 500;
	$scrubbed = 0;
	while (true) {
		$rows = $frogmanDb->query("SELECT id, params, detail FROM oc_audit_log ORDER BY id LIMIT {$batchSize} OFFSET {$offset}")->fetchAll(\PDO::FETCH_ASSOC);
		if (empty($rows)) break;
		foreach ($rows as $row) {
			$updates = [];
			$binds = [];
			foreach (['params', 'detail'] as $col) {
				if (empty($row[$col])) continue;
				$decoded = json_decode($row[$col], true);
				if (!is_array($decoded)) continue;
				$redacted = $redactArray($decoded);
				$reencoded = json_encode($redacted);
				if ($reencoded !== $row[$col]) {
					$updates[] = "{$col} = ?";
					$binds[] = $reencoded;
				}
			}
			if (!empty($updates)) {
				$binds[] = $row['id'];
				$sth = $frogmanDb->prepare("UPDATE oc_audit_log SET " . implode(', ', $updates) . " WHERE id = ?");
				$sth->execute($binds);
				$scrubbed++;
			}
		}
		$offset += $batchSize;
	}
	if (function_exists('out')) {
		out(sprintf(_("Frogman: redacted sensitive values from %d historical audit log entries (GHSA-3p65-2prr-cfvf)."), $scrubbed));
	}
} catch (\Throwable $e) {
	if (function_exists('out')) {
		out(_("Frogman: audit log redaction migration failed — ") . $e->getMessage());
	}
	throw $e;
}

// v1.6.7 — capture chat-origin natural language in oc_audit_log so an audit
// row reconstructs the full chain: what the user said → what an upstream
// natural-language layer made of it → which tool ran. Both columns NULL for
// non-chat invocations (HTTP API, GraphQL, CLI, MCP); only the chat entry
// point populates them. Idempotent — column-exists guard means re-running
// install.php is safe. See module.xml for the corresponding Doctrine
// declaration; this ALTER is the fast path so Doctrine reconciler drift
// across FreePBX versions doesn't leave the columns missing in practice.
try {
	$cols = $frogmanDb->query("SHOW COLUMNS FROM oc_audit_log")->fetchAll(\PDO::FETCH_COLUMN);
	if (!in_array('chat_input', $cols, true)) {
		$frogmanDb->query("ALTER TABLE oc_audit_log ADD COLUMN chat_input TEXT NULL AFTER intent");
	}
	if (!in_array('interpreted_as', $cols, true)) {
		$frogmanDb->query("ALTER TABLE oc_audit_log ADD COLUMN interpreted_as TEXT NULL AFTER chat_input");
	}
	if (function_exists('out')) {
		out(_("Frogman: ensured oc_audit_log has chat_input + interpreted_as columns (v1.6.7)."));
	}
} catch (\Throwable $e) {
	if (function_exists('out')) {
		out(_("Frogman: chat_input migration failed — ") . $e->getMessage());
	}
	throw $e;
}

// v2.7.0 — defensive create for oc_downloads. Doctrine reconciler usually handles
// the module.xml <database> block, but a fast path here keeps install.php
// idempotent and avoids the "module.xml says table exists but reconciler skipped
// it" failure mode that has bitten other migrations. CREATE IF NOT EXISTS so
// re-running is safe.
try {
	$frogmanDb->query("CREATE TABLE IF NOT EXISTS oc_downloads (
		id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
		token VARCHAR(64) NOT NULL DEFAULT '',
		caller VARCHAR(100) NOT NULL DEFAULT '',
		kind VARCHAR(20) NOT NULL DEFAULT '',
		file_path TEXT NULL,
		mime_type VARCHAR(80) NOT NULL DEFAULT 'application/octet-stream',
		display_name VARCHAR(255) NULL,
		meta TEXT NULL,
		created_at INT UNSIGNED NOT NULL DEFAULT 0,
		expires_at INT UNSIGNED NOT NULL DEFAULT 0,
		accessed_at INT UNSIGNED NULL,
		access_count INT UNSIGNED NOT NULL DEFAULT 0,
		UNIQUE KEY idx_oc_downloads_token (token),
		KEY idx_oc_downloads_caller (caller),
		KEY idx_oc_downloads_expires (expires_at)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8");
	if (function_exists('out')) {
		out(_("Frogman: ensured oc_downloads table exists (v2.7.0)."));
	}
} catch (\Throwable $e) {
	if (function_exists('out')) {
		out(_("Frogman: oc_downloads migration failed: ") . $e->getMessage());
	}
	throw $e;
}

// Tokens sidebar / posture audit support — record when each token was last used
// for auth. Powers the Tokens panel in views/main.php (stale-badge + last-used
// column) and any future fm_audit_token_posture. Idempotent SHOW COLUMNS guard
// mirrors the chat_input pattern above. 0 means "never used since the column
// existed" — same convention as created_at (stored as Unix timestamp).
try {
	$tokenCols = $frogmanDb->query("SHOW COLUMNS FROM oc_api_tokens")->fetchAll(\PDO::FETCH_COLUMN);
	if (!in_array('last_used_at', $tokenCols, true)) {
		$frogmanDb->query("ALTER TABLE oc_api_tokens ADD COLUMN last_used_at INT UNSIGNED NOT NULL DEFAULT 0 AFTER created_at");
	}
	if (function_exists('out')) {
		out(_("Frogman: ensured oc_api_tokens has last_used_at column."));
	}
} catch (\Throwable $e) {
	if (function_exists('out')) {
		out(_("Frogman: last_used_at migration failed — ") . $e->getMessage());
	}
	throw $e;
}
