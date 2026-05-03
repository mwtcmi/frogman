<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetTrunkByName extends AbstractTool {
	public function name() { return 'fm_get_trunk_by_name'; }
	public function description() { return 'Find a trunk by its name or channel ID. Params: name (required).'; }
	public function validate($params) { if (empty($params['name'])) return 'Parameter "name" is required';
		return true; }
	public function execute($params, $context) {
		$db = $this->freepbx->Database; $sth = $db->prepare('SELECT trunkid, name, tech, outcid, channelid, disabled FROM trunks WHERE name LIKE ? OR channelid LIKE ?'); $sth->execute(['%'.$params['name'].'%', '%'.$params['name'].'%']); $results = $sth->fetchAll(\PDO::FETCH_ASSOC); return ['count' => count($results), 'trunks' => $results];
	}
}
