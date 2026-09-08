<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class RunSavedQuery extends AbstractTool {

	public function name() {
		return 'fm_run_saved_query';
	}

	public function description() {
		return 'Execute a saved GraphQL query by name. Params: name (required), params (optional JSON object of variables to substitute).';
	}

	public function validate($params) {
		if (empty($params['name'])) {
			return 'Parameter "name" is required';
		}
		return true;
	}

	public function requiredPermission() {
		return null;
	}

	// Executes an arbitrary saved GraphQL query — including mutations if any saved
	// query happens to be one. The tool can't tell read queries from writes, so the
	// only safe gate is the caller's trust level. Admin-grade access, not write-with-
	// confirm (a malicious caller just confirms).
	public function permissionLevel() { return self::PERM_ADMIN; }

	public function execute($params, $context) {
		$name = $params['name'];
		$variables = $params['params'] ?? [];
		$apiInfo = \module_functions::create()->getinfo('api', MODULE_STATUS_ENABLED);
		$apiVersion = $apiInfo['api']['dbversion'] ?? null;
		if (!$apiVersion || version_compare($apiVersion, '17.0.1', '<')) {
			return ['error' => 'Saved GraphQL queries require the optional FreePBX API module 17.0.1+ to be installed and enabled.'];
		}

		$db = $this->freepbx->Database;

		// Look up the saved query
		$sth = $db->prepare("SELECT * FROM oc_saved_queries WHERE name = ?");
		$sth->execute([$name]);
		$saved = $sth->fetch(\PDO::FETCH_ASSOC);

		if (empty($saved)) {
			throw new \Exception("Saved query '{$name}' not found");
		}

		$query = $saved['query'];

		if (is_string($variables)) {
			$decoded = json_decode($variables, true);
			if ($decoded === null && $variables !== '{}' && $variables !== '') {
				throw new \Exception("params must be valid JSON");
			}
			$variables = $decoded ?? [];
		}

		// Get API credentials. Stored client_secrets are hashed at rest, so we can't reuse arbitrary
		// existing apps — we cache Frogman's own auto-created app credentials in our module KVStore.
		$cached = $this->freepbx->Frogman->getConfig('api_app_credentials');
		$app = !empty($cached) ? json_decode($cached, true) : null;

		// Validate the cached app still exists in api_applications
		if (!empty($app['client_id'])) {
			$check = $db->prepare("SELECT 1 FROM api_applications WHERE client_id = ? LIMIT 1");
			$check->execute([$app['client_id']]);
			if (!$check->fetch()) {
				$app = null;
			}
		}

		if (empty($app)) {
			$users = $this->freepbx->Userman->getAllUsers();
			if (empty($users)) {
				throw new \Exception("Cannot create API app: no User Manager users exist");
			}
			$ownerId = (int)$users[0]['id'];
			$created = $this->freepbx->Api->applications->add(
				$ownerId,
				'client_credentials',
				'Frogman',
				'Auto-created by Frogman for GraphQL queries',
				null,
				null,
				'gql'
			);
			$app = ['client_id' => $created['client_id'], 'client_secret' => $created['client_secret']];
			$this->freepbx->Frogman->setConfig('api_app_credentials', json_encode($app));
		}

		// Get a token
		$ch = curl_init('http://localhost/admin/api/api/token');
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
			'grant_type' => 'client_credentials',
			'client_id' => $app['client_id'],
			'client_secret' => $app['client_secret'],
		]));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$tokenResponse = curl_exec($ch);
		curl_close($ch);

		$tokenData = json_decode($tokenResponse, true);
		if (empty($tokenData['access_token'])) {
			throw new \Exception("Failed to obtain API token for query execution");
		}

		// Execute GraphQL query
		$payload = json_encode([
			'query' => $query,
			'variables' => !empty($variables) ? $variables : new \stdClass(),
		]);

		$ch = curl_init('http://localhost/admin/api/api/gql');
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Authorization: Bearer ' . $tokenData['access_token'],
			'Content-Type: application/json',
		]);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		$result = json_decode($response, true);

		if ($httpCode !== 200 || isset($result['errors'])) {
			$errors = isset($result['errors']) ? json_encode($result['errors']) : $response;
			throw new \Exception("GraphQL execution error: {$errors}");
		}

		return [
			'query_name' => $name,
			'variables' => $variables,
			'result' => $result['data'] ?? $result,
		];
	}
}
