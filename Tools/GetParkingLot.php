<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetParkingLot extends AbstractTool {
	public function name() { return 'fm_get_parking_lot'; }
	public function description() { return 'Get details for a parking lot. Params: id (required).'; }
	public function validate($params) {
		if (!isset($params['id'])) return 'Parameter "id" is required';
		return true;
	}
	public function execute($params, $context) {
		$lot = $this->freepbx->Parking->getParkingLotByID($params['id']);
		if (empty($lot)) throw new \Exception("Parking lot {$params['id']} not found");
		$parked = $this->freepbx->Parking->getParkedCalls();
		$lotParked = [];
		if (!empty($parked)) {
			foreach ($parked as $call) {
				if (($call['parkingspace'] ?? '') >= ($lot['parkpos_start'] ?? 0) && ($call['parkingspace'] ?? '') <= ($lot['parkpos_end'] ?? 0)) {
					$lotParked[] = $call;
				}
			}
		}
		return ['lot' => $lot, 'parked_calls' => $lotParked];
	}
}
