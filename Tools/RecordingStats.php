<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

/**
 * Discovery layer for the recording surface: counts of recorded calls by
 * extension and by day over a window. Lets a user know "is there even anything
 * to look at" before paging through fm_list_call_recordings results.
 *
 * Read-only, no audio touched, no tokens minted. Defaults to today via
 * AbstractTool::applyDefaultReportWindow so an unbounded scan can never happen.
 */
class RecordingStats extends AbstractTool {
	public function name() { return 'fm_recording_stats'; }

	public function description() {
		return 'Recording activity counts over a window: total recordings, top extensions by recorded calls, per-day histogram. Pair with fm_list_call_recordings to drill into a specific bucket. Params: date_from / date_to (defaults to today 00:00 through now), top (extension list size, default 10). Read-only.';
	}

	public function validate($params) {
		if (isset($params['top']) && (!is_numeric($params['top']) || (int)$params['top'] < 1)) {
			return 'Parameter "top" must be a positive integer.';
		}
		return true;
	}

	public function execute($params, $context) {
		$db = $this->freepbx->Database;
		$params = $this->applyDefaultReportWindow($params);
		$top = max(1, min(100, (int)($params['top'] ?? 10)));

		$where = ["recordingfile IS NOT NULL", "recordingfile != ''"];
		$binds = [];
		if (!empty($params['date_from'])) { $where[] = 'calldate >= ?'; $binds[] = $params['date_from']; }
		if (!empty($params['date_to']))   { $where[] = 'calldate <= ?'; $binds[] = $params['date_to']; }
		$whereSql = implode(' AND ', $where);

		// Pull a wide enough set to dedupe by linkedid, then count.
		$sth = $db->prepare("SELECT calldate, src, dst, linkedid, uniqueid, dcontext, channel, dstchannel, recordingfile FROM asteriskcdrdb.cdr WHERE {$whereSql} ORDER BY calldate DESC LIMIT 50000");
		$sth->execute($binds);
		$rows = $sth->fetchAll(\PDO::FETCH_ASSOC);
		$rows = array_values(array_filter($rows, function($r) { return $this->isRealCall($r); }));
		$rows = $this->dedupeByCall($rows);

		$total = count($rows);
		$perExt = [];
		$perDay = [];
		$conferenceCount = 0;
		foreach ($rows as $r) {
			$day = substr((string)$r['calldate'], 0, 10);
			$perDay[$day] = ($perDay[$day] ?? 0) + 1;
			foreach (['src', 'dst'] as $leg) {
				$num = (string)$r[$leg];
				if ($this->isInternalExtension($num)) {
					$perExt[$num] = ($perExt[$num] ?? 0) + 1;
				}
			}
			$file = (string)$r['recordingfile'];
			$ctx = (string)$r['dcontext'];
			if (stripos($ctx, 'confbridge') !== false || stripos($ctx, 'meetme') !== false
				|| stripos($file, 'confbridge-') === 0 || stripos($file, 'meetme-') === 0) {
				$conferenceCount++;
			}
		}
		arsort($perExt);
		ksort($perDay);

		$topExt = [];
		foreach (array_slice($perExt, 0, $top, true) as $ext => $n) {
			$topExt[] = ['ext' => (string)$ext, 'name' => $this->lookupExtensionName($ext), 'recordings' => $n];
		}

		return [
			'total_recordings' => $total,
			'conference_recordings' => $conferenceCount,
			'extensions_with_recordings' => count($perExt),
			'days_in_window' => count($perDay),
			'top_extensions' => $topExt,
			'per_day' => $perDay,
			'window' => [
				'date_from' => $params['date_from'] ?? null,
				'date_to' => $params['date_to'] ?? null,
			],
		];
	}
}
