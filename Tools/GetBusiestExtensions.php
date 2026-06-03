<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetBusiestExtensions extends AbstractTool {
	public function name() { return 'fm_get_busiest_extensions'; }

	public function description() {
		return 'Busiest extensions by call count. Resolves both legs against the live extensions list so inbound PSTN numbers do not get mislabeled. Filters: limit (default 10, max 50), date_from, date_to, include_non_calls (default false).';
	}

	public function validate($params) {
		if (isset($params['limit'])) {
			$lim = (int)$params['limit'];
			if ($lim < 1 || $lim > 50) return 'Parameter "limit" must be between 1 and 50';
		}
		return true;
	}

	public function permissionLevel() { return self::PERM_READ; }

	public function execute($params, $context) {
		$params = $this->applyDefaultReportWindow($params);
		$db = $this->freepbx->Database;
		$limit = isset($params['limit']) ? min((int)$params['limit'], 50) : 10;
		$includeNonCalls = !empty($params['include_non_calls']);

		$exts = $this->getInternalExtensions();
		if (empty($exts)) {
			return ['count' => 0, 'window' => $this->describeWindow($params), 'extensions' => []];
		}

		// SQL-side prefilter: only rows where at least one leg is a known extension.
		// Cuts row volume massively before we walk in PHP.
		$placeholders = implode(',', array_fill(0, count($exts), '?'));
		$sql = "SELECT calldate, src, dst, dcontext, channel, dstchannel, disposition, duration, billsec, uniqueid, linkedid
		        FROM asteriskcdrdb.cdr
		        WHERE 1=1";
		$binds = [];
		if (!empty($params['date_from'])) { $sql .= ' AND calldate >= ?'; $binds[] = $params['date_from']; }
		if (!empty($params['date_to']))   { $sql .= ' AND calldate <= ?'; $binds[] = $params['date_to']; }
		$sql .= " AND (src IN ($placeholders) OR dst IN ($placeholders))";
		$binds = array_merge($binds, $exts, $exts);
		$sql .= ' ORDER BY calldate DESC LIMIT 100000';

		$sth = $db->prepare($sql);
		$sth->execute($binds);
		$rows = $sth->fetchAll(\PDO::FETCH_ASSOC);

		if (!$includeNonCalls) {
			$rows = array_values(array_filter($rows, function($r) { return $this->isRealCall($r); }));
		}
		$rows = $this->dedupeByCall($rows);

		// Bucket each call against any extension legs present.
		$buckets = [];
		foreach ($rows as $r) {
			$srcIsExt = $this->isInternalExtension($r['src']);
			$dstIsExt = $this->isInternalExtension($r['dst']);
			if (!$srcIsExt && !$dstIsExt) continue;
			$direction = $srcIsExt && $dstIsExt ? 'internal' : ($srcIsExt ? 'outbound' : 'inbound');
			foreach ([$srcIsExt ? $r['src'] : null, $dstIsExt ? $r['dst'] : null] as $ext) {
				if ($ext === null) continue;
				if (!isset($buckets[$ext])) {
					$buckets[$ext] = [
						'extension' => (string)$ext,
						'name' => $this->lookupExtensionName($ext),
						'calls' => 0,
						'duration_total_s' => 0,
						'inbound' => 0,
						'outbound' => 0,
						'internal' => 0,
					];
				}
				$buckets[$ext]['calls']++;
				$buckets[$ext]['duration_total_s'] += (int)($r['duration'] ?? 0);
				$buckets[$ext][$direction]++;
			}
		}

		// Sort by total calls desc, then by ext for stable output.
		usort($buckets, function($a, $b) {
			if ($a['calls'] !== $b['calls']) return $b['calls'] - $a['calls'];
			return strcmp($a['extension'], $b['extension']);
		});
		$buckets = array_slice($buckets, 0, $limit);

		// Round display values; preserve raw for re-aggregation.
		foreach ($buckets as &$b) {
			$b['avg_duration_s'] = $b['calls'] > 0 ? round($b['duration_total_s'] / $b['calls'], 1) : 0;
		}
		unset($b);

		return [
			'count' => count($buckets),
			'window' => $this->describeWindow($params),
			'include_non_calls' => $includeNonCalls,
			'rows_scanned' => count($rows),
			'extensions' => $buckets,
		];
	}

	private function describeWindow($params) {
		return [
			'date_from' => $params['date_from'] ?? null,
			'date_to'   => $params['date_to'] ?? null,
		];
	}
}
