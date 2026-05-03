<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class ListActiveCalls extends AbstractTool {

	public function name() {
		return 'fm_list_active_calls';
	}

	public function description() {
		return 'List currently active calls on the PBX via AMI.';
	}

	public function validate($params) {
		return true;
	}

	public function requiredPermission() {
		return null;
	}

	public function execute($params, $context) {
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) {
			throw new \Exception('Cannot connect to Asterisk Manager');
		}

		$res = $astman->Command("core show channels concise");
		$data = isset($res['data']) ? trim($res['data']) : '';

		$calls = [];
		if (!empty($data)) {
			foreach (explode("\n", $data) as $line) {
				$line = trim($line);
				if (empty($line) || strpos($line, 'Privilege:') === 0) {
					continue;
				}
				// Format: channel!context!extension!priority!state!application!data!callerid!accountcode!amaflags!duration!bridgedto!uniqueid
				$parts = explode('!', $line);
				if (count($parts) >= 7) {
					$calls[] = [
						'channel' => $parts[0],
						'context' => $parts[1],
						'extension' => $parts[2],
						'state' => isset($parts[4]) ? $parts[4] : '',
						'application' => isset($parts[5]) ? $parts[5] : '',
						'callerid' => isset($parts[7]) ? $parts[7] : '',
						'duration' => isset($parts[10]) ? $parts[10] : '',
						'bridged_to' => isset($parts[11]) ? $parts[11] : '',
					];
				}
			}
		}

		return [
			'active_call_count' => count($calls),
			'calls' => $calls,
		];
	}
}
