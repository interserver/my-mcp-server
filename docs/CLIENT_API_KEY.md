# InterServer Client MCP — API Key Setup

This guide connects any major AI app (Claude Desktop, Claude Code, Cursor, Windsurf, VS Code, Zed, opencode, openclaw, and others) to InterServer's **Client** MCP server using a static **`X-API-KEY`** header. Customer accounts only — no admin access.

If you want an admin connection, see `ADMIN_API_KEY.md`. For the OAuth flow (recommended for shared machines), see `CLIENT_OAUTH.md`.

If you only read one sentence: generate an API key on `my.interserver.net → Account → Security`, then find your AI app in the table below and paste the snippet into the file path shown.

---

## Table of Contents

1. [What This Server Does](#1-what-this-server-does)
2. [Endpoint](#2-endpoint)
3. [Generate Your API Key](#3-generate-your-api-key)
4. [Client-by-Client Setup](#4-client-by-client-setup)
   - 4.1 [Claude Desktop](#41-claude-desktop)
   - 4.2 [Claude Code (CLI)](#42-claude-code-cli)
   - 4.3 [Cursor](#43-cursor)
   - 4.4 [Windsurf (Codeium)](#44-windsurf-codeium)
   - 4.5 [VS Code (native MCP)](#45-vs-code-native-mcp)
   - 4.6 [VS Code via Cline extension](#46-vs-code-via-cline-extension)
   - 4.7 [VS Code via Continue extension](#47-vs-code-via-continue-extension)
   - 4.8 [Zed](#48-zed)
   - 4.9 [opencode](#49-opencode)
   - 4.10 [openclaw (CLI)](#410-openclaw-cli)
   - 4.11 [Claude.ai web](#411-claudeai-web)
   - 4.12 [LM Studio](#412-lm-studio)
   - 4.13 [Goose (Block)](#413-goose-block)
   - 4.14 [Cody (Sourcegraph)](#414-cody-sourcegraph)
   - 4.15 [Any client (mcp-remote bridge fallback)](#415-any-client--mcp-remote-bridge-fallback)
5. [Validating the Connection](#5-validating-the-connection)
6. [Troubleshooting](#6-troubleshooting)
7. [Security Notes](#7-security-notes)

---

## 1. What This Server Does

InterServer exposes its customer-facing `/apiv2` REST API as the **Client MCP server**. Once connected, your AI app can call hundreds of tools — list VPSes, create domains, file tickets, view invoices, manage DNS — directly from chat, without you having to hand-craft curl commands.

The Client MCP server is backed by `public_html/spec/openapi.yaml` and uses the MCP **Streamable HTTP** transport — the same protocol every modern MCP client speaks. No stdio binary needs to be installed; the server lives on InterServer's web tier.

> Replace `my.interserver.net` with `mystage.interserver.net` in every example below if you are testing against staging. The behavior is identical.

---

## 2. Endpoint

```
https://mcp.interserver.net/client
```

That's the URL every AI app needs.

---

## 3. Generate Your API Key

1. Log into `https://my.interserver.net/`.
2. Go to **Account → Security** (or the gear menu → **API Keys**).
3. Click **Generate New API Key**. Give it a label like `Claude Desktop laptop`.
4. **Copy the key immediately** — it is shown only once.
5. Store it in a password manager. You will paste it into the AI app's config.

The key looks roughly like `abc123def456...` (32-64 chars, opaque). Keep it secret. If a key leaks, rotate it from the same page.

**Header name is `X-API-KEY` exactly** (some clients are case-sensitive — match capitalization).

### Smoke-test from the shell

```bash
curl -sS -X POST https://mcp.interserver.net/client \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -H "X-API-KEY: PASTE_YOUR_KEY_HERE" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"curl","version":"1.0"}}}'
```

A successful response is a JSON object whose `result.serverInfo.name` reads `InterServer Client API`. If you see `{"error":"unauthorized",…}` your key is wrong or has been rotated.

---

## 4. Client-by-Client Setup

| Client | Native HTTP MCP? | Config file |
|--------|------------------|-------------|
| Claude Desktop | Yes (Pro+, custom-headers depend on build) | UI or `claude_desktop_config.json` |
| Claude Code (CLI) | Yes | `~/.claude/settings.json` or `.mcp.json` |
| Cursor | Yes | `~/.cursor/mcp.json` or `.cursor/mcp.json` |
| Windsurf | Yes | `~/.codeium/windsurf/mcp_config.json` |
| VS Code (native) | Yes (1.99+) | `.vscode/mcp.json` or user settings |
| Cline (VS Code) | Yes | UI — saved to extension storage |
| Continue | Yes | `~/.continue/config.json` or `config.yaml` |
| Zed | Yes (0.150+) | `~/.config/zed/settings.json` |
| opencode | Yes | `~/.config/opencode/opencode.json` |
| openclaw | Yes | `~/.openclaw/config.toml` |
| Claude.ai web | No custom headers — skip to OAuth | — |
| LM Studio | Yes (0.3+) | `~/.cache/lm-studio/mcp.json` |
| Goose | Yes | `~/.config/goose/config.yaml` |
| Cody | stdio only — use bridge | `~/.config/sourcegraph/cody.json` |

---

### 4.1 Claude Desktop

Claude Desktop on **Pro / Team / Enterprise** plans supports remote MCP servers via the **Custom Connectors** UI. Whether you can paste a custom header depends on your build version.

**If your build exposes Advanced → Custom headers:**

1. Open Claude Desktop → **Settings** → **Connectors** (or **Developer → Custom Connectors**).
2. Click **Add custom connector**.
3. Fill in:
   - **Name:** `InterServer Client`
   - **URL:** `https://mcp.interserver.net/client`
4. Expand **Advanced** → **Custom headers**, add:
   - **Name:** `X-API-KEY`
   - **Value:** *paste your key*
5. Click **Connect**. Connection goes straight through (no browser flow with API-key auth).

**If your build does not expose custom headers** — use the `mcp-remote` bridge in the desktop config file:

- macOS: `~/Library/Application Support/Claude/claude_desktop_config.json`
- Windows: `%APPDATA%\Claude\claude_desktop_config.json`
- Linux: `~/.config/Claude/claude_desktop_config.json`

```json
{
  "mcpServers": {
    "interserver-client": {
      "command": "npx",
      "args": [
        "-y",
        "mcp-remote",
        "https://mcp.interserver.net/client",
        "--header",
        "X-API-KEY:${INTERSERVER_API_KEY}"
      ],
      "env": {
        "INTERSERVER_API_KEY": "paste_your_key_here"
      }
    }
  }
}
```

Restart Claude Desktop after saving.

> Why the `${VAR}` indirection? Some Claude Desktop builds strip raw header values that contain colons or whitespace. The env-var form is the safest.

---

### 4.2 Claude Code (CLI)

One-liner:

```bash
claude mcp add --transport http interserver-client \
  https://mcp.interserver.net/client \
  --header "X-API-KEY: paste_your_key_here"
```

Verify:

```bash
claude mcp list
```

**Manual config** (`~/.claude/settings.json` global or project-local `.mcp.json`):

```json
{
  "mcpServers": {
    "interserver-client": {
      "type": "http",
      "url": "https://mcp.interserver.net/client",
      "headers": {
        "X-API-KEY": "paste_your_key_here"
      }
    }
  }
}
```

Inside a session, check with `/mcp`.

---

### 4.3 Cursor

- **Global config:** `~/.cursor/mcp.json`
- **Per-project:** `.cursor/mcp.json` at the repo root

```json
{
  "mcpServers": {
    "interserver-client": {
      "url": "https://mcp.interserver.net/client",
      "headers": {
        "X-API-KEY": "paste_your_key_here"
      }
    }
  }
}
```

Open Cursor → **Settings (⌘,)** → **Features** → **MCP**. Look for a green dot next to the server name.

---

### 4.4 Windsurf (Codeium)

Config: `~/.codeium/windsurf/mcp_config.json`

```json
{
  "mcpServers": {
    "interserver-client": {
      "serverUrl": "https://mcp.interserver.net/client",
      "headers": {
        "X-API-KEY": "paste_your_key_here"
      }
    }
  }
}
```

After saving: **Settings → Cascade → MCP Servers → Refresh**.

> Older Windsurf builds use `url` instead of `serverUrl`. Rename if the refresh fails.

---

### 4.5 VS Code (native MCP)

VS Code 1.99+ has built-in MCP exposed to Copilot **Agent Mode**.

- **Workspace:** `.vscode/mcp.json` at the repo root.
- **User:** Command Palette → `MCP: Open User Configuration`.

```json
{
  "servers": {
    "interserver-client": {
      "type": "http",
      "url": "https://mcp.interserver.net/client",
      "headers": {
        "X-API-KEY": "${input:interserver-api-key}"
      }
    }
  },
  "inputs": [
    {
      "id": "interserver-api-key",
      "type": "promptString",
      "description": "InterServer API key (from my.interserver.net → Account → Security)",
      "password": true
    }
  ]
}
```

VS Code prompts for the key the first time the server starts and caches it in the OS keychain — so the key never lands in the repo even if `.vscode/mcp.json` is committed.

Open Copilot Chat → switch dropdown to **Agent** → wrench icon shows your servers.

---

### 4.6 VS Code via Cline extension

1. Install **Cline** from the VS Code marketplace.
2. Click the Cline icon in the sidebar → **MCP Servers** tab → **Edit MCP Settings**.
3. Paste:

```json
{
  "mcpServers": {
    "interserver-client": {
      "type": "streamableHttp",
      "url": "https://mcp.interserver.net/client",
      "headers": {
        "X-API-KEY": "paste_your_key_here"
      }
    }
  }
}
```

File lives at:
- macOS: `~/Library/Application Support/Code/User/globalStorage/saoudrizwan.claude-dev/settings/cline_mcp_settings.json`
- Windows: `%APPDATA%\Code\User\globalStorage\saoudrizwan.claude-dev\settings\cline_mcp_settings.json`
- Linux: `~/.config/Code/User/globalStorage/saoudrizwan.claude-dev/settings/cline_mcp_settings.json`

---

### 4.7 VS Code via Continue extension

File: `~/.continue/config.json` (or `config.yaml` on newer builds).

**JSON form:**

```json
{
  "experimental": {
    "modelContextProtocolServers": [
      {
        "transport": {
          "type": "streamable-http",
          "url": "https://mcp.interserver.net/client",
          "headers": {
            "X-API-KEY": "paste_your_key_here"
          }
        }
      }
    ]
  }
}
```

**YAML form (`~/.continue/config.yaml`):**

```yaml
mcpServers:
  - name: interserver-client
    type: streamable-http
    url: https://mcp.interserver.net/client
    requestOptions:
      headers:
        X-API-KEY: paste_your_key_here
```

Open Continue → **gear icon → Reload** after saving.

---

### 4.8 Zed

Config: `~/.config/zed/settings.json` (open with `Cmd+,` / `Ctrl+,`).

```json
{
  "context_servers": {
    "interserver-client": {
      "source": "custom",
      "transport": {
        "type": "http",
        "url": "https://mcp.interserver.net/client",
        "headers": {
          "X-API-KEY": "paste_your_key_here"
        }
      }
    }
  }
}
```

Restart Zed (Cmd+Q then re-open).

> Older Zed builds (pre-0.150) only spoke stdio MCP. If `"type": "http"` is rejected, fall back to the [mcp-remote bridge in 4.15](#415-any-client--mcp-remote-bridge-fallback).

---

### 4.9 opencode

Config: `~/.config/opencode/opencode.json` (or `opencode.json` in the project root).

```json
{
  "$schema": "https://opencode.ai/config.json",
  "mcp": {
    "interserver-client": {
      "type": "remote",
      "url": "https://mcp.interserver.net/client",
      "headers": {
        "X-API-KEY": "paste_your_key_here"
      },
      "enabled": true
    }
  }
}
```

Verify with `opencode mcp list` (or inside a session: `/mcp`).

---

### 4.10 openclaw (CLI)

Config: `~/.openclaw/config.toml`

```toml
[mcp.servers.interserver-client]
transport = "http"
url       = "https://mcp.interserver.net/client"
headers   = { "X-API-KEY" = "paste_your_key_here" }
```

Reload openclaw (`openclaw reload` or restart). List with `openclaw mcp ls`.

> Some openclaw builds use `[[mcp_servers]]` array tables instead. `openclaw mcp add --help` always writes the right form for your release.

---

### 4.11 Claude.ai web

Claude.ai's Custom Connectors UI **does not accept custom auth headers** — only OAuth. You cannot use an API key directly here.

Use `CLIENT_OAUTH.md` instead, or host an `mcp-remote` bridge yourself on a server Claude can reach.

---

### 4.12 LM Studio

LM Studio 0.3+: **Discover → MCP Servers → Add custom server.**

```json
{
  "mcpServers": {
    "interserver-client": {
      "url": "https://mcp.interserver.net/client",
      "headers": {
        "X-API-KEY": "paste_your_key_here"
      }
    }
  }
}
```

File: `~/.cache/lm-studio/mcp.json` (or use the UI which writes the same file).

---

### 4.13 Goose (Block)

Config: `~/.config/goose/config.yaml`

```yaml
extensions:
  interserver-client:
    type: streamable_http
    uri: https://mcp.interserver.net/client
    headers:
      X-API-KEY: paste_your_key_here
    enabled: true
```

Restart Goose, then `goose session` and `/extensions` to confirm.

---

### 4.14 Cody (Sourcegraph)

Cody only speaks stdio MCP, so you need the `mcp-remote` bridge. File: `~/.config/sourcegraph/cody.json`

```json
{
  "mcp.servers": {
    "interserver-client": {
      "command": "npx",
      "args": [
        "-y",
        "mcp-remote",
        "https://mcp.interserver.net/client",
        "--header",
        "X-API-KEY:paste_your_key_here"
      ]
    }
  }
}
```

---

### 4.15 Any client — `mcp-remote` bridge fallback

If your client only supports stdio MCP servers, the universal fix is the `mcp-remote` npm package. It runs as a tiny stdio process and forwards JSON-RPC to a remote HTTP MCP server.

**Prereqs:** Node.js 18+ installed and `npx` on PATH.

**Generic stdio-server entry:**

```json
{
  "command": "npx",
  "args": [
    "-y",
    "mcp-remote",
    "https://mcp.interserver.net/client",
    "--header",
    "X-API-KEY:${INTERSERVER_API_KEY}"
  ],
  "env": {
    "INTERSERVER_API_KEY": "paste_your_key_here"
  }
}
```

---

## 5. Validating the Connection

Whichever client you used, do these two checks.

**Check 1 — list tools.** Inside the AI app, ask:

> *"List the MCP tools available from the interserver-client server."*

A working connection returns a long list (40+ tools) — names like `vps_list`, `getDomain`, `submitTicket`, etc. If you see "no tools" or "not connected," go to [Troubleshooting](#6-troubleshooting).

**Check 2 — read-only call.** Ask:

> *"Using interserver-client, call the tool that returns my VPS list. Just show me how many I have."*

This forces the AI to actually invoke a tool through the MCP transport. A successful return means auth + transport are both healthy end to end.

---

## 6. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| `401 unauthorized` on every call | API key wrong, expired, or rotated | Re-generate at `my.interserver.net → Account → Security`. Header name **must** be `X-API-KEY`. |
| `403 insufficient_scope` | You're hitting the admin URL with a client key | Use `https://mcp.interserver.net/client`, not `/admin/mcp/admin`. |
| `406 Not Acceptable` | (Server-side) `MultiViews` re-enabled on Apache | Report to InterServer support. |
| `connection refused` / `name not resolved` | Typo in URL, or network can't reach `my.interserver.net` | `curl -I https://mcp.interserver.net/client` — anything other than `401 Unauthorized` means a network problem. |
| All MCP traffic returns instantly with no tools | Client cached a stale discovery doc | Use the client's **Refresh / Reload Servers** button, or restart the app entirely. |
| Header seems to vanish in transit | Client strips colons/spaces in raw header values | Switch to the `${VAR}`/`${input:...}` indirection shown in the snippets. |

**Smoking-gun curl** if nothing else helps:

```bash
curl -sS -i -X POST https://mcp.interserver.net/client \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -H "X-API-KEY: PASTE_YOUR_KEY_HERE" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"t","version":"0"}}}'
```

If that returns the server-info JSON, the server + your key are both fine — the problem is in your client config. If it returns `401 unauthorized`, the key is wrong. If it returns anything else (timeout, 5xx), it's a server or network issue.

---

## 7. Security Notes

- **API keys are bearer credentials.** Anyone with the key has the same permissions as the account that issued it. Store them in OS keychains (`${input:...}` in VS Code, `env` blocks in Claude Code) rather than committing JSON configs with embedded keys.
- **Project-level configs are public.** If you commit `.cursor/mcp.json` or `.vscode/mcp.json`, never embed the raw key. Use the `${input:...}` / `${env:...}` indirection so the secret lives in the OS keychain, not the repo.
- **Rotate on departure.** When a team member leaves or a laptop is lost, rotate the API key.
- **API keys have account-wide breadth.** Unlike OAuth tokens, they cannot be scoped to read-only or to a single resource type. If you want per-capability limits, use `CLIENT_OAUTH.md` instead.
- **Cloudflare bot challenge on production.** `my.interserver.net` is fronted by Cloudflare; an MCP client that doesn't follow redirects properly can be 403'd. If your client hangs on connect, retry against `mystage.interserver.net` first to isolate Cloudflare from server issues.
