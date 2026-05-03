<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class BackupCreate extends AbstractTool {

	public function name() {
		return 'fm_backup_create';
	}

	public function description() {
		return 'Run an existing backup job by ID, or list available backup definitions. Params: id (backup ID, optional — omit to list available backups). Requires confirm:true to run.';
	}

	public function validate($params) {
		return true;
	}

	public function requiredPermission() {
		return null;
	}

	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;

		// List available backups
		$backups = $this->freepbx->Backup->listBackups();

		if (empty($params['id'])) {
			if (empty($backups)) {
				return [
					'message' => 'No backup definitions found. Create a backup definition in the FreePBX Backup module first.',
					'backups' => [],
				];
			}

			$list = [];
			foreach ($backups as $b) {
				$list[] = [
					'id' => $b['id'],
					'name' => $b['backup_name'] ?? $b['name'] ?? 'unnamed',
					'description' => $b['backup_description'] ?? $b['description'] ?? '',
				];
			}
			return [
				'message' => 'Available backup definitions. Pass id:<backup_id> and confirm:true to run one.',
				'backups' => $list,
			];
		}

		$backupId = $params['id'];

		if (!$confirm) {
			return [
				'dry_run' => true,
				'message' => "Would run backup ID {$backupId}. Pass confirm:true to execute.",
			];
		}

		// Execute backup via shell
		$output = [];
		$exitCode = 0;
		$cmd = '/usr/sbin/fwconsole backup --backup=' . escapeshellarg($backupId) . ' 2>&1';
		exec($cmd, $output, $exitCode);
		$outputStr = implode("\n", $output);

		if ($exitCode !== 0) {
			throw new \Exception("Backup failed (exit code {$exitCode}): {$outputStr}");
		}

		return [
			'dry_run' => false,
			'message' => "Backup {$backupId} completed",
			'output' => $outputStr,
		];
	}
}
