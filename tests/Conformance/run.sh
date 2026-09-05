#!/usr/bin/env bash
#
# Official MCP conformance suite, both eras, against a baseline.
#
# Two runs, because one tool cannot yet do both:
#
#   handshake era (2025-06-18)  — the released CLI, pinned to v0.1.16.
#   modern era    (2026-07-28)  — a pinned git checkout of the conformance repo.
#                                 v0.1.16's --spec-version rejects 2026-07-28
#                                 outright, and its `draft` resolves to zero
#                                 scenarios, so there is no way to cover the
#                                 revision we actually target from npm today.
#
# Each run is diffed against a committed baseline of expected failures, so a
# regression is a diff rather than a judgement call. The CLI fails in both
# directions: a new failure is an error, and a baseline entry that now passes is
# also an error ("stale entry"), which is what stops the baseline quietly becoming
# a list of things nobody checks any more.
#
#   ./tests/Conformance/run.sh            run both eras, fail on any drift
#   ./tests/Conformance/run.sh --update   re-establish both baselines
#
# The baselines are almost entirely "this server does not define the fixture tool
# the scenario expects". That is expected and permanent: this is an OpenAPI proxy,
# and its tools are the operations in the API spec.

set -uo pipefail

# Move this deliberately, and re-baseline when you do. Pinned rather than tracking
# main so an upstream scenario change cannot turn a green build red overnight.
CONFORMANCE_REPO="https://github.com/modelcontextprotocol/conformance.git"
CONFORMANCE_REF="7160fc8"
RELEASED_CLI="@modelcontextprotocol/conformance@0.1.16"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
HERE="${ROOT}/tests/Conformance"
PORT="${MCP_CONFORMANCE_PORT:-8091}"
BASE_URL="http://127.0.0.1:${PORT}"

# The surface to probe. `/public` needs no credential, which is what makes it the
# one the conformance suite can drive end to end — a scenario cannot present an
# OAuth token. See the admin repo for how a fully-gated surface is baselined.
SURFACE="${MCP_CONFORMANCE_SURFACE:-/public}"

UPDATE=0
[[ "${1:-}" == "--update" ]] && UPDATE=1

WORK="$(mktemp -d)"
SERVER_PID=""
cleanup() {
    [[ -n "${SERVER_PID}" ]] && kill "${SERVER_PID}" 2>/dev/null
    rm -rf "${WORK}"
}
trap cleanup EXIT

spec_path() {
    if [[ -n "${MCP_CLIENT_SPEC:-}" && -r "${MCP_CLIENT_SPEC}" ]]; then
        echo "${MCP_CLIENT_SPEC}"
    elif [[ -r /home/sites/mystage/public_html/spec/openapi.yaml ]]; then
        echo /home/sites/mystage/public_html/spec/openapi.yaml
    else
        echo ""
    fi
}

SPEC="$(spec_path)"
if [[ -z "${SPEC}" ]]; then
    echo "conformance: no OpenAPI spec available; set MCP_CLIENT_SPEC" >&2
    echo "conformance: NOT fetched over HTTP on purpose — Cloudflare answers 403" >&2
    exit 1
fi

echo "==> starting the server on ${BASE_URL} (spec: ${SPEC})"
mkdir -p "${WORK}/cache" "${WORK}/sessions"
MCP_CLIENT_SPEC="${SPEC}" \
MCP_PUBLIC_ORIGIN="${BASE_URL}" \
MCP_ALLOWED_HOSTS="127.0.0.1,localhost" \
MCP_ALLOWED_ORIGINS='*' \
MCP_CACHE_DIR="${WORK}/cache" \
MCP_SESSION_DIR="${WORK}/sessions" \
MCP_LOG_FILE="${WORK}/mcp.log" \
    php -S "127.0.0.1:${PORT}" -t "${ROOT}/public_html" "${ROOT}/public_html/index.php" \
    > "${WORK}/server.log" 2>&1 &
SERVER_PID=$!

for _ in $(seq 1 20); do
    sleep 0.5
    curl -sS -o /dev/null "${BASE_URL}${SURFACE}" 2>/dev/null && break
done

if ! kill -0 "${SERVER_PID}" 2>/dev/null; then
    echo "conformance: the server failed to start" >&2
    cat "${WORK}/server.log" >&2
    exit 1
fi

echo "==> preparing the pinned checkout for the modern era (${CONFORMANCE_REF})"
git clone --quiet "${CONFORMANCE_REPO}" "${WORK}/conformance" \
    && git -C "${WORK}/conformance" checkout --quiet "${CONFORMANCE_REF}" \
    && (cd "${WORK}/conformance" && npm ci --silent >/dev/null 2>&1 && npm run build --silent >/dev/null 2>&1)
MODERN_CLI="${WORK}/conformance/dist/index.js"

STATUS=0

run_era() {
    local era="$1" runner="$2" baseline="${HERE}/expected-failures-$1.yaml"

    echo
    echo "==> conformance: ${era}"

    if [[ "${UPDATE}" == "1" ]]; then
        local failures
        failures="$(${runner} --url "${BASE_URL}${SURFACE}" --spec-version "${era}" 2>&1 \
                    | grep -E '^✗' | sed 's/^✗ //; s/:.*//' | sort)"
        # Preserve the explanatory header; only the list below `server:` is regenerated.
        awk '/^server:/{print; exit} {print}' "${baseline}" > "${baseline}.new"
        echo "${failures}" | sed 's/^/  - /' >> "${baseline}.new"
        mv "${baseline}.new" "${baseline}"
        echo "    baseline updated: $(echo "${failures}" | grep -c . ) expected failures"
        return 0
    fi

    ${runner} --url "${BASE_URL}${SURFACE}" \
              --spec-version "${era}" \
              --expected-failures "${baseline}"
    local rc=$?
    [[ ${rc} -ne 0 ]] && STATUS=1
    return 0
}

run_era "2025-06-18" "npx --yes ${RELEASED_CLI} server"

if [[ -x "${MODERN_CLI}" || -f "${MODERN_CLI}" ]]; then
    run_era "2026-07-28" "node ${MODERN_CLI} server"
else
    # Not fatal: the pinned checkout needs network and a node toolchain. Say so
    # loudly rather than reporting a green build that never ran half the suite.
    echo "!!  the pinned conformance checkout is unavailable — the modern era was NOT tested" >&2
    STATUS=1
fi

echo
[[ ${STATUS} -eq 0 ]] && echo "conformance: both eras match their baselines" \
                      || echo "conformance: drift against a baseline (see above)" >&2
exit ${STATUS}
