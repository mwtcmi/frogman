<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class CallTimeline extends AbstractTool {
	public function name() { return 'fm_call_timeline'; }

	public function description() {
		return 'Reconstruct a single call from CEL events. Returns channels, bridges, transfers, IVR legs, park/pickup events, and the raw event sequence. Pass linkedid (preferred) or uniqueid (we walk to the master linkedid). Scoped to one linkedid — transfer targets appear in transfers[] with target_linkedid for the caller to follow.';
	}

	public function validate($params) {
		if (empty($params['linkedid']) && empty($params['uniqueid'])) {
			return 'Required: linkedid or uniqueid';
		}
		return true;
	}

	public function permissionLevel() { return self::PERM_READ; }

	public function execute($params, $context) {
		$db = $this->freepbx->Database;
		$linkedid = $params['linkedid'] ?? null;
		if (!$linkedid && !empty($params['uniqueid'])) {
			$sth = $db->prepare("SELECT linkedid FROM asteriskcdrdb.cel WHERE uniqueid = ? LIMIT 1");
			$sth->execute([$params['uniqueid']]);
			$linkedid = $sth->fetchColumn();
			if (!$linkedid) {
				return ['found' => false, 'message' => 'No CEL events found for that uniqueid.'];
			}
		}

		$sth = $db->prepare(
			"SELECT id, eventtype, eventtime, cid_name, cid_num, cid_dnid, exten, context,
			        channame, appname, appdata, uniqueid, linkedid, peer, extra
			 FROM asteriskcdrdb.cel
			 WHERE linkedid = ?
			 ORDER BY eventtime ASC, id ASC"
		);
		$sth->execute([$linkedid]);
		$rows = $sth->fetchAll(\PDO::FETCH_ASSOC);

		if (empty($rows)) {
			return ['found' => false, 'linkedid' => $linkedid, 'message' => 'No CEL events for that linkedid.'];
		}

		foreach ($rows as &$r) {
			$r['extra_parsed'] = (!empty($r['extra'])) ? json_decode($r['extra'], true) : null;
		}
		unset($r);

		$startedAt = $rows[0]['eventtime'];
		$endedAt   = $rows[count($rows) - 1]['eventtime'];
		$durationSeconds = max(0, strtotime($endedAt) - strtotime($startedAt));

		$channels = $this->reconstructChannels($rows);
		$bridges = $this->reconstructBridges($rows);
		$transfers = $this->reconstructTransfers($rows);
		$ivrLegs = $this->reconstructIvrLegs($rows);
		$parkEvents = $this->reconstructParks($rows);
		$pickupEvents = $this->reconstructPickups($rows);

		// Trim extra_parsed off the raw_events copy so the response isn't doubled.
		$rawEvents = array_map(function($r) {
			$r['extra'] = $r['extra_parsed'];
			unset($r['extra_parsed']);
			return $r;
		}, $rows);

		return [
			'found' => true,
			'linkedid' => $linkedid,
			'started_at' => $startedAt,
			'ended_at' => $endedAt,
			'duration_seconds' => $durationSeconds,
			'event_count' => count($rows),
			'channels' => $channels,
			'bridges' => $bridges,
			'transfers' => $transfers,
			'ivr_legs' => $ivrLegs,
			'park_events' => $parkEvents,
			'pickup_events' => $pickupEvents,
			'raw_events' => $rawEvents,
		];
	}

	private function reconstructChannels(array $rows) {
		$channels = [];
		foreach ($rows as $r) {
			$name = $r['channame'];
			if ($name === '') continue;
			if (!isset($channels[$name])) {
				$channels[$name] = [
					'channame' => $name,
					'cid_num' => $r['cid_num'],
					'cid_name' => $r['cid_name'],
					'started_at' => null,
					'ended_at' => null,
					'answered' => false,
					'role' => null,
				];
			}
			if ($r['eventtype'] === 'CHAN_START' && $channels[$name]['started_at'] === null) {
				$channels[$name]['started_at'] = $r['eventtime'];
			}
			if ($r['eventtype'] === 'CHAN_END') {
				$channels[$name]['ended_at'] = $r['eventtime'];
			}
			if ($r['eventtype'] === 'ANSWER') {
				$channels[$name]['answered'] = true;
			}
		}
		// Earliest started_at is the originator; everything else is answerer if
		// answered, else dialed (rang but didn't pick up).
		$origName = null;
		$earliest = null;
		foreach ($channels as $c) {
			if ($c['started_at'] === null) continue;
			if ($earliest === null || strtotime($c['started_at']) < strtotime($earliest)) {
				$earliest = $c['started_at'];
				$origName = $c['channame'];
			}
		}
		foreach ($channels as &$c) {
			if ($c['channame'] === $origName) $c['role'] = 'originator';
			elseif ($c['answered']) $c['role'] = 'answerer';
			else $c['role'] = 'dialed';
		}
		unset($c);
		return array_values($channels);
	}

	private function reconstructBridges(array $rows) {
		$bridges = [];
		foreach ($rows as $r) {
			if ($r['eventtype'] !== 'BRIDGE_ENTER' && $r['eventtype'] !== 'BRIDGE_EXIT') continue;
			$extra = $r['extra_parsed'] ?? [];
			$bridgeId = $extra['bridge_id'] ?? '(unknown)';
			if (!isset($bridges[$bridgeId])) {
				$bridges[$bridgeId] = [
					'bridge_id' => $bridgeId,
					'bridge_technology' => $extra['bridge_technology'] ?? null,
					'entered_at' => null,
					'exited_at' => null,
					'participants' => [],
				];
			}
			if ($r['eventtype'] === 'BRIDGE_ENTER') {
				if ($bridges[$bridgeId]['entered_at'] === null) {
					$bridges[$bridgeId]['entered_at'] = $r['eventtime'];
				}
				$bridges[$bridgeId]['participants'][] = [
					'channame' => $r['channame'],
					'at' => $r['eventtime'],
					'action' => 'enter',
				];
			} else {
				$bridges[$bridgeId]['exited_at'] = $r['eventtime'];
				$bridges[$bridgeId]['participants'][] = [
					'channame' => $r['channame'],
					'at' => $r['eventtime'],
					'action' => 'exit',
				];
			}
		}
		return array_values($bridges);
	}

	private function reconstructTransfers(array $rows) {
		$out = [];
		$callStart = $rows[0]['eventtime'] ?? null;
		foreach ($rows as $r) {
			if ($r['eventtype'] !== 'BLINDTRANSFER' && $r['eventtype'] !== 'ATTENDEDTRANSFER') continue;
			$extra = $r['extra_parsed'] ?? [];
			$transfererExt = $r['cid_num'];
			$row = [
				'type' => $r['eventtype'],
				'at' => $r['eventtime'],
				'transferer' => [
					'channame' => $r['channame'],
					'ext' => $transfererExt,
					'name' => $this->lookupExtensionName($transfererExt),
				],
				'extra' => $extra,
				'target_linkedid' => $extra['transferee_channel_uniqueid'] ?? ($extra['channel2_uniqueid'] ?? null),
				'call_duration_before_transfer_s' => $callStart ? max(0, strtotime($r['eventtime']) - strtotime($callStart)) : null,
			];
			if ($r['eventtype'] === 'BLINDTRANSFER') {
				$row['to_party'] = $extra['extension'] ?? null;
			} else {
				$row['to_party'] = $extra['bridge2_uniqueid'] ?? null;
			}
			$out[] = $row;
		}
		return $out;
	}

	private function reconstructIvrLegs(array $rows) {
		$ivrApps = ['Background', 'BackgroundDetect', 'Read', 'Playback', 'WaitExten'];
		$open = [];
		$legs = [];
		foreach ($rows as $r) {
			if ($r['eventtype'] !== 'APP_START' && $r['eventtype'] !== 'APP_END') continue;
			if (!in_array($r['appname'], $ivrApps, true)) continue;
			$key = $r['channame'] . '|' . $r['appname'];
			if ($r['eventtype'] === 'APP_START') {
				$open[$key] = [
					'channame' => $r['channame'],
					'app' => $r['appname'],
					'data' => $r['appdata'],
					'started_at' => $r['eventtime'],
				];
			} else {
				if (isset($open[$key])) {
					$leg = $open[$key];
					$leg['ended_at'] = $r['eventtime'];
					$leg['duration_seconds'] = max(0, strtotime($r['eventtime']) - strtotime($leg['started_at']));
					$legs[] = $leg;
					unset($open[$key]);
				}
			}
		}
		// Unclosed legs: still emit with ended_at=null and unknown duration.
		foreach ($open as $leg) {
			$leg['ended_at'] = null;
			$leg['duration_seconds'] = null;
			$legs[] = $leg;
		}
		return $legs;
	}

	private function reconstructParks(array $rows) {
		$open = [];
		$events = [];
		foreach ($rows as $r) {
			if ($r['eventtype'] !== 'PARK_START' && $r['eventtype'] !== 'PARK_END') continue;
			$key = $r['channame'];
			if ($r['eventtype'] === 'PARK_START') {
				$open[$key] = [
					'channame' => $r['channame'],
					'cid_num' => $r['cid_num'],
					'parked_at' => $r['eventtime'],
					'parking_lot' => ($r['extra_parsed']['parking_lot'] ?? null),
				];
			} else {
				if (isset($open[$key])) {
					$ev = $open[$key];
					$ev['retrieved_at'] = $r['eventtime'];
					$ev['duration_seconds'] = max(0, strtotime($r['eventtime']) - strtotime($ev['parked_at']));
					$events[] = $ev;
					unset($open[$key]);
				}
			}
		}
		foreach ($open as $ev) {
			$ev['retrieved_at'] = null;
			$ev['duration_seconds'] = null;
			$events[] = $ev;
		}
		return $events;
	}

	private function reconstructPickups(array $rows) {
		$out = [];
		foreach ($rows as $r) {
			if ($r['eventtype'] !== 'PICKUP') continue;
			$out[] = [
				'at' => $r['eventtime'],
				'picker_channame' => $r['channame'],
				'picker_ext' => $r['cid_num'],
				'picker_name' => $this->lookupExtensionName($r['cid_num']),
				'extra' => $r['extra_parsed'] ?? null,
			];
		}
		return $out;
	}
}
