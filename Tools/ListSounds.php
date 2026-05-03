<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListSounds extends AbstractTool {
	public function name() { return 'fm_list_sounds'; }
	public function description() { return 'List installed sound/language packs.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$output = []; exec('/usr/sbin/fwconsole sounds --list 2>&1', $output, $ec);
		return ['output' => implode("\n", $output)];
	}
}
