<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class ModuleList extends AbstractTool {
	public function name() { return 'fm_module_list'; }
	public function description() { return 'List installed FreePBX modules with version and status. Optional filters: status (enabled/disabled), license (commercial/gpl/gpl2/gpl3/agpl/other), all (true to force the full list view in chat), licensing (true to attach Sangoma registration/expiry data + renewal link; implies commercial filter).'; }
	public function validate($params) {
		if (isset($params['status']) && !in_array(strtolower($params['status']), ['enabled', 'disabled', ''])) {
			return 'Parameter "status" must be "enabled" or "disabled"';
		}
		return true;
	}

	// Map a raw license string ("Commercial+", "GPLv3+", "AGPLv3+", etc.) to a canonical bucket.
	// AGPL is checked before GPLv3 because "agplv3" contains "gplv3".
	private function bucketFor($license) {
		$lic = strtolower((string)$license);
		if (strpos($lic, 'commercial') !== false) return 'Commercial';
		if (strpos($lic, 'agpl') !== false) return 'AGPLv3';
		if (strpos($lic, 'gplv3') !== false || strpos($lic, 'gpl3') !== false) return 'GPLv3+';
		if (strpos($lic, 'gplv2') !== false || strpos($lic, 'gpl2') !== false) return 'GPLv2';
		return 'Other';
	}

	// Decide whether a module passes the user's license keyword filter. Keywords:
	//   commercial / agpl / gpl3 / gplv3 / gpl2 / gplv2 / gpl (matches v2+v3 but not AGPL) / other / all
	private function matchesLicense($license, $keyword) {
		if ($keyword === '' || $keyword === 'all') return true;
		$bucket = $this->bucketFor($license);
		switch ($keyword) {
			case 'commercial': return $bucket === 'Commercial';
			case 'agpl':
			case 'agpl3':
			case 'agplv3': return $bucket === 'AGPLv3';
			case 'gpl3':
			case 'gplv3': return $bucket === 'GPLv3+';
			case 'gpl2':
			case 'gplv2': return $bucket === 'GPLv2';
			case 'gpl': return $bucket === 'GPLv3+' || $bucket === 'GPLv2'; // GPL family, not AGPL
			case 'other': return $bucket === 'Other';
			default: return false;
		}
	}

	public function execute($params, $context) {
		$statusFilter = isset($params['status']) ? strtolower($params['status']) : '';
		$licenseFilter = isset($params['license']) ? strtolower($params['license']) : '';
		$showAll = !empty($params['all']);
		$licensing = !empty($params['licensing']);

		// "licensing" implies the commercial bucket (GPL modules have no Sangoma
		// registration to report on). User can still override with an explicit
		// license param if they want a different bucket — but that's a niche.
		if ($licensing && !$licenseFilter) {
			$licenseFilter = 'commercial';
		}

		$mf = \module_functions::create();
		$allMods = $mf->getinfo(false, false, true);
		$modules = [];
		foreach ($allMods as $name => $info) {
			$status = '';
			if (isset($info['status'])) {
				$statusMap = [0 => 'not_installed', 1 => 'disabled', 2 => 'enabled', 3 => 'broken'];
				$status = $statusMap[$info['status']] ?? 'unknown';
			}
			if ($statusFilter && $status !== $statusFilter) continue;
			if ($licenseFilter && !$this->matchesLicense($info['license'] ?? '', $licenseFilter)) continue;
			$modules[] = [
				'name' => $name,
				'version' => $info['version'] ?? '',
				'status' => $status,
				'license' => $info['license'] ?? '',
				'bucket' => $this->bucketFor($info['license'] ?? ''),
			];
		}

		// Check for available upgrades from notifications (no network call)
		$upgrades = [];
		$notif = $this->freepbx->Notifications;
		$updates = $notif->list_update(true);
		foreach ($updates as $item) {
			if (($item['id'] ?? '') === 'NEWUPDATES' && !empty($item['extended_text'])) {
				foreach (explode("\n", $item['extended_text']) as $line) {
					if (preg_match('/^\s*(\S+)\s+(\S+)\s+\(current:\s+(\S+)\)/', trim($line), $m)) {
						$upgrades[$m[1]] = $m[2];
					}
				}
			}
		}

		// Tag modules with available upgrades
		foreach ($modules as &$mod) {
			$mod['upgrade_available'] = $upgrades[$mod['name']] ?? null;
		}
		unset($mod);

		// Licensing cross-reference: only when explicitly requested, because the
		// CommercialLicense HTML parse is comparatively expensive (loads remote
		// content into a string and regex-parses it). Mirrors the data sources
		// used by fm_get_license_info — kept narrowly scoped to commercial rows.
		$support = null;
		$renewalUrl = null;
		$sysadminAvailable = false;
		if ($licensing) {
			$sysadminAvailable = \FreePBX::Modules()->moduleHasMethod('sysadmin', 'getModuleLicenseInformation');
			$licensedMap = [];
			$expiryMap = [];

			if ($sysadminAvailable) {
				try {
					$licenseData = $this->freepbx->Sysadmin->getModuleLicenseInformation() ?: [];
					foreach ($licenseData as $key => $info) {
						$isLicensed = isset($info['expires']) && $info['expires'] == 1;
						$modKey = preg_replace('/_exp$/', '', $key);
						$licensedMap[$modKey] = $isLicensed;
						if (!empty($info['name'])) {
							$licensedMap[$info['name']] = $isLicensed;
						}
					}
				} catch (\Exception $e) {}
			}

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
					$html = $cl->loadCommercialLicenseContent();
					if ($html && preg_match('/Module licenses.*?<tbody>(.*?)<\/tbody>/s', $html, $mlMatch)) {
						if (preg_match_all('/<td[^>]*>\s*(.+?)\s*<\/td>/s', $mlMatch[1], $cells)) {
							$vals = array_map(function($v) { return trim(strip_tags($v)); }, $cells[1]);
							// Cells come in groups: name, expiry, current_version, latest_version, status
							foreach (array_chunk($vals, 5) as $chunk) {
								if (count($chunk) >= 2 && !empty($chunk[0])) {
									$expiryMap[strtolower($chunk[0])] = $chunk[1];
								}
							}
						}
					}
				} catch (\Exception $e) {}
			}

			// Stamp each module with licensed/expiry/expired. Match by module key,
			// rawname, and the display name from the license data — Sangoma keys
			// don't always equal FreePBX module dir names (e.g. cdrpro).
			$today = date('Y-m-d');
			foreach ($modules as &$mod) {
				$key = $mod['name'];
				$mod['licensed'] = $licensedMap[$key] ?? null;
				$expiry = $expiryMap[strtolower($key)] ?? null;
				if (!$expiry) {
					// Try a couple of common aliases — e.g. "Call Recording Reports" vs "cdrpro".
					$rawInfo = $allMods[$key] ?? [];
					$displayName = strtolower($rawInfo['displayName'] ?? $rawInfo['name'] ?? '');
					if ($displayName && isset($expiryMap[$displayName])) {
						$expiry = $expiryMap[$displayName];
					}
				}
				$mod['expiry'] = $expiry;
				$mod['expired'] = ($expiry && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry))
					? ($expiry < $today)
					: false;
			}
			unset($mod);

			$renewalUrl = 'https://portal.sangoma.com/index.php/report/viewReport/283';
		}

		// View hint for the chat formatter: licensing has its own focused view;
		// otherwise show summary unless the user filtered or asked for all.
		if ($licensing) {
			$view = 'licensing';
		} else {
			$view = ($statusFilter || $licenseFilter || $showAll) ? 'list' : 'summary';
		}

		$out = [
			'count' => count($modules),
			'modules' => $modules,
			'upgrades_available' => count($upgrades),
			'view' => $view,
			'license_filter' => $licenseFilter,
			'status_filter' => $statusFilter,
		];
		if ($licensing) {
			$out['licensing'] = true;
			$out['support_contract'] = $support;
			$out['renewal_url'] = $renewalUrl;
			$out['sysadmin_available'] = $sysadminAvailable;
		}
		return $out;
	}
}
