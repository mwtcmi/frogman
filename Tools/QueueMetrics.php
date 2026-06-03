<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class QueueMetrics extends AbstractTool {
	public function name() { return 'fm_queue_metrics'; }

	public function description() {
		return 'Per-queue summary from queuelog: offered, answered, abandoned, abandonment_rate, service_level (default answered_within_T / offered, T=20s, both configurable via service_level_threshold and service_level_variant=offered|answered_plus_abandoned), ASA, AHT, talk_time, longest_wait_*. Filters: date_from, date_to, queue.';
	}

	public function validate($params) {
		if (isset($params['service_level_threshold'])) {
			$t = (int)$params['service_level_threshold'];
			if ($t < 1 || $t > 3600) return 'Parameter "service_level_threshold" must be between 1 and 3600 (seconds)';
		}
		if (isset($params['service_level_variant'])) {
			if (!in_array($params['service_level_variant'], ['offered', 'answered_plus_abandoned'], true)) {
				return 'Parameter "service_level_variant" must be one of: offered, answered_plus_abandoned';
			}
		}
		return true;
	}

	public function permissionLevel() { return self::PERM_READ; }

	public function execute($params, $context) {
		$params = $this->applyDefaultReportWindow($params);
		$db = $this->freepbx->Database;
		$slThreshold = isset($params['service_level_threshold']) ? (int)$params['service_level_threshold'] : 20;
		$slVariant = $params['service_level_variant'] ?? 'offered';

		$where = '1=1';
		$binds = [];
		if (!empty($params['date_from'])) { $where .= ' AND time >= ?'; $binds[] = $params['date_from']; }
		if (!empty($params['date_to']))   { $where .= ' AND time <= ?'; $binds[] = $params['date_to']; }
		if (!empty($params['queue']))     { $where .= ' AND queuename = ?'; $binds[] = (string)$params['queue']; }

		// Hard cap protects against OOM on high-volume PBXes. If the cap is hit
		// the window is wider than this tool can safely roll up — caller should
		// narrow date_from/date_to.
		$fetchCap = 100000;
		try {
			$sql = "SELECT time, callid, queuename, agent, event, data1, data2, data3
			        FROM asteriskcdrdb.queuelog
			        WHERE $where
			        ORDER BY time ASC, id ASC
			        LIMIT $fetchCap";
			$sth = $db->prepare($sql);
			$sth->execute($binds);
			$rows = $sth->fetchAll(\PDO::FETCH_ASSOC);
		} catch (\Throwable $e) {
			return $this->emptyResponse($params, $slThreshold, $slVariant,
				'queuelog table not available on this PBX (no queues configured yet).');
		}

		if (empty($rows)) {
			return $this->emptyResponse($params, $slThreshold, $slVariant);
		}
		$windowCapped = count($rows) >= $fetchCap;

		$queueNames = $this->loadQueueNames($db);
		$queues = [];

		foreach ($rows as $r) {
			$q = $r['queuename'];
			if (!isset($queues[$q])) {
				$queues[$q] = [
					'queue' => $q,
					'name' => $queueNames[$q] ?? '',
					'offered' => 0,
					'answered' => 0,
					'abandoned' => 0,
					'answered_within_t' => 0,
					'hold_total_s' => 0,
					'talk_total_s' => 0,
					'longest_wait_answered_s' => 0,
					'longest_wait_abandoned_s' => 0,
				];
			}
			$ev = $r['event'];
			if ($ev === 'ENTERQUEUE') {
				$queues[$q]['offered']++;
			} elseif ($ev === 'CONNECT') {
				$queues[$q]['answered']++;
				$hold = (int)$r['data1'];
				$queues[$q]['hold_total_s'] += $hold;
				if ($hold > $queues[$q]['longest_wait_answered_s']) {
					$queues[$q]['longest_wait_answered_s'] = $hold;
				}
				if ($hold <= $slThreshold) {
					$queues[$q]['answered_within_t']++;
				}
			} elseif ($ev === 'ABANDON') {
				$queues[$q]['abandoned']++;
				$wait = (int)$r['data3'];
				if ($wait > $queues[$q]['longest_wait_abandoned_s']) {
					$queues[$q]['longest_wait_abandoned_s'] = $wait;
				}
			} elseif ($ev === 'COMPLETEAGENT' || $ev === 'COMPLETECALLER') {
				$queues[$q]['talk_total_s'] += (int)$r['data2'];
			}
		}

		$out = [];
		$totals = ['offered' => 0, 'answered' => 0, 'abandoned' => 0];
		foreach ($queues as $q) {
			$denominator = $slVariant === 'answered_plus_abandoned'
				? ($q['answered'] + $q['abandoned'])
				: $q['offered'];
			$sl = $denominator > 0 ? $q['answered_within_t'] / $denominator : 0.0;
			$abandonRate = $q['offered'] > 0 ? $q['abandoned'] / $q['offered'] : 0.0;
			$asa = $q['answered'] > 0 ? $q['hold_total_s'] / $q['answered'] : 0.0;
			$aht = $q['answered'] > 0 ? ($q['talk_total_s'] + $q['hold_total_s']) / $q['answered'] : 0.0;

			$out[] = [
				'queue' => $q['queue'],
				'name' => $q['name'],
				'offered' => $q['offered'],
				'answered' => $q['answered'],
				'abandoned' => $q['abandoned'],
				'abandonment_rate' => round($abandonRate, 4),
				'abandonment_rate_display' => $this->pct($abandonRate),
				'service_level' => round($sl, 4),
				'service_level_display' => $this->pct($sl),
				'service_level_threshold_seconds' => $slThreshold,
				'service_level_variant' => $slVariant === 'answered_plus_abandoned'
					? 'answered_within_T / (answered + abandoned)'
					: 'answered_within_T / offered',
				'asa_seconds' => round($asa, 1),
				'asa_display' => $this->humanSeconds($asa),
				'aht_seconds' => round($aht, 1),
				'aht_display' => $this->humanSeconds($aht),
				'talk_time_seconds' => $q['talk_total_s'],
				'talk_time_display' => $this->humanSeconds($q['talk_total_s']),
				'longest_wait_answered_seconds' => $q['longest_wait_answered_s'],
				'longest_wait_abandoned_seconds' => $q['longest_wait_abandoned_s'],
			];
			$totals['offered'] += $q['offered'];
			$totals['answered'] += $q['answered'];
			$totals['abandoned'] += $q['abandoned'];
		}

		usort($out, function($a, $b) { return $b['offered'] - $a['offered']; });

		$resp = [
			'count' => count($out),
			'window' => [
				'date_from' => $params['date_from'] ?? null,
				'date_to'   => $params['date_to'] ?? null,
			],
			'service_level_threshold_seconds' => $slThreshold,
			'summary' => [
				'total_offered' => $totals['offered'],
				'total_answered' => $totals['answered'],
				'total_abandoned' => $totals['abandoned'],
			],
			'rows' => $out,
		];
		if ($windowCapped) {
			$resp['note'] = "Window hit the {$fetchCap}-row fetch cap; metrics may be partial. Narrow date_from / date_to for accurate totals.";
		}
		return $resp;
	}

	private function loadQueueNames($db) {
		try {
			$sth = $db->query("SELECT extension, descr FROM queues_config");
			$out = [];
			foreach ($sth->fetchAll(\PDO::FETCH_ASSOC) as $row) {
				$out[(string)$row['extension']] = (string)$row['descr'];
			}
			return $out;
		} catch (\Throwable $e) {
			return [];
		}
	}

	private function emptyResponse($params, $slThreshold, $slVariant, $note = null) {
		$resp = [
			'count' => 0,
			'window' => [
				'date_from' => $params['date_from'] ?? null,
				'date_to'   => $params['date_to'] ?? null,
			],
			'service_level_threshold_seconds' => $slThreshold,
			'summary' => ['total_offered' => 0, 'total_answered' => 0, 'total_abandoned' => 0],
			'rows' => [],
		];
		if ($note !== null) $resp['note'] = $note;
		return $resp;
	}

	private function pct($v) { return number_format($v * 100, 1) . '%'; }

	private function humanSeconds($s) {
		$s = (int)round($s);
		if ($s < 60) return "{$s}s";
		$m = intdiv($s, 60); $r = $s % 60;
		if ($m < 60) return $r ? "{$m}m {$r}s" : "{$m}m";
		$h = intdiv($m, 60); $r2 = $m % 60;
		return $r2 ? "{$h}h {$r2}m" : "{$h}h";
	}
}
