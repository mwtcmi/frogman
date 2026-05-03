<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetLicenseInfo extends AbstractTool {
	public function name() { return 'fm_get_license_info'; }
	public function description() { return 'Get system activation and license information.'; }
	public function validate($params) { return true; }
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$output = []; $exitCode = 0;
		exec('script -qc "/usr/sbin/fwconsole sa info --no-ansi 2>&1" /dev/null', $output, $exitCode);

		// Parse activation info
		$activation = [];
		foreach ($output as $line) {
			$line = trim($line);
			if (preg_match('/^(.+?):\s+(.+)$/', $line, $m)) {
				$activation[trim($m[1])] = trim($m[2]);
			}
		}

		// Get real dates from the license file (same source as sa update table)
		$licFile = [];
		try {
			$licFile = $this->freepbx->Sysadmin->get_licencefile_info() ?: [];
		} catch (\Exception $e) {}

		// Get commercial modules and cross-reference with license data
		$commercial = [];
		$licenseData = [];
		if (\FreePBX::Modules()->moduleHasMethod('sysadmin', 'getModuleLicenseInformation')) {
			$licenseData = $this->freepbx->Sysadmin->getModuleLicenseInformation() ?: [];
		}
		// Build a lookup: module name => licensed (true/false)
		$licensedMap = [];
		foreach ($licenseData as $key => $info) {
			$licensed = isset($info['expires']) && $info['expires'] == 1;
			// Keys are like "areminder_exp", "cdrpro_exp" — strip _exp suffix
			$modKey = preg_replace('/_exp$/', '', $key);
			$licensedMap[$modKey] = $licensed;
			if (!empty($info['name'])) {
				$licensedMap[$info['name']] = $licensed;
			}
		}

		$modules = \FreePBX::Modules()->getActiveModules();
		foreach ($modules as $name => $mod) {
			if (!empty($mod['license']) && stripos($mod['license'], 'Commercial') !== false) {
				$isLicensed = $licensedMap[$name] ?? $licensedMap[$mod['name'] ?? ''] ?? false;
				$commercial[] = [
					'name' => $name,
					'version' => $mod['version'] ?? '',
					'license' => $mod['license'],
					'licensed' => $isLicensed,
				];
			}
		}
		usort($commercial, function($a, $b) { return strcmp($a['name'], $b['name']); });

		// Support contract and registered licenses from CommercialLicense
		$support = [];
		$addons = [];
		$moduleLicenses = [];
		if (\FreePBX::Modules()->moduleHasMethod('sysadmin', 'CommercialLicense')) {
			try {
				$cl = $this->freepbx->Sysadmin->CommercialLicense();
				$contractInfo = $cl->isSupportContractExpired();
				if (is_array($contractInfo)) {
					$support = [
						'expired' => !empty($contractInfo['isExpired']),
						'expiring_soon' => !empty($contractInfo['isAboutToExpired']),
						'expiration_date' => $contractInfo['expiredDate'] ?? null,
					];
				}
				// Parse the HTML content for add-on and module license details
				$html = $cl->loadCommercialLicenseContent();
				if ($html) {
					// Add-on licenses (e.g. Sangoma Phones)
					if (preg_match_all('/<tr[^>]*>.*?<td[^>]*>(.*?)<\/td>.*?<td[^>]*>(\d+)<\/td>.*?<td[^>]*>(\d+)<\/td>.*?<td[^>]*>(\d+)<\/td>.*?<td[^>]*>([\d\-]+)<\/td>/s', $html, $matches, PREG_SET_ORDER)) {
						foreach ($matches as $m) {
							$name = strip_tags(trim($m[1]));
							if (empty($name) || $name === 'Total Licensed') continue;
							$addons[] = [
								'name' => $name,
								'total' => (int)$m[2],
								'used' => (int)$m[3],
								'remaining' => (int)$m[4],
								'expiry' => trim($m[5]),
							];
						}
					}
					// Module licenses (e.g. Zulu)
					if (preg_match('/Module licenses.*?<tbody>(.*?)<\/tbody>/s', $html, $mlMatch)) {
						if (preg_match_all('/<td[^>]*>\s*(.+?)\s*<\/td>/s', $mlMatch[1], $cells)) {
							$vals = array_map(function($v) { return trim(strip_tags($v)); }, $cells[1]);
							// Cells come in groups: name, expiry, current_version, latest_version, status
							$chunks = array_chunk($vals, 5);
							foreach ($chunks as $chunk) {
								if (count($chunk) >= 2 && !empty($chunk[0])) {
									$moduleLicenses[] = [
										'name' => $chunk[0],
										'expiry' => $chunk[1] ?? '',
									];
								}
							}
						}
					}
				}
			} catch (\Exception $e) {}
		}

		return [
			'activated' => $this->freepbx->Sysadmin->isActivated(),
			'activation' => $activation,
			'support_contract' => $support,
			'addon_licenses' => $addons,
			'module_licenses' => $moduleLicenses,
			'commercial_modules' => $commercial,
			'commercial_count' => count($commercial),
		];
	}
}
