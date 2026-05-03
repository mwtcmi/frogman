<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetPeakHours extends AbstractTool {
	public function name() { return 'fm_get_peak_hours'; }
	public function description() { return 'Get call volume by hour of day. Optional: date_from, date_to.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$db = $this->freepbx->Database; $where = ''; $binds = []; if(!empty($params['date_from'])) { $where .= ' AND calldate >= ?'; $binds[] = $params['date_from']; } if(!empty($params['date_to'])) { $where .= ' AND calldate <= ?'; $binds[] = $params['date_to']; } $sth = $db->prepare('SELECT HOUR(calldate) as hour, COUNT(*) as calls FROM asteriskcdrdb.cdr WHERE 1=1' . $where . ' GROUP BY HOUR(calldate) ORDER BY hour'); $sth->execute($binds); return ['hours' => $sth->fetchAll(\PDO::FETCH_ASSOC)];
	}
}
