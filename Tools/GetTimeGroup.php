<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetTimeGroup extends AbstractTool {
	public function name() { return 'fm_get_time_group'; }
	public function description() { return 'Get time group details including configured hours. Params: id (required).'; }
	public function validate($params) {
		if (empty($params['id'])) return 'Parameter "id" is required';
		return true;
	}
	public function execute($params, $context) {
		$id = $params['id'];
		$group = $this->freepbx->Timeconditions->getTimeGroup($id);
		if (empty($group)) throw new \Exception("Time group {$id} not found");

		$db = $this->freepbx->Database;
		$sth = $db->prepare("SELECT * FROM timegroups_details WHERE timegroupid = ?");
		$sth->execute([$id]);
		$times = $sth->fetchAll(\PDO::FETCH_ASSOC);

		return [
			'id' => $id,
			'description' => $group[1] ?? $group['description'] ?? '',
			'times' => $times,
		];
	}
}
