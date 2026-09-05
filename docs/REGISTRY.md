# MCP Registry

The client surface is published to the [MCP Registry](https://github.com/modelcontextprotocol/registry)
as `net.interserver/my-mcp-server`.

**The admin server is not, and must not be.** A staff-only endpoint has no place
in a public directory — no registry entry, no server card, no `Link` header, no
`llms.txt` mention.

## Files

| | |
|---|---|
| `server.json` | the manifest, against the `2025-12-11` schema |
| `scripts/registry-key.sh` | generates the Ed25519 namespace key — run once |
| `scripts/publish-registry.sh` | preflight + publish, one command |
| `tests/Config/ServerManifestTest.php` | keeps the manifest inside the constraints |

## First-time setup

1. **Generate the namespace key.** Run once, on a machine where the private key
   can live safely:

   ```bash
   ./scripts/registry-key.sh
   ```

   It writes the key to `~/.config/mcp-registry/interserver-ed25519.pem` (mode
   600, override with `MCP_REGISTRY_KEY`) and prints the DNS record. Back the key
   up — the namespace cannot be reclaimed without it.

2. **Add the TXT record on the apex.** This is the step that goes wrong:

   ```
   interserver.net.  IN  TXT  "v=MCPv1; k=ed25519; p=<base64 pubkey>"
   ```

   On `interserver.net` itself — **not** on a `_mcp-auth.` selector. It is
   SPF-style, not DKIM-style. Putting it on a selector fails at publish time with
   a generic signature error that never mentions DNS, which is an expensive
   afternoon.

3. **Wait for propagation**, then confirm:

   ```bash
   dig +short TXT interserver.net | grep MCPv1
   ```

## Publishing

```bash
./scripts/publish-registry.sh
```

Preflight refuses to publish on any of: description over 100 characters, a
version that looks like a range, a missing signing key, a missing apex TXT
record, or an endpoint that does not resolve. `DRY_RUN=1` runs the checks and
stops.

Requires [`mcp-publisher`](https://github.com/modelcontextprotocol/registry) on
`PATH`.

## Republishing

Bump `version` in `server.json` first. **Published versions are immutable** — the
same version cannot be overwritten, and a version *range* is rejected outright.

The registry API is frozen at v0.1 and in **preview**: the maintainers state that
breaking changes or data resets may occur. That is the reason publishing is a
script rather than a documented sequence of steps — expect to run it again.

## A remotes-only entry needs no package ownership proof

Only namespace authentication, which is what the DNS record provides. There is
no npm/PyPI publish to prove, because nothing is distributed as a package.

## Directory submission is a separate thing

Listing in the Claude directory has its own gate list — PKCE S256 advertised in
AS metadata, a `401` carrying `WWW-Authenticate` with `resource_metadata` (Claude
ignores it on a `200`), a PRM `resource` exactly equal to the URL the user types,
`authorization_servers` whose **first** entry is the one Claude will use, and
port-agnostic loopback redirect URIs. See `mcp_plan.md` §11.4 in the mystage repo.
