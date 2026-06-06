<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class QueueWallboard extends AbstractTool {
	public function name() { return 'fm_queue_wallboard'; }

	public function description() {
		return 'Live queue wallboard via AMI. Per queue: callers_waiting, longest_current_wait_seconds, agents split into available/on_call/paused (with reason). Optional: queue (limit to one queue). Degrades gracefully when no queues are configured.';
	}

	public function validate($params) { return true; }

	public function permissionLevel() { return self::PERM_READ; }

	public function execute($params, $context) {
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) {
			throw new \Exception('Cannot connect to Asterisk Manager');
		}
		$cmd = 'queue show';
		if (!empty($params['queue'])) $cmd .= ' ' . preg_replace('/[^A-Za-z0-9_\-]/', '', $params['queue']);
		$res = $astman->Command($cmd);
		$raw = trim($res['data'] ?? '');
		$raw = preg_replace('/^Privilege:\s+\w+\s*/i', '', $raw);

		$queues = $this->parseQueueShow($raw);

		// Empty-PBX case: queue show on a system with no queues prints a single
		// "No queues." line. Return [] cleanly rather than misparsing.
		if (empty($queues) || stripos($raw, 'No queues') !== false) {
			return [
				'as_of' => date('c'),
				'queues' => [],
				'summary' => [
					'total_waiting' => 0,
					'agents_available' => 0,
					'agents_on_call' => 0,
					'agents_paused' => 0,
				],
			];
		}

		$totals = ['waiting' => 0, 'available' => 0, 'on_call' => 0, 'paused' => 0];
		foreach ($queues as &$q) {
			$totals['waiting'] += $q['callers_waiting'];
			$totals['available'] += count($q['agents']['available']);
			$totals['on_call'] += count($q['agents']['on_call']);
			$totals['paused'] += count($q['agents']['paused']);
		}
		unset($q);

		return [
			'as_of' => date('c'),
			'queues' => $queues,
			'summary' => [
				'total_waiting' => $totals['waiting'],
				'agents_available' => $totals['available'],
				'agents_on_call' => $totals['on_call'],
				'agents_paused' => $totals['paused'],
			],
		];
	}

	// Parse the text output of `queue show` (Asterisk 18-22). Format is stable
	// across recent versions. One queue block looks like:
	//
	//   sales has 3 calls (max unlimited) in 'ringall' strategy (12s holdtime, ...
	//      Members:
	//        Local/1005@from-queue/n (ringinuse ...) (dynamic) (Not in use) ...
	//        Local/1006@from-queue/n (dynamic) (paused was: Lunch) ...
	//      Callers:
	//        1. 5551234 (wait: 0:47, prio: 0)
	private function parseQueueShow($raw) {
		$queues = [];
		$current = null;
		$section = null;
		foreach (explode("\n", $raw) as $line) {
			if (preg_match('/^(\S+)\s+has\s+(\d+)\s+calls/', $line, $m)) {
				if ($current !== null) $queues[] = $current;
				$current = [
					'queue' => $m[1],
					'name' => $this->lookupQueueName($m[1]),
					'callers_waiting' => (int)$m[2],
					'longest_current_wait_seconds' => 0,
					'longest_current_wait_display' => '0s',
					'agents' => ['available' => [], 'on_call' => [], 'paused' => []],
					'callers' => [],
				];
				$section = null;
				continue;
			}
			if (preg_match('/^\s*Members:\s*$/i', $line)) { $section = 'members'; continue; }
			if (preg_match('/^\s*Callers:\s*$/i', $line)) { $section = 'callers'; continue; }
			if ($current === null) continue;

			if ($section === 'members' && preg_match('/^\s+(\S+)\s+\((.*)\)\s+has\s+taken/', $line, $m)) {
				$iface = $m[1];
				$ext = $this->extractExt($iface);
				$name = $ext !== null ? $this->lookupExtensionName($ext) : '';
				$parens = $m[2];
				$pauseReason = null;
				if (preg_match('/paused was:\s*([^\)]+)/i', $parens, $pm)) {
					$pauseReason = trim($pm[1]);
				} elseif (stripos($parens, 'paused') !== false) {
					$pauseReason = 'unspecified';
				}
				$status = 'available';
				if ($pauseReason !== null) $status = 'paused';
				elseif (stripos($parens, 'in use') !== false || stripos($parens, 'busy') !== false || stripos($parens, 'on hold') !== false || stripos($parens, 'ringing') !== false) {
					$status = 'on_call';
				}
				$agentRow = ['ext' => $ext, 'name' => $name, 'interface' => $iface];
				if ($status === 'paused') $agentRow['reason'] = $pauseReason;
				$current['agents'][$status][] = $agentRow;
			} elseif ($section === 'callers' && preg_match('/^\s+\d+\.\s+(\S+)\s+\(wait:\s+(\d+):(\d+)/', $line, $m)) {
				$callerId = $m[1];
				$waitSec = ((int)$m[2]) * 60 + (int)$m[3];
				$current['callers'][] = [
					'caller_id' => $callerId,
					'wait_seconds' => $waitSec,
					'wait_display' => $this->humanSeconds($waitSec),
				];
				if ($waitSec > $current['longest_current_wait_seconds']) {
					$current['longest_current_wait_seconds'] = $waitSec;
					$current['longest_current_wait_display'] = $this->humanSeconds($waitSec);
				}
			}
		}
		if ($current !== null) $queues[] = $current;
		return $queues;
	}

	private function lookupQueueName($queueExt) {
		static $cache = null;
		if ($cache === null) {
			$cache = [];
			try {
				$db = $this->freepbx->Database;
				$sth = $db->query("SELECT extension, descr FROM queues_config");
				foreach ($sth->fetchAll(\PDO::FETCH_ASSOC) as $row) {
					$cache[(string)$row['extension']] = (string)$row['descr'];
				}
			} catch (\Throwable $e) {}
		}
		return $cache[$queueExt] ?? '';
	}

	private function extractExt($agentRaw) {
		if (preg_match('#^Local/(\d+)@#', $agentRaw, $m)) return $m[1];
		if (preg_match('#^[A-Z]+/(\d+)#i', $agentRaw, $m)) return $m[1];
		if (preg_match('/^\d+$/', $agentRaw)) return $agentRaw;
		return null;
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
