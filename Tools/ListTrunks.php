<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class ListTrunks extends AbstractTool {

	public function name() {
		return 'fm_list_trunks';
	}

	public function description() {
		return 'List all configured trunks with their type, name, and status.';
	}

	public function validate($params) {
		return true;
	}

	public function requiredPermission() {
		return null;
	}

	public function execute($params, $context) {
		$trunks = $this->freepbx->Core->listTrunks();
		$result = [];
		foreach ($trunks as $trunk) {
			$result[] = [
				'trunkid' => $trunk['trunkid'],
				'name' => $trunk['name'],
				'tech' => $trunk['tech'],
				'channelid' => $trunk['channelid'],
				'disabled' => isset($trunk['disabled']) ? $trunk['disabled'] : 'off',
			];
		}

		return [
			'count' => count($result),
			'trunks' => $result,
		];
	}
}
