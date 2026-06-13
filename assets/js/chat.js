if (!window._ocChatLoaded) {
window._ocChatLoaded = true;

// Intercept Mermaid node clicks (href="#oc-cmd:...")
$(document).on('click', 'a[href^="#oc-cmd:"]', function(e) {
	e.preventDefault();
	var cmd = $(this).attr('href').replace('#oc-cmd:', '');
	if (cmd && window._ocSendMessage) {
		window._ocSendMessage(cmd);
	}
});

$(function() {
	var $msgs = $('#oc-messages');
	var $input = $('#oc-input');
	var $send = $('#oc-send');
	var $typing = $('#oc-typing');
	var sessionId = 'web-' + Math.random().toString(36).substr(2, 9);
	var sending = false;
	var commandHistory = [];
	var historyIndex = -1;

	function escapeHtml(s) {
		return $('<div>').text(s).html();
	}

	function formatMarkdown(text) {
		// Mermaid diagrams: ```mermaid ... ```
		text = text.replace(/```mermaid([\s\S]*?)```/g, function(m, code) {
			var id = 'mermaid-' + Math.random().toString(36).substr(2, 8);
			var encoded = btoa(unescape(encodeURIComponent(code.trim())));
			return '<div class="oc-mermaid" id="' + id + '" data-graph="' + encoded + '" style="display:none;"></div>';
		});
		// Regular code blocks
		text = text.replace(/```([\s\S]*?)```/g, function(m, code) {
			return '<pre><code>' + escapeHtml(code.trim()) + '</code></pre>';
		});
		// Download links: {{download:url|filename}}
		// URL is escaped for attribute context AND scheme-checked (block javascript: /
		// data: / vbscript: which would execute on click). Tool responses are
		// admin-tier output, but a malicious admin who can edit a reflected field
		// could otherwise sneak a javascript: URL through.
		text = text.replace(/\{\{download:([^|]+)\|([^}]+)\}\}/g, function(m, url, label) {
			if (/^\s*(javascript|data|vbscript):/i.test(url)) return escapeHtml(label);
			return '<a href="' + escapeHtml(url) + '" download class="oc-download" target="_blank">📥 ' + escapeHtml(label) + '</a>';
		});
		// Inline audio player: {{audio:url|label}} renders as a labeled <audio
		// controls> element. URL is scheme-checked the same way as download links
		// so a tool response can't sneak a javascript:/data: URL into the src
		// attribute. preload=none avoids burning bandwidth on every recording in
		// a long listing — the user only pays the byte cost on actual play.
		text = text.replace(/\{\{audio:([^|]+)\|([^}]+)\}\}/g, function(m, url, label) {
			if (/^\s*(javascript|data|vbscript):/i.test(url)) return escapeHtml(label);
			return '<span class="oc-audio">' +
				'<span class="oc-audio-label">▶ ' + escapeHtml(label) + '</span>' +
				'<audio controls preload="none" src="' + escapeHtml(url) + '"></audio>' +
				'</span>';
		});
		// Clickable commands: {{cmd:command text|display label}}
		text = text.replace(/\{\{cmd:([^|]+)\|([^}]+)\}\}/g, function(m, cmd, label) {
			return '<span class="oc-clickable" data-cmd="' + escapeHtml(cmd) + '">' + escapeHtml(label) + '</span>';
		});
		// Inline `code` — capture body must be escaped before HTML insertion. Without
		// the escape, a tool response containing `<img src=x onerror=alert(1)>` reaches
		// the DOM as an actual <img> element. See GHSA-... (filed at v1.6.6 ship time).
		text = text.replace(/`([^`]+)`/g, function(m, code) { return '<code>' + escapeHtml(code) + '</code>'; });
		// **bold** — same escape requirement.
		text = text.replace(/\*\*(.+?)\*\*/g, function(m, body) { return '<strong>' + escapeHtml(body) + '</strong>'; });
		// Markdown links: [text](url) — URL must be absolute https?:// OR a root-
		// relative path starting with a single `/` (not `//`, which is protocol-
		// relative and could phish to another origin). escapeHtml still wraps the
		// value so a stray `"` can't break out of href="...". javascript:/data:/
		// vbscript: have no leading `/` or `http(s)://` so they fall through to
		// plain text without matching.
		text = text.replace(/\[([^\]]+)\]\(((?:https?:\/\/|\/(?!\/))[^)\s]+)\)/g, function(m, txt, url) {
			return '<a href="' + escapeHtml(url) + '" target="_blank" class="oc-link">' + escapeHtml(txt) + '</a>';
		});
		text = text.replace(/\n/g, '<br>');
		return text;
	}

	function afterLayout(fn) {
		if (window.requestAnimationFrame) {
			window.requestAnimationFrame(function() {
				window.requestAnimationFrame(fn);
			});
		} else {
			setTimeout(fn, 0);
		}
	}

	function botScrollState() {
		var state = {cancelled: false};
		state.cancel = function() {
			state.cancelled = true;
			state.cleanup();
		};
		state.cleanup = function() {
			$msgs.off('wheel touchstart mousedown keydown', state.cancel);
		};
		$msgs.one('wheel touchstart mousedown keydown', state.cancel);
		return state;
	}

	function finishBotMessage(message, state) {
		if (!message || !message.scrollIntoView) {
			if (state) state.cleanup();
			return;
		}
		afterLayout(function() {
			if (!state || !state.cancelled) {
				message.scrollIntoView({block: 'start', behavior: 'smooth'});
			}
			if (state) state.cleanup();
		});
	}

	function addMessage(text, type) {
		var time = new Date().toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
		var bubble = '<div class="oc-msg oc-msg-' + type + '">' +
			'<div><div class="oc-msg-bubble">' + formatMarkdown(text) + '</div>' +
			'<div class="oc-msg-time">' + time + '</div></div></div>';
		$msgs.append(bubble);
		var newMessage = $msgs.children().last()[0];
		var isBotMessage = type === 'bot' && newMessage;
		var scrollState = isBotMessage ? botScrollState() : null;
		var mermaidNodes = isBotMessage && typeof mermaid !== 'undefined'
			? Array.prototype.slice.call(newMessage.querySelectorAll('.oc-mermaid'))
			: [];
		var pendingMermaid = mermaidNodes.length;
		var finishMermaid = function() {
			pendingMermaid--;
			if (pendingMermaid === 0) finishBotMessage(newMessage, scrollState);
		};
		// Render any Mermaid diagrams
		$msgs.find('.oc-mermaid').each(function() {
			var el = $(this);
			if (!el.data('rendered') && typeof mermaid !== 'undefined') {
				el.data('rendered', true);
				var id = el.attr('id') + '-svg';
				var code = decodeURIComponent(escape(atob(el.data('graph'))));
				var belongsToNewMessage = mermaidNodes.indexOf(el[0]) !== -1;
				try {
					mermaid.render(id, code).then(function(result) {
						el.html(result.svg).show();
						if (belongsToNewMessage) finishMermaid();
					}).catch(function(err) {
						el.text('Diagram error: ' + err.message).show();
						if (belongsToNewMessage) finishMermaid();
					});
				} catch(e) {
					el.text('Diagram error: ' + e.message).show();
					if (belongsToNewMessage) finishMermaid();
				}
			}
		});
		if (isBotMessage) {
			if (pendingMermaid === 0) finishBotMessage(newMessage, scrollState);
		} else {
			$msgs.scrollTop($msgs[0].scrollHeight);
		}
	}

	function sendMessage(text) {
		if (!text.trim() || sending) return;
		// Catch unfilled <placeholder> tokens before they hit the parser, since the
		// parser will just fuzzy-suggest and lose the user's intent.
		var ph = text.match(/<[^<>]+>/);
		if (ph) {
			$input.focus();
			selectFirstPlaceholder($input[0]);
			addMessage("Replace the highlighted `" + ph[0] + "` with the value you want, then send.", 'bot');
			return;
		}
		commandHistory.unshift(text.trim());
		historyIndex = -1;
		sending = true;
		addMessage(text, 'user');
		$input.val('').focus();
		$send.prop('disabled', true);
		$typing.show();

		$.ajax({
			url: 'ajax.php?module=frogman&command=chat',
			method: 'POST',
			contentType: 'application/json',
			data: JSON.stringify({message: text, session_id: sessionId}),
			dataType: 'json',
			success: function(data) {
				$typing.hide();
				$send.prop('disabled', false);
				sending = false;
				addMessage(data.reply || 'No response', 'bot');
				// Hide the red Apply Config bar after a successful reload
				if (data.reply && data.reply.indexOf('reload completed') !== -1) {
					$('#button_reload').hide();
				}
				refreshAudit();
			},
			error: function(xhr) {
				$typing.hide();
				$send.prop('disabled', false);
				sending = false;
				var oops = ['Lost the thread there.', 'Couldn\'t reach the PBX.', 'Something went sideways.'];
				var prefix = oops[Math.floor(Math.random() * oops.length)];
				addMessage('**' + prefix + '** ' + (xhr.statusText || 'Connection failed'), 'bot');
			}
		});
	}

	window._ocSendMessage = sendMessage;

	$send.off('click').on('click', function() {
		sendMessage($input.val());
	});

	// ── Typeahead suggestions (dropdown that filters as you type) ──────────
	// Defensive cast: json_encode in PHP turns numeric strings into JS numbers, which would
	// blow up .toLowerCase() at the first keystroke. Force everything to string here.
	var SUGGESTIONS = (window.FROGMAN_SUGGESTIONS || []).map(function(s) { return String(s); });
	var $typeahead = $('#oc-typeahead');
	var typeaheadActiveIdx = -1;
	var typeaheadCurrent = []; // currently displayed list
	// Don't pop up when the input is one of these (mid-confirm / mid-input-prompt).
	var SUPPRESS_TOKENS = ['yes','y','no','n','cancel','skip','nevermind','nope','abort','ok','sure'];
	var MAX_TYPEAHEAD = 8;

	function typeaheadOpen() { return !$typeahead.prop('hidden') && $typeahead.is(':visible'); }
	function typeaheadHide() { $typeahead.empty().prop('hidden', true); typeaheadActiveIdx = -1; typeaheadCurrent = []; }
	function typeaheadHighlight(text, q) {
		// Bold the matched substring (case-insensitive). Falls back to plain text if no match.
		if (!q) return escapeHtml(text);
		var i = text.toLowerCase().indexOf(q.toLowerCase());
		if (i < 0) return escapeHtml(text);
		return escapeHtml(text.slice(0, i)) + '<b>' + escapeHtml(text.slice(i, i + q.length)) + '</b>' + escapeHtml(text.slice(i + q.length));
	}
	function typeaheadScore(phrase, q) {
		var p = phrase.toLowerCase(), x = q.toLowerCase();
		if (p === x) return 1000;
		if (p.indexOf(x) === 0) return 500;            // prefix match
		if (p.split(/\s+/).some(function(w) { return w.indexOf(x) === 0; })) return 250; // word-prefix
		var i = p.indexOf(x);
		if (i >= 0) return 100 - i;                    // substring (closer = better)
		return -1;
	}
	function typeaheadRefresh() {
		var q = $input.val().trim();
		if (q.length < 2 || SUPPRESS_TOKENS.indexOf(q.toLowerCase()) !== -1) {
			typeaheadHide();
			return;
		}
		var scored = [];
		for (var i = 0; i < SUGGESTIONS.length; i++) {
			var s = SUGGESTIONS[i];
			var score = typeaheadScore(s, q);
			if (score >= 0) scored.push({ phrase: s, score: score });
		}
		if (!scored.length) { typeaheadHide(); return; }
		scored.sort(function(a, b) { return b.score - a.score || a.phrase.length - b.phrase.length; });
		typeaheadCurrent = scored.slice(0, MAX_TYPEAHEAD).map(function(x) { return x.phrase; });
		typeaheadActiveIdx = 0;
		var html = typeaheadCurrent.map(function(p, idx) {
			return '<div class="oc-typeahead-item' + (idx === 0 ? ' active' : '') + '" data-idx="' + idx + '">' + typeaheadHighlight(p, q) + '</div>';
		}).join('');
		$typeahead.html(html).prop('hidden', false);
	}
	// After pasting/picking a template, select the first <placeholder> token so the
	// user can immediately type to replace it. Falls back to cursor-at-end.
	function selectFirstPlaceholder(el) {
		var v = el.value;
		var m = v.match(/<[^<>]+>/);
		if (m) {
			var start = v.indexOf(m[0]);
			el.setSelectionRange(start, start + m[0].length);
		} else {
			el.setSelectionRange(v.length, v.length);
		}
	}
	function typeaheadSelect(idx) {
		if (idx < 0 || idx >= typeaheadCurrent.length) return;
		$input.val(typeaheadCurrent[idx]).focus();
		selectFirstPlaceholder($input[0]);
		typeaheadHide();
	}
	$input.off('input').on('input', function() {
		historyIndex = -1; // any new typing exits history navigation
		typeaheadRefresh();
	});
	$typeahead.off('mousedown').on('mousedown', '.oc-typeahead-item', function(e) {
		e.preventDefault(); // prevent input blur
		typeaheadSelect(parseInt($(this).data('idx'), 10));
	});
	$typeahead.off('mousemove').on('mousemove', '.oc-typeahead-item', function() {
		var idx = parseInt($(this).data('idx'), 10);
		if (idx === typeaheadActiveIdx) return;
		$typeahead.find('.oc-typeahead-item').removeClass('active');
		$(this).addClass('active');
		typeaheadActiveIdx = idx;
	});
	$(document).on('click.octa', function(e) {
		if (!$(e.target).closest('#oc-typeahead, #oc-input').length) typeaheadHide();
	});

	$input.off('keydown').on('keydown', function(e) {
		// Typeahead-aware key handling — when dropdown is open, take over arrows/Enter/Tab.
		if (typeaheadOpen()) {
			if (e.key === 'ArrowDown') {
				e.preventDefault();
				typeaheadActiveIdx = (typeaheadActiveIdx + 1) % typeaheadCurrent.length;
				$typeahead.find('.oc-typeahead-item').removeClass('active').eq(typeaheadActiveIdx).addClass('active');
				return;
			}
			if (e.key === 'ArrowUp') {
				e.preventDefault();
				typeaheadActiveIdx = (typeaheadActiveIdx - 1 + typeaheadCurrent.length) % typeaheadCurrent.length;
				$typeahead.find('.oc-typeahead-item').removeClass('active').eq(typeaheadActiveIdx).addClass('active');
				return;
			}
			if (e.key === 'Tab') {
				e.preventDefault();
				typeaheadSelect(typeaheadActiveIdx);
				return;
			}
			if (e.key === 'Enter' && !e.shiftKey) {
				// Pick suggestion (fill input, don't send) — Shift+Enter sends as-is.
				e.preventDefault();
				typeaheadSelect(typeaheadActiveIdx);
				return;
			}
			if (e.key === 'Escape') {
				e.preventDefault();
				typeaheadHide();
				return;
			}
		}

		// Default behavior — Enter sends, Up/Down navigate history.
		if (e.key === 'Enter' && !e.shiftKey) {
			e.preventDefault();
			sendMessage($input.val());
		} else if (e.key === 'ArrowUp') {
			if (commandHistory.length > 0 && historyIndex < commandHistory.length - 1) {
				e.preventDefault();
				historyIndex++;
				$input.val(commandHistory[historyIndex]);
			}
		} else if (e.key === 'ArrowDown') {
			e.preventDefault();
			if (historyIndex > 0) {
				historyIndex--;
				$input.val(commandHistory[historyIndex]);
			} else {
				historyIndex = -1;
				$input.val('');
			}
		}
	});

	$(document).off('click.ocquick').on('click.ocquick', '.oc-quick-btn', function() {
		var paste = $(this).data('paste');
		if (paste) {
			$input.val(paste).focus();
			selectFirstPlaceholder($input[0]);
			return;
		}
		var cmd = $(this).data('cmd');
		if (cmd) sendMessage(cmd);
	});

	$(document).off('click.occlick').on('click.occlick', '.oc-clickable', function() {
		var cmd = $(this).data('cmd');
		if (cmd) sendMessage(cmd);
	});

	$(document).off('click.ocsidebar').on('click.ocsidebar', '.oc-sidebar-header', function() {
		$(this).next('.oc-sidebar-body').slideToggle(150);
	});

	function refreshAudit() {
		$.ajax({
			url: 'ajax.php?module=frogman&command=audit-feed',
			method: 'GET',
			dataType: 'json',
			success: function(resp) {
				var $audit = $('#oc-audit-list');
				$audit.empty();
				if (resp.entries) {
					resp.entries.forEach(function(e) {
						var cls = e.status === 'success' ? 'success' : 'error';
						$audit.append(
							'<div class="oc-audit-entry">' +
							'<span class="oc-audit-tool">' + escapeHtml(e.tool) + '</span>' +
							'<span class="oc-audit-time">' + escapeHtml(e.time) + '</span><br>' +
							'<span class="oc-audit-status-' + cls + '">' + escapeHtml(e.status) + '</span>' +
							'</div>'
						);
					});
				}
			}
		});
	}

	function refreshTokens() {
		$.ajax({
			url: 'ajax.php?module=frogman&command=tool',
			method: 'POST',
			contentType: 'application/json',
			data: JSON.stringify({tool: 'fm_list_api_tokens', params: {}}),
			dataType: 'json',
			success: function(resp) {
				var $list = $('#oc-tokens-list');
				var $count = $('#oc-tokens-count');
				$list.empty();
				if (!resp || resp.status !== 'success' || !resp.data) {
					$list.append('<div class="oc-tokens-empty">No access to token list</div>');
					$count.text('');
					return;
				}
				var tokens = (resp.data.tokens || []);
				var activeCount = tokens.filter(function(t) { return t.active; }).length;
				$count.text('(' + activeCount + ')');
				$list.append(
					'<div class="oc-tokens-new-row">' +
						'<button class="oc-quick-btn oc-tokens-new" data-paste="create api token <username> with read">+ New token</button>' +
					'</div>'
				);
				if (tokens.length === 0) {
					$list.append('<div class="oc-tokens-empty">No tokens yet</div>');
					return;
				}
				tokens.forEach(function(t) {
					var level = (t.level || 'read').toLowerCase();
					var levelClass = 'oc-tokens-level-' + (['read','write','admin'].indexOf(level) >= 0 ? level : 'read');
					var badges = '';
					if (!t.active)        badges += '<span class="oc-tokens-badge oc-tokens-badge-revoked">revoked</span>';
					else if (t.never_used) badges += '<span class="oc-tokens-badge oc-tokens-badge-never">never used</span>';
					else if (t.stale)      badges += '<span class="oc-tokens-badge oc-tokens-badge-stale">stale</span>';

					// Action buttons follow the two-step destructive UX:
					// active token  → Revoke (soft, keeps paper trail)
					// revoked token → Delete (hard, DROPs the row)
					// There's no "reactivate" tool, so requiring Revoke-first means an
					// accidental Revoke leaves the row recoverable for one more deliberate step.
					var actions = '';
					if (t.active) {
						actions = '<button class="oc-tokens-revoke" data-id="' + escapeHtml(String(t.id)) + '" data-user="' + escapeHtml(t.username) + '">Revoke</button>';
					} else {
						actions = '<button class="oc-tokens-delete" data-id="' + escapeHtml(String(t.id)) + '" data-user="' + escapeHtml(t.username) + '">Delete</button>';
					}

					var desc = t.description ? escapeHtml(t.description) : '<em>no description</em>';

					var detail =
						'<div class="oc-tokens-detail" hidden>' +
							'<div class="oc-tokens-detail-row"><span class="oc-tokens-detail-label">id:</span>' + escapeHtml(String(t.id)) + '</div>' +
							'<div class="oc-tokens-detail-row"><span class="oc-tokens-detail-label">description:</span>' + desc + '</div>' +
							'<div class="oc-tokens-detail-row"><span class="oc-tokens-detail-label">created:</span>' + escapeHtml(t.created_at_human || '') + '</div>' +
							'<div class="oc-tokens-detail-row"><span class="oc-tokens-detail-label">last used:</span>' + escapeHtml(t.last_used_human || 'never') + '</div>' +
							actions +
						'</div>';

					$list.append(
						'<div class="oc-tokens-entry">' +
							'<div class="oc-tokens-row">' +
								'<span class="oc-tokens-user">' + escapeHtml(t.username || '') + '</span>' +
								'<span class="oc-tokens-level ' + levelClass + '">' + escapeHtml(level) + '</span>' +
								'<span class="oc-tokens-last">' + escapeHtml(t.last_used_human || 'never') + '</span>' +
								badges +
							'</div>' +
							detail +
						'</div>'
					);
				});
			},
			error: function() {
				$('#oc-tokens-list').empty().append('<div class="oc-tokens-empty">Could not load tokens</div>');
			}
		});
	}

	// Row click → expand inline detail. Action-button clicks fall through to their
	// own handlers below (which stopPropagation so the row doesn't toggle).
	$(document).off('click.octokens').on('click.octokens', '.oc-tokens-entry', function(e) {
		if ($(e.target).is('.oc-tokens-revoke, .oc-tokens-delete')) return;
		$(this).find('.oc-tokens-detail').toggle();
	});

	function tokenAction(actionLabel, toolName, $btn, confirmMsg) {
		var id = $btn.data('id');
		if (!confirm(confirmMsg)) return;
		var origText = $btn.text();
		$btn.prop('disabled', true).text(actionLabel + '…');
		$.ajax({
			url: 'ajax.php?module=frogman&command=tool',
			method: 'POST',
			contentType: 'application/json',
			data: JSON.stringify({tool: toolName, params: {id: id, confirm: true}}),
			dataType: 'json',
			success: function(resp) {
				if (resp && resp.status === 'success') {
					refreshTokens();
				} else {
					$btn.prop('disabled', false).text(origText);
					alert(actionLabel + ' failed: ' + (resp && resp.message ? resp.message : 'unknown error'));
				}
			},
			error: function(xhr) {
				$btn.prop('disabled', false).text(origText);
				alert(actionLabel + ' failed: ' + (xhr.statusText || 'connection error'));
			}
		});
	}

	$(document).off('click.octokensrevoke').on('click.octokensrevoke', '.oc-tokens-revoke', function(e) {
		e.stopPropagation();
		var $btn = $(this);
		var user = $btn.data('user');
		tokenAction('Revoke', 'fm_revoke_api_token', $btn,
			'Revoke token for "' + user + '"? The bot will stop authenticating. You can still see the row in the list for audit purposes.');
	});

	$(document).off('click.octokensdelete').on('click.octokensdelete', '.oc-tokens-delete', function(e) {
		e.stopPropagation();
		var $btn = $(this);
		var user = $btn.data('user');
		tokenAction('Delete', 'fm_delete_api_token', $btn,
			'Permanently delete the revoked token for "' + user + '"? This drops the row from the database and cannot be undone.');
	});

	// Collapse all sidebar sections on load
	$('.oc-sidebar-body').hide();

	addMessage("Welcome to **Frogman**. Type a command or click a quick action. Type **help** for the full command list.", 'bot');
	sendMessage('status');
	refreshAudit();
	refreshTokens();
});

}
