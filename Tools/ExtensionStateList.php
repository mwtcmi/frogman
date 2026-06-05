<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ExtensionStateList extends AbstractTool {
	public function name() { return 'fm_extension_states'; }
	public function description() { return 'List BLF/presence state for all extensions — shows who is on a call, ringing, idle.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');
		$res = $astman->Command('core show hints');
		$raw = trim($res['data'] ?? '');

		$extensions = [];
		foreach (explode("\n", $raw) as $line) {
			$line = trim($line);
			// Only match extension hints (ext@ext-local), skip parking, feature codes, etc.
			if (preg_match('/^(\d+)@ext-local\s+:\s+PJSIP\/\d+.*State:(\S+)\s+Presence:(\S+)/', $line, $m)) {
				$state = $m[2];
				// PHP 7.4-compatible lookup (FreePBX 16 ships PHP 7.4; match() is 8.0+).
				$stateMap = [
					'Idle' => 'Available',
					'InUse' => 'On a call',
					'Ringing' => 'Ringing',
					'Unavailable' => 'Offline',
					'Busy' => 'Busy',
					'OnHold' => 'On Hold',
				];
				$stateLabel = $stateMap[$state] ?? $state;
				$extensions[] = [
					'ext' => $m[1],
					'state' => $stateLabel,
					'presence' => $m[3],
				];
			}
		}

		// Sort by extension number
		usort($extensions, function($a, $b) { return (int)$a['ext'] - (int)$b['ext']; });

		return ['count' => count($extensions), 'extensions' => $extensions];
	}
}
