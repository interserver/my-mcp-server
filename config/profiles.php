<?php

declare(strict_types=1);

use InterServer\Mcp\Core\Auth\ScopeMap;
use InterServer\Mcp\Core\OpenApi\DestructiveClassifier;
use InterServer\Mcp\Core\Support\Config;

/**
 * The surfaces this application serves.
 *
 * `mcp.interserver.net/client` — the full client API, OAuth-gated and
 * scope-filtered. `mcp.interserver.net/public` — three allowlisted tools, no
 * authentication, for pre-sales lookups.
 *
 * Note what is *absent*: no `/admin/orders/` path rule and no
 * `^admin(Cancel|Delete|…)` operationId regex. Those are admin rules and they live
 * in the admin repo. Both origin repos compiled them into both builds, so the
 * client shipped classification logic that could never match one of its own paths.
 */

/**
 * Returned as a closure over {@see Config} rather than reading the environment
 * itself. A file that calls `Config::fromEnvironment()` builds its own view of the
 * world, so a Kernel constructed with an explicit configuration — a test, a CLI
 * warm-cache run, a staging host — silently gets production values here. That was
 * a real bug: the PRM advertised `mcp.interserver.net` while running on staging,
 * and Claude compares that URL literally.
 */
return static function (Config $config): array {
    $origin = $config->get('MCP_API_ORIGIN', 'https://my.interserver.net');
    $publicOrigin = $config->get('MCP_PUBLIC_ORIGIN', 'https://mcp.interserver.net');
    $version = $config->get('MCP_SERVER_VERSION', '1.0.0');

    $scopeMap = ScopeMap::forClient(require __DIR__.'/scope-map.php');

    /**
     * Side-effecting GETs.
     *
     * This API exposes 20 operations that mutate on a GET — stopping and restarting
     * VPSes and QuickServers, blocking SMTP, toggling DDoS scrubbing, destroying a
     * session. HTTP semantics say a GET is safe, so without these patterns the parser
     * annotates every one of them `readOnlyHint: true`.
     *
     * That is not a cosmetic error. `readOnlyHint` is what a client consults to
     * decide whether an action needs the user's confirmation, so a tool that stops
     * someone's server or spends their money was advertised as safe to call
     * speculatively.
     *
     * The patterns are matched against the operationId rather than the path because
     * the paths have nothing in common — `/vps/{id}/stop`, `/logout` and
     * `/servers/order/buy_now_server` share no fragment — while the naming
     * convention is consistent: an action is a verb.
     *
     * Verified against both live specs by reading each operation's description
     * rather than guessing from its name: these flag exactly those 20 GETs, catch no
     * read operation, and change nothing on the admin surface.
     *
     * `buyItNowServerOrder` is the near-miss worth naming. It reads like a purchase
     * and is not one — it is step 1 of the order flow and returns configurable
     * options so the form can be rendered; `placeBuyNowServer` (POST) is what creates
     * the invoice. A `^(buy|order|purchase)` pattern looks obviously right and would
     * mark a read destructive.
     */
    $destructive = new DestructiveClassifier(
        operationIdPatterns: [
            // doVpsStop, doQsRestart, doVpsBlockSmtp, doVpsEjectCd, ...
            '/^do[A-Z]/',
            // Logout, logoutAccountOauth — ends a session.
            '/^logout/i',
            // enableScrub, disableScrub.
            '/^(enable|disable)[A-Z]/',
        ],
    );

    return [
        'client' => [
            // Fetched over HTTP: the spec belongs to the API, not to this server, and
            // a copy in this repo is a copy that goes stale silently.
            'specSource' => $config->get('MCP_CLIENT_SPEC', $origin.'/spec/openapi.yaml'),
            'upstreamBaseUrl' => $origin.'/apiv2',
            'serverName' => 'InterServer API',
            'serverVersion' => $version,
            'requiresAuth' => true,
            'scopeMap' => $scopeMap,
            // Left open so a client can inspect the catalogue before logging in —
            // unlike the admin surface, where the tool *names* are the thing worth
            // protecting.
            'listRequiresAuth' => false,
            'authRealm' => 'interserver-client',
            // RFC 8707. Must equal the URL the user types, exactly, path included:
            // Claude compares literally, and this is the check that stops an
            // admin-audience token being replayed here.
            'resourceIdentifiers' => [$publicOrigin.'/client'],
            'destructiveClassifier' => $destructive,
            // experimental-ext-server-card, at the reserved
            // <streamable-http-url>/server-card path. Rendered from a real
            // server/discover against this very server, so the card and the live
            // result cannot disagree — which they did in the implementation this
            // replaced, where a hand-written literal claimed `tools` alone while the
            // server reported five capabilities.
            'servesServerCard' => true,
            'documentationUrl' => $origin.'/api-docs/',
        ],

        'public' => [
            'specSource' => $config->get('MCP_CLIENT_SPEC', $origin.'/spec/openapi.yaml'),
            'upstreamBaseUrl' => $origin.'/apiv2',
            'serverName' => 'InterServer Public API',
            'serverVersion' => $version,
            'requiresAuth' => false,
            // Three tools, named explicitly. An allowlist rather than a scope filter,
            // because "what an anonymous caller may see" is a product decision and
            // should read like one.
            'toolAllowlist' => ['getMPServers', 'getDomainSearch', 'getDomainLookup'],
            'scopeMap' => null,
            'authRealm' => 'interserver-public',
            'resourceIdentifiers' => [$publicOrigin.'/public'],
            'destructiveClassifier' => $destructive,
            // The public surface gets one too: it is the surface an unauthenticated
            // client meets first, so it is the one a card helps most.
            'servesServerCard' => true,
            'documentationUrl' => $origin.'/api-docs/',
        ],
    ];
};
