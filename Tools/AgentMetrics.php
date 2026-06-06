<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class AgentMetrics extends AbstractTool {
	public function name() { return 'fm_agent_metrics'; }

	public function description() {
		return 'Per-agent scorecard from queuelog: calls_handled, talk_time, ring_no_answer_count, session_time, available_time, occupancy (talk / (talk+available)), pauses grouped by reason. Filters: date_from, date_to, queue, agent (extension or full interface).';
	}

	public function validate($params) { return true; }

	public function permissionLevel() { return self::PERM_READ; }

	public function execute($params, $context) {
		$params = $this->applyDefaultReportWindow($params);
		$db = $this->freepbx->Database;

		$where = '1=1';
		$binds = [];
		if (!empty($params['date_from'])) { $where .= ' AND time >= ?'; $binds[] = $params['date_from']; }
		if (!empty($params['date_to']))   { $where .= ' AND time <= ?'; $binds[] = $params['date_to']; }
		if (!empty($params['queue']))     { $where .= ' AND queuename = ?'; $binds[] = (string)$params['queue']; }
		if (!empty($params['agent'])) {
			$where .= ' AND (agent = ? OR agent LIKE ?)';
			$binds[] = $params['agent'];
			$binds[] = 'Local/' . $params['agent'] . '@%';
		}

		$fetchCap = 100000;
		try {
			$sql = "SELECT time, callid, queuename, agent, event, data1, data2
			        FROM asteriskcdrdb.queuelog
			        WHERE $where
			        ORDER BY time ASC, id ASC
			        LIMIT $fetchCap";
			$sth = $db->prepare($sql);
			$sth->execute($binds);
			$rows = $sth->fetchAll(\PDO::FETCH_ASSOC);
		} catch (\Throwable $e) {
			return $this->emptyResponse($params,
				'queuelog table not available on this PBX (no queues configured yet).');
		}

		if (empty($rows)) {
			return $this->emptyResponse($params);
		}
		$windowCapped = count($rows) >= $fetchCap;

		$agents = [];
		$pauseOpen = [];      // agent => ['reason'=>..., 'since'=>ts]
		$sessionOpen = [];    // agent => ts of ADDMEMBER

		foreach ($rows as $r) {
			$agentRaw = $r['agent'];
			if ($agentRaw === '' || $agentRaw === 'NONE') continue;
			$ext = $this->extractExt($agentRaw);
			$key = $agentRaw;
			if (!isset($agents[$key])) {
				$agents[$key] = [
					'agent_interface' => $agentRaw,
					'ext' => $ext,
					'name' => $ext !== null ? $this->lookupExtensionName($ext) : null,
					'queues' => [],
					'calls_handled' => 0,
					'talk_total_s' => 0,
					'ring_no_answer_count' => 0,
					'session_total_s' => 0,
					'pause_total_s' => 0,
					'pauses_by_reason' => [],
				];
			}
			if ($r['queuename'] !== '' && !in_array($r['queuename'], $agents[$key]['queues'], true)) {
				$agents[$key]['queues'][] = $r['queuename'];
			}
			$ev = $r['event'];
			switch ($ev) {
				case 'CONNECT':
					$agents[$key]['calls_handled']++;
					break;
				case 'COMPLETEAGENT':
				case 'COMPLETECALLER':
					$agents[$key]['talk_total_s'] += (int)$r['data2'];
					break;
				case 'RINGNOANSWER':
					$agents[$key]['ring_no_answer_count']++;
					break;
				case 'ADDMEMBER':
					$sessionOpen[$key] = strtotime($r['time']);
					break;
				case 'REMOVEMEMBER':
					if (isset($sessionOpen[$key])) {
						$agents[$key]['session_total_s'] += max(0, strtotime($r['time']) - $sessionOpen[$key]);
						unset($sessionOpen[$key]);
					}
					break;
				case 'PAUSE':
				case 'PAUSEALL':
					$reason = $r['data1'] !== '' ? $r['data1'] : 'unspecified';
					$pauseOpen[$key] = ['reason' => $reason, 'since' => strtotime($r['time'])];
					break;
				case 'UNPAUSE':
				case 'UNPAUSEALL':
					if (isset($pauseOpen[$key])) {
						$elapsed = max(0, strtotime($r['time']) - $pauseOpen[$key]['since']);
						$reason = $pauseOpen[$key]['reason'];
						if (!isset($agents[$key]['pauses_by_reason'][$reason])) {
							$agents[$key]['pauses_by_reason'][$reason] = ['count' => 0, 'total_seconds' => 0];
						}
						$agents[$key]['pauses_by_reason'][$reason]['count']++;
						$agents[$key]['pauses_by_reason'][$reason]['total_seconds'] += $elapsed;
						$agents[$key]['pause_total_s'] += $elapsed;
						unset($pauseOpen[$key]);
					}
					break;
			}
		}

		// Close still-open sessions/pauses at the window end so reports on a
		// currently-active shift don't undercount. Use last seen event time as
		// the close point (deterministic, no clock skew).
		$lastTime = $rows[count($rows) - 1]['time'] ?? null;
		$lastTs = $lastTime ? strtotime($lastTime) : null;
		if ($lastTs !== null) {
			foreach ($sessionOpen as $key => $sinceTs) {
				$agents[$key]['session_total_s'] += max(0, $lastTs - $sinceTs);
			}
			foreach ($pauseOpen as $key => $p) {
				$elapsed = max(0, $lastTs - $p['since']);
				$reason = $p['reason'];
				if (!isset($agents[$key]['pauses_by_reason'][$reason])) {
					$agents[$key]['pauses_by_reason'][$reason] = ['count' => 0, 'total_seconds' => 0];
				}
				$agents[$key]['pauses_by_reason'][$reason]['count']++;
				$agents[$key]['pauses_by_reason'][$reason]['total_seconds'] += $elapsed;
				$agents[$key]['pause_total_s'] += $elapsed;
			}
		}

		$out = [];
		foreach ($agents as $a) {
			$available = max(0, $a['session_total_s'] - $a['talk_total_s'] - $a['pause_total_s']);
			$occDenom = $a['talk_total_s'] + $available;
			$occupancy = $occDenom > 0 ? $a['talk_total_s'] / $occDenom : 0.0;

			$pauses = [];
			foreach ($a['pauses_by_reason'] as $reason => $p) {
				$pauses[] = [
					'reason' => $reason,
					'count' => $p['count'],
					'total_seconds' => $p['total_seconds'],
					'display' => $this->humanSeconds($p['total_seconds']),
				];
			}
			usort($pauses, function($x, $y) { return $y['total_seconds'] - $x['total_seconds']; });

			$out[] = [
				'agent' => [
					'ext' => $a['ext'],
					'name' => $a['name'],
					'interface' => $a['agent_interface'],
				],
				'queues' => $a['queues'],
				'calls_handled' => $a['calls_handled'],
				'talk_time_seconds' => $a['talk_total_s'],
				'talk_time_display' => $this->humanSeconds($a['talk_total_s']),
				'ring_no_answer_count' => $a['ring_no_answer_count'],
				'session_time_seconds' => $a['session_total_s'],
				'session_time_display' => $this->humanSeconds($a['session_total_s']),
				'available_time_seconds' => $available,
				'available_time_display' => $this->humanSeconds($available),
				'occupancy' => round($occupancy, 4),
				'occupancy_display' => number_format($occupancy * 100, 1) . '%',
				'pauses' => $pauses,
				'total_pause_seconds' => $a['pause_total_s'],
				'total_pause_display' => $this->humanSeconds($a['pause_total_s']),
			];
		}

		usort($out, function($a, $b) { return $b['calls_handled'] - $a['calls_handled']; });

		$resp = [
			'count' => count($out),
			'window' => [
				'date_from' => $params['date_from'] ?? null,
				'date_to'   => $params['date_to'] ?? null,
			],
			'rows' => $out,
		];
		if ($windowCapped) {
			$resp['note'] = "Window hit the {$fetchCap}-row fetch cap; metrics may be partial. Narrow date_from / date_to for accurate totals.";
		}
		return $resp;
	}

	// Pull "1005" out of "Local/1005@from-queue/n", "PJSIP/1005-00000042",
	// "SIP/1005", or bare "1005". Returns string-or-null.
	private function extractExt($agentRaw) {
		if (preg_match('#^Local/(\d+)@#', $agentRaw, $m)) return $m[1];
		if (preg_match('#^[A-Z]+/(\d+)#i', $agentRaw, $m)) return $m[1];
		if (preg_match('/^\d+$/', $agentRaw)) return $agentRaw;
		return null;
	}

	private function emptyResponse($params, $note = null) {
		$resp = [
			'count' => 0,
			'window' => [
				'date_from' => $params['date_from'] ?? null,
				'date_to'   => $params['date_to'] ?? null,
			],
			'rows' => [],
		];
		if ($note !== null) $resp['note'] = $note;
		return $resp;
	}

	private function humanSeconds($s) {
		$s = (int)round($s);
		if ($s < 60) return "{$s}s";
		$m = intdiv($s, 60); $r = $s % 60;
		if ($m < 60) return $r ? "{$m}m {$r}s" : "{$m}m";
		$h = intdiv($m, 60); $r2 = $m % 60;
		return $r2 ? "{$h}h {$r2}m" : "{$h}h";
	}
}
