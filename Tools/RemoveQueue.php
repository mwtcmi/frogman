<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

// See AddQueue.php header for the in-walls routing justification.
// Delete path is queues_del() in queues/functions.inc/geters_seters.php.
class RemoveQueue extends AbstractTool {
	public function name() { return 'fm_remove_queue'; }
	public function description() { return 'Remove a call queue. Params: account (required, queue number). Requires confirm:true. Pre-flight scans inbound routes / IVR entries / IVR fallbacks / time conditions / ring group fallbacks / other-queue fallbacks / day-night / announcements / misc destinations for references; refuses unless force:true is also set when references exist.'; }
	public function validate($params) {
		if (empty($params['account'])) return 'Parameter "account" is required (queue number)';
		if (!preg_match('/^\d{1,11}$/', (string)$params['account'])) return 'Parameter "account" must be 1-11 digits';
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_WRITE; }

	// Scan FreePBX core destination columns for references to this queue.
	// Returns a list of human-readable usage strings (empty = no references).
	// Reads only — Hard Rule 3 allows cross-module reads.
	public static function findReferences($db, $account) {
		$prefix = "ext-queues,{$account},";
		$like = $prefix . '%';
		$found = [];

		// Curated set: covers FreePBX core destinations. Commercial-module tables
		// (vqplus_*, virtual_queue_*, dynroute_*, etc.) are skipped because they
		// may not exist on every install — INFORMATION_SCHEMA could probe them
		// but the value/cost tradeoff isn't worth it for a delete-time check.
		// Curated set: covers FreePBX core destinations. Commercial-module tables
		// (vqplus_*, virtual_queue_*, dynroute_*, etc.) are skipped because they
		// may not exist on every install. id_col, table, col are static literals
		// — never interpolated from caller input. where_extra binds via ?.
		$checks = [
			['table' => 'incoming',       'col' => 'destination', 'label' => 'Inbound route',          'id_col' => 'CONCAT(cidnum,"/",extension)'],
			['table' => 'ivr_entries',    'col' => 'dest',        'label' => 'IVR entry',              'id_col' => 'CONCAT("ivr ",ivr_id," selection ",selection)'],
			['table' => 'ivr_details',    'col' => 'timeout_destination', 'label' => 'IVR timeout',    'id_col' => 'CONCAT("ivr ",id," (",name,")")'],
			['table' => 'ivr_details',    'col' => 'invalid_destination', 'label' => 'IVR invalid',    'id_col' => 'CONCAT("ivr ",id," (",name,")")'],
			['table' => 'timeconditions', 'col' => 'truegoto',    'label' => 'Time condition (true)',  'id_col' => 'CONCAT(timeconditions_id," ",displayname)'],
			['table' => 'timeconditions', 'col' => 'falsegoto',   'label' => 'Time condition (false)', 'id_col' => 'CONCAT(timeconditions_id," ",displayname)'],
			['table' => 'ringgroups',     'col' => 'postdest',    'label' => 'Ring group fallback',    'id_col' => 'CONCAT(grpnum," ",description)'],
			['table' => 'queues_config',  'col' => 'dest',        'label' => 'Queue fail-over',        'id_col' => 'CONCAT(extension," ",descr)', 'where_extra' => 'extension <> ?', 'extra_bind' => $account],
			['table' => 'daynight',       'col' => 'dest',        'label' => 'Day/Night',              'id_col' => 'id'],
			['table' => 'announcement',   'col' => 'post_dest',   'label' => 'Announcement',           'id_col' => 'CONCAT(announcement_id," ",description)'],
			['table' => 'miscdests',      'col' => 'destdial',    'label' => 'Misc destination',       'id_col' => 'CONCAT(id," ",description)'],
			['table' => 'findmefollow',   'col' => 'postdest',    'label' => 'Follow-me fallback',     'id_col' => 'grpnum'],
		];

		foreach ($checks as $c) {
			try {
				$sql = "SELECT {$c['id_col']} AS who FROM {$c['table']} WHERE {$c['col']} LIKE ?";
				$bind = [$like];
				if (!empty($c['where_extra'])) {
					$sql .= " AND {$c['where_extra']}";
					$bind[] = $c['extra_bind'];
				}
				$sth = $db->prepare($sql);
				$sth->execute($bind);
				foreach ($sth->fetchAll(\PDO::FETCH_ASSOC) as $row) {
					$found[] = "{$c['label']}: " . trim((string)($row['who'] ?? '?'));
				}
			} catch (\Throwable $e) {
				// Table may not exist on this install — skip silently.
			}
		}

		return $found;
	}

	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$force = !empty($params['force']) && $params['force'] === true;
		$account = (string)$params['account'];

		// DB-based existence check. getQueuesDetails() reads AMI "queue show"
		// which would miss DB-only queues (pre-reload state).
		$this->freepbx->Modules->loadFunctionsInc('queues');
		$existing = function_exists('queues_get') ? queues_get($account) : [];
		if (empty($existing)) {
			$accountSan = $this->frogman->sanitizeForChat($account);
			return ['error' => "Queue `{$accountSan}` not found"];
		}
		$descr = (string)($existing['name'] ?? '');
		$db = $this->freepbx->Database;

		$refs = self::findReferences($db, $account);
		$accountSan = $this->frogman->sanitizeForChat($account);
		$descrSan = $this->frogman->sanitizeForChat($descr);

		if (!empty($refs) && !$force) {
			$frogman = $this->frogman;
			$refLines = array_map(function($r) use ($frogman) { return '• ' . $frogman->sanitizeForChat($r); }, array_slice($refs, 0, 20));
			$more = count($refs) > 20 ? "\n• …and " . (count($refs) - 20) . ' more' : '';
			return ['error' => "Refusing to delete queue `{$accountSan}` (`{$descrSan}`) — " . count($refs) . " destination(s) currently point to it. Re-target them first, or pass force:true to delete anyway (those destinations will start failing).\n\n" . implode("\n", $refLines) . $more, 'references' => $refs];
		}

		$memberCount = is_array($existing) && isset($existing['member']) ? count((array)$existing['member']) : 0;

		if (!$confirm) {
			$forceNote = !empty($refs) ? "\n⚠️ force:true acknowledged — " . count($refs) . " destination(s) will be left pointing at a non-existent queue." : '';
			return ['dry_run' => true, 'message' => "Would delete queue `{$accountSan}` `{$descrSan}` ({$memberCount} member(s)).{$forceNote}\n\nReply yes to confirm.", 'account' => $account, 'name' => $descr, 'references' => $refs];
		}

		$this->freepbx->Modules->loadFunctionsInc('queues');
		if (!function_exists('queues_del')) return ['error' => 'queues_del() not available — Queues module not loaded'];

		$prior = $_REQUEST;
		$_REQUEST['action'] = 'delete';
		try {
			queues_del($account);
		} finally {
			$_REQUEST = $prior;
		}

		return ['dry_run' => false, 'message' => "✅ Queue `{$accountSan}` `{$descrSan}` deleted ({$memberCount} member(s) removed).", 'account' => $account, 'name' => $descr, 'needs_reload' => true];
	}
}
