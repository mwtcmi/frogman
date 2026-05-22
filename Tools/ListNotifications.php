<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ListNotifications extends AbstractTool {
	public function name() { return 'fm_list_notifications'; }
	public function description() { return 'List system notifications by severity. Optional: id (show details for a specific notification).'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		// Single notification detail
		if (!empty($params['id'])) {
			$notif = $this->freepbx->Notifications;
			$all = $notif->list_all();
			foreach ($all as $item) {
				if (($item['id'] ?? '') === $params['id']) {
					return [
						'single' => true,
						'level' => $this->mapLevel($item['level'] ?? ''),
						'module' => $item['module'] ?? '',
						'id' => $item['id'],
						'text' => $item['display_text'] ?? '',
						'details' => trim($item['extended_text'] ?? ''),
						// FreePBX sets candelete=0 on config-error notifications (BADDEST,
						// etc.) — these can only clear by fixing the underlying config, not
						// by user dismissal. The formatter checks this before offering a
						// dismiss chip; fm_delete_notification refuses with a useful error.
						'candelete' => !empty($item['candelete']),
					];
				}
			}
			throw new \Exception("Notification '{$params['id']}' not found");
		}

		$notif = $this->freepbx->Notifications;
		$all = [];
		foreach (['critical', 'security', 'update', 'error', 'warning', 'notice'] as $level) {
			$method = "list_{$level}";
			$items = $notif->$method(true);
			if (!empty($items)) {
				foreach ($items as $item) {
					$extended = trim($item['extended_text'] ?? '');
					$all[] = [
						'level' => $level,
						'module' => $item['module'] ?? '',
						'id' => $item['id'] ?? '',
						'text' => $item['display_text'] ?? '',
						'details' => $extended,
						'candelete' => !empty($item['candelete']),
					];
				}
			}
		}
		return ['count' => count($all), 'notifications' => $all];
	}

	private function mapLevel($level) {
		$map = [100 => 'notice', 200 => 'warning', 300 => 'update', 400 => 'error', 500 => 'critical', 600 => 'security'];
		return $map[$level] ?? (is_string($level) ? $level : 'notice');
	}
}
