<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class DeleteNotification extends AbstractTool {
	public function name() { return 'fm_delete_notification'; }
	public function description() { return 'Dismiss a system notification. Params: id (required); module (optional — looked up from id if omitted). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['id'])) return 'Parameter "id" is required';
		return true;
	}
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$id = $params['id'];
		$module = $params['module'] ?? '';

		// Resolve module + candelete from the BMO. Chat users typing
		// "dismiss notification FW_TAMPERED" only know the id; the underlying BMO
		// needs both module and id, and we also need candelete to refuse early
		// on notifications that FreePBX marks as undismissable (BADDEST, etc.).
		$candelete = null;
		$all = $this->freepbx->Notifications->list_all();
		foreach ($all as $item) {
			if (($item['id'] ?? '') !== $id) continue;
			if ($module !== '' && ($item['module'] ?? '') !== $module) continue;
			$module = $item['module'] ?? $module;
			$candelete = !empty($item['candelete']);
			break;
		}
		if ($candelete === null) {
			throw new \Exception("Notification '{$id}' not found");
		}

		$idSan = $this->frogman->sanitizeForChat($id);
		$modSan = $this->frogman->sanitizeForChat($module);

		// FreePBX flags config-error notifications (BADDEST, etc.) as candelete=0
		// because they reflect actual broken state, not user-tolerable warnings.
		// The BMO's safe_delete silently no-ops on these — the previous behavior
		// surfaced a misleading "dismissed" message. Fail loudly instead, with
		// a pointer to what would actually clear it.
		if (!$candelete) {
			throw new \Exception("Notification `{$idSan}` is marked as undismissable by FreePBX (typically because it reflects an unresolved config issue). Fix the underlying state to clear it.");
		}

		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		if (!$confirm) {
			return ['dry_run' => true, 'message' => "Would dismiss notification `{$idSan}` (`{$modSan}`). Reply yes to confirm."];
		}
		$this->freepbx->Notifications->safe_delete($module, $id);
		return ['dry_run' => false, 'message' => "Notification `{$idSan}` dismissed."];
	}
}
