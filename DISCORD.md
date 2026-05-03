# Frogman Discord Bot Setup

Control your PBX from Discord. Type natural language commands and Frogman executes them.

## Step 1 — Create a Discord Bot

1. Go to https://discord.com/developers/applications
2. Click **New Application** — name it whatever you want
3. Click **Bot** in the left sidebar
4. Click **Reset Token** — copy and save the token
5. Scroll down to **Privileged Gateway Intents** and enable **Message Content Intent**
6. Click **Save Changes**

## Step 2 — Invite the Bot to Your Server

1. Click **OAuth2** in the left sidebar
2. Click **URL Generator**
3. Under **Scopes**, check `bot`
4. Under **Bot Permissions**, check:
   - Send Messages
   - Read Message History
   - Embed Links
5. Copy the generated URL at the bottom
6. Open the URL in your browser and select your Discord server

## Step 3 — Install on Your FreePBX Server

```bash
# Install the Python dependency
pip3 install --break-system-packages discord.py

# Edit the systemd service to add your bot token
nano /etc/systemd/system/frogman-discord.service
```

Find this line and paste your token:
```
Environment=DISCORD_BOT_TOKEN=your_token_here
```

Then start it:
```bash
systemctl daemon-reload
systemctl enable frogman-discord
systemctl start frogman-discord

# Check it's running
systemctl status frogman-discord

# Watch logs
journalctl -u frogman-discord -f
```

## Step 4 — Talk to It

In any channel the bot can see, use `!` prefix or @mention the bot:

```
!list extensions
!1001
!health 1001
!create extension 1005 for Bob Smith
!yes
!active calls
!show ringgroups
!reload
!help
```

In DMs, no prefix needed — just type naturally.

## How It Works

```
Discord message
     │
     ▼
Discord bot (Python, on your PBX server)
     │
     ▼ POST {"message": "list extensions", "session_id": "discord-channel-id"}
     │
Frogman chat endpoint (localhost)
     │
     ├─ ChatParser matches "list extensions" → fm_list_extensions
     ├─ Tool registry: validate → audit → execute → audit
     └─ Format result as readable text
     │
     ▼
Discord reply
```

The bot is ~60 lines of Python. All the intelligence (command parsing, tool execution, audit logging, confirmation gates) lives in Frogman on the PBX.

## Configuration

Environment variables for the systemd service:

| Variable | Default | Description |
|----------|---------|-------------|
| `DISCORD_BOT_TOKEN` | (required) | Your Discord bot token |
| `FROGMAN_CHAT_URL` | `http://localhost/admin/ajax.php?module=frogman&command=chat` | Frogman chat endpoint |
| `FROGMAN_CHANNELS` | (empty = all) | Comma-separated channel names to listen in |

To restrict the bot to specific channels:
```
Environment=FROGMAN_CHANNELS=pbx-admin,frogman
```

## Troubleshooting

**Bot is online but not responding:**
- Make sure **Message Content Intent** is enabled in the Discord developer portal
- Bot only responds to `!commands` or @mentions in server channels (DMs work without prefix)
- Check logs: `journalctl -u frogman-discord -f`

**Bot keeps restarting:**
- Check the token is correct: `systemctl status frogman-discord`
- Check FreePBX is running: `fwconsole status`

**"Error connecting to Frogman":**
- The Frogman module must be installed: `fwconsole ma list | grep frogman`
- Apache must be running: `systemctl status httpd`
