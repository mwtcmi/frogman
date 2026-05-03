<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetCdrRecord extends AbstractTool {
	public function name() { return 'fm_get_cdr_record'; }
	public function description() { return 'Get a specific CDR record by unique ID. Params: uniqueid (required).'; }
	public function validate($params) { if (empty($params['uniqueid'])) return 'Parameter "uniqueid" is required';
		return true; }
	public function execute($params, $context) {
		return $this->freepbx->Cdr->getRecordByID($params['uniqueid']);
	}
}
