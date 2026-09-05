<?php

declare(strict_types=1);

namespace InterServer\Mcp\App\Tests\Integration;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use InterServer\Mcp\App\Kernel;
use InterServer\Mcp\Core\Support\Config;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Cross-surface audience rejection, end to end through the assembled application.
 *
 * This is the test the migration plan calls "the test that catches the D2 failure that
 * actually matters". MyAdmin is one OAuth authorization server in front of two MCP
 * resource servers on two different hosts — `mcp.interserver.net` (this repo, ~311
 * client tools) and `adminmcp.interserver.net` (~445 admin tools). Nothing except RFC
 * 8707 audience binding separates them:
 *
 *  - **Scope cannot.** A token may legitimately carry the `admin` scope without being
 *    meant for the admin host.
 *  - **The IP allowlist cannot.** Two thirds of the addresses the admin host admits are
 *    Claude's own egress range, so any request arriving through Claude is inside the
 *    allowlist by construction.
 *
 * So if the audience check regresses, an admin-audience token reaches this server's
 * tools and a client-audience token reaches the admin server's. Core already pins the
 * unit-level version of this in tests/Unit/Auth/IntrospectionTokenValidatorTest.php.
 * What this adds is the end-to-end counterpart: the refusal has to come out of the real
 * Kernel, the real ProfileResolver, the real ServerFactory and the real SDK transport,
 * because a validator that is correct but never reached protects nothing.
 *
 * Only the socket to the authorization server is stubbed. The introspection payloads
 * below are the exact shape MyAdmin's `POST /apiv2/oauth/introspect` returns — see
 * include/Security/TokenIntrospection.php in that repo.
 *
 * @coversNothing
 */
final class CrossSurfaceAuthTest extends TestCase
{
    private const HOST = 'https://mcpstage.interserver.net';
    private const CLIENT_RESOURCE = self::HOST.'/client';
    private const ADMIN_RESOURCE = 'https://adminmcpstage.interserver.net/';
    private const DEFAULT_SPEC = '/home/sites/mystage/public_html/spec/openapi.yaml';

    private string $cacheDir;

    protected function setUp(): void
    {
        if (null === $this->specPath()) {
            $this->markTestSkipped('the client OpenAPI spec is not available; set MCP_CLIENT_SPEC');
        }

        $this->cacheDir = sys_get_temp_dir().'/mcp-xsurface-'.bin2hex(random_bytes(4));
        mkdir($this->cacheDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->cacheDir);
    }

    private function specPath(): ?string
    {
        $path = getenv('MCP_CLIENT_SPEC') ?: self::DEFAULT_SPEC;

        return is_readable($path) ? $path : null;
    }

    /**
     * An introspection response for a live token bound to $audience.
     *
     * @return array<string, mixed>
     */
    private function introspection(string $audience, string $scope = 'vps:read domains:read'): array
    {
        return [
            'active' => true,
            'scope' => $scope,
            'client_id' => 'my-client-login',
            'username' => 'customer@example.com',
            'sub' => '12345',
            'token_type' => 'Bearer',
            'exp' => time() + 600,
            'aud' => $audience,
            'ext' => ['account_status' => 'active'],
        ];
    }

    /**
     * @param array<string, mixed> $introspection
     */
    private function kernel(array $introspection): Kernel
    {
        // Enough queued copies that a request introspecting more than once still gets
        // a consistent answer rather than an empty-queue exception.
        $responses = array_fill(0, 8, new GuzzleResponse(200, [], (string) json_encode($introspection)));

        return new Kernel(
            new Config([
                'MCP_CLIENT_SPEC' => (string) $this->specPath(),
                'MCP_API_ORIGIN' => 'https://my.interserver.net',
                'MCP_PUBLIC_ORIGIN' => self::HOST,
                'MCP_AUTH_SERVER' => 'https://my.interserver.net',
                'MCP_CACHE_DIR' => $this->cacheDir,
                'MCP_SESSION_DIR' => $this->cacheDir,
                'MCP_ALLOWED_HOSTS' => 'mcpstage.interserver.net',
                'MCP_ALLOWED_ORIGINS' => '*',
                'MCP_LOG_FILE' => $this->cacheDir.'/mcp.log',
                // Introspection must be fully configured, or the Kernel deliberately
                // builds no validator at all and every token is refused — which would
                // make this test pass for entirely the wrong reason.
                'MCP_INTROSPECTION_URL' => 'https://my.interserver.net/apiv2/oauth/introspect',
                'MCP_INTROSPECTION_CLIENT_ID' => 'mcp-client-introspect',
                'MCP_INTROSPECTION_CLIENT_SECRET' => 'test-secret',
            ]),
            \dirname(__DIR__, 2),
            new GuzzleClient(['handler' => HandlerStack::create(new MockHandler($responses))]),
        );
    }

    /**
     * @param array<string, mixed> $introspection
     */
    private function callTool(array $introspection, string $token = 'a-real-looking-token'): ResponseInterface
    {
        $body = (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'getMPServers', 'arguments' => []],
        ]);

        $request = (new ServerRequest('POST', self::HOST.'/client', [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
            'Host' => 'mcpstage.interserver.net',
            'MCP-Protocol-Version' => '2026-07-28',
            'Authorization' => 'Bearer '.$token,
        ]))->withBody(\Nyholm\Psr7\Stream::create($body));

        return $this->kernel($introspection)->frontController()->handle($request);
    }

    public function testAnAdminAudienceTokenCannotCallAClientTool(): void
    {
        // The failure this whole mechanism exists to prevent, in the direction that
        // matters least for privilege but most for proving the check runs at all.
        $response = $this->callTool($this->introspection(self::ADMIN_RESOURCE));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testAClientAudienceTokenIsAcceptedByTheClientSurface(): void
    {
        // The positive control. Without it the test above would still pass if audience
        // checking rejected everything, or if the validator were never wired in.
        $response = $this->callTool($this->introspection(self::CLIENT_RESOURCE));

        $this->assertNotSame(401, $response->getStatusCode());
    }

    public function testTheRefusalIsAnHttpStatusRatherThanAToolError(): void
    {
        // A 200 carrying {"result":{"isError":true}} is handed to the model as text:
        // no Connect card, no auth prompt, and a connector that silently does nothing.
        // The refusal has to be visible to the transport, so assert the challenge
        // header is present rather than only the status code.
        $response = $this->callTool($this->introspection(self::ADMIN_RESOURCE));

        $this->assertTrue($response->hasHeader('WWW-Authenticate'));
    }

    public function testTheChallengePointsAtThisSurfacesOwnMetadata(): void
    {
        // RFC 9728: the resource_metadata URL must be this resource's own, on this
        // host. Borrowing the admin server's would send Claude to the wrong PRM and the
        // connection would never recover.
        $response = $this->callTool($this->introspection(self::ADMIN_RESOURCE));

        $this->assertStringContainsString(
            'oauth-protected-resource/client',
            $response->getHeaderLine('WWW-Authenticate'),
        );
    }

    public function testAnAdminAudienceTokenIsRefusedEvenWhenItCarriesAdminScope(): void
    {
        // The reason audience binding cannot be replaced by a scope check: this token
        // is a genuine, live, admin-scoped credential. It is refused here purely
        // because it was not minted for this resource.
        $response = $this->callTool($this->introspection(self::ADMIN_RESOURCE, 'admin admin_login'));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testATokenWithNoAudienceIsRefused(): void
    {
        // Tokens issued before audience binding existed carry no `aud`. This profile
        // declares resource identifiers, so an unbound token must not be treated as
        // universally valid — the permissive case belongs only to a profile that
        // declares no identifiers at all.
        $introspection = $this->introspection(self::CLIENT_RESOURCE);
        unset($introspection['aud']);

        $this->assertSame(401, $this->callTool($introspection)->getStatusCode());
    }

    public function testAnInactiveTokenIsRefused(): void
    {
        // The uniform-failure shape the authorization server returns for every invalid
        // token: expired, unknown, revoked and non-active-account all look like this.
        $this->assertSame(401, $this->callTool(['active' => false])->getStatusCode());
    }

    public function testATokenBoundToBothSurfacesAtOnceIsAcceptedHere(): void
    {
        // RFC 8707 permits an array audience. A token legitimately minted for both
        // surfaces must work on each of them, or a user who authorised both would find
        // neither working.
        $introspection = $this->introspection(self::CLIENT_RESOURCE);
        $introspection['aud'] = [self::ADMIN_RESOURCE, self::CLIENT_RESOURCE];

        $this->assertNotSame(401, $this->callTool($introspection)->getStatusCode());
    }
}
