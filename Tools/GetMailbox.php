<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetMailbox extends AbstractTool {
	public function name() { return 'fm_get_mailbox'; }
	public function description() { return 'Get voicemail mailbox details. Params: ext (required).'; }
	public function validate($params) { if (empty($params['ext'])) return 'Parameter "ext" is required';
		return true; }
	public function execute($params, $context) {
		$mb = $this->freepbx->Voicemail->getMailbox($params['ext'], 'default'); if(empty($mb)) throw new \Exception('Mailbox ' . $params['ext'] . ' not found'); return $mb;
	}
}
