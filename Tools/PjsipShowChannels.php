<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class PjsipShowChannels extends AbstractTool {
	public function name() { return 'fm_pjsip_show_channels'; }
	public function description() { return 'Show active PJSIP channels with codec, media, and call detail. Optional filter: endpoint (extension or trunk name).'; }

	public function validate($params) {
		if (!empty($params['endpoint']) && !preg_match('/^[a-zA-Z0-9_\-]+$/', $params['endpoint'])) {
			return 'Parameter "endpoint" must be alphanumeric';
		}
		return true;
	}

	public function execute($params, $context) {
		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager');

		// Get PJSIP channel list
		$res = $astman->Command('pjsip show channelstats');
		$raw = trim($res['data'] ?? '');

		$channels = [];
		$lines = explode("\n", $raw);
		foreach ($lines as $line) {
			$line = trim($line);
			// Skip headers, separators, and empty lines
			if (empty($line) || strpos($line, '====') === 0 || strpos($line, 'Channel') === 0 || strpos($line, 'Privilege:') === 0) {
				continue;
			}
			// Channel stats lines contain PJSIP/ prefix
			if (strpos($line, 'PJSIP/') !== false) {
				$channels[] = $line;
			}
		}

		// Filter by endpoint if requested
		if (!empty($params['endpoint'])) {
			$filter = $params['endpoint'];
			$channels = array_values(array_filter($channels, function($ch) use ($filter) {
				return stripos($ch, "PJSIP/{$filter}") !== false;
			}));
		}

		// Also get detailed codec info for each active channel
		$channelDetails = [];
		if (!empty($params['endpoint'])) {
			$detailRes = $astman->Command("pjsip show channel PJSIP/{$params['endpoint']}");
			$channelDetails = trim($detailRes['data'] ?? '');
		}

		return [
			'channel_count' => count($channels),
			'channels' => $channels,
			'raw_stats' => $raw,
			'channel_details' => $channelDetails ?: null,
		];
	}
}
