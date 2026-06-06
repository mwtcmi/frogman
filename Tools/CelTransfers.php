<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class CelTransfers extends AbstractTool {
	public function name() { return 'fm_cel_transfers'; }

	public function description() {
		return 'List blind and attended transfer events from CEL in a time window. Filters: date_from, date_to, transferer_ext, limit (default 100, max 1000). Returns one row per transfer with transferer/from_party/to_party resolved and call_duration_before_transfer_s.';
	}

	public function validate($params) {
		if (isset($params['limit'])) {
			$lim = (int)$params['limit'];
			if ($lim < 1 || $lim > 1000) return 'Parameter "limit" must be between 1 and 1000';
		}
		return true;
	}

	public function permissionLevel() { return self::PERM_READ; }

	public function execute($params, $context) {
		$params = $this->applyDefaultReportWindow($params);
		$db = $this->freepbx->Database;
		$limit = isset($params['limit']) ? min((int)$params['limit'], 1000) : 100;

		$where = "eventtype IN ('BLINDTRANSFER','ATTENDEDTRANSFER')";
		$binds = [];
		if (!empty($params['date_from'])) { $where .= ' AND eventtime >= ?'; $binds[] = $params['date_from']; }
		if (!empty($params['date_to']))   { $where .= ' AND eventtime <= ?'; $binds[] = $params['date_to']; }
		if (!empty($params['transferer_ext'])) {
			$where .= ' AND cid_num = ?';
			$binds[] = $params['transferer_ext'];
		}

		$sql = "SELECT id, eventtype, eventtime, cid_name, cid_num, exten, context, channame, uniqueid, linkedid, extra
		        FROM asteriskcdrdb.cel
		        WHERE $where
		        ORDER BY eventtime DESC, id DESC
		        LIMIT $limit";
		$sth = $db->prepare($sql);
		$sth->execute($binds);
		$rows = $sth->fetchAll(\PDO::FETCH_ASSOC);

		$out = [];
		$blind = 0;
		$attended = 0;
		$byTransferer = [];
		foreach ($rows as $r) {
			$extra = !empty($r['extra']) ? json_decode($r['extra'], true) : null;
			$linkedid = $r['linkedid'];
			$callStart = $this->getCallStart($db, $linkedid);
			$durBefore = $callStart ? max(0, strtotime($r['eventtime']) - strtotime($callStart)) : null;
			$transfererExt = (string)$r['cid_num'];
			$transfererName = $this->lookupExtensionName($transfererExt);

			$toParty = null;
			$targetLinkedid = null;
			if ($r['eventtype'] === 'BLINDTRANSFER') {
				$toParty = $extra['extension'] ?? null;
				$targetLinkedid = $extra['transferee_channel_uniqueid'] ?? null;
				$blind++;
			} else {
				$toParty = $extra['bridge2_uniqueid'] ?? ($extra['channel2_uniqueid'] ?? null);
				$targetLinkedid = $extra['channel2_uniqueid'] ?? null;
				$attended++;
			}

			$key = $transfererExt;
			if (!isset($byTransferer[$key])) {
				$byTransferer[$key] = ['ext' => $transfererExt, 'name' => $transfererName, 'count' => 0];
			}
			$byTransferer[$key]['count']++;

			$out[] = [
				'at' => $r['eventtime'],
				'linkedid' => $linkedid,
				'type' => $r['eventtype'],
				'transferer' => [
					'channame' => $r['channame'],
					'ext' => $transfererExt,
					'name' => $transfererName,
				],
				'from_party' => $r['cid_name'] !== '' ? $r['cid_name'] : null,
				'to_party' => $toParty,
				'target_linkedid' => $targetLinkedid,
				'call_duration_before_transfer_s' => $durBefore,
				'extra' => $extra,
			];
		}

		usort($byTransferer, function($a, $b) { return $b['count'] - $a['count']; });

		return [
			'count' => count($out),
			'window' => [
				'date_from' => $params['date_from'] ?? null,
				'date_to'   => $params['date_to'] ?? null,
			],
			'summary' => [
				'blind' => $blind,
				'attended' => $attended,
				'by_transferer' => array_values($byTransferer),
			],
			'rows' => $out,
		];
	}

	// Earliest CHAN_START or any earliest event for a linkedid. Small per-row
	// query — fine for transfer-event windows since transfers are rare relative
	// to overall CEL volume. If we ever hit a high-transfer deployment, switch
	// to a single JOIN.
	private function getCallStart($db, $linkedid) {
		static $cache = [];
		if (!$linkedid) return null;
		if (array_key_exists($linkedid, $cache)) return $cache[$linkedid];
		$sth = $db->prepare("SELECT MIN(eventtime) FROM asteriskcdrdb.cel WHERE linkedid = ?");
		$sth->execute([$linkedid]);
		$t = $sth->fetchColumn();
		$cache[$linkedid] = $t ?: null;
		return $cache[$linkedid];
	}
}
