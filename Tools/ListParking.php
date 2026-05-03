<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListParking extends AbstractTool {
	public function name() { return 'fm_list_parking'; }
	public function description() { return 'List parking lots and currently parked calls.'; }
	public function validate($params) { return true; }
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$lots = $this->freepbx->Parking->getAllParkingLots();
		$parked = $this->freepbx->Parking->getParkedCalls();
		return ['lots' => $lots ?: [], 'parked_calls' => $parked ?: []];
	}
}
