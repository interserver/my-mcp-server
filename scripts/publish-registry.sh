#!/usr/bin/env bash
#
# Publish server.json to the MCP Registry. One command, because the registry API
# is in preview — "breaking changes or data resets may occur" — so republishing
# is something we should expect to do, not something to reconstruct from notes
# each time.
#
# Preflight is deliberately strict. Every check here is a failure mode that is
# either silent or reports something misleading:
#
#   - a `description` over 100 characters is rejected by the schema, and the
#     server card's own description is far longer, so copying it is the obvious
#     mistake to make
#   - a version that already exists is rejected: published versions are
#     immutable, and version RANGES are rejected outright
#   - a missing or misplaced TXT record fails with a generic signature error
#     that never mentions DNS
#   - publishing before the host resolves lists an endpoint nobody can reach
#
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

KEY_PATH="${MCP_REGISTRY_KEY:-$HOME/.config/mcp-registry/interserver-ed25519.pem}"
MANIFEST="server.json"
APEX="interserver.net"
DRY_RUN="${DRY_RUN:-0}"

fail() { echo "ERROR: $*" >&2; exit 1; }

command -v mcp-publisher >/dev/null 2>&1 \
    || fail "mcp-publisher is not installed. See https://github.com/modelcontextprotocol/registry"

[[ -f "$MANIFEST" ]] || fail "$MANIFEST not found"
python3 -c "import json;json.load(open('$MANIFEST'))" 2>/dev/null \
    || fail "$MANIFEST is not valid JSON"

NAME="$(python3 -c "import json;print(json.load(open('$MANIFEST'))['name'])")"
VERSION="$(python3 -c "import json;print(json.load(open('$MANIFEST'))['version'])")"
DESC_LEN="$(python3 -c "import json;print(len(json.load(open('$MANIFEST'))['description']))")"
URL="$(python3 -c "import json;print(json.load(open('$MANIFEST'))['remotes'][0]['url'])")"

echo "  server:  $NAME"
echo "  version: $VERSION"
echo "  remote:  $URL"

[[ "$DESC_LEN" -le 100 ]] \
    || fail "description is $DESC_LEN characters; the schema caps it at 100"

[[ "$VERSION" != *"^"* && "$VERSION" != *"~"* && "$VERSION" != *"*"* ]] \
    || fail "version '$VERSION' looks like a range; the registry rejects ranges"

# The key proves the namespace. Without it the publish fails as a signature
# error, which reads like a protocol problem rather than a missing file.
[[ -f "$KEY_PATH" ]] \
    || fail "no signing key at $KEY_PATH — run scripts/registry-key.sh (once), or set MCP_REGISTRY_KEY"

# Apex, not a selector. This is the check that saves the most time.
if ! dig +short TXT "$APEX" 2>/dev/null | grep -q "MCPv1"; then
    fail "no 'v=MCPv1' TXT record on the apex $APEX.
       It must be on the apex itself, NOT on a _mcp-auth. selector — SPF-style,
       not DKIM-style. Wrong placement fails later with a generic signature error.
       Run scripts/registry-key.sh to print the record."
fi

# Publishing a URL that does not resolve lists a dead server in a public directory.
HOST="$(printf '%s' "$URL" | sed -E 's#^https?://([^/]+).*#\1#')"
if ! dig +short "$HOST" | grep -qE '[0-9a-f]'; then
    fail "$HOST does not resolve. Publishing now lists an endpoint nobody can reach."
fi

if ! curl -fsS --max-time 15 -o /dev/null "$URL" 2>/dev/null; then
    # A 401 is correct here — the client surface is OAuth-gated — so only a
    # connection failure is fatal, not a non-2xx status.
    if ! curl -sS --max-time 15 -o /dev/null -w '%{http_code}' "$URL" 2>/dev/null | grep -qE '^[0-9]{3}$'; then
        fail "$URL is not answering at all"
    fi
fi

if [[ "$DRY_RUN" == "1" ]]; then
    echo "DRY_RUN=1 — preflight passed, not publishing."
    exit 0
fi

echo "Publishing…"
mcp-publisher login dns --domain "$APEX" --private-key "$KEY_PATH"
mcp-publisher publish

echo "Published $NAME@$VERSION."
echo "Bump the version in $MANIFEST before publishing again — versions are immutable."
