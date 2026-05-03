<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListVoicemail extends AbstractTool {
	public function name() { return 'fm_list_voicemail'; }
	public function description() { return 'List all voicemail boxes. Optional: type ("settings" to show global voicemail settings instead).'; }
	public function validate($params) { return true; }
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$vms = $this->freepbx->Voicemail->getVoicemail();

		// Return global settings if requested
		if (!empty($params['type']) && strtolower($params['type']) === 'settings') {
			$settings = $vms['general'] ?? [];
			return ['count' => count($settings), 'settings' => $settings];
		}

		$result = [];
		// Skip 'general' and 'zonemessages' — those are settings, not mailboxes
		$skipContexts = ['general', 'zonemessages'];
		if (!empty($vms)) {
			foreach ($vms as $vmcontext => $boxes) {
				if (in_array($vmcontext, $skipContexts)) continue;
				if (!is_array($boxes)) continue;
				foreach ($boxes as $ext => $box) {
					if (!is_array($box) || !isset($box['name'])) continue;
					$result[] = [
						'extension' => $ext,
						'name' => $box['name'] ?? '',
						'email' => $box['email'] ?? '',
						'context' => $vmcontext,
					];
				}
			}
		}
		return ['count' => count($result), 'voicemails' => $result];
	}
}
