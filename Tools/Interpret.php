<?php
namespace FreePBX\modules\Frogman;

/**
 * Interpret — natural-language expansion layer for ChatParser.
 *
 * Sits between ChatParser's strict regex waterfall and its fuzzy matcher.
 * Takes phrasing that wouldn't match strict patterns and rewrites it into
 * phrasing that will, so the same tool catalog handles a wider vocabulary
 * without bloating the parser itself.
 *
 * Pure text-in, text-out. No database, no session state, no schema changes.
 *
 * Local mode only in v1. The mode constant exists so future versions can
 * route through a different backend without touching the call site in
 * ChatParser.
 *
 * Disabled cleanly by setting the kvstore key 'frogman_interpret_mode'
 * to 'off'.
 *
 * Parser helper only: this is not an AbstractTool and must not be
 * auto-registered as a callable Frogman tool.
 */
if (!class_exists(__NAMESPACE__ . '\\Interpret', false)) {
class Interpret {

	const MODE_OFF = 'off';
	const MODE_LOCAL = 'local';
	const RISK_READ = 'read';
	const RISK_WRITE = 'write';
	const RISK_STATE = 'state';
	const RISK_UNKNOWN = 'unknown';
	// Reserved for future use: MODE_REMOTE

	/**
	 * Entry point. ChatParser calls this when strict patterns have all missed.
	 *
	 * Returns one of:
	 *   - string: a normalised version of the input. ChatParser should re-parse it.
	 *   - null:   couldn't help. ChatParser falls through to fuzzy matching.
	 */
	public static function expand($msg) {
		$result = self::interpret($msg);
		if ($result && self::shouldRun($result)) {
			return $result['text'];
		}
		return null;
	}

	/**
	 * Structured interpretation result for ChatParser.
	 *
	 * Returns null when Interpret has no useful change. Otherwise:
	 *   text       => normalised command text
	 *   confidence => deterministic confidence score, 0.0-1.0
	 *   risk       => read/write/state/unknown
	 *   reason     => short explanation for audit/debug/review
	 */
	public static function interpret($msg) {
		$mode = self::getMode();
		if ($mode === self::MODE_OFF) {
			return null;
		}
		if ($mode === self::MODE_LOCAL) {
			return self::interpretLocal($msg);
		}
		return null;
	}

	public static function shouldRun($result) {
		if (!is_array($result) || empty($result['text'])) {
			return false;
		}
		$confidence = (float)($result['confidence'] ?? 0);
		$risk = $result['risk'] ?? self::RISK_UNKNOWN;
		$thresholds = [
			self::RISK_READ => 0.78,
			self::RISK_WRITE => 0.88,
			self::RISK_STATE => 0.90,
			self::RISK_UNKNOWN => 0.95,
		];
		return $confidence >= ($thresholds[$risk] ?? $thresholds[self::RISK_UNKNOWN]);
	}

	/**
	 * True when the user is explicitly saying the pending action is wrong.
	 * ChatParser calls this before wizard/confirmation text is consumed as input.
	 */
	public static function isCorrectionCancel($msg) {
		$work = trim($msg);
		$work = self::stripPunctuationNoise($work);
		$work = preg_replace('/\s+/', ' ', trim($work));

		$patterns = [
			'/^(?:no\s*){2,}$/i',
			'/^(?:no+\s+)+no+$/i',
			'/^(?:no\s+thanks|no\s+thank\s+you|not\s+now)$/i',
			'/^(?:no\s+)?(?:you|u)\s+mis+understood$/i',
			'/^(?:that(?:\'s|s| is)\s+)?not\s+what\s+i\s+mea?nt$/i',
			'/^(?:i\s+didn\'?t|i\s+didnt|i\s+did\s+not)\s+mea?nt?\s+(?:that|this)$/i',
			'/^(?:i\s+didn\'?t|i\s+didnt|i\s+did\s+not)\s+say\s+(?:that|this)(?:\s+(?:idiot|stupid|damn|dammit|ffs))?$/i',
			'/^(?:i\s+didn\'?t|i\s+didnt|i\s+did\s+not)\s+ask\s+for\s+(?:that|this)(?:\s+(?:idiot|stupid|damn|dammit|ffs))?$/i',
			'/^(?:not\s+what\s+i\s+asked|not\s+what\s+i\s+wanted)$/i',
			'/^(?:that(?:\'s|s| is)\s+)?(?:the\s+)?wrong\s+(?:thing|command|one)$/i',
			'/^stop\s+(?:that(?:\'s|s| is)\s+)?wrong$/i',
			'/^don\'?t\s+do\s+that$/i',
			'/^cancel\s+that$/i',
		];
		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $work)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Response used when a rewrite changed the input but still did not land on
	 * a strict parser command. This is a low-confidence interpretation, so ask
	 * the user to rephrase instead of guessing.
	 */
	public static function rephrasePrompt($original, $expanded = null) {
		$original = trim((string)$original);
		$expanded = trim((string)$expanded);
		if ($expanded !== '' && strcasecmp($expanded, $original) !== 0) {
			return "I tried reading that as `{$expanded}`, but I'm not sure what command you want. Please rephrase.";
		}
		return "I'm not sure what command you want. Please rephrase.";
	}

	/**
	 * Read the interpret mode from Frogman's FreePBX module config store.
	 * Defaults to 'local'.
	 * Admins can disable by setting it to 'off'.
	 */
	private static function getMode() {
		try {
			$val = \FreePBX::Frogman()->getConfig('frogman_interpret_mode');
			if ($val === self::MODE_OFF || $val === self::MODE_LOCAL) {
				return $val;
			}
		} catch (\Throwable $e) {
			// Module config unavailable — fail safe to local.
		}
		return self::MODE_LOCAL;
	}

	/**
	 * Local expansion. Strips filler, normalises phrasal verbs, and maps
	 * anchored state-of-the-user phrases to PBX actions.
	 *
	 * Each transformation is deliberately anchored so it only fires when
	 * we're confident it's an instruction, not a casual mention.
	 */
	private static function interpretLocal($msg) {
		$original = $msg;
		$work = $msg;
		$confidence = 0.70;
		$risk = self::RISK_READ;
		$reasons = [];

		// 1. Conversation/tone cleanup. These remove wrappers around intent
		// without changing the PBX object/action being requested.
		self::applyRuleGroup($work, self::requestWrapperRules(), $confidence, $risk, $reasons);
		self::applyRuleGroup($work, self::inlinePolitenessRules(), $confidence, $risk, $reasons);

		$before = $work;
		$work = self::expandTonePhrases($work);
		if ($work !== $before) {
			self::markRewrite($confidence, $risk, $reasons, $work === 'help' ? 0.93 : 0.78, $work === 'help' ? self::RISK_READ : null, 'tone wrapper');
		}

		$before = $work;
		$work = self::stripAssistantAbuse($work);
		if ($work !== $before) {
			self::markRewrite($confidence, $risk, $reasons, 0.78, null, 'assistant-directed abuse');
		}

		$before = $work;
		$work = self::stripTrailingTone($work);
		if ($work !== $before) {
			self::markRewrite($confidence, $risk, $reasons, 0.84, null, 'trailing urgency');
		}

		// 2. Vocabulary normalisation. These make user wording look like
		// phrases the strict parser already understands.
		self::applyRuleGroup($work, self::spellingRules(), $confidence, $risk, $reasons);
        self::applyRuleGroup($work, self::phrasalVerbRules(), $confidence, $risk, $reasons);

        $before = $work;
        $work = preg_replace('/^\s*(delete|remove|disable|drop)\s+(\d{3,6})\s*$/i', '$1 extension $2', $work);
        if ($work !== $before) {
	    self::markRewrite($confidence, $risk, $reasons, 0.96, self::RISK_WRITE, 'numeric extension delete shorthand');
        }

        // 3. Intent rewrites. These are stricter, usually anchored, and should
		// land directly on known ChatParser command shapes.
		self::applyRuleGroup($work, self::viewerPhraseRules(), $confidence, $risk, $reasons);
		$before = $work;
		$work = self::expandStatePhrases($work);
		if ($work !== $before) {
			self::markRewrite($confidence, $risk, $reasons, 0.94, self::RISK_STATE, 'extension state phrase');
		}

		// 4. Final cleanup preserves structured values, then command-shape
		// scoring decides whether the rewrite is safe enough to run.
		$work = self::stripPunctuationNoise($work);
		$work = preg_replace('/\s+/', ' ', trim($work));
		$work = trim($work);

		// Only return if we changed something meaningful.
		if ($work !== '' && $work !== $original) {
			$shape = self::scoreCommandShape($work);
			if ($shape) {
				$confidence = max($confidence, $shape['confidence']);
				$risk = $shape['risk'];
				$reasons[] = $shape['reason'];
			}
			return [
				'text' => $work,
				'confidence' => min(1.0, $confidence),
				'risk' => $risk,
				'reason' => implode(', ', array_unique($reasons)),
			];
		}
		return null;
	}

	private static function applyRuleGroup(&$work, $rules, &$confidence, &$risk, &$reasons) {
		foreach ($rules as $rule) {
			$before = $work;
			$work = preg_replace($rule['pattern'], $rule['replacement'], $work);
			if ($work !== $before) {
				self::markRewrite(
					$confidence,
					$risk,
					$reasons,
					$rule['confidence'],
					$rule['risk'] ?? null,
					$rule['reason']
				);
			}
		}
	}

	private static function markRewrite(&$confidence, &$risk, &$reasons, $newConfidence, $newRisk, $reason) {
		$confidence = max($confidence, $newConfidence);
		if ($newRisk !== null) {
			$risk = $newRisk;
		}
		$reasons[] = $reason;
	}

	private static function requestWrapperRules() {
		return [
			['pattern' => '/^\s*(please|pls|plz)\s+/i', 'replacement' => '', 'confidence' => 0.82, 'reason' => 'leading request wrapper'],
			['pattern' => '/^\s*(could\s+you|can\s+you|would\s+you)\s+/i', 'replacement' => '', 'confidence' => 0.82, 'reason' => 'leading request wrapper'],
			['pattern' => '/^\s*(i\s+need\s+to|i\s+want\s+to|i\s+would\s+like\s+to|i\'?d\s+like\s+to)\s+/i', 'replacement' => '', 'confidence' => 0.82, 'reason' => 'leading request wrapper'],
			['pattern' => '/^\s*just\s+/i', 'replacement' => '', 'confidence' => 0.82, 'reason' => 'leading request wrapper'],
		];
	}

	private static function inlinePolitenessRules() {
		return [
			['pattern' => '/\b(please|pls|plz)\b/i', 'replacement' => '', 'confidence' => 0.80, 'reason' => 'inline politeness'],
			['pattern' => '/\b(for\s+me|if\s+you\s+can)\b/i', 'replacement' => '', 'confidence' => 0.80, 'reason' => 'inline politeness'],
		];
	}

	private static function spellingRules() {
		return [
			['pattern' => '/\be-mail\b/i', 'replacement' => 'email', 'confidence' => 0.86, 'reason' => 'spelling normalisation'],
			['pattern' => '/\bcall\s*forward\b/i', 'replacement' => 'call forward', 'confidence' => 0.86, 'reason' => 'spelling normalisation'],
			['pattern' => '/\bfollow\s+me\b/i', 'replacement' => 'followme', 'confidence' => 0.86, 'reason' => 'spelling normalisation'],
		];
	}

	private static function phrasalVerbRules() {
		return [
			['pattern' => '/\b(have\s+a\s+look\s+at|take\s+a\s+look\s+at|look\s+at)\b/i', 'replacement' => 'show', 'confidence' => 0.72, 'risk' => self::RISK_UNKNOWN, 'reason' => 'phrasal verb'],
			['pattern' => '/\b(get\s+me|pull\s+up|bring\s+up|find\s+me)\b/i', 'replacement' => 'show', 'confidence' => 0.72, 'risk' => self::RISK_UNKNOWN, 'reason' => 'phrasal verb'],
			['pattern' => '/\b(sort\s+out|deal\s+with|take\s+care\s+of)\b/i', 'replacement' => 'fix', 'confidence' => 0.72, 'risk' => self::RISK_UNKNOWN, 'reason' => 'phrasal verb'],
			['pattern' => '/\b(spin\s+up|stand\s+up|provision)\b/i', 'replacement' => 'create', 'confidence' => 0.72, 'risk' => self::RISK_UNKNOWN, 'reason' => 'phrasal verb'],
			['pattern' => '/\b(decommission|retire)\b/i', 'replacement' => 'delete', 'confidence' => 0.72, 'risk' => self::RISK_UNKNOWN, 'reason' => 'phrasal verb'],
			['pattern' => '/\b(turn\s+on|switch\s+on)\b/i', 'replacement' => 'enable', 'confidence' => 0.72, 'risk' => self::RISK_UNKNOWN, 'reason' => 'phrasal verb'],
			['pattern' => '/\b(turn\s+off|switch\s+off|shut\s+down|shut\s+off)\b/i', 'replacement' => 'disable', 'confidence' => 0.72, 'risk' => self::RISK_UNKNOWN, 'reason' => 'phrasal verb'],
			['pattern' => '/\b(reboot|bounce|cycle)\b/i', 'replacement' => 'restart', 'confidence' => 0.72, 'risk' => self::RISK_UNKNOWN, 'reason' => 'phrasal verb'],
		];
	}

	private static function viewerPhraseRules() {
		return [
			['pattern' => '/^\s*(show|get|check)\s+me\s+/i', 'replacement' => '$1 ', 'confidence' => 0.91, 'risk' => self::RISK_READ, 'reason' => 'viewer phrase'],
			['pattern' => '/^\s*(what\'?s|what\s+is)\s+(?:the\s+)?(ext|extension)\s+(\d{3,6})\s*$/i', 'replacement' => 'show extension $3', 'confidence' => 0.91, 'risk' => self::RISK_READ, 'reason' => 'viewer phrase'],
			['pattern' => '/^\s*(ext|extension)\s+(\d{3,6})\s*$/i', 'replacement' => 'show extension $2', 'confidence' => 0.91, 'risk' => self::RISK_READ, 'reason' => 'viewer phrase'],
			['pattern' => '/^\s*(who\'?s|who\s+is)\s+on\s+(?:the\s+)?phone\s*$/i', 'replacement' => 'who is on the phone', 'confidence' => 0.91, 'risk' => self::RISK_READ, 'reason' => 'viewer phrase'],
			['pattern' => '/^\s*(current|live)\s+call\s+activity\s*$/i', 'replacement' => 'current calls', 'confidence' => 0.91, 'risk' => self::RISK_READ, 'reason' => 'viewer phrase'],
		];
	}

	private static function scoreCommandShape($msg) {
		$work = trim($msg);
		$shapes = [
			['pattern' => '/^(?:show|get|check|list|who\s+is|current\s+calls|help)\b/i', 'confidence' => 0.90, 'risk' => self::RISK_READ, 'reason' => 'read command shape'],
			['pattern' => '/^(?:health|diagnose|troubleshoot)\b/i', 'confidence' => 0.88, 'risk' => self::RISK_READ, 'reason' => 'diagnostic command shape'],
			['pattern' => '/^(?:enable|disable|set|clear|forward|configure)\b/i', 'confidence' => 0.90, 'risk' => self::RISK_STATE, 'reason' => 'state command shape'],
			['pattern' => '/^(?:create|add|new|rename|update)\b/i', 'confidence' => 0.89, 'risk' => self::RISK_WRITE, 'reason' => 'write command shape'],
			['pattern' => '/^(?:delete|remove|drop|disable)\s+(?:ext|extension)\s+\d{3,6}$/i', 'confidence' => 0.96, 'risk' => self::RISK_WRITE, 'reason' => 'known extension removal command shape'],
		];
		foreach ($shapes as $shape) {
			if (preg_match($shape['pattern'], $work)) {
				return $shape;
			}
		}
		return null;
	}

	/**
	 * Tone-aware normalization. Removes emotional fill, blunt urgency, and
	 * abusive language that would otherwise block a valid rewrite.
	 */
	private static function expandTonePhrases($msg) {
		$work = $msg;

		// Leading rude/urgent preambles before a command.
		$leading = [
			'/^\s*(?:just\s+)?do\s+what\s+(?:I|you)\s+(?:asked|said)(?:[.!?]*\s*)(?:and\s+|then\s+)?/i',
			'/^\s*(?:just\s+)?I\s+told\s+you(?:\s+to)?(?:[.!?]*\s*)(?:and\s+|then\s+)?/i',
			'/^\s*(?:just\s+)?you\s+better(?:[.!?]*\s*)(?:and\s+|then\s+)?/i',
			'/^\s*(?:just\s+)?you\s+need\s+to(?:[.!?]*\s*)(?:and\s+|then\s+)?/i',
			'/^\s*(?:do\s+it\s+now|do\s+it|now|right\s+now)(?:[.!?]*\s*)(?:and\s+|then\s+)?/i',
			'/^\s*(?:listen|look|seriously|honestly)(?:[.!?]*\s*)(?:and\s+|then\s+)?/i',
			'/^\s*(why\s+(?:won\'?t|can\'?t)\s+you\s+just|why\s+(?:won\'?t|can\'?t)\s+you)(?:\s+)?/i',
		];
		$work = preg_replace($leading, '', $work);

		// Remove abusive words that are not essential to intent. Benign words
		// like "just", "now", and "please" are left alone mid-sentence because
		// they can be meaningful in entity names or search text.
		$inlines = [
			'/\b(fucking|damn|bloody|goddamn|shit|hell|bastard|son\s+of\s+a\s+bitch|bitch)\b/i',
		];
		foreach ($inlines as $pattern) {
			$work = preg_replace($pattern, '', $work);
		}

		$work = preg_replace('/\s+/', ' ', trim($work));

		// Remove punctuation left over from tone stripping.
		$work = self::stripPunctuationNoise($work);
		$work = preg_replace('/\s+/', ' ', trim($work));

		// If the entire message is an emotional plea rather than a command,
		// turn it into a help query.
		if (preg_match('/^(?:I(?:\'m|\s+am)\s+(?:stuck|lost|overwhelmed|unsure|blocked|frozen)|I\s+can(?:\'t|not)\s+do\s+this|I\s+need\s+help|help\s+me|this\s+is\s+too\s+much|I\s+can\'t\s+handle\s+this)$/i', $work)) {
			return 'help';
		}

		return $work;
	}

	/**
	 * Remove insults aimed at the assistant, not potentially meaningful
	 * descriptors attached to PBX objects.
	 */
	private static function stripAssistantAbuse($msg) {
		$work = $msg;
		$insults = '(?:idiot|moron|twat|cunt|prick|wanker|dumbass|dumb\s+ass|clown|fool|shitbot|useless\s+(?:bot|machine|thing)|stupid\s+(?:bot|machine|thing))';
		$patterns = [
			'/^\s*(?:you(?:\'re|re| are)?\s+)?' . $insults . '[.!?,-]*\s*/i',
			'/^\s*(?:you(?:\'re|re| are)\s+)?(?:bloody|fucking|damn|goddamn)\s+' . $insults . '[.!?,-]*\s*/i',
			'/[\s,]+(?:you(?:\'re|re| are)?\s+)?' . $insults . '\s*$/',
		];
		foreach ($patterns as $pattern) {
			$work = preg_replace($pattern, ' ', $work);
		}
		return preg_replace('/\s+/', ' ', trim($work));
	}

	/**
	 * Remove end-of-message urgency/tone phrases. Anchoring this at the end
	 * avoids stripping ordinary words from entity names and search text.
	 */
	private static function stripTrailingTone($msg) {
		$work = $msg;
		$patterns = [
			'/\s+(?:quickly|asap|ASAP|right\s+away|straight\s+away|immediately|now|today)\s*$/',
			'/\s+(?:when\s+you\s+can|if\s+you\s+can|for\s+me)\s*$/',
		];
		foreach ($patterns as $pattern) {
			$work = preg_replace($pattern, '', $work);
		}
		return $work;
	}

	/**
	 * State phrases need extension-number anchoring to avoid firing on
	 * unrelated uses of the words. Rewrites must land on strict ChatParser
	 * anchors because the second pass runs with fuzzy matching disabled.
	 * Bare "the call left the queue" → unchanged.
	 */
	private static function expandStatePhrases($msg) {
		$work = $msg;

		// "<ext> is sick/off/out/away (today)" -> "enable dnd on <ext>"
		$work = preg_replace(
			'/\b(\d{3,6})\s+(?:is\s+)?(?:called\s+in\s+)?(sick|off|out|away|unavailable)(?:\s+today)?\b/i',
			'enable dnd on $1',
			$work
		);

		// "<ext> is back/available" -> "disable dnd on <ext>"
		$work = preg_replace(
			'/\b(\d{3,6})\s+is\s+(back|available|in\s+today|back\s+in|back\s+at\s+work)\b/i',
			'disable dnd on $1',
			$work
		);

		// "<ext> is working from home" / "wfh" -> "set followme <ext>"
		// This hits ChatParser's existing Follow Me wizard with ext pre-filled.
		$work = preg_replace(
			'/\b(\d{3,6})\s+is\s+(working\s+from\s+home|wfh|home\s+today|remote\s+today|working\s+remotely|remote)\b/i',
			'set followme $1',
			$work
		);

		return $work;
	}

	/**
	 * Strip conversational punctuation while preserving punctuation that
	 * belongs inside values such as email addresses, IPs, phone numbers,
	 * hostnames, and Asterisk channel names.
	 */
	private static function stripPunctuationNoise($msg) {
		$work = preg_replace('/[!?]+(?=\s|$)/', ' ', $msg);
		$work = preg_replace('/[.,]+(?=\s|$)/', ' ', $work);
		return $work;
	}
}
}
