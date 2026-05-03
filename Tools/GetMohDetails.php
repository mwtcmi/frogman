<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetMohDetails extends AbstractTool {
	public function name() { return 'fm_get_moh_details'; }
	public function description() { return 'Get Music on Hold category details. Params: name (required).'; }
	public function validate($params) { if (empty($params['name'])) return 'Parameter "name" is required';
		return true; }
	public function execute($params, $context) {
		$db = $this->freepbx->Database; $sth = $db->prepare('SELECT * FROM music WHERE category = ?'); $sth->execute([$params['name']]); $rows = $sth->fetchAll(\PDO::FETCH_ASSOC); if(empty($rows)) throw new \Exception('MOH category not found'); return ['category' => $params['name'], 'entries' => $rows];
	}
}
