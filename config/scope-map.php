<?php

declare(strict_types=1);

/**
 * Client-surface scope map.
 *
 * Copied from `MyAdmin\Api\OAuthScope::$clientSectionScopeMap`, not moved: the
 * REST API in mystage still needs its own copy for `PermissionGate` and the
 * router. The two are expected to stay in step, and the cross-repo drift test in
 * `tests/Integration/ScopeMapParityTest.php` is what notices when they do not.
 *
 * A section maps to a module scope. GET requires `{module}:read`, every other
 * method the bare `{module}`. A section that is *not* listed requires no scope at
 * all — that is deliberate, and covers `/login`, `/info`, `/ping` and the other
 * endpoints any authenticated token may reach. (The admin surface takes the
 * opposite default and fails closed; see that repo's copy.)
 */

return [
    'account' => 'account',
    'affiliate' => 'affiliate',
    'backups' => 'backups',
    'billing' => 'billing',
    'dns' => 'dns',
    'domains' => 'domains',
    'floating_ips' => 'floating_ips',
    'licenses' => 'licenses',
    'mail' => 'mail',
    'qs' => 'quickservers',
    'quickservers' => 'quickservers',
    'scrub_ips' => 'scrub_ips',
    'servers' => 'servers',
    'ssl' => 'ssl',
    'tickets' => 'tickets',
    'vps' => 'vps',
    'webhosting' => 'webhosting',
    'websites' => 'webhosting',
];
