<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class SaveQuery extends AbstractTool {

	public function name() {
		return 'fm_save_query';
	}

	public function description() {
		return 'Save a named GraphQL query. Validates that the query parses correctly. Params: name (required), query (required GraphQL string), param_spec (optional JSON describing variable types).';
	}

	public function validate($params) {
		if (empty($params['name'])) {
			return 'Parameter "name" is required';
		}
		if (!preg_match('/^[a-zA-Z0-9_-]+$/', $params['name'])) {
			return 'Parameter "name" must be alphanumeric with hyphens/underscores only';
		}
		if (empty($params['query'])) {
			return 'Parameter "query" is required (GraphQL query string)';
		}
		return true;
	}

	public function requiredPermission() {
		return null;
	}

	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$name = $params['name'];
		$query = $params['query'];
		$paramSpec = $params['param_spec'] ?? '{}';

		// Validate the GraphQL query parses
		$autoloadPath = '/var/www/html/admin/modules/api/vendor/autoload.php';
		if (file_exists($autoloadPath)) {
			require_once $autoloadPath;
			try {
				\GraphQL\Language\Parser::parse($query);
			} catch (\Exception $e) {
				throw new \Exception("GraphQL parse error: " . $e->getMessage());
			}
		}

		// Validate param_spec is valid JSON if provided
		if (!empty($paramSpec) && $paramSpec !== '{}') {
			$decoded = json_decode($paramSpec, true);
			if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
				throw new \Exception("param_spec is not valid JSON: " . json_last_error_msg());
			}
		}

		$db = $this->freepbx->Database;

		// Check if name already exists
		$sth = $db->prepare("SELECT id FROM oc_saved_queries WHERE name = ?");
		$sth->execute([$name]);
		$existing = $sth->fetch(\PDO::FETCH_ASSOC);

		if (!empty($existing)) {
			// Update existing
			$sth = $db->prepare("UPDATE oc_saved_queries SET query = ?, param_spec = ? WHERE name = ?");
			$sth->execute([$query, $paramSpec, $name]);
			return [
				'action' => 'updated',
				'name' => $name,
				'message' => "Saved query '{$name}' updated",
			];
		}

		// Insert new
		$sth = $db->prepare("INSERT INTO oc_saved_queries (name, query, param_spec, created_by, created_at) VALUES (?, ?, ?, ?, ?)");
		$sth->execute([
			$name,
			$query,
			$paramSpec,
			$context['userId'] ?? 0,
			time(),
		]);

		return [
			'action' => 'created',
			'name' => $name,
			'id' => (int) $db->lastInsertId(),
			'message' => "Saved query '{$name}' created",
		];
	}
}
