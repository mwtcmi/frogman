<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class EditTimeGroup extends AbstractTool {
	public function name() { return 'fm_edit_time_group'; }
	public function description() { return 'Set hours on a time group. Params: id (required), times (required, array of time entries). Each entry: hour_start, minute_start, hour_finish, minute_finish, wday_start (mon-sun), wday_finish (mon-sun). Example: times=[{"hour_start":"09","minute_start":"00","hour_finish":"17","minute_finish":"00","wday_start":"mon","wday_finish":"fri"}]. Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['id'])) return 'Parameter "id" is required (time group ID)';
		if (empty($params['times']) || !is_array($params['times'])) return 'Parameter "times" is required (array of time entries)';
		return true;
	}
	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$id = $params['id'];
		$times = $params['times'];
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;

		// Verify group exists
		$group = $this->freepbx->Timeconditions->getTimeGroup($id);
		if (empty($group)) throw new \Exception("Time group {$id} not found");
		$name = $group[1] ?? $group['description'] ?? $id;

		// Normalize times with defaults
		$normalized = [];
		foreach ($times as $t) {
			$normalized[] = [
				'hour_start' => $t['hour_start'] ?? '*',
				'minute_start' => $t['minute_start'] ?? '*',
				'hour_finish' => $t['hour_finish'] ?? '*',
				'minute_finish' => $t['minute_finish'] ?? '*',
				'wday_start' => $t['wday_start'] ?? '*',
				'wday_finish' => $t['wday_finish'] ?? '*',
				'mday_start' => $t['mday_start'] ?? '*',
				'mday_finish' => $t['mday_finish'] ?? '*',
				'month_start' => $t['month_start'] ?? '*',
				'month_finish' => $t['month_finish'] ?? '*',
			];
		}

		if (!$confirm) {
			$preview = [];
			foreach ($times as $t) {
				$days = ($t['wday_start'] ?? '*') . '-' . ($t['wday_finish'] ?? '*');
				$hours = ($t['hour_start'] ?? '00') . ':' . ($t['minute_start'] ?? '00') . '-' . ($t['hour_finish'] ?? '00') . ':' . ($t['minute_finish'] ?? '00');
				$preview[] = "{$days} {$hours}";
			}
			return ['dry_run' => true, 'message' => "Would set hours on time group \"{$name}\" (ID: {$id}): " . implode(', ', $preview)];
		}

		$this->freepbx->Timeconditions->editTimes($id, $normalized);
		return ['dry_run' => false, 'message' => "Time group \"{$name}\" (ID: {$id}) updated with " . count($normalized) . " time entries.", 'needs_reload' => true];
	}
}
