# my-mcp-server

MCP server for the InterServer **client** and **public** APIs.
Runs at `mcp.interserver.net`; staging at `mcpstage.interserver.net`.

The admin surface lives in a separate repository on a separate host:
[`interserver/my-admin-mcp-server`](https://github.com/interserver/my-admin-mcp-server).

## What is in here

Almost nothing, deliberately. The parser, the proxy, the auth and both protocol
eras live in [`interserver/mcp-openapi-core`](https://github.com/interserver/mcp-openapi-core).
This repository is:

```
config/profiles.php      the two surfaces, as data
config/scope-map.php     section -> module scope (a copy of the API's; see below)
src/Kernel.php           wires that config into the core
public_html/index.php    ~20 lines: superglobals in, response out
public_html/.htaccess
docs/vhost-mcp.conf      the Apache vhost, with the four settings that matter
```

## Endpoints

| Path | Surface | Auth |
|---|---|---|
| `/client` | full client API, scope-filtered | OAuth bearer · `X-API-KEY` · `sessionid` |
| `/public` | 3 allowlisted tools | none |
| `/` | alias for `/client` | — |
| `/.well-known/oauth-protected-resource/{client,public}` | RFC 9728 metadata | none |

`tools/list` on `/client` is readable without a credential, so a connector can be
inspected before login. That is a deliberate difference from the admin server,
where the tool *names* are the thing worth protecting.

## Configuration

| Variable | Purpose |
|---|---|
| `MCP_API_ORIGIN` | where the REST API lives (default `https://my.interserver.net`) |
| `MCP_PUBLIC_ORIGIN` | this server's own origin — **must match the URL users type** |
| `MCP_AUTH_SERVER` | the OAuth authorization server |
| `MCP_INTROSPECTION_URL` | RFC 7662 endpoint on the API |
| `MCP_INTROSPECTION_CLIENT_ID` / `_SECRET` | this server's own confidential client |
| `MCP_REDIS_DSN` | shared sessions + introspection cache |
| `MCP_ALLOWED_HOSTS` | DNS-rebinding allowlist — **not** the CORS origins |
| `MCP_ALLOWED_ORIGINS` | CORS origins |
| `MCP_CACHE_DIR`, `MCP_SESSION_DIR`, `MCP_LOG_FILE` | runtime paths under `var/` |

`MCP_PUBLIC_ORIGIN` is load-bearing: it becomes the RFC 8707 resource identifier
and the `resource` in the protected-resource metadata, and Claude compares that
against the URL the user typed **literally**, path and trailing slash included.
Setting it to the production host on staging is a working server that no client
can authenticate against.

Credentials belong in an environment file readable only by the FPM pool user, not
in the vhost.

## Deploying

```bash
composer install --no-dev
composer warm-cache          # ~600 ms now, or on every cold request forever
```

Run `warm-cache` from the deploy **and** from cron. `Yaml::parse()` on the 1.31 MB
client spec costs ~593 ms; registering the 311 tools it yields costs ~14 ms.

## Testing

```bash
vendor/bin/phpunit
```

| Suite | What it covers |
|---|---|
| `Config` | the profile rows and the scope map, asserted like code |
| `Integration` | real PSR-7 requests through the real front controller, both eras |

`tests/Config/ScopeMapParityTest.php` reads `OAuthScope::$clientSectionScopeMap`
out of the API repo and fails if this repo's copy has drifted. It skips when that
checkout is absent; point `MYADMIN_ROOT` at it to run it.

## Things that will waste your afternoon

- **`CGIPassAuth On`** in the vhost, or the `Authorization` header never reaches
  PHP-FPM and every authenticated request looks anonymous.
- **`Options -MultiViews`**, or `Accept: application/json, text/event-stream` is
  content-negotiated into a 406 before PHP runs.
- **`ProxyErrorOverride off`**, explicitly. With it on, Apache replaces the body of
  every response ≥ 400 with its own HTML page — so `-32020`, `-32021`, `-32022`
  and `-32601` all arrive as empty shells with the right status code. The origin
  vhost has it on; that is why the old endpoint looked broken.
- **A `401` must be an HTTP status.** A `200` carrying `isError` is handed to the
  model as text: no Connect card, no auth prompt, connector appears to do nothing.
- **Don't add `ProtocolVersionMiddleware`** to the front controller's middleware
  list. It runs before the era is classified and only knows handshake revisions,
  so it answers `-32022` to every modern request. The transport applies it to the
  handshake leg itself.
