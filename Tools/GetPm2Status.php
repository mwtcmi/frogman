<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetPm2Status extends AbstractTool {
	public function name() { return 'fm_get_pm2_status'; }
	public function description() { return 'Get PM2 process status for all FreePBX services.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$raw = $this->freepbx->Pm2->listProcesses() ?: [];
		$processes = [];
		foreach ($raw as $p) {
			$env = $p['pm2_env'] ?? [];
			$monit = $p['monit'] ?? [];
			$mem = isset($monit['memory']) ? round($monit['memory'] / 1024 / 1024) : 0;
			$uptime = isset($env['pm_uptime']) ? $env['pm_uptime'] : 0;
			$processes[] = [
				'name' => $p['name'] ?? '?',
				'pid' => $p['pid'] ?? '?',
				'status' => $env['status'] ?? 'unknown',
				'cpu' => $monit['cpu'] ?? 0,
				'memory_mb' => $mem,
				'restarts' => $env['restart_time'] ?? 0,
				'uptime' => $uptime,
			];
		}
		return ['count' => count($processes), 'processes' => $processes];
	}
}
