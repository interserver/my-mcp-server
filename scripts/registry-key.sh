#!/usr/bin/env bash
#
# Generate the Ed25519 keypair that proves ownership of the `net.interserver`
# registry namespace, and print the DNS record that publishes its public half.
#
# Run this ONCE. The private key is the namespace credential: anyone holding it
# can publish under net.interserver/*. It is written outside the repo and must
# never be committed.
#
# Placement of the TXT record matters and fails obscurely when wrong: it goes on
# the APEX (interserver.net), not on a `_mcp-auth.` selector. This is SPF-style,
# not DKIM-style. A record on a selector produces a generic signature error that
# says nothing about placement.
#
set -euo pipefail

KEY_PATH="${MCP_REGISTRY_KEY:-$HOME/.config/mcp-registry/interserver-ed25519.pem}"

if [[ -e "$KEY_PATH" ]]; then
    echo "Key already exists at $KEY_PATH — refusing to overwrite." >&2
    echo "Rotating? Move the old key aside first, then re-run and update the TXT record." >&2
    exit 1
fi

mkdir -p "$(dirname "$KEY_PATH")"
touch "$KEY_PATH"
chmod 600 "$KEY_PATH"
openssl genpkey -algorithm ed25519 -out "$KEY_PATH"

# The registry wants the 32 raw public-key bytes, base64. An Ed25519 SPKI DER is
# a fixed 44 bytes with a 12-byte header, so the key is the last 32 — tail -c 32
# rather than a DER parser.
PUBKEY="$(openssl pkey -in "$KEY_PATH" -pubout -outform DER | tail -c 32 | base64)"

cat <<TXT

Private key written to $KEY_PATH (mode 600). Back it up somewhere durable; the
namespace cannot be reclaimed without it.

Add this TXT record on the APEX — not on a subdomain, not on a selector:

    interserver.net.  IN  TXT  "v=MCPv1; k=ed25519; p=$PUBKEY"

Verify it has propagated before publishing:

    dig +short TXT interserver.net | grep MCPv1

TXT
