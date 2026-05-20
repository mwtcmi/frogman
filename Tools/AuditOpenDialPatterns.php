<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class AuditOpenDialPatterns extends AbstractTool {

	public function name() {
		return 'fm_audit_open_dial_patterns';
	}

	public function description() {
		return 'Audit outbound routes for overly permissive dial patterns that admit unbounded destinations: pure catch-all (_. or _!), wildcards appearing in the first few positions (_X. / _NXX. / _.) so calls can reach any premium-rate, international, or unintended destination. Skips patterns covered by fm_audit_outbound_international (011/0011/00 prefixes). Toll-fraud reachability check — read-only.';
	}

	public function validate($params) {
		return true;
	}

	public function requiredPermission() {
		return null;
	}

	// Surfaces dial-plan permissiveness — same operational sensitivity as the
	// rest of the audit family.
	public function permissionLevel() { return self::PERM_ADMIN; }

	public function execute($params, $context) {
		$routes = $this->freepbx->Core->getAllRoutes();
		$findings = [];
		$counts = ['high' => 0, 'medium' => 0, 'info' => 0];

		foreach ($routes as $route) {
			$routeId = $route['route_id'] ?? $route['routeid'] ?? null;
			$name = (string)($route['name'] ?? '');
			if ($routeId === null) continue;
			if (($route['disabled'] ?? 'off') === 'on') continue;

			$patterns = $this->freepbx->Core->getRoutePatternsByID($routeId);
			if (empty($patterns) || !is_array($patterns)) continue;

			foreach ($patterns as $pat) {
				$prefix = (string)($pat['prepend'] ?? '') . (string)($pat['prefix'] ?? '');
				$match = (string)($pat['match_pattern'] ?? $pat['match_pattern_pass'] ?? '');

				// Pattern as the dialplan actually sees it: prefix + match.
				$combined = trim($prefix . $match);
				if ($combined === '') continue;

				$classification = $this->classifyPattern($combined);
				if ($classification === null) continue;

				$findings[] = [
					'route_id' => (string)$routeId,
					'route_name' => $name,
					'prefix' => $prefix,
					'match_pattern' => $match,
					'combined' => $combined,
					'severity' => $classification['severity'],
					'issue' => $classification['issue'],
					'recommendation' => 'Tighten this dial pattern, add a route password, or restrict the route to a known extension allowlist via Outbound Routes.',
				];
				$counts[$classification['severity']]++;
			}
		}

		$order = ['high' => 0, 'medium' => 1, 'info' => 2];
		usort($findings, function ($a, $b) use ($order) {
			$sevDiff = $order[$a['severity']] - $order[$b['severity']];
			if ($sevDiff !== 0) return $sevDiff;
			return strnatcmp($a['route_id'], $b['route_id']);
		});

		return [
			'count' => count($findings),
			'severity_counts' => $counts,
			'findings' => $findings,
			'summary' => $this->summary($findings, $counts),
		];
	}

	/**
	 * Classify a dial pattern. Returns null for "looks fine," or an array with
	 * severity/issue if the pattern is overly permissive.
	 *
	 * FreePBX dial-pattern syntax:
	 *   _   prefix indicates this is a pattern (not a literal)
	 *   X   any digit 0-9
	 *   Z   any digit 1-9
	 *   N   any digit 2-9
	 *   .   one or more of anything (catch-all wildcard)
	 *   !   zero or more of anything (catch-all wildcard, even greedier)
	 *   [r] character class — single position
	 *   d   any other char = literal match for that digit/symbol
	 */
	private function classifyPattern($pattern) {
		$pattern = (string)$pattern;
		if ($pattern === '' || $pattern[0] !== '_') {
			// Literal pattern — finite, no wildcards. Safe.
			return null;
		}
		$body = substr($pattern, 1);
		if ($body === '') return null;

		// Skip patterns already owned by fm_audit_outbound_international.
		if (preg_match('/^(011|0011|00[^0-9])/', $body) || strpos($body, '011') === 0 || strpos($body, '00') === 0) {
			return null;
		}

		// Skip emergency / well-known short codes that legitimately have no
		// wildcards but might be mis-flagged by a length check.
		$shortCodes = ['911', '999', '112', '411', '611', '711', '811', '*98', '*97'];
		if (in_array($body, $shortCodes, true)) return null;

		// Pure catch-all: _. or _! — accepts ANY input
		if ($body === '.' || $body === '!') {
			return [
				'severity' => 'high',
				'issue' => "Pattern '{$pattern}' is a pure catch-all — every dialed digit string matches",
			];
		}

		// Tokenize the body so [ranges] count as a single position.
		$tokens = $this->tokenize($body);
		if ($tokens === null) return null; // malformed

		// Find the first wildcard token.
		$wildcardIdx = -1;
		foreach ($tokens as $i => $tok) {
			if ($tok === '.' || $tok === '!') {
				$wildcardIdx = $i;
				break;
			}
		}
		if ($wildcardIdx === -1) {
			// Finite-length pattern — bounded by token count. Safe.
			return null;
		}

		$typedLen = $wildcardIdx;

		// Wildcard at position 0 means body starts with . or !, already
		// caught above. Defensive only.
		if ($typedLen === 0) {
			return [
				'severity' => 'high',
				'issue' => "Pattern '{$pattern}' wildcards from position 0 — every dialed digit string matches",
			];
		}

		// 1-2 typed positions before wildcard: high — caller controls almost
		// every digit, can reach premium-rate / unintended destinations.
		if ($typedLen <= 2) {
			return [
				'severity' => 'high',
				'issue' => "Pattern '{$pattern}' has wildcard after only {$typedLen} position(s) — caller controls almost every dialed digit",
			];
		}

		// 3-6 typed positions: medium — narrower than the worst case but still
		// well below NANP minimum (10 digits = NXXNXXXXXX, 7 if local).
		if ($typedLen <= 6) {
			return [
				'severity' => 'medium',
				'issue' => "Pattern '{$pattern}' has wildcard after only {$typedLen} position(s) — narrower than NANP minimum",
			];
		}

		// 7+ typed positions then wildcard: info — common for "dial 1-prefix
		// then anything" trunks; flagging so admins see it, not blocking.
		return [
			'severity' => 'info',
			'issue' => "Pattern '{$pattern}' has wildcard after {$typedLen} position(s) — generally bounded but worth a glance",
		];
	}

	/**
	 * Split a pattern body into tokens, treating [class] as one position.
	 * Returns null if the pattern is malformed (unterminated class).
	 */
	private function tokenize($body) {
		$tokens = [];
		$len = strlen($body);
		$i = 0;
		while ($i < $len) {
			$ch = $body[$i];
			if ($ch === '[') {
				$end = strpos($body, ']', $i);
				if ($end === false) return null;
				$tokens[] = substr($body, $i, $end - $i + 1);
				$i = $end + 1;
			} else {
				$tokens[] = $ch;
				$i++;
			}
		}
		return $tokens;
	}

	private function summary($findings, $counts) {
		if (count($findings) === 0) {
			return 'No overly permissive dial patterns found.';
		}
		$parts = [];
		foreach (['high', 'medium', 'info'] as $sev) {
			if ($counts[$sev] > 0) $parts[] = "{$counts[$sev]} {$sev}";
		}
		return count($findings) . ' permissive pattern(s) found: ' . implode(', ', $parts) . '.';
	}
}
