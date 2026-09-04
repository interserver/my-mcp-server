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
            'destructiveClassifier' => new DestructiveClassifier(),
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
            'destructiveClassifier' => new DestructiveClassifier(),
        ],
    ];
};
