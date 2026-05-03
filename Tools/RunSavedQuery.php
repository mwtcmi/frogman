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

	public function execute($params, $context) {
		$name = $params['name'];
		$variables = $params['params'] ?? [];

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

		// Get API credentials from database — look for our app or any app with gql scope
		$sth = $db->prepare("SELECT client_id, client_secret FROM api_applications WHERE allowed_scopes LIKE '%gql%' LIMIT 1");
		$sth->execute();
		$app = $sth->fetch(\PDO::FETCH_ASSOC);

		if (empty($app)) {
			// Auto-create an API app for Frogman
			$clientId = 'frogman-' . bin2hex(random_bytes(8));
			$clientSecret = bin2hex(random_bytes(16));
			$sth = $db->prepare("INSERT INTO api_applications (name, description, grant_type, client_id, client_secret, allowed_scopes) VALUES (?, ?, ?, ?, ?, ?)");
			$sth->execute(['Frogman', 'Auto-created by Frogman for GraphQL queries', 'client_credentials', $clientId, $clientSecret, 'gql']);
			$app = ['client_id' => $clientId, 'client_secret' => $clientSecret];
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
