<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetPeakHours extends AbstractTool {
	public function name() { return 'fm_get_peak_hours'; }

	public function description() {
		return 'Call volume by hour of day. Collapses multi-leg fan-outs via linkedid so one conversation counts once, not 2-3x. Filters: date_from, date_to, include_non_calls (default false).';
	}

	public function validate($params) { return true; }

	public function permissionLevel() { return self::PERM_READ; }

	public function execute($params, $context) {
		$params = $this->applyDefaultReportWindow($params);
		$db = $this->freepbx->Database;
		$includeNonCalls = !empty($params['include_non_calls']);

		$sql = "SELECT calldate, src, dst, dcontext, channel, dstchannel, uniqueid, linkedid
		        FROM asteriskcdrdb.cdr
		        WHERE 1=1";
		$binds = [];
		if (!empty($params['date_from'])) { $sql .= ' AND calldate >= ?'; $binds[] = $params['date_from']; }
		if (!empty($params['date_to']))   { $sql .= ' AND calldate <= ?'; $binds[] = $params['date_to']; }
		$sql .= ' ORDER BY calldate ASC LIMIT 200000';

		$sth = $db->prepare($sql);
		$sth->execute($binds);
		$rawRows = $sth->fetchAll(\PDO::FETCH_ASSOC);

		$filtered = $includeNonCalls
			? $rawRows
			: array_values(array_filter($rawRows, function($r) { return $this->isRealCall($r); }));

		$deduped = $this->dedupeByCall($filtered);

		$rawByHour = array_fill(0, 24, 0);
		$callsByHour = array_fill(0, 24, 0);
		foreach ($filtered as $r) {
			$h = (int)substr($r['calldate'], 11, 2);
			if ($h >= 0 && $h < 24) $rawByHour[$h]++;
		}
		foreach ($deduped as $r) {
			$h = (int)substr($r['calldate'], 11, 2);
			if ($h >= 0 && $h < 24) $callsByHour[$h]++;
		}

		$hours = [];
		for ($h = 0; $h < 24; $h++) {
			$hours[] = [
				'hour' => $h,
				'calls' => $callsByHour[$h],
				'raw_rows' => $rawByHour[$h],
			];
		}

		return [
			'window' => [
				'date_from' => $params['date_from'] ?? null,
				'date_to'   => $params['date_to'] ?? null,
			],
			'include_non_calls' => $includeNonCalls,
			'total_calls' => array_sum($callsByHour),
			'total_raw_rows' => array_sum($rawByHour),
			'hours' => $hours,
		];
	}
}
