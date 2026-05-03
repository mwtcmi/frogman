<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetMcpConfig extends AbstractTool {
	public function name() { return 'fm_get_mcp_config'; }
	public function description() { return 'Get MCP connection config for Claude Desktop or Claude Code. Shows copy-paste ready configuration.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$host = gethostname();
		$ip = $_SERVER['SERVER_ADDR'] ?? exec('hostname -I 2>/dev/null | awk \'{print $1}\'');
		$modulePath = '/var/www/html/admin/modules/frogman/mcp-server.php';

		$claudeDesktop = [
			'mcpServers' => [
				'frogman' => [
					'command' => 'ssh',
					'args' => ["root@{$ip}", 'php', $modulePath],
				],
			],
		];

		$claudeCode = "Add to .claude/settings.json or run:\nfrogman MCP: ssh root@{$ip} php {$modulePath}";

		$httpApi = [
			'catalog' => "https://{$ip}/admin/ajax.php?module=frogman&command=catalog",
			'tool' => "https://{$ip}/admin/ajax.php?module=frogman&command=tool",
			'chat' => "https://{$ip}/admin/ajax.php?module=frogman&command=chat",
			'header' => 'X-Frogman-Token: <your-token>',
		];

		return [
			'hostname' => $host,
			'ip' => $ip,
			'mcp_server' => $modulePath,
			'claude_desktop_config' => $claudeDesktop,
			'claude_code' => $claudeCode,
			'http_api' => $httpApi,
		];
	}
}
