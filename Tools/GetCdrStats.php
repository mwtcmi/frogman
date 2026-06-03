<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetCdrStats extends AbstractTool {
	public function name() { return 'fm_get_cdr_stats'; }

	public function description() {
		return 'CDR statistics — call counts by disposition, avg duration. Collapses multi-leg fan-outs via linkedid so totals reflect conversations, not raw rows. Filters: date_from, date_to, include_non_calls (default false).';
	}

	public function validate($params) { return true; }

	public function permissionLevel() { return self::PERM_READ; }

	public function execute($params, $context) {
		$params = $this->applyDefaultReportWindow($params);
		$db = $this->freepbx->Database;
		$includeNonCalls = !empty($params['include_non_calls']);

		$sql = "SELECT calldate, src, dst, dcontext, channel, dstchannel, disposition, duration, billsec, uniqueid, linkedid
		        FROM asteriskcdrdb.cdr
		        WHERE 1=1";
		$binds = [];
		if (!empty($params['date_from'])) { $sql .= ' AND calldate >= ?'; $binds[] = $params['date_from']; }
		if (!empty($params['date_to']))   { $sql .= ' AND calldate <= ?'; $binds[] = $params['date_to']; }
		$sql .= ' LIMIT 500000';

		$sth = $db->prepare($sql);
		$sth->execute($binds);
		$rawRows = $sth->fetchAll(\PDO::FETCH_ASSOC);

		$filtered = $includeNonCalls
			? $rawRows
			: array_values(array_filter($rawRows, function($r) { return $this->isRealCall($r); }));
		$deduped = $this->dedupeByCall($filtered);

		$byDisp = [];
		$totalDur = 0;
		$totalBill = 0;
		foreach ($deduped as $r) {
			$d = $r['disposition'] ?: 'UNKNOWN';
			if (!isset($byDisp[$d])) {
				$byDisp[$d] = ['disposition' => $d, 'count' => 0, 'duration_total_s' => 0, 'billsec_total_s' => 0];
			}
			$byDisp[$d]['count']++;
			$byDisp[$d]['duration_total_s'] += (int)($r['duration'] ?? 0);
			$byDisp[$d]['billsec_total_s']  += (int)($r['billsec'] ?? 0);
			$totalDur  += (int)($r['duration'] ?? 0);
			$totalBill += (int)($r['billsec'] ?? 0);
		}
		foreach ($byDisp as &$row) {
			$row['avg_duration_s'] = $row['count'] > 0 ? round($row['duration_total_s'] / $row['count'], 1) : 0;
			$row['avg_billsec_s']  = $row['count'] > 0 ? round($row['billsec_total_s']  / $row['count'], 1) : 0;
		}
		unset($row);
		$byDisp = array_values($byDisp);

		return [
			'window' => [
				'date_from' => $params['date_from'] ?? null,
				'date_to'   => $params['date_to'] ?? null,
			],
			'include_non_calls' => $includeNonCalls,
			'total_calls' => count($deduped),
			'total_raw_rows' => count($filtered),
			'total_duration_s' => $totalDur,
			'total_billsec_s' => $totalBill,
			'by_disposition' => $byDisp,
		];
	}
}
