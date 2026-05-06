<?php if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); } ?>
<link rel="stylesheet" href="modules/frogman/assets/css/chat.css">
<script src="https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.min.js"></script><!-- CDN: 3.3MB library, not practical to bundle -->
<script>mermaid.initialize({startOnLoad:false, theme:'neutral', securityLevel:'loose'});</script>

<div class="oc-wrapper">
	<div class="oc-chat-panel">
		<div class="oc-chat-header">Frogman Console <span style="float:right;font-weight:normal;font-size:12px;opacity:0.7;">v<?php echo $moduleVersion ?? ''; ?> — <?php echo $toolCount ?? ''; ?> tools</span></div>
		<div class="oc-chat-messages" id="oc-messages"></div>
		<div class="oc-typing" id="oc-typing">Processing...</div>
		<div class="oc-chat-input">
			<input type="text" id="oc-input" placeholder="Type a command... (try: list extensions)" autocomplete="off">
			<button id="oc-send">Send</button>
		</div>
	</div>

	<div class="oc-sidebar">
		<div class="oc-sidebar-section">
			<div class="oc-sidebar-header">Wizards</div>
			<div class="oc-sidebar-body">
				<div class="oc-guide-group">
					<button class="oc-quick-btn oc-write-btn" data-cmd="add inbound route">add inbound route</button>
					<button class="oc-quick-btn oc-write-btn" data-cmd="set follow me">set follow me</button>
				</div>
			</div>
		</div>
		<div class="oc-sidebar-section">
			<div class="oc-sidebar-header">Commands</div>
			<div class="oc-sidebar-body">
				<div class="oc-guide-group">
					<div class="oc-guide-title">Extensions</div>
					<button class="oc-quick-btn oc-write-btn" data-paste="create extension 1005 for ">create extension [ext] for [name]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="rename extension 1001 to ">rename extension [ext] to [name]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="delete extension ">delete extension [ext]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="enable voicemail on ">enable voicemail on [ext]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="disable voicemail on ">disable voicemail on [ext]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="set cid on 101 to ">set caller ID [ext] to [number]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="clear cid on ">clear caller ID [ext]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="enable recording on ">enable recording [ext]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="disable recording on ">disable recording [ext]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="set ringtimer for 101 to ">set ring timeout [ext] to [seconds]</button>
				</div>
				<div class="oc-guide-group">
					<div class="oc-guide-title">Call Forward & DND</div>
					<button class="oc-quick-btn" data-paste="show forward on ">show forward on [ext]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="forward 1001 to ">forward [ext] to [number]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="clear forward on ">clear forward on [ext]</button>
					<button class="oc-quick-btn" data-paste="show dnd on ">show dnd on [ext]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="enable dnd on ">enable dnd on [ext]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="disable dnd on ">disable dnd on [ext]</button>
				</div>
				<div class="oc-guide-group">
					<div class="oc-guide-title">Follow Me</div>
					<button class="oc-quick-btn oc-write-btn" data-paste="set followme on 1001 to ">set followme on [ext] to [numbers]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="clear followme on ">clear followme on [ext]</button>
				</div>
				<div class="oc-guide-group">
					<div class="oc-guide-title">Inbound Routes</div>
					<button class="oc-quick-btn" data-paste="show inbound route ">show inbound route [DID]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="add inbound route 5551234567 to ">add inbound route [DID] to [dest]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="remove inbound route ">remove inbound route [DID]</button>
				</div>
				<div class="oc-guide-group">
					<div class="oc-guide-title">Ring Groups</div>
					<button class="oc-quick-btn oc-write-btn" data-paste="create ringgroup 700 with ">create ringgroup [num] with [members]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="add 1001 to ringgroup ">add [ext] to ringgroup [id]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="remove 1001 from ringgroup ">remove [ext] from ringgroup [id]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="delete ringgroup ">delete ringgroup [id]</button>
				</div>
				<div class="oc-guide-group">
					<div class="oc-guide-title">Trunks</div>
					<button class="oc-quick-btn oc-write-btn" data-paste="enable trunk ">enable trunk [id]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="disable trunk ">disable trunk [id]</button>
				</div>
				<div class="oc-guide-group">
					<div class="oc-guide-title">Blacklist</div>
					<button class="oc-quick-btn oc-write-btn" data-paste="block ">block [number]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="unblock ">unblock [number]</button>
				</div>
				<div class="oc-guide-group">
					<div class="oc-guide-title">Time Conditions & Day/Night</div>
					<button class="oc-quick-btn oc-write-btn" data-paste="toggle time condition ">toggle time condition [id]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="toggle daynight ">toggle daynight [id]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="set daynight 1 to ">set daynight [id] to [day/night]</button>
				</div>
				<div class="oc-guide-group">
					<div class="oc-guide-title">Misc Destinations</div>
					<button class="oc-quick-btn oc-write-btn" data-paste='add destination "Name" to '>add destination "[name]" to [dial]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="remove destination ">remove destination [id]</button>
				</div>
				<div class="oc-guide-group">
					<div class="oc-guide-title">Module Management</div>
					<button class="oc-quick-btn oc-write-btn" data-paste="install module ">install module [name]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="uninstall module ">uninstall module [name]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="enable module ">enable module [name]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="disable module ">disable module [name]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="upgrade module ">upgrade module [name]</button>
					<button class="oc-quick-btn oc-write-btn" data-cmd="upgrade all modules">upgrade all modules</button>
				</div>
				<div class="oc-guide-group">
					<div class="oc-guide-title">Services & Infrastructure</div>
					<button class="oc-quick-btn oc-write-btn" data-cmd="start freepbx">start freepbx</button>
					<button class="oc-quick-btn oc-write-btn" data-cmd="stop freepbx">stop freepbx</button>
					<button class="oc-quick-btn oc-write-btn" data-cmd="restart freepbx">restart freepbx</button>
					<button class="oc-quick-btn oc-write-btn" data-cmd="fix permissions">fix permissions</button>
					<button class="oc-quick-btn oc-write-btn" data-cmd="system update">system update</button>
					<button class="oc-quick-btn oc-write-btn" data-cmd="sync userman">sync userman</button>
					<button class="oc-quick-btn oc-write-btn" data-cmd="update certificates">update certificates</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="restart service ">restart service [name]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="stop service ">stop service [name]</button>
				</div>
				<div class="oc-guide-group">
					<div class="oc-guide-title">Advanced Settings</div>
					<button class="oc-quick-btn" data-cmd="list settings">list settings</button>
					<button class="oc-quick-btn" data-paste="show setting ">show setting [KEY]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="set setting KEY to ">set setting [KEY] to [VALUE]</button>
				</div>
				<div class="oc-guide-group">
					<div class="oc-guide-title">Firewall & SIP</div>
					<button class="oc-quick-btn oc-write-btn" data-paste="add 10.0.0.0/8 to zone ">add [network] to zone [zone]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="set external ip to ">set external ip to [ip]</button>
				</div>
				<div class="oc-guide-group">
					<div class="oc-guide-title">Dialplan Builder</div>
					<button class="oc-quick-btn oc-write-btn" data-paste="create menu on 8000 press 1 for 600 press 2 for 601">create menu...</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="create time route for 1001 business hours to 600 after hours to voicemail">create time route...</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="send webhook to https://example.com/hook after every call">send webhook...</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="create failover 1001 1002 1003 then voicemail">create failover...</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="create feature code *99 that reads back my extension">create feature code...</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="route calls from 212 to 700">route calls from [pattern]...</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="remove context ">remove context [name]</button>
				</div>
				<div class="oc-guide-group">
					<div class="oc-guide-title">Live Call Control</div>
					<button class="oc-quick-btn oc-write-btn" data-paste="call 1001 to ">call [ext] to [dest]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="hangup ">hangup [channel]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="transfer CHANNEL to ">transfer [channel] to [ext]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="park ">park [channel]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="record ">record [channel]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="stop recording ">stop recording [channel]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="mute ">mute [channel]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="unmute ">unmute [channel]</button>
				</div>
				<div class="oc-guide-group">
					<div class="oc-guide-title">Queue Agents</div>
					<button class="oc-quick-btn oc-write-btn" data-paste="add 1001 to queue ">add [ext] to queue [id]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="remove 1001 from queue ">remove [ext] from queue [id]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="pause 1001 in queue ">pause [ext] in queue [id]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="unpause 1001 in queue ">unpause [ext] in queue [id]</button>
				</div>
				<div class="oc-guide-group">
					<div class="oc-guide-title">Conference Control</div>
					<button class="oc-quick-btn" data-paste="who's in conference ">who's in conference [room]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="kick CHANNEL from conference ">kick [channel] from conference [room]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="lock conference ">lock conference [room]</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="unlock conference ">unlock conference [room]</button>
				</div>
				<div class="oc-guide-group">
					<div class="oc-guide-title">Permissions</div>
					<button class="oc-quick-btn" data-cmd="list permissions">list permissions</button>
					<button class="oc-quick-btn oc-write-btn" data-paste="set permission username to ">set permission [user] to [level]</button>
				</div>
				<div class="oc-guide-group">
					<div class="oc-guide-title">System</div>
					<button class="oc-quick-btn oc-write-btn" data-cmd="reload">reload</button>
					<button class="oc-quick-btn" data-cmd="check reload">check reload needed</button>
					<button class="oc-quick-btn" data-paste="fwconsole ">fwconsole [command]</button>
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
				<button class="oc-quick-btn" data-paste="diagnose ext ">Diagnose Extension</button>
				<button class="oc-quick-btn" data-paste="diagnose trunk ">Diagnose Trunk</button>
				<button class="oc-quick-btn" data-paste="endpoint details ">Endpoint Details</button>
				<button class="oc-quick-btn" data-cmd="start sip trace">Start SIP Trace</button>
				<button class="oc-quick-btn" data-cmd="stop trace">Stop SIP Trace</button>
				<button class="oc-quick-btn" data-cmd="trace status">Trace Status</button>
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

<script src="modules/frogman/assets/js/chat.js"></script>
