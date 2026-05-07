<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class WhosOnThePhone extends AbstractTool {
	public function name() { return 'fm_whos_on_the_phone'; }
	public function description() { return 'Show who is currently on a call — names, caller IDs, durations, and who is talking to who.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');
		$db = $this->freepbx->Database;

		$res = $astman->Command("core show channels concise");
		$data = trim($res['data'] ?? '');
		$data = preg_replace('/^Privilege:\s+\w+\s*/i', '', $data);

		if (empty($data)) return ['count' => 0, 'calls' => [], 'message' => 'Nobody is on the phone.'];

		$channels = [];
		foreach (explode("\n", $data) as $line) {
			$line = trim($line);
			if (empty($line)) continue;
			$parts = explode('!', $line);
			if (count($parts) >= 12) {
				$channels[] = [
					'channel' => $parts[0],
					'context' => $parts[1],
					'extension' => $parts[2],
					'state' => $parts[4] ?? '',
					'application' => $parts[5] ?? '',
					'callerid' => $parts[7] ?? '',
					'duration' => $parts[10] ?? '',
					'bridged_to' => $parts[11] ?? '',
				];
			}
		}

		// Drop Asterisk internal channels that aren't actual phone calls.
		$channels = array_values(array_filter($channels, function($ch) {
			return !$this->isAsteriskInternalChannel($ch['channel']);
		}));

		// Match bridged pairs and resolve names
		$calls = [];
		$seen = [];
		foreach ($channels as $ch) {
			if (in_array($ch['channel'], $seen)) continue;

			// Get extension number from channel name
			$ext = null;
			if (preg_match('/PJSIP\/(\d+)-/', $ch['channel'], $m)) $ext = $m[1];

			// Look up name
			$name = null;
			if ($ext) {
				$sth = $db->prepare("SELECT name FROM users WHERE extension = ?");
				$sth->execute([$ext]);
				$row = $sth->fetch(\PDO::FETCH_ASSOC);
				if ($row) $name = $row['name'];
			}

			$call = [
				'channel' => $ch['channel'],
				'ext' => $ext,
				'name' => $name,
				'callerid' => $ch['callerid'],
				'state' => $ch['state'],
				'duration' => $ch['duration'] ? (int)$ch['duration'] : 0,
				'talking_to' => null,
			];

			// Find bridged partner
			if (!empty($ch['bridged_to'])) {
				$seen[] = $ch['bridged_to'];
				$partnerExt = null;
				$partnerName = null;
				$partnerCid = '';
				if (preg_match('/PJSIP\/(\d+)-/', $ch['bridged_to'], $m2)) {
					$partnerExt = $m2[1];
					$sth = $db->prepare("SELECT name FROM users WHERE extension = ?");
					$sth->execute([$partnerExt]);
					$row = $sth->fetch(\PDO::FETCH_ASSOC);
					if ($row) $partnerName = $row['name'];
				}
				// Find partner's caller ID from channels list
				foreach ($channels as $pch) {
					if ($pch['channel'] === $ch['bridged_to']) {
						$partnerCid = $pch['callerid'];
						break;
					}
				}
				$call['talking_to'] = [
					'channel' => $ch['bridged_to'],
					'ext' => $partnerExt,
					'name' => $partnerName,
					'callerid' => $partnerCid,
				];
			}

			$seen[] = $ch['channel'];
			$calls[] = $call;
		}

		return ['count' => count($calls), 'calls' => $calls];
	}
}
