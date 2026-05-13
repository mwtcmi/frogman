<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
// Schema is defined in module.xml <database> blocks.
// This file is reserved for feature codes, kvstore defaults, or data migrations.
// Migrations are written to be idempotent so install.php is safe to re-run.

$db = FreePBX::Database();

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
		$rows = $db->query("SELECT id, params, detail FROM oc_audit_log ORDER BY id LIMIT {$batchSize} OFFSET {$offset}")->fetchAll(\PDO::FETCH_ASSOC);
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
				$sth = $db->prepare("UPDATE oc_audit_log SET " . implode(', ', $updates) . " WHERE id = ?");
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
