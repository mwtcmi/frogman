<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ExportCsv extends AbstractTool {
	public function name() { return 'fm_export'; }
	public function description() { return 'Export PBX data as CSV. Params: type (required: extensions, ringgroups, dids, trunks, cdr, queues). Optional for CDR: date_from, date_to, limit (default 500).'; }
	public function validate($params) {
		if (empty($params['type'])) return 'Parameter "type" is required (extensions, ringgroups, dids, trunks, cdr, queues)';
		$valid = ['extensions', 'ringgroups', 'dids', 'trunks', 'cdr', 'queues'];
		if (!in_array(strtolower($params['type']), $valid)) return 'Parameter "type" must be: ' . implode(', ', $valid);
		return true;
	}
	public function execute($params, $context) {
		$type = strtolower($params['type']);
		$db = $this->freepbx->Database;

		switch ($type) {
			case 'extensions':
				$rows = $db->query("SELECT u.extension, u.name, d.tech, u.voicemail, u.outboundcid, u.ringtimer, u.recording FROM users u JOIN devices d ON u.extension = d.id ORDER BY CAST(u.extension AS UNSIGNED)")->fetchAll(\PDO::FETCH_ASSOC);
				$headers = ['Extension', 'Name', 'Tech', 'Voicemail', 'Outbound CID', 'Ring Timer', 'Recording'];
				break;

			case 'ringgroups':
				$rows = $db->query("SELECT grpnum, description, strategy, grptime, grplist, postdest FROM ringgroups ORDER BY grpnum")->fetchAll(\PDO::FETCH_ASSOC);
				$headers = ['Group', 'Description', 'Strategy', 'Ring Time', 'Members', 'Failover Dest'];
				break;

			case 'dids':
				$rows = $db->query("SELECT extension, description, destination FROM incoming ORDER BY extension")->fetchAll(\PDO::FETCH_ASSOC);
				$headers = ['DID', 'Description', 'Destination'];
				break;

			case 'trunks':
				$rows = $db->query("SELECT trunkid, name, tech, outcid, channelid, disabled FROM trunks ORDER BY trunkid")->fetchAll(\PDO::FETCH_ASSOC);
				$headers = ['Trunk ID', 'Name', 'Tech', 'Outbound CID', 'Channel ID', 'Disabled'];
				break;

			case 'queues':
				$rows = $db->query("SELECT extension, descr, strategy, timeout, maxlen FROM queues_config ORDER BY extension")->fetchAll(\PDO::FETCH_ASSOC);
				$headers = ['Extension', 'Description', 'Strategy', 'Timeout', 'Max Length'];
				break;

			case 'cdr':
				$limit = min((int)($params['limit'] ?? 500), 5000);
				$where = '';
				$binds = [];
				if (!empty($params['date_from'])) { $where .= ' AND calldate >= ?'; $binds[] = $params['date_from']; }
				if (!empty($params['date_to'])) { $where .= ' AND calldate <= ?'; $binds[] = $params['date_to']; }
				$sth = $db->prepare("SELECT calldate, src, dst, disposition, duration, billsec, channel, dstchannel, clid, did FROM asteriskcdrdb.cdr WHERE 1=1{$where} ORDER BY calldate DESC LIMIT {$limit}");
				$sth->execute($binds);
				$rows = $sth->fetchAll(\PDO::FETCH_ASSOC);
				$headers = ['Date', 'Source', 'Destination', 'Disposition', 'Duration', 'Billsec', 'Channel', 'Dst Channel', 'Caller ID', 'DID'];
				break;
		}

		if (empty($rows)) {
			return ['type' => $type, 'count' => 0, 'file' => null, 'message' => "No {$type} data to export."];
		}

		// Write CSV
		$filename = "frogman-{$type}-" . date('Ymd-His') . '.csv';
		$exportDir = __DIR__ . '/../assets/exports';
		if (!is_dir($exportDir)) mkdir($exportDir, 0755, true);
		$filepath = $exportDir . '/' . $filename;

		$fp = fopen($filepath, 'w');
		fputcsv($fp, $headers);
		foreach ($rows as $row) {
			fputcsv($fp, array_values($row));
		}
		fclose($fp);

		$url = "ajax.php?module=frogman&command=download&file={$filename}";

		return [
			'type' => $type,
			'count' => count($rows),
			'filename' => $filename,
			'url' => $url,
			'message' => "Exported {$type}: " . count($rows) . " rows.",
		];
	}
}
