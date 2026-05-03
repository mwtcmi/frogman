<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListChannels extends AbstractTool {
	public function name() { return 'fm_list_channels'; }
	public function description() { return 'List all active Asterisk channels with details.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$astman = $this->freepbx->astman; if(!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager'); $res = $astman->Command('core show channels verbose'); $output = trim($res['data'] ?? ''); $output = preg_replace('/^Privilege:\s+\w+\s*/i', '', $output); return ['output' => $output];
	}
}
