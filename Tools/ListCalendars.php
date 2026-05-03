<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListCalendars extends AbstractTool {
	public function name() { return 'fm_list_calendars'; }
	public function description() { return 'List all calendars configured in Calendar module.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$calendars = $this->freepbx->Calendar->listCalendars();
		$result = [];
		if (!empty($calendars)) {
			foreach ($calendars as $cal) {
				$result[] = [
					'id' => $cal['id'] ?? '',
					'name' => $cal['name'] ?? '',
					'type' => $cal['type'] ?? '',
					'description' => $cal['description'] ?? '',
				];
			}
		}
		return ['count' => count($result), 'calendars' => $result];
	}
}
