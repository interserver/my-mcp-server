# InterServer Client MCP — OAuth 2.1 Setup

This guide connects any major AI app (Claude Desktop, Claude Code, Cursor, Windsurf, VS Code, Zed, opencode, openclaw, and others) to InterServer's **Client** MCP server using **OAuth 2.1**. Customer accounts only — no admin access.

If you want a static API key instead, see `CLIENT_API_KEY.md`. For the admin server, see `ADMIN_OAUTH.md`.

If you only read one sentence: paste the bare MCP URL into your AI app with no headers, hit save, and your browser will open to ask for permission — approve and you are done.

---

## Table of Contents

1. [What This Server Does](#1-what-this-server-does)
2. [Endpoint](#2-endpoint)
3. [How the OAuth Flow Works](#3-how-the-oauth-flow-works)
4. [Scopes You Can Request](#4-scopes-you-can-request)
5. [Client-by-Client Setup](#5-client-by-client-setup)
   - 5.1 [Claude Desktop](#51-claude-desktop)
   - 5.2 [Claude Code (CLI)](#52-claude-code-cli)
   - 5.3 [Cursor](#53-cursor)
   - 5.4 [Windsurf (Codeium)](#54-windsurf-codeium)
   - 5.5 [VS Code (native MCP)](#55-vs-code-native-mcp)
   - 5.6 [VS Code via Cline extension](#56-vs-code-via-cline-extension)
   - 5.7 [VS Code via Continue extension](#57-vs-code-via-continue-extension)
   - 5.8 [Zed](#58-zed)
   - 5.9 [opencode](#59-opencode)
   - 5.10 [openclaw (CLI)](#510-openclaw-cli)
   - 5.11 [Claude.ai web — Custom Connectors](#511-claudeai-web--custom-connectors)
   - 5.12 [LM Studio](#512-lm-studio)
   - 5.13 [Goose (Block)](#513-goose-block)
   - 5.14 [Cody (Sourcegraph)](#514-cody-sourcegraph)
   - 5.15 [Any client (mcp-remote bridge fallback)](#515-any-client--mcp-remote-bridge-fallback)
6. [Validating the Connection](#6-validating-the-connection)
7. [Troubleshooting](#7-troubleshooting)
8. [Security Notes](#8-security-notes)

---

## 1. What This Server Does

InterServer exposes its customer-facing `/apiv2` REST API as the **Client MCP server**. Once connected, your AI app can call hundreds of tools — list VPSes, create domains, file tickets, view invoices, manage DNS — directly from chat.

The Client MCP server is backed by `public_html/spec/openapi.yaml` and uses the MCP **Streamable HTTP** transport. With OAuth, the AI app handles the entire handshake — no headers to copy, no keys to store. You log in via the browser once, the AI app caches the token, and from then on it just works.

> Replace `my.interserver.net` with `mystage.interserver.net` in every example below if you are testing against staging.

---

## 2. Endpoint

```
https://mcp.interserver.net/client
```

The OAuth discovery document (you do not visit this directly — your AI app does):

```
https://mcp.interserver.net/.well-known/oauth-protected-resource
```

---

## 3. How the OAuth Flow Works

In plain English:

1. You add the MCP URL to your AI app **with no auth headers**.
2. The app connects, gets a `401` back with a `WWW-Authenticate` header pointing at the discovery document.
3. The app pops open your browser at `https://my.interserver.net/oauth/bshaffer/authorize.php?...`.
4. You log into InterServer as you normally do, then click **Authorize**.
5. The browser redirects back to a tiny local listener your AI app spins up; the app receives an access token.
6. The app reconnects with `Authorization: Bearer <token>` — done.

You only do this once per app. The token is cached on disk (each app has its own location — see per-client sections).

**Why OAuth over an API key?**

- You never copy a long-lived secret into a config file.
- Every token is **scoped** to exactly the capabilities you grant — e.g. read-only access to VPS data and nothing else.
- You can revoke a token from the InterServer UI the moment a device is lost (`Account → Security → Authorized Apps`).
- Tokens have a built-in expiry.

---

## 4. Scopes You Can Request

The Client MCP server filters its tool list **per request** based on the scopes in your token. Read-only scopes hide every destructive tool — even if the AI is told to call one, the tool will be absent from its list.

Available scopes (request any subset — most AI apps let you pick on the consent screen, others request a default set):

```
basic
account              account:read
affiliate            affiliate:read
backups              backups:read
billing              billing:read
dns                  dns:read
domains              domains:read
floating_ips         floating_ips:read
licenses             licenses:read
mail                 mail:read
quickservers         quickservers:read
scrub_ips            scrub_ips:read
servers              servers:read
ssl                  ssl:read
tickets              tickets:read
vps                  vps:read
webhosting           webhosting:read
```

**Read-only recommendation:** if you only want the AI to look at things — not change them — request the `*:read` variants only. The token will literally not contain the write tools.

**Admin scopes are blocked here.** Tokens scoped to only `admin`/`admin_login` are rejected by the Client MCP server (it tells you to use the admin one). Request non-admin scopes.

---

## 5. Client-by-Client Setup

| Client | Native OAuth flow? | Config file |
|--------|---------------------|-------------|
| Claude Desktop | Yes (Pro+) | UI — no file edit |
| Claude Code (CLI) | Yes | `~/.claude/settings.json` or `.mcp.json` |
| Cursor | Yes | `~/.cursor/mcp.json` or `.cursor/mcp.json` |
| Windsurf | Yes | `~/.codeium/windsurf/mcp_config.json` |
| VS Code (native) | Yes (1.99+) | `.vscode/mcp.json` or user settings |
| Cline (VS Code) | Yes | UI — saved to extension storage |
| Continue | Yes | `~/.continue/config.json` or `config.yaml` |
| Zed | Yes (0.150+) | `~/.config/zed/settings.json` |
| opencode | Yes | `~/.config/opencode/opencode.json` |
| openclaw | Yes | `~/.openclaw/config.toml` |
| Claude.ai web | Yes (paid plans) | UI — no file edit |
| LM Studio | Yes (0.3+) | UI or `~/.cache/lm-studio/mcp.json` |
| Goose | Yes | `~/.config/goose/config.yaml` |
| Cody | stdio only — use bridge | `~/.config/sourcegraph/cody.json` |

---

### 5.1 Claude Desktop

1. Open Claude Desktop → **Settings** → **Connectors** (or **Developer → Custom Connectors**).
2. Click **Add custom connector**.
3. Fill in:
   - **Name:** `InterServer Client`
   - **URL:** `https://mcp.interserver.net/client`
   - Leave the **Advanced → Custom headers** section empty.
4. Click **Connect**. Your browser opens to InterServer's authorize page. Log in, pick the scopes you want, click **Authorize**.
5. Back in Claude Desktop you should see a green dot next to the connector and a tool count in **Settings → Connectors**.

If your build doesn't include the Custom Connectors UI, use the `mcp-remote` bridge instead — see [5.15](#515-any-client--mcp-remote-bridge-fallback) — and edit `claude_desktop_config.json`:

- macOS: `~/Library/Application Support/Claude/claude_desktop_config.json`
- Windows: `%APPDATA%\Claude\claude_desktop_config.json`
- Linux: `~/.config/Claude/claude_desktop_config.json`

---

### 5.2 Claude Code (CLI)

```bash
claude mcp add --transport http interserver-client \
  https://mcp.interserver.net/client
```

The first time you launch a Claude Code session that uses this server, you'll see a prompt to authorize in the browser. Approve, the token is cached, you're done.

**Manual config** (`~/.claude/settings.json` global or `.mcp.json` project-local):

```json
{
  "mcpServers": {
    "interserver-client": {
      "type": "http",
      "url": "https://mcp.interserver.net/client"
    }
  }
}
```

Inside a session, check status with `/mcp`. Token cache: `~/.claude/.credentials.json` (Claude Code handles this — no need to edit).

---

### 5.3 Cursor

- **Global config:** `~/.cursor/mcp.json`
- **Per-project:** `.cursor/mcp.json` at the repo root

```json
{
  "mcpServers": {
    "interserver-client": {
      "url": "https://mcp.interserver.net/client"
    }
  }
}
```

After saving, open Cursor → **Settings (⌘,)** → **Features** → **MCP**. Cursor pops open the browser on first use.

---

### 5.4 Windsurf (Codeium)

Config: `~/.codeium/windsurf/mcp_config.json`

```json
{
  "mcpServers": {
    "interserver-client": {
      "serverUrl": "https://mcp.interserver.net/client"
    }
  }
}
```

**Settings → Cascade → MCP Servers → Refresh** triggers the OAuth flow.

> Older Windsurf builds use `url` instead of `serverUrl`. Rename if refresh fails.

---

### 5.5 VS Code (native MCP)

VS Code 1.99+ has built-in MCP exposed to Copilot **Agent Mode**.

- **Workspace:** `.vscode/mcp.json` at the repo root.
- **User:** Command Palette → `MCP: Open User Configuration`.

```json
{
  "servers": {
    "interserver-client": {
      "type": "http",
      "url": "https://mcp.interserver.net/client"
    }
  }
}
```

Open Copilot Chat → switch dropdown to **Agent** → hit the wrench icon. The first time you call a tool, browser auth opens. Tokens land in the OS keychain.

---

### 5.6 VS Code via Cline extension

1. Install **Cline** from the VS Code marketplace.
2. Click the Cline icon in the sidebar → **MCP Servers** tab → **Edit MCP Settings**.
3. Paste:

```json
{
  "mcpServers": {
    "interserver-client": {
      "type": "streamableHttp",
      "url": "https://mcp.interserver.net/client"
    }
  }
}
```

File lives at:
- macOS: `~/Library/Application Support/Code/User/globalStorage/saoudrizwan.claude-dev/settings/cline_mcp_settings.json`
- Windows: `%APPDATA%\Code\User\globalStorage\saoudrizwan.claude-dev\settings\cline_mcp_settings.json`
- Linux: `~/.config/Code/User/globalStorage/saoudrizwan.claude-dev/settings/cline_mcp_settings.json`

Cline handles the browser flow automatically.

---

### 5.7 VS Code via Continue extension

File: `~/.continue/config.json` (or `config.yaml` on newer builds).

**JSON form:**

```json
{
  "experimental": {
    "modelContextProtocolServers": [
      {
        "transport": {
          "type": "streamable-http",
          "url": "https://mcp.interserver.net/client"
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
```

Open Continue → **gear icon → Reload**.

---

### 5.8 Zed

Config: `~/.config/zed/settings.json` (open with `Cmd+,` / `Ctrl+,`).

```json
{
  "context_servers": {
    "interserver-client": {
      "source": "custom",
      "transport": {
        "type": "http",
        "url": "https://mcp.interserver.net/client"
      }
    }
  }
}
```

Restart Zed (Cmd+Q then re-open). Browser auth pops on first use.

> Older Zed builds (pre-0.150) only spoke stdio MCP. If `"type": "http"` is rejected, fall back to the [mcp-remote bridge in 5.15](#515-any-client--mcp-remote-bridge-fallback).

---

### 5.9 opencode

Config: `~/.config/opencode/opencode.json` (or `opencode.json` in the project root).

```json
{
  "$schema": "https://opencode.ai/config.json",
  "mcp": {
    "interserver-client": {
      "type": "remote",
      "url": "https://mcp.interserver.net/client",
      "enabled": true
    }
  }
}
```

opencode pops the browser on first tool use. Verify with `opencode mcp list` (or inside a session: `/mcp`).

---

### 5.10 openclaw (CLI)

Config: `~/.openclaw/config.toml`

```toml
[mcp.servers.interserver-client]
transport = "http"
url       = "https://mcp.interserver.net/client"
```

Reload openclaw (`openclaw reload` or restart). List with `openclaw mcp ls`.

> Some openclaw builds use `[[mcp_servers]]` array tables instead. `openclaw mcp add --help` always writes the right form for your release.

---

### 5.11 Claude.ai web — Custom Connectors

claude.ai (Pro / Max / Team / Enterprise) supports remote MCP via the **Custom Connectors** UI in **Settings → Connectors**. Free-plan users do not see this option.

1. Click **Add custom connector**.
2. **Name:** `InterServer Client`. **URL:** `https://mcp.interserver.net/client`.
3. Click **Add**. Authorize in the popped-up browser tab.

OAuth is the only option here — Claude.ai web does not accept custom auth headers.

---

### 5.12 LM Studio

LM Studio 0.3+: **Discover → MCP Servers → Add custom server.**

```json
{
  "mcpServers": {
    "interserver-client": {
      "url": "https://mcp.interserver.net/client"
    }
  }
}
```

File: `~/.cache/lm-studio/mcp.json` (or use the UI which writes the same file). LM Studio's auth popup handles the flow.

---

### 5.13 Goose (Block)

Config: `~/.config/goose/config.yaml`

```yaml
extensions:
  interserver-client:
    type: streamable_http
    uri: https://mcp.interserver.net/client
    enabled: true
```

Restart Goose, then `goose session` and `/extensions` to confirm.

---

### 5.14 Cody (Sourcegraph)

Cody only speaks stdio MCP, so you need the `mcp-remote` bridge. File: `~/.config/sourcegraph/cody.json`

```json
{
  "mcp.servers": {
    "interserver-client": {
      "command": "npx",
      "args": [
        "-y",
        "mcp-remote",
        "https://mcp.interserver.net/client"
      ]
    }
  }
}
```

`mcp-remote` handles the browser dance and caches the token at `~/.mcp-auth/`.

---

### 5.15 Any client — `mcp-remote` bridge fallback

If your client only supports stdio MCP servers (older Claude Desktop, Cody, some IDE plugins, custom in-house tools), the universal fix is the `mcp-remote` npm package. It runs as a tiny stdio process, forwards JSON-RPC to a remote HTTP MCP server, **and handles the OAuth flow** transparently.

**Prereqs:** Node.js 18+ installed and `npx` on PATH.

**Generic stdio-server entry:**

```json
{
  "command": "npx",
  "args": [
    "-y",
    "mcp-remote",
    "https://mcp.interserver.net/client"
  ]
}
```

`mcp-remote` caches the OAuth token at `~/.mcp-auth/` keyed by URL. Delete that directory to force re-auth.

---

## 6. Validating the Connection

Whichever client you used, do these two checks.

**Check 1 — list tools.** Inside the AI app, ask:

> *"List the MCP tools available from the interserver-client server."*

A working connection returns tools matching the scopes you granted. If you only requested `vps:read`, you'll see ~5 vps tools and nothing else — that is expected and correct.

**Check 2 — read-only call.** Ask:

> *"Using interserver-client, call the tool that returns my VPS list. Just show me how many I have."*

A successful return means auth + transport + scope filtering are all healthy.

---

## 7. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Browser opens but never redirects back | A firewall or VPN is blocking the loopback callback (typically `http://127.0.0.1:33xxx`) | Disable the VPN for the duration of the auth flow, or punch a localhost rule. |
| Browser opens, redirects, but the app still says "not connected" | Token cache file is owned by root or read-only | `rm -rf ~/.mcp-auth/` (mcp-remote) or the equivalent path for your client; retry. |
| `401 invalid_token` | Token expired | Most clients refresh automatically; if not, force re-auth by clearing the cache and reconnecting. |
| `403 insufficient_scope` with a non-admin token | You only requested `*:read` scopes but the tool you tried is a write tool | Re-authorize with the matching write scope (e.g. `billing` instead of `billing:read`). |
| `403 insufficient_scope` mentioning "admin MCP server" | Token only carries `admin`/`admin_login` scopes | This is the client MCP server. Either re-authorize with non-admin scopes, or use `ADMIN_OAUTH.md` to connect to the admin server. |
| OAuth prompt asks for scopes you didn't request | The OAuth client row in `oauth_clients` allows that exact set; the server downscopes the request but the consent screen reflects the allowed list | Edit the OAuth client row in `oauth_clients` to tighten the allowed scopes (DB admin task). |
| `406 Not Acceptable` | (Server-side) Apache `MultiViews` re-enabled | Report to InterServer support. |
| All MCP traffic returns instantly with no tools | Client cached an old discovery doc | Use the client's **Refresh / Reload Servers** button, or restart the app entirely. |

**Smoking-gun curl** if nothing else helps. Should print 401 + WWW-Authenticate header pointing at `/.well-known/oauth-protected-resource`:

```bash
curl -sS -i -X POST https://mcp.interserver.net/client \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"t","version":"0"}}}'
```

If that does **not** return a `401` with a `WWW-Authenticate: Bearer ... resource_metadata=...` header, the server itself is misconfigured — report it to InterServer support. If it does, the problem is in your client's auth handling.

---

## 8. Security Notes

- **OAuth tokens are scoped.** Prefer `*:read` scopes for any AI you don't fully trust to make changes. The MCP server enforces the scope filter on every request — even if the AI is told to call a destructive tool, the tool will be absent from its list if the token isn't allowed.
- **Token caches live in plain files.** Treat `~/.mcp-auth/`, `~/.claude/.credentials.json`, and equivalent client locations like password files. Make them user-readable only and never sync them through dotfile repos.
- **Revoke on departure.** When a team member leaves or a laptop is lost, revoke the OAuth client from `Account → Security → Authorized Apps`. Rotating the user's password also kills all outstanding refresh tokens.
- **Cloudflare bot challenge on production.** `my.interserver.net` is fronted by Cloudflare; the OAuth authorize page may show a challenge briefly on first visit. If the popup hangs forever, retry against `mystage.interserver.net` to isolate Cloudflare from server issues.
- **Admin scopes are auto-stripped here.** If a buggy client requests `admin` against this URL, the auth server quietly drops it from the granted token rather than failing the flow — but the resulting token won't have any admin power. Use `ADMIN_OAUTH.md` if you actually need admin access.
