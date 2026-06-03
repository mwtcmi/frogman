<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class GetCdr extends AbstractTool {

	public function name() {
		return 'fm_get_cdr';
	}

	public function description() {
		return 'Query call detail records. Filters: src, dst, date_from, date_to, disposition (ANSWERED/NO ANSWER/BUSY/FAILED), limit (default 25, max 100), include_non_calls (default false — exclude multicast paging, lockdown beacons, echo tests).';
	}

	public function validate($params) {
		if (isset($params['limit'])) {
			$limit = (int) $params['limit'];
			if ($limit < 1 || $limit > 100) {
				return 'Parameter "limit" must be between 1 and 100';
			}
		}
		if (isset($params['disposition']) && !in_array(strtoupper($params['disposition']), ['ANSWERED', 'NO ANSWER', 'BUSY', 'FAILED'])) {
			return 'Parameter "disposition" must be one of: ANSWERED, NO ANSWER, BUSY, FAILED';
		}
		return true;
	}

	public function permissionLevel() { return self::PERM_READ; }

	public function requiredPermission() {
		return null;
	}

	public function execute($params, $context) {
		$params = $this->applyDefaultReportWindow($params);
		$conditions = [];
		$binds = [];

		if (!empty($params['src'])) {
			$conditions[] = "src = ?";
			$binds[] = $params['src'];
		}
		if (!empty($params['dst'])) {
			$conditions[] = "dst = ?";
			$binds[] = $params['dst'];
		}
		if (!empty($params['date_from'])) {
			$conditions[] = "calldate >= ?";
			$binds[] = $params['date_from'];
		}
		if (!empty($params['date_to'])) {
			$conditions[] = "calldate <= ?";
			$binds[] = $params['date_to'];
		}
		if (!empty($params['disposition'])) {
			$conditions[] = "disposition = ?";
			$binds[] = strtoupper($params['disposition']);
		}

		$where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
		$limit = isset($params['limit']) ? min((int) $params['limit'], 100) : 25;
		$includeNonCalls = !empty($params['include_non_calls']);

		// Overfetch so the post-filter still hits the user's requested limit.
		// Cap defensively so a pathological window can't blow memory.
		$fetchLimit = $includeNonCalls ? $limit : min($limit * 10, 5000);

		$sql = "SELECT calldate, clid, src, dst, dcontext, channel, dstchannel,
		               disposition, duration, billsec, uniqueid, linkedid, did, recordingfile
		        FROM asteriskcdrdb.cdr
		        {$where}
		        ORDER BY calldate DESC
		        LIMIT {$fetchLimit}";

		$db = $this->freepbx->Database;
		$sth = $db->prepare($sql);
		$sth->execute($binds);
		$rows = $sth->fetchAll(\PDO::FETCH_ASSOC);

		if (!$includeNonCalls) {
			$rows = array_values(array_filter($rows, function($r) { return $this->isRealCall($r); }));
		}
		if (count($rows) > $limit) {
			$rows = array_slice($rows, 0, $limit);
		}

		return [
			'count' => count($rows),
			'include_non_calls' => $includeNonCalls,
			'records' => $rows,
		];
	}
}
