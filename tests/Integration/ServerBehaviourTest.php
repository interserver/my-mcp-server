<?php

declare(strict_types=1);

namespace InterServer\Mcp\App\Tests\Integration;

use InterServer\Mcp\App\Kernel;
use InterServer\Mcp\Core\Support\Config;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Drives the real front controller with real PSR-7 requests, against the real
 * client spec.
 *
 * This is the layer the plan's "easy to forget" list lives at — a `GET` that must
 * be 405, a notification that must be 202, a header/`_meta` disagreement that must
 * be `-32020`. Each of those is a one-line mistake in wiring that no unit test of
 * the core would catch, because the core is wired correctly and the application is
 * what wires it.
 *
 * No socket: `FrontController::handle()` takes a request and returns a response.
 *
 * @coversNothing
 */
final class ServerBehaviourTest extends TestCase
{
    private const HOST = 'https://mcpstage.interserver.net';
    private const DEFAULT_SPEC = '/home/sites/mystage/public_html/spec/openapi.yaml';

    private string $cacheDir;

    protected function setUp(): void
    {
        if (null === $this->specPath()) {
            $this->markTestSkipped('the client OpenAPI spec is not available; set MCP_CLIENT_SPEC');
        }

        $this->cacheDir = sys_get_temp_dir().'/mcp-app-test-'.bin2hex(random_bytes(4));
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

    private function kernel(): Kernel
    {
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
            ]),
            \dirname(__DIR__, 2),
        );
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers
     */
    private function send(string $method, string $path, ?array $body = null, array $headers = []): ResponseInterface
    {
        $request = new ServerRequest(
            $method,
            self::HOST.$path,
            ['Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json'] + $headers,
            null === $body ? null : (string) json_encode($body),
        );

        return $this->kernel()->frontController()->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private static function modern(string $method, array $params = []): array
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => [],
        ];

        return [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params],
            ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => $method],
        ];
    }

    // ------------------------------------------------------------ the public surface

    public function testThePublicSurfaceExposesExactlyItsThreeTools(): void
    {
        [$body, $headers] = self::modern('tools/list');

        $result = self::decode($this->send('POST', '/public', $body, $headers));

        self::assertSame(
            ['getDomainLookup', 'getDomainSearch', 'getMPServers'],
            array_column($result['result']['tools'], 'name'),
        );
    }

    /**
     * §3.3: tools/list SHOULD be deterministically ordered. An unstable list costs
     * both the client's list cache and the model's prompt cache.
     */
    public function testThePublicToolListIsSorted(): void
    {
        [$body, $headers] = self::modern('tools/list');

        $names = array_column(self::decode($this->send('POST', '/public', $body, $headers))['result']['tools'], 'name');
        $sorted = $names;
        sort($sorted, \SORT_STRING);

        self::assertSame($sorted, $names);
    }

    public function testThePublicSurfaceNeedsNoCredential(): void
    {
        [$body, $headers] = self::modern('tools/list');

        self::assertSame(200, $this->send('POST', '/public', $body, $headers)->getStatusCode());
    }

    public function testEveryPublicToolCarriesTheAnnotationsConnectorReviewRequires(): void
    {
        [$body, $headers] = self::modern('tools/list');

        foreach (self::decode($this->send('POST', '/public', $body, $headers))['result']['tools'] as $tool) {
            self::assertNotEmpty($tool['annotations']['title'] ?? '', "{$tool['name']} has no title");
            self::assertTrue(
                ($tool['annotations']['readOnlyHint'] ?? false) || ($tool['annotations']['destructiveHint'] ?? false),
                "{$tool['name']} declares neither readOnlyHint nor destructiveHint",
            );
            self::assertLessThanOrEqual(64, \strlen($tool['name']));
        }
    }

    // ------------------------------------------------------------ the client surface

    /**
     * The single most common way a connector silently does nothing: a `200`
     * carrying `isError` is handed to the model as text, so Claude never shows a
     * Connect card. The refusal has to be an HTTP status.
     */
    public function testAnUnauthenticatedToolCallIsA401NotAnErrorResult(): void
    {
        $response = $this->send('POST', '/client', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'getVpsList', 'arguments' => []],
        ]);

        self::assertSame(401, $response->getStatusCode());

        $challenge = $response->getHeaderLine('WWW-Authenticate');
        self::assertStringStartsWith('Bearer ', $challenge);
        self::assertStringContainsString(
            'resource_metadata="'.self::HOST.'/.well-known/oauth-protected-resource/client"',
            $challenge,
        );
    }

    /**
     * Unlike the admin surface, whose tool *names* are the thing worth protecting.
     */
    public function testTheClientToolListIsReadableBeforeLogin(): void
    {
        [$body, $headers] = self::modern('tools/list');

        $response = $this->send('POST', '/client', $body, $headers);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotEmpty(self::decode($response)['result']['tools']);
    }

    /**
     * With no scopes, only the sections the map does not gate survive — `/login`,
     * `/ping` and friends. Anything module-scoped must be absent.
     */
    public function testAnAnonymousClientListShowsOnlyUngatedTools(): void
    {
        [$body, $headers] = self::modern('tools/list');

        $names = array_column(self::decode($this->send('POST', '/client', $body, $headers))['result']['tools'], 'name');

        self::assertNotContains('getVpsList', $names);
        self::assertNotContains('getDomainsList', $names);
    }

    /**
     * The bare host is an alias for the client surface, for people who paste
     * `mcp.interserver.net` and expect it to work.
     */
    public function testTheBareHostResolvesToTheClientSurface(): void
    {
        [$body, $headers] = self::modern('tools/list');

        $atRoot = self::decode($this->send('POST', '/', $body, $headers));
        $atClient = self::decode($this->send('POST', '/client', $body, $headers));

        self::assertSame(
            array_column($atClient['result']['tools'], 'name'),
            array_column($atRoot['result']['tools'], 'name'),
        );
    }

    // ---------------------------------------------------------------- both eras

    public function testTheHandshakeEraStillWorks(): void
    {
        $response = $this->send('POST', '/public', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('2025-06-18', self::decode($response)['result']['protocolVersion']);
        self::assertNotSame('', $response->getHeaderLine('Mcp-Session-Id'));
    }

    public function testServerDiscoverAdvertisesTheModernRevision(): void
    {
        [$body, $headers] = self::modern('server/discover');

        $result = self::decode($this->send('POST', '/public', $body, $headers))['result'];

        self::assertContains('2026-07-28', $result['supportedVersions']);
    }

    /**
     * §3.3 makes ttlMs and cacheScope required on cacheable results, and the SDK's
     * default is a flat refusal to cache anything.
     */
    public function testModernResultsCarryUsefulCachingHints(): void
    {
        [$body, $headers] = self::modern('tools/list');

        $result = self::decode($this->send('POST', '/public', $body, $headers))['result'];

        self::assertSame('complete', $result['resultType']);
        self::assertGreaterThan(0, $result['ttlMs']);
        self::assertSame('private', $result['cacheScope']);
    }

    // -------------------------------------------------- the easy-to-forget list

    public function testAGetOnTheEndpointIs405(): void
    {
        self::assertSame(405, $this->send('GET', '/public')->getStatusCode());
    }

    public function testADeleteWithoutASessionIs400(): void
    {
        self::assertSame(400, $this->send('DELETE', '/public')->getStatusCode());
    }

    /**
     * Routing is body-primary; the header never decides, but a disagreement is
     * `-32020` before either era sees the request.
     */
    public function testAHeaderMetaDisagreementIs32020(): void
    {
        [$body] = self::modern('tools/list');

        $response = $this->send('POST', '/public', $body, [
            'MCP-Protocol-Version' => '2025-06-18',
            'Mcp-Method' => 'tools/list',
        ]);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(-32020, self::decode($response)['error']['code']);
    }

    public function testAnUnsupportedVersionIs32022AndNamesWhatIsSupported(): void
    {
        $response = $this->send('POST', '/public', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2099-01-01',
                'io.modelcontextprotocol/clientCapabilities' => [],
            ]],
        ], ['MCP-Protocol-Version' => '2099-01-01', 'Mcp-Method' => 'tools/list']);

        $error = self::decode($response)['error'];

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(-32022, $error['code']);
        self::assertNotEmpty($error['data']['supported']);
    }

    /**
     * `ping` is one of the methods 2026-07-28 removed.
     */
    public function testPingIsGoneInTheModernEra(): void
    {
        [$body, $headers] = self::modern('ping');

        $response = $this->send('POST', '/public', $body, $headers);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(-32601, self::decode($response)['error']['code']);
    }

    // ------------------------------------------------------------------- the PRM

    public function testTheProtectedResourceMetadataIsServedOnThisHost(): void
    {
        $response = $this->send('GET', '/.well-known/oauth-protected-resource/client');
        $document = self::decode($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::HOST.'/client', $document['resource']);
        self::assertSame(['https://my.interserver.net'], $document['authorization_servers']);
    }

    /**
     * Caching a stale scope list breaks a client's authorize request, and
     * discovery documents are cached globally by URL for ~5 minutes if you let them.
     */
    public function testTheMetadataDocumentIsNeverCached(): void
    {
        self::assertSame(
            'no-store, max-age=0',
            $this->send('GET', '/.well-known/oauth-protected-resource/client')->getHeaderLine('Cache-Control'),
        );
    }

    public function testTheAdvertisedScopesCoverTheWholeScopeMap(): void
    {
        $document = self::decode($this->send('GET', '/.well-known/oauth-protected-resource/client'));

        self::assertContains('vps', $document['scopes_supported']);
        self::assertContains('vps:read', $document['scopes_supported']);
        self::assertNotContains(
            'admin',
            $document['scopes_supported'],
            'this server must never advertise the admin superscope',
        );
    }
}
