<?php

declare(strict_types=1);

namespace InterServer\Mcp\App\Tests\Config;

use InterServer\Mcp\App\Kernel;
use InterServer\Mcp\Core\Profile\Profile;
use InterServer\Mcp\Core\Profile\ProfileRegistry;
use InterServer\Mcp\Core\Support\Config;
use PHPUnit\Framework\TestCase;

/**
 * The profile rows are this application's entire behaviour, so they get asserted
 * like code rather than reviewed like configuration.
 *
 * @coversNothing
 */
final class ProfilesTest extends TestCase
{
    /**
     * Built through the Kernel, with production defaults, so these tests assert
     * what a production deploy actually gets rather than what the file looks like.
     */
    private static function registry(): ProfileRegistry
    {
        $kernel = new Kernel(new Config(), \dirname(__DIR__, 2));

        return ProfileRegistry::fromArray($kernel->profileRows());
    }

    private static function profile(string $name): Profile
    {
        return self::registry()->get($name);
    }

    public function testThisAppServesExactlyTheClientAndPublicSurfaces(): void
    {
        self::assertSame(['client', 'public'], self::registry()->names());
    }

    /**
     * The admin surface belongs to a different repository on a different host. A
     * profile for it appearing here would put ~445 admin tools behind this
     * server's authentication rules.
     */
    public function testThereIsNoAdminProfile(): void
    {
        self::assertFalse(self::registry()->has('admin'));
    }

    // ------------------------------------------------------------------ client

    public function testTheClientSurfaceRequiresAuthentication(): void
    {
        self::assertTrue(self::profile('client')->requiresAuth);
    }

    public function testTheClientSurfaceFiltersByScope(): void
    {
        self::assertNotNull(self::profile('client')->scopeMap);
    }

    public function testTheClientSurfaceExposesTheWholeSpec(): void
    {
        self::assertNull(
            self::profile('client')->toolAllowlist,
            'the client surface is gated by scope, not by an allowlist'
        );
    }

    /**
     * Unlike the admin surface: a client tool catalogue is not sensitive, and
     * leaving it readable lets a connector be inspected before login.
     */
    public function testTheClientToolListIsInspectableBeforeLogin(): void
    {
        self::assertFalse(self::profile('client')->listRequiresAuth);
    }

    public function testTheClientSurfaceIsNotAdminGated(): void
    {
        self::assertFalse(self::profile('client')->requiresAdminTier);
        self::assertNull(self::profile('client')->requiredScope);
    }

    // ------------------------------------------------------------------ public

    public function testThePublicSurfaceNeedsNoAuthentication(): void
    {
        self::assertFalse(self::profile('public')->requiresAuth);
    }

    /**
     * Three tools, named explicitly. An allowlist rather than a scope filter,
     * because "what an anonymous caller may see" is a product decision and should
     * read like one.
     */
    public function testThePublicSurfaceExposesExactlyThreeTools(): void
    {
        self::assertSame(
            ['getMPServers', 'getDomainSearch', 'getDomainLookup'],
            self::profile('public')->toolAllowlist,
        );
    }

    public function testThePublicSurfaceIsNotScopeFiltered(): void
    {
        // With no authentication there are no scopes; a scope map here would
        // silently empty the catalogue rather than fail loudly.
        self::assertNull(self::profile('public')->scopeMap);
    }

    // -------------------------------------------------------- audience binding

    /**
     * RFC 8707. With two resource servers on two hosts sharing one authorization
     * server, this is the check that stops an admin-scoped token being replayed
     * here. A profile with no identifiers accepts *any* audience.
     *
     * @dataProvider profileNameProvider
     */
    public function testEveryProfileDeclaresAResourceIdentifier(string $name): void
    {
        self::assertNotEmpty(
            self::profile($name)->resourceIdentifiers,
            'a profile with no resource identifiers accepts a token minted for any server'
        );
    }

    /**
     * @dataProvider profileNameProvider
     */
    public function testNoProfileClaimsTheAdminHostsAudience(string $name): void
    {
        foreach (self::profile($name)->resourceIdentifiers as $identifier) {
            self::assertStringNotContainsString(
                'adminmcp.',
                $identifier,
                'this server must never accept a token minted for the admin host'
            );
        }
    }

    public function testTheTwoSurfacesDoNotShareAnAudience(): void
    {
        self::assertSame(
            [],
            array_intersect(
                self::profile('client')->resourceIdentifiers,
                self::profile('public')->resourceIdentifiers,
            ),
        );
    }

    public static function profileNameProvider(): \Generator
    {
        yield 'client' => ['client'];
        yield 'public' => ['public'];
    }

    // ------------------------------------------------------- destructive rules

    /**
     * The concrete drift this whole split exists to prevent: both origin repos
     * compiled `/admin/orders/` and an `^admin(Cancel|Delete|Refund|…)` regex into
     * the *client* build, where they could never match one of its own paths.
     *
     * @dataProvider profileNameProvider
     */
    public function testNoAdminHeuristicsLeakIntoThisBuild(string $name): void
    {
        $classifier = self::profile($name)->destructiveClassifier;

        self::assertFalse(
            $classifier->isDestructive('GET', '/admin/orders/vps', 'adminOrderVps'),
            'this build must not carry admin-only destructive rules'
        );
        self::assertFalse(
            $classifier->isDestructive('POST', '/admin/x', 'adminForceDeleteThing'),
            'this build must not carry the admin operationId regex'
        );
    }

    /**
     * The shared defaults must still be in force, or nothing gets flagged at all.
     *
     * @dataProvider profileNameProvider
     */
    public function testTheSharedDestructiveDefaultsStillApply(string $name): void
    {
        $classifier = self::profile($name)->destructiveClassifier;

        self::assertTrue($classifier->isDestructive('DELETE', '/vps/{id}'));
        self::assertTrue($classifier->isDestructive('POST', '/vps/{id}/reinstall'));
        self::assertTrue($classifier->isDestructive('GET', '/mail/{id}/reset_password'));
    }

    // ------------------------------------------------------------------ wiring

    /**
     * A base URL missing `/apiv2` sends every tool call to the web UI, which
     * redirects to a login page — and the model receives a 56 KB HTML blob with
     * nothing to indicate the API was never reached.
     *
     * @dataProvider profileNameProvider
     */
    public function testEveryProfileProxiesToTheApiNotTheWebUi(string $name): void
    {
        self::assertStringEndsWith('/apiv2', self::profile($name)->upstreamBaseUrl);
    }

    /**
     * The spec belongs to the API. A copy in this repo is a copy that goes stale
     * without anyone noticing.
     *
     * @dataProvider profileNameProvider
     */
    public function testEveryProfileFetchesItsSpecFromTheApi(string $name): void
    {
        $source = self::profile($name)->specSource;

        self::assertStringStartsWith('http', $source);
        self::assertStringContainsString('openapi', $source);
    }

    public function testBothSurfacesReadTheSameSpec(): void
    {
        self::assertSame(
            self::profile('client')->specSource,
            self::profile('public')->specSource,
            'the public surface is an allowlist over the client spec, not a spec of its own'
        );
    }
}
