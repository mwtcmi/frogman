<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListPcaps extends AbstractTool {
	public function name() { return 'fm_list_pcaps'; }
	public function description() { return 'List available packet captures (.pcap) newest first, with timestamp and size. Read-only.'; }
	public function permissionLevel() { return self::PERM_ADMIN; }

	public function validate($params) {
		if (isset($params['all']) && !is_bool($params['all'])) {
			return 'Parameter "all" must be a boolean when supplied';
		}
		if (isset($params['limit'])) {
			$limit = (int)$params['limit'];
			if ($limit < 1 || $limit > 200) {
				return 'Parameter "limit" must be between 1 and 200';
			}
		}
		return true;
	}

	public function execute($params, $context) {
		$captures = [];
		foreach ($this->captureBases() as $base) {
			$baseReal = realpath($base);
			if ($baseReal === false || !is_dir($baseReal) || !is_readable($baseReal)) continue;

			foreach (['*.pcap', '*.cap'] as $pattern) {
				$files = glob(rtrim($baseReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $pattern);
				if (!is_array($files)) continue;
				foreach ($files as $file) {
					$real = realpath($file);
					if ($real === false || !is_file($real) || !is_readable($real)) continue;
					$size = @filesize($real);
					$mtime = @filemtime($real);
					if ($size === false || $mtime === false) continue;
					$captures[] = [
						'path' => $real,
						'name' => basename($real),
						'size_bytes' => (int)$size,
						'mtime' => (int)$mtime,
						'when' => date('Y-m-d H:i:s', (int)$mtime),
					];
				}
			}
		}

		usort($captures, function($a, $b) {
			if ($a['mtime'] === $b['mtime']) return strcmp($a['name'], $b['name']);
			return ($a['mtime'] > $b['mtime']) ? -1 : 1;
		});

		$count = count($captures);
		$showAll = !empty($params['all']);
		$limit = isset($params['limit']) ? (int)$params['limit'] : 25;
		$shown = $showAll ? $count : min($limit, $count);
		$captures = $showAll ? $captures : array_slice($captures, 0, $shown);

		return [
			'status' => 'ok',
			'count' => $count,
			'shown' => count($captures),
			'captures' => $captures,
		];
	}

	private function captureBases() {
		return [
			'/var/spool/asterisk/frogman/captures',
			'/var/spool/asterisk/packetcapture',
		];
	}
}
