<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class AmiCommand extends AbstractTool {
	public function name() { return 'oc_ami_command'; }
	public function description() { return 'Run an Asterisk CLI command via AMI. Params: command (required). Read-only commands only.'; }
	public function validate($params) {
		if (empty($params['command'])) return 'Parameter "command" is required';
		// Allowlist of safe read-only AMI commands
		$allowed = '/^(core show|pjsip show|sip show|iax2 show|dialplan show|database show|database get|queue show|bridge show|channel show|features show|stun show|manager show|module show|logger show|http show)/i';
		if (!preg_match($allowed, $params['command'])) {
			return 'Only read-only show/get commands are allowed via AMI.';
		}
		return true;
	}
	public function execute($params, $context) {
		$cmd = $params['command'];
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');
		$res = $astman->Command($cmd);
		$output = trim($res['data'] ?? '');
		$output = preg_replace('/^Privilege:\s+\w+\s*/i', '', $output);
		return ['command' => $cmd, 'output' => $output];
	}
}
