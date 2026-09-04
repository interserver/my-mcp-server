<?php

declare(strict_types=1);

namespace InterServer\Mcp\App\Tests\Config;

use PHPUnit\Framework\TestCase;

/**
 * `config/scope-map.php` is a **copy** of `OAuthScope::$clientSectionScopeMap` in
 * the API repo, not a move: the REST API still needs its own for `PermissionGate`
 * and the router. Two copies of an authorization table is a drift risk, and drift
 * here is not cosmetic — a section this server maps and the API does not (or the
 * reverse) means a tool is gated differently depending on which door a caller
 * uses.
 *
 * The check reads the API's copy directly out of its source, so it needs the
 * checkout. Absent, it skips rather than passing vacuously.
 *
 * @coversNothing
 */
final class ScopeMapParityTest extends TestCase
{
    private const DEFAULT_MYADMIN_ROOT = '/home/sites/mystage';

    private static function myadminRoot(): ?string
    {
        $root = getenv('MYADMIN_ROOT') ?: self::DEFAULT_MYADMIN_ROOT;

        return is_file($root.'/include/Api/OAuthScope.php') ? $root : null;
    }

    /**
     * Read a private static map out of `OAuthScope` without booting the
     * application: that file pulls in most of MyAdmin, and this test wants one
     * array, not a framework.
     *
     * @return array<string, string>
     */
    private static function apiScopeMap(string $root, string $property): array
    {
        $source = (string) file_get_contents($root.'/include/Api/OAuthScope.php');

        $matched = preg_match(
            '/private\s+static\s+\$'.preg_quote($property, '/').'\s*=\s*\[(.*?)\];/s',
            $source,
            $m,
        );

        if (1 !== $matched) {
            self::fail("could not locate \${$property} in the API's OAuthScope.php");
        }

        preg_match_all("/'([a-z_]+)'\s*=>\s*'([a-z_]+)'/", $m[1], $pairs, \PREG_SET_ORDER);

        $map = [];
        foreach ($pairs as $pair) {
            $map[$pair[1]] = $pair[2];
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private static function localScopeMap(): array
    {
        return require \dirname(__DIR__, 2).'/config/scope-map.php';
    }

    public function testTheClientScopeMapMatchesTheApisCopy(): void
    {
        $root = self::myadminRoot();

        if (null === $root) {
            $this->markTestSkipped('the MyAdmin checkout is not available; set MYADMIN_ROOT to run the parity check');
        }

        $api = self::apiScopeMap($root, 'clientSectionScopeMap');
        $local = self::localScopeMap();

        ksort($api);
        ksort($local);

        self::assertSame(
            $api,
            $local,
            "config/scope-map.php has drifted from OAuthScope::\$clientSectionScopeMap.\n"
            ."Only here: ".json_encode(array_diff_assoc($local, $api))."\n"
            ."Only there: ".json_encode(array_diff_assoc($api, $local))
        );
    }

    /**
     * The admin map is a different table on a different surface. If it ever
     * matched this one, one of the two would be wrong.
     */
    public function testTheClientMapIsNotTheAdminMap(): void
    {
        $root = self::myadminRoot();

        if (null === $root) {
            $this->markTestSkipped('the MyAdmin checkout is not available');
        }

        self::assertNotSame(
            self::apiScopeMap($root, 'adminSectionScopeMap'),
            self::localScopeMap(),
        );
    }

    /**
     * `clients` and `customers` are admin-only sections. Their presence here would
     * mean the admin map had been copied into the client build.
     */
    public function testNoAdminOnlySectionsAppearHere(): void
    {
        $local = self::localScopeMap();

        foreach (['clients', 'customers', 'storages'] as $adminOnly) {
            self::assertArrayNotHasKey(
                $adminOnly,
                $local,
                "'{$adminOnly}' is an admin-surface section and must not appear in the client map"
            );
        }
    }

    public function testEverySectionMapsToANonEmptyModuleScope(): void
    {
        foreach (self::localScopeMap() as $section => $scope) {
            self::assertNotSame('', trim($scope), "section '{$section}' maps to an empty scope");
            self::assertStringNotContainsString(':read', $scope, "section '{$section}' must map to a bare module scope; the :read variant is derived from the HTTP method");
        }
    }

    /**
     * `admin` is the superscope. A client section mapping to it would hand every
     * tool on this server to anyone holding it.
     */
    public function testNoClientSectionMapsToTheAdminSuperScope(): void
    {
        self::assertNotContains('admin', self::localScopeMap());
    }
}
