<?php if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); } ?>
<?php require_once __DIR__ . '/../Tools/ChatParser.php'; ?>
<?php $_fmAssetVer = max(@filemtime(__DIR__ . '/../assets/js/chat.js') ?: 0, @filemtime(__DIR__ . '/../assets/css/chat.css') ?: 0) ?: time(); ?>
<link rel="stylesheet" href="modules/frogman/assets/css/chat.css?v=<?php echo $_fmAssetVer; ?>">
<script src="https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.min.js"></script><!-- CDN: 3.3MB library, not practical to bundle -->
<script>mermaid.initialize({startOnLoad:false, theme:'neutral', securityLevel:'loose'});</script>
<script>window.FROGMAN_SUGGESTIONS = <?php echo json_encode(\FreePBX\modules\Frogman\ChatParser::getSuggestions()); ?>;</script>

<div class="oc-wrapper">
	<div class="oc-chat-panel">
		<div class="oc-chat-header">Frogman Console <span style="float:right;font-weight:normal;font-size:12px;opacity:0.7;">v<?php echo $moduleVersion ?? ''; ?> — <?php echo $toolCount ?? ''; ?> tools</span></div>
		<div class="oc-chat-messages" id="oc-messages"></div>
		<div class="oc-typing" id="oc-typing">Processing...</div>
		<div class="oc-chat-input">
			<input type="text" id="oc-input" placeholder="Type to search commands · ↑↓ to pick · Tab/Enter to fill" autocomplete="off">
			<div class="oc-typeahead" id="oc-typeahead" hidden></div>
			<button id="oc-send">Send</button>
		</div>
	</div>

	<div class="oc-sidebar">
		<div class="oc-sidebar-section">
			<div class="oc-sidebar-header">Wizards</div>
			<div class="oc-sidebar-body">
				<div class="oc-guide-group">
					<button class="oc-quick-btn oc-write-btn" data-cmd="add inbound route">add inbound route</button>
					<button class="oc-quick-btn oc-write-btn" data-cmd="onboard new employee">onboard new employee</button>
					<button class="oc-quick-btn oc-write-btn" data-cmd="set follow me">set follow me</button>
				</div>
			</div>
		</div>
		<div class="oc-sidebar-section">
			<div class="oc-sidebar-header">Commands</div>
			<div class="oc-sidebar-body">
				<div class="oc-guide-group">
					<button class="oc-quick-btn oc-write-btn" data-paste="create extension <ext> for <name>">create extension [ext] for [name]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="forward <ext> to <number>">forward [ext] to [number]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="enable voicemail on <ext>">enable voicemail on [ext]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="block <number>">block [number]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="add inbound route <DID> to <dest>">add inbound route [DID] to [dest]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="call <ext> to <number>">call [ext] to [number]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="add <ext> to ringgroup <grp>">add [ext] to ringgroup [grp]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="enable recording on <ext>">enable recording on [ext]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="toggle daynight <id>">toggle daynight [id]</button>
					<button class="oc-quick-btn oc-write-btn" data-cmd="reload">reload</button>
				</div>
			</div>
		</div>

		<div class="oc-sidebar-section">
			<div class="oc-sidebar-header">Quick Views</div>
			<div class="oc-sidebar-body">
				<button class="oc-quick-btn" data-cmd="active calls">Active Calls</button>
				<button class="oc-quick-btn" data-cmd="status">Dashboard</button>
				<button class="oc-quick-btn" data-cmd="disk space">Disk Space</button>
				<button class="oc-quick-btn" data-cmd="list all dids">All DIDs</button>
				<button class="oc-quick-btn" data-cmd="list settings">Advanced Settings</button>
				<button class="oc-quick-btn" data-cmd="list announcements">Announcements</button>
				<button class="oc-quick-btn" data-cmd="asterisk info">Asterisk Info</button>
				<button class="oc-quick-btn" data-cmd="audit 10">Audit Log</button>
				<button class="oc-quick-btn" data-cmd="list backups">Backups</button>
				<button class="oc-quick-btn" data-cmd="list allowlist">Allowlist</button>
				<button class="oc-quick-btn" data-cmd="list blacklist">Blacklist</button>
				<button class="oc-quick-btn" data-cmd="list callbacks">Callbacks</button>
				<button class="oc-quick-btn" data-cmd="list calendars">Calendars</button>
				<button class="oc-quick-btn" data-cmd="list certificates">Certificates</button>
				<button class="oc-quick-btn" data-cmd="cdr stats">CDR Stats</button>
				<button class="oc-quick-btn" data-cmd="list cid lookup">CID Lookup</button>
				<button class="oc-quick-btn" data-cmd="list conferences">Conferences</button>
				<button class="oc-quick-btn" data-cmd="list contacts">Contacts</button>
				<button class="oc-quick-btn" data-cmd="show dialplan">Custom Dialplan</button>
				<button class="oc-quick-btn" data-cmd="list call flows">Day/Night</button>
				<button class="oc-quick-btn" data-cmd="show templates">Dialplan Templates</button>
				<button class="oc-quick-btn" data-cmd="list extensions">Extensions</button>
				<button class="oc-quick-btn" data-cmd="extension states">Extension States</button>
				<button class="oc-quick-btn" data-cmd="external ip">External IP</button>
				<button class="oc-quick-btn" data-cmd="failed calls">Failed Calls</button>
				<button class="oc-quick-btn" data-cmd="list feature codes">Feature Codes</button>
				<button class="oc-quick-btn" data-cmd="list filestores">Filestores</button>
				<button class="oc-quick-btn" data-cmd="show firewall">Firewall</button>
				<button class="oc-quick-btn" data-cmd="list inbound routes">Inbound Routes</button>
				<button class="oc-quick-btn" data-cmd="list ivrs">IVRs</button>
				<button class="oc-quick-btn" data-cmd="show license">License</button>
				<button class="oc-quick-btn" data-cmd="list destinations">Misc Destinations</button>
				<button class="oc-quick-btn" data-cmd="list modules">Modules</button>
				<button class="oc-quick-btn" data-cmd="list moh">Music on Hold</button>
				<button class="oc-quick-btn" data-cmd="list notifications">Notifications</button>
				<button class="oc-quick-btn" data-cmd="list outbound routes">Outbound Routes</button>
				<button class="oc-quick-btn" data-cmd="list paging groups">Paging</button>
				<button class="oc-quick-btn" data-cmd="list parking">Parking</button>
				<button class="oc-quick-btn" data-cmd="list permissions">Permissions</button>
				<button class="oc-quick-btn" data-cmd="list queues">Queues</button>
				<button class="oc-quick-btn" data-cmd="queue status">Queue Status (live)</button>
				<button class="oc-quick-btn" data-cmd="call history 10">Recent CDR</button>
				<button class="oc-quick-btn" data-cmd="list recording rules">Recording Rules</button>
				<button class="oc-quick-btn" data-cmd="list recordings">Recordings</button>
				<button class="oc-quick-btn" data-cmd="list ringgroups">Ring Groups</button>
				<button class="oc-quick-btn" data-cmd="validate">Security Scan</button>
				<button class="oc-quick-btn" data-cmd="show pm2">Services (PM2)</button>
				<button class="oc-quick-btn" data-cmd="sip channels">SIP Channels</button>
				<button class="oc-quick-btn" data-cmd="registrations">SIP Registrations</button>
				<button class="oc-quick-btn" data-cmd="show sip settings">SIP Settings</button>
				<button class="oc-quick-btn" data-cmd="list sound packs">Sound Packs</button>
				<button class="oc-quick-btn" data-cmd="list speed dials">Speed Dials</button>
				<button class="oc-quick-btn" data-cmd="list time conditions">Time Conditions</button>
				<button class="oc-quick-btn" data-cmd="list trunks">Trunks</button>
				<button class="oc-quick-btn" data-cmd="update activation">Update Activation</button>
				<button class="oc-quick-btn" data-cmd="list voicemails">Voicemails</button>
				<button class="oc-quick-btn" data-cmd="list voicemail settings">Voicemail Settings</button>
			</div>
			<div class="oc-sidebar-header" style="margin-top: 10px;">SIP Tools</div>
			<div class="oc-sidebar-body">
				<button class="oc-quick-btn" data-paste="diagnose ext <ext>">Diagnose Extension</button>
				<button class="oc-quick-btn" data-paste="diagnose trunk <id>">Diagnose Trunk</button>
				<button class="oc-quick-btn" data-paste="endpoint details <ext>">Endpoint Details</button>
				<button class="oc-quick-btn" data-cmd="start sip trace">Start SIP Trace</button>
				<button class="oc-quick-btn" data-cmd="stop trace">Stop SIP Trace</button>
				<button class="oc-quick-btn" data-cmd="trace status">Trace Status</button>
			</div>
		</div>

		<div class="oc-sidebar-section">
			<div class="oc-sidebar-header">Tokens <span id="oc-tokens-count" style="float:right;font-weight:normal;font-size:12px;opacity:0.7;"></span></div>
			<div class="oc-sidebar-body" id="oc-tokens-list" style="max-height: 220px; overflow-y: auto;">
				<div class="oc-tokens-empty" style="color: #999;">Loading...</div>
			</div>
		</div>

		<div class="oc-sidebar-section">
			<div class="oc-sidebar-header">Activity</div>
			<div class="oc-sidebar-body" id="oc-audit-list" style="max-height: 200px; overflow-y: auto;">
				<div class="oc-audit-entry" style="color: #999;">Loading...</div>
			</div>
		</div>
	</div>
</div>

<script src="modules/frogman/assets/js/chat.js?v=<?php echo $_fmAssetVer; ?>"></script>
