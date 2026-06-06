<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetCel extends AbstractTool {
	public function name() { return 'fm_get_cel'; }

	public function description() {
		return 'Query Channel Event Log (CEL) rows from asteriskcdrdb.cel. CEL emits a row per channel state change so a single call typically produces 10-20 rows. Filter by date_from, date_to, linkedid, uniqueid, event_types (array, e.g. ["BRIDGE_ENTER","ANSWER"]), context_like, channame_like. limit default 100, max 1000.';
	}

	public function validate($params) {
		if (isset($params['limit'])) {
			$lim = (int)$params['limit'];
			if ($lim < 1 || $lim > 1000) return 'Parameter "limit" must be between 1 and 1000';
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
		$limit = isset($params['limit']) ? min((int)$params['limit'], 1000) : 100;
		$offset = isset($params['offset']) ? max(0, (int)$params['offset']) : 0;

		$where = '1=1';
		$binds = [];
		if (!empty($params['date_from'])) { $where .= ' AND eventtime >= ?'; $binds[] = $params['date_from']; }
		if (!empty($params['date_to']))   { $where .= ' AND eventtime <= ?'; $binds[] = $params['date_to']; }
		if (!empty($params['linkedid']))  { $where .= ' AND linkedid = ?';   $binds[] = $params['linkedid']; }
		if (!empty($params['uniqueid']))  { $where .= ' AND uniqueid = ?';   $binds[] = $params['uniqueid']; }
		if (!empty($params['event_types']) && is_array($params['event_types'])) {
			$cleaned = array_values(array_filter(array_map(function($e) {
				return preg_match('/^[A-Z_]+$/i', (string)$e) ? strtoupper($e) : null;
			}, $params['event_types'])));
			if (!empty($cleaned)) {
				$ph = implode(',', array_fill(0, count($cleaned), '?'));
				$where .= " AND eventtype IN ($ph)";
				$binds = array_merge($binds, $cleaned);
			}
		}
		if (!empty($params['context_like'])) {
			$where .= ' AND context LIKE ?';
			$binds[] = '%' . $params['context_like'] . '%';
		}
		if (!empty($params['channame_like'])) {
			$where .= ' AND channame LIKE ?';
			$binds[] = '%' . $params['channame_like'] . '%';
		}

		$sql = "SELECT id, eventtype, eventtime, cid_name, cid_num, cid_dnid, exten, context,
		               channame, appname, appdata, uniqueid, linkedid, peer, extra
		        FROM asteriskcdrdb.cel
		        WHERE $where
		        ORDER BY eventtime DESC, id DESC
		        LIMIT $limit OFFSET $offset";

		$sth = $db->prepare($sql);
		$sth->execute($binds);
		$rows = $sth->fetchAll(\PDO::FETCH_ASSOC);

		// Decode the JSON extra column when present so the MCP client can read it
		// without a second parse step. Leave empty string when row's extra is empty.
		foreach ($rows as &$r) {
			if (!empty($r['extra'])) {
				$decoded = json_decode($r['extra'], true);
				$r['extra'] = $decoded === null ? $r['extra'] : $decoded;
			} else {
				$r['extra'] = null;
			}
		}
		unset($r);

		$eventCounts = [];
		foreach ($rows as $r) {
			$t = $r['eventtype'];
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
