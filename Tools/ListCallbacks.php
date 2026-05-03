<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListCallbacks extends AbstractTool {
	public function name() { return 'fm_list_callbacks'; }
	public function description() { return 'List all callback entries.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$list = $this->freepbx->Callback->listCallbacks();
		$result = [];
		if (!empty($list)) {
			foreach ($list as $cb) {
				$result[] = [
					'id' => $cb['callback_id'] ?? $cb[0] ?? '',
					'description' => $cb['description'] ?? $cb[1] ?? '',
					'number' => $cb['callbacknum'] ?? $cb[2] ?? '',
					'destination' => $cb['destination'] ?? '',
				];
			}
		}
		return ['count' => count($result), 'callbacks' => $result];
	}
}
