<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';
require_once __DIR__ . '/AddQueue.php';
require_once __DIR__ . '/AddQueueMember.php';

// Persistent queue-member remove. Distinct from fm_queue_remove_agent which
// removes via AMI QueueRemove (live-only). See AddQueueMember.php header for
// the del+add edit pattern.
class RemoveQueueMember extends AbstractTool {
	public function name() { return 'fm_remove_queue_member'; }
	public function description() { return 'Remove an extension from a queue\'s persistent static member list. Params: queue (required), ext (required). Requires confirm:true. For live/runtime removal via AMI use fm_queue_remove_agent instead.'; }
	public function validate($params) {
		if (empty($params['queue'])) return 'Parameter "queue" is required';
		if (empty($params['ext'])) return 'Parameter "ext" is required';
		if (!preg_match('/^\d{1,11}$/', (string)$params['queue'])) return 'Parameter "queue" must be 1-11 digits';
		if (!preg_match('/^\d{1,11}$/', (string)$params['ext'])) return 'Parameter "ext" must be 1-11 digits';
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_WRITE; }

	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$account = (string)$params['queue'];
		$ext = (string)$params['ext'];

		$this->freepbx->Modules->loadFunctionsInc('queues');
		if (!function_exists('queues_get')) return ['error' => 'queues_get() not available — Queues module not loaded'];
		$current = queues_get($account);
		if (empty($current)) {
			$accountSan = $this->frogman->sanitizeForChat($account);
			return ['error' => "Queue `{$accountSan}` not found"];
		}

		$members = is_array($current['member'] ?? null) ? array_values($current['member']) : [];
		$filtered = array_values(array_filter($members, function($m) use ($ext) {
			return AddQueue::extractMemberExt($m) !== $ext;
		}));

		$accountSan = $this->frogman->sanitizeForChat($account);
		$extSan = $this->frogman->sanitizeForChat($ext);
		$nameSan = $this->frogman->sanitizeForChat((string)($current['name'] ?? ''));

		if (count($filtered) === count($members)) {
			return ['error' => "Extension `{$extSan}` is not a static member of queue `{$accountSan}` `{$nameSan}`."];
		}

		if (!$confirm) {
			return ['dry_run' => true, 'message' => "Would remove extension `{$extSan}` from queue `{$accountSan}` `{$nameSan}`. Queue would go from " . count($members) . " to " . count($filtered) . " static member(s).\n\nReply yes to confirm.", 'queue' => $account, 'ext' => $ext];
		}

		$merged = UpdateQueueMemberHelper::buildMergedFromCurrent($current, $account);
		$merged['members'] = $filtered;

		if (!function_exists('queues_del')) return ['error' => 'queues_del() not available'];
		$prior = $_REQUEST;
		$_REQUEST['action'] = 'edit';
		try {
			queues_del($account);
		} finally {
			$_REQUEST = $prior;
		}
		AddQueue::writeQueue($this->freepbx, $merged);

		return ['dry_run' => false, 'message' => "✅ Extension `{$extSan}` removed from queue `{$accountSan}` `{$nameSan}`. Queue now has " . count($filtered) . " static member(s).", 'queue' => $account, 'ext' => $ext, 'needs_reload' => true];
	}
}
