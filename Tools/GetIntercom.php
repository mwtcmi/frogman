<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetIntercom extends AbstractTool {
	public function name() { return 'fm_get_intercom'; }
	public function description() { return 'Get intercom/auto-answer status for an extension. Params: ext (required).'; }
	public function validate($params) { if (empty($params['ext'])) return 'Parameter "ext" is required';
		return true; }
	public function execute($params, $context) {
		$user = $this->freepbx->Core->getUser($params['ext']); if(empty($user)) throw new \Exception('Extension ' . $params['ext'] . ' not found'); return ['extension' => $params['ext'], 'name' => $user['name'], 'intercom' => $user['intercom'] ?? 'disabled', 'answermode' => $user['answermode'] ?? 'disabled'];
	}
}
