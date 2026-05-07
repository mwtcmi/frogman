<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ShowDialplanContext extends AbstractTool {
	public function name() { return 'fm_show_context'; }
	public function description() { return 'Show any Asterisk dialplan context (not just custom). Params: name (required).'; }
	public function validate($params) {
		if (empty($params['name'])) return 'Parameter "name" is required';
		// Context names are alphanumeric with hyphens and underscores only
		if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $params['name'])) {
			return 'Context name must be alphanumeric (with - and _ allowed)';
		}
		return true;
	}
	public function execute($params, $context) {
		$name = $params['name'];
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) {
			throw new \Exception('Asterisk Manager (AMI) is not connected — cannot read live dialplan.');
		}
		$res = $astman->Command('dialplan show ' . $name);
		$body = trim($res['data'] ?? '');
		// AMI prefixes Command responses with a "Privilege: Command" header line
		$body = preg_replace('/^Privilege:\s*Command\s*\R?/m', '', $body, 1);
		$body = trim($body);
		if ($body === '' || stripos($body, 'failed') !== false) {
			throw new \Exception("Asterisk did not return a dialplan for context `{$name}` — check the name with `show dialplan`.");
		}
		return ['context' => $name, 'dialplan' => $body];
	}
}
