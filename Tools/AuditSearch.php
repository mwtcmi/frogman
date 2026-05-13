<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class AuditSearch extends AbstractTool {

	public function name() {
		return 'fm_audit_search';
	}

	public function description() {
		return 'Search the Frogman audit log. Filters: tool, status (pending/success/error), user_id, session_id, limit (default 25, max 100).';
	}

	public function validate($params) {
		if (isset($params['status']) && !in_array($params['status'], ['pending', 'success', 'error'])) {
			return 'Parameter "status" must be one of: pending, success, error';
		}
		if (isset($params['limit'])) {
			$limit = (int) $params['limit'];
			if ($limit < 1 || $limit > 100) {
				return 'Parameter "limit" must be between 1 and 100';
			}
		}
		return true;
	}

	public function requiredPermission() {
		return null;
	}

	// Audit details routinely carry operational context that read-tier callers
	// shouldn't see: session IDs, full param dumps, tool responses (already
	// redacted for known sensitive keys per GHSA-3p65-2prr-cfvf, but the broader
	// surface — extension secrets in transit, IP addresses, etc. — is still
	// admin-only material). Bumped to PERM_ADMIN.
	public function permissionLevel() { return self::PERM_ADMIN; }

	public function execute($params, $context) {
		$conditions = [];
		$binds = [];

		if (!empty($params['tool'])) {
			$conditions[] = "tool = ?";
			$binds[] = $params['tool'];
		}
		if (!empty($params['status'])) {
			$conditions[] = "status = ?";
			$binds[] = $params['status'];
		}
		if (isset($params['user_id'])) {
			$conditions[] = "user_id = ?";
			$binds[] = (int) $params['user_id'];
		}
		if (!empty($params['session_id'])) {
			$conditions[] = "session_id = ?";
			$binds[] = $params['session_id'];
		}

		$where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
		$limit = isset($params['limit']) ? min((int) $params['limit'], 100) : 25;

		$sql = "SELECT id, tool, params, user_id, session_id, status, intent,
		               created_at, completed_at
		        FROM oc_audit_log
		        {$where}
		        ORDER BY created_at DESC
		        LIMIT {$limit}";

		$db = $this->frogman->FreePBX ?? $this->freepbx;
		$sth = $this->freepbx->Database->prepare($sql);
		$sth->execute($binds);
		$rows = $sth->fetchAll(\PDO::FETCH_ASSOC);

		// Decode params JSON for readability
		foreach ($rows as &$row) {
			$decoded = json_decode($row['params'], true);
			if ($decoded !== null) {
				$row['params'] = $decoded;
			}
			$row['created_at_human'] = date('Y-m-d H:i:s', $row['created_at']);
			if ($row['completed_at']) {
				$row['completed_at_human'] = date('Y-m-d H:i:s', $row['completed_at']);
			}
		}
		unset($row);

		return [
			'count' => count($rows),
			'entries' => $rows,
		];
	}
}
