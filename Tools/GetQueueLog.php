<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetQueueLog extends AbstractTool {
	public function name() { return 'fm_get_queue_log'; }

	public function description() {
		return 'Query raw queue events from asteriskcdrdb.queuelog. Filters: date_from, date_to, queue (queuename), agent (interface string or extension), callid, event_types (array, e.g. ["CONNECT","ABANDON","ENTERQUEUE"]), limit (default 200, max 2000).';
	}

	public function validate($params) {
		if (isset($params['limit'])) {
			$lim = (int)$params['limit'];
			if ($lim < 1 || $lim > 2000) return 'Parameter "limit" must be between 1 and 2000';
		}
		if (isset($params['event_types']) && !is_array($params['event_types'])) {
			return 'Parameter "event_types" must be an array';
		}
		return true;
	}

	public function permissionLevel() { return self::PERM_READ; }

	public function execute($params, $context) {
		$params = $this->applyDefaultReportWindow($params);
		$db = $this->freepbx->Database;
		$limit = isset($params['limit']) ? min((int)$params['limit'], 2000) : 200;
		$offset = isset($params['offset']) ? max(0, (int)$params['offset']) : 0;

		$where = '1=1';
		$binds = [];
		if (!empty($params['date_from'])) { $where .= ' AND time >= ?'; $binds[] = $params['date_from']; }
		if (!empty($params['date_to']))   { $where .= ' AND time <= ?'; $binds[] = $params['date_to']; }
		if (!empty($params['queue']))     { $where .= ' AND queuename = ?'; $binds[] = (string)$params['queue']; }
		if (!empty($params['callid']))    { $where .= ' AND callid = ?';    $binds[] = $params['callid']; }
		if (!empty($params['agent'])) {
			// Caller may pass just "1005" or a full "Local/1005@from-queue/n" interface.
			// Match either form via OR.
			$where .= ' AND (agent = ? OR agent LIKE ?)';
			$binds[] = $params['agent'];
			$binds[] = 'Local/' . $params['agent'] . '@%';
		}
		if (!empty($params['event_types']) && is_array($params['event_types'])) {
			$cleaned = array_values(array_filter(array_map(function($e) {
				return preg_match('/^[A-Z_]+$/i', (string)$e) ? strtoupper($e) : null;
			}, $params['event_types'])));
			if (!empty($cleaned)) {
				$ph = implode(',', array_fill(0, count($cleaned), '?'));
				$where .= " AND event IN ($ph)";
				$binds = array_merge($binds, $cleaned);
			}
		}

		try {
			$sql = "SELECT id, time, callid, queuename, agent, event, data1, data2, data3, data4, data5
			        FROM asteriskcdrdb.queuelog
			        WHERE $where
			        ORDER BY time DESC, id DESC
			        LIMIT $limit OFFSET $offset";
			$sth = $db->prepare($sql);
			$sth->execute($binds);
			$rows = $sth->fetchAll(\PDO::FETCH_ASSOC);
		} catch (\Throwable $e) {
			return [
				'count' => 0,
				'rows' => [],
				'event_counts' => [],
				'note' => 'queuelog table not available on this PBX (no queues configured yet).',
			];
		}

		$eventCounts = [];
		foreach ($rows as $r) {
			$t = $r['event'];
			$eventCounts[$t] = ($eventCounts[$t] ?? 0) + 1;
		}
		arsort($eventCounts);

		return [
			'count' => count($rows),
			'window' => [
				'date_from' => $params['date_from'] ?? null,
				'date_to'   => $params['date_to'] ?? null,
			],
			'limit' => $limit,
			'offset' => $offset,
			'event_counts' => $eventCounts,
			'rows' => $rows,
		];
	}
}
