<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetFailedCalls extends AbstractTool {
	public function name() { return 'fm_get_failed_calls'; }
	public function description() { return 'Get recent failed/unanswered calls. Optional: limit (default 25), date_from.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$db = $this->freepbx->Database; $limit = min((int)($params['limit'] ?? 25), 100); $where = ''; $binds = []; if(!empty($params['date_from'])) { $where .= ' AND calldate >= ?'; $binds[] = $params['date_from']; } $sth = $db->prepare('SELECT calldate, src, dst, disposition, duration, channel, dstchannel FROM asteriskcdrdb.cdr WHERE disposition != "ANSWERED"' . $where . ' ORDER BY calldate DESC LIMIT ' . $limit); $sth->execute($binds); $rows = $sth->fetchAll(\PDO::FETCH_ASSOC); return ['count' => count($rows), 'calls' => $rows];
	}
}
