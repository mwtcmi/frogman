<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

/**
 * Per-mailbox voicemail counts (new / old / urgent) via Voicemail BMO. The
 * discovery cousin of fm_list_voicemails — answers "who has voicemail waiting
 * and how much" in one pass without listing individual messages.
 *
 * Uses Voicemail->getMessagesCountByExtensionFolder for each (mailbox, folder)
 * pair. That method also goes through getMessagesByExtension internally and
 * shares its per-instance cache, so we call clearCache() between mailboxes
 * the same way ListVoicemails does.
 */
class VoicemailSummary extends AbstractTool {
	const FOLDERS = ['INBOX' => 'new', 'Old' => 'old', 'Urgent' => 'urgent'];

	public function name() { return 'fm_voicemail_summary'; }

	public function description() {
		return 'Per-mailbox voicemail counts (new / old / urgent) via Voicemail BMO. Optional params: context (default "default"), mailbox (single mailbox; omit for all), nonzero_only (default true — hide mailboxes with no messages). Read-only.';
	}

	public function validate($params) {
		if (!empty($params['mailbox']) && !preg_match('/^[a-zA-Z0-9_-]+$/', (string)$params['mailbox'])) {
			return 'Parameter "mailbox" must be alphanumeric.';
		}
		if (!empty($params['context']) && !preg_match('/^[a-zA-Z0-9_-]+$/', (string)$params['context'])) {
			return 'Parameter "context" must be alphanumeric.';
		}
		return true;
	}

	public function execute($params, $context) {
		$ctx = $params['context'] ?? 'default';
		$mailbox = $params['mailbox'] ?? null;
		$nonzeroOnly = !isset($params['nonzero_only']) || $params['nonzero_only'];

		// Enumerate mailboxes from the vmconf rather than walking the spool.
		$mailboxes = [];
		if ($mailbox) {
			$mailboxes[] = $mailbox;
		} else {
			$vmconf = $this->freepbx->Voicemail->getVoicemail();
			$skip = ['general', 'zonemessages', 'pbxaliases', 'device'];
			if (!empty($vmconf[$ctx]) && is_array($vmconf[$ctx])) {
				foreach ($vmconf[$ctx] as $mb => $box) {
					if (in_array($mb, $skip, true)) continue;
					if (!is_array($box)) continue;
					$mailboxes[] = (string)$mb;
				}
			}
		}

		$out = [];
		$totals = ['new' => 0, 'old' => 0, 'urgent' => 0];
		foreach ($mailboxes as $mb) {
			$counts = ['new' => 0, 'old' => 0, 'urgent' => 0];
			foreach (self::FOLDERS as $folder => $key) {
				// Same cache-collision workaround as fm_list_voicemails. BMO's
				// getMessagesByExtension caches globally; clearCache() between
				// mailboxes is the documented public API for this case.
				$this->freepbx->Voicemail->clearCache();
				$counts[$key] = (int)$this->freepbx->Voicemail->getMessagesCountByExtensionFolder($mb, $folder);
			}
			$total = $counts['new'] + $counts['old'] + $counts['urgent'];
			if ($nonzeroOnly && $total === 0) continue;
			$out[] = [
				'mailbox' => (string)$mb,
				'name' => $this->lookupExtensionName($mb),
				'new' => $counts['new'],
				'old' => $counts['old'],
				'urgent' => $counts['urgent'],
				'total' => $total,
			];
			$totals['new'] += $counts['new'];
			$totals['old'] += $counts['old'];
			$totals['urgent'] += $counts['urgent'];
		}

		usort($out, function($a, $b) {
			if ($a['new'] !== $b['new']) return $b['new'] <=> $a['new'];
			return $b['total'] <=> $a['total'];
		});

		return [
			'context' => $ctx,
			'mailbox_count' => count($out),
			'mailboxes' => $out,
			'totals' => $totals,
		];
	}
}
