<?php

declare(strict_types=1);

namespace InterServer\Mcp\App\Tests\Config;

use PHPUnit\Framework\TestCase;

/**
 * `server.json` — the MCP Registry manifest.
 *
 * The registry rejects a bad manifest at publish time with messages that do not
 * always name the field at fault, and a published version is immutable, so the
 * constraints that are cheap to get wrong are asserted here instead of being
 * discovered during a release.
 *
 * The manifest must also agree with what this server actually serves: a registry
 * entry pointing at the wrong URL is a directory listing that sends people
 * somewhere else.
 *
 * @coversNothing
 */
final class ServerManifestTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function manifest(): array
    {
        $path = \dirname(__DIR__, 2).'/server.json';
        self::assertFileExists($path, 'server.json is the registry manifest and must exist');

        $raw = file_get_contents($path);
        self::assertIsString($raw);

        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, 'server.json must be a JSON object');

        return $decoded;
    }

    /**
     * The cap that is easiest to breach: the server card's own description is
     * several times this length, so reusing it is the natural mistake.
     */
    public function testDescriptionIsWithinTheHundredCharacterCap(): void
    {
        $description = self::manifest()['description'] ?? '';
        self::assertIsString($description);
        self::assertNotSame('', $description, 'description is required');
        self::assertLessThanOrEqual(
            100,
            mb_strlen($description),
            'the 2025-12-11 schema caps description at 100 characters'
        );
    }

    /** The namespace half is what the DNS TXT record on the apex authorises. */
    public function testNameIsInTheInterserverNamespaceAndMatchesTheSchemaPattern(): void
    {
        $name = self::manifest()['name'] ?? '';
        self::assertIsString($name);
        self::assertMatchesRegularExpression('~^[a-zA-Z0-9.-]+/[a-zA-Z0-9._-]+$~', $name);
        self::assertStringStartsWith(
            'net.interserver/',
            $name,
            'the namespace must be the one the apex TXT record proves ownership of'
        );
    }

    /**
     * Immutable once published, and ranges are rejected outright — so a range
     * that slips in is a failed release rather than a wrong one.
     */
    public function testVersionIsAnExactVersionRatherThanARange(): void
    {
        $version = self::manifest()['version'] ?? '';
        self::assertIsString($version);
        self::assertMatchesRegularExpression(
            '~^\d+\.\d+\.\d+~',
            $version,
            'the registry rejects version ranges; publish an exact version'
        );
    }

    /**
     * The registry entry must point at the client surface on the MCP host.
     *
     * Not the admin server, which is deliberately absent from every public
     * discovery channel, and not a path on my.interserver.net, which no longer
     * serves MCP at all.
     */
    public function testTheRemotePointsAtTheClientSurfaceOnTheMcpHost(): void
    {
        $remotes = self::manifest()['remotes'] ?? [];
        self::assertIsArray($remotes);
        self::assertCount(1, $remotes, 'one entry, one remote — the client surface');

        self::assertSame('streamable-http', $remotes[0]['type'] ?? null);
        self::assertSame('https://mcp.interserver.net/client', $remotes[0]['url'] ?? null);
    }

    /** A staff-only surface has no business in a public directory. */
    public function testTheManifestNeverMentionsTheAdminServer(): void
    {
        $raw = file_get_contents(\dirname(__DIR__, 2).'/server.json');
        self::assertIsString($raw);
        self::assertStringNotContainsString(
            'adminmcp',
            $raw,
            'the admin server is excluded from the registry, the server card and every discovery document'
        );
    }
}
