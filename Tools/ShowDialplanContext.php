<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ShowDialplanContext extends AbstractTool {
	public function name() { return 'oc_show_context'; }
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
		$astman = $this->freepbx->astman;
		if ($astman && $astman->connected()) {
			$res = $astman->Command('dialplan show ' . escapeshellarg($params['name']));
			return ['context' => $params['name'], 'dialplan' => trim($res['data'] ?? '')];
		}
		$output = []; exec('/usr/sbin/fwconsole context ' . escapeshellarg($params['name']) . ' 2>&1', $output, $ec);
		return ['context' => $params['name'], 'dialplan' => implode("\n", $output)];
	}
}
