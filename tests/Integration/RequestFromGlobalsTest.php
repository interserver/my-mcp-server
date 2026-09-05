<?php

declare(strict_types=1);

namespace InterServer\Mcp\App\Tests\Integration;

use InterServer\Mcp\App\Kernel;
use InterServer\Mcp\Core\Support\Config;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use PHPUnit\Framework\TestCase;

/**
 * The request as `public_html/index.php` actually builds it.
 *
 * Every other test in this suite hands the front controller a `ServerRequest`
 * constructed by hand, which quietly normalises away the one thing that broke
 * the first live deployment: `ServerRequestCreator::fromGlobals()` emits `Host`
 * TWICE — once from `$_SERVER['HTTP_HOST']` and once from the URI it derives
 * from that same value.
 *
 * `getHeaderLine('Host')` joins repeated values with ", ", so the SDK's
 * DNS-rebinding middleware compared "mcp.interserver.net, mcp.interserver.net"
 * against its allowlist and refused it. Every request to a correctly configured
 * host returned 403 with "Forbidden: Invalid Host header." — a failure that
 * reads like a firewall or a vhost problem and says nothing about the header
 * being duplicated.
 *
 * So this test builds the request the way the entry point does, from a
 * `$_SERVER` array, rather than the way that is convenient.
 *
 * @coversNothing
 */
final class RequestFromGlobalsTest extends TestCase
{
    private const DEFAULT_SPEC = '/home/sites/mystage/public_html/spec/openapi.yaml';

    private string $cacheDir;

    protected function setUp(): void
    {
        if (null === $this->specPath()) {
            $this->markTestSkipped('the client OpenAPI spec is not available; set MCP_CLIENT_SPEC');
        }
        $this->cacheDir = sys_get_temp_dir().'/mcp-globals-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cacheDir)) {
            foreach (glob($this->cacheDir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->cacheDir);
        }
    }

    private function specPath(): ?string
    {
        $spec = getenv('MCP_CLIENT_SPEC') ?: self::DEFAULT_SPEC;

        return is_readable($spec) ? $spec : null;
    }

    /**
     * Guards the assumption the rest of this file rests on. If a future Nyholm
     * stops duplicating the header, this test says so plainly instead of quietly
     * becoming a test of nothing.
     */
    public function testFromGlobalsStillProducesARepeatedHostHeader(): void
    {
        $request = $this->fromServerArray();

        self::assertSame(
            ['mcp.interserver.net', 'mcp.interserver.net'],
            $request->getHeader('Host'),
            'if this ever returns a single value, the collapse in the core is no longer load-bearing'
        );
    }

    /**
     * The regression itself: a request built the way production builds it must
     * not be refused by DNS-rebinding protection.
     */
    public function testARequestBuiltFromGlobalsIsNotRefusedAsAnInvalidHost(): void
    {
        $response = $this->kernel()->frontController()->handle($this->fromServerArray());

        $body = (string) $response->getBody();

        self::assertStringNotContainsString('Invalid Host header', $body);
        self::assertNotSame(
            403,
            $response->getStatusCode(),
            'a correctly configured host refused its own name — the Host header arrived repeated'
        );
    }

    /**
     * Collapsing identical values must not soften the check itself: two genuinely
     * different Host headers are request smuggling and stay refused.
     */
    public function testTwoDifferentHostHeadersAreStillRefused(): void
    {
        $request = $this->fromServerArray()->withAddedHeader('Host', 'evil.example.com');

        $response = $this->kernel()->frontController()->handle($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Invalid Host header', (string) $response->getBody());
    }

    /** Builds the PSR-7 request exactly as public_html/index.php does. */
    private function fromServerArray(): \Psr\Http\Message\ServerRequestInterface
    {
        $server = [
            'HTTP_HOST' => 'mcp.interserver.net',
            'HTTPS' => 'on',
            'SERVER_PORT' => '443',
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/public',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_CONTENT_TYPE' => 'application/json',
        ];

        $psr17 = new Psr17Factory();
        $creator = new ServerRequestCreator($psr17, $psr17, $psr17, $psr17);

        $body = (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        return $creator->fromArrays($server, ['Host' => ['mcp.interserver.net']], [], [], [], [], $body);
    }

    private function kernel(): Kernel
    {
        return new Kernel(
            new Config([
                'MCP_CLIENT_SPEC' => (string) $this->specPath(),
                'MCP_API_ORIGIN' => 'https://my.interserver.net',
                'MCP_PUBLIC_ORIGIN' => 'https://mcp.interserver.net',
                'MCP_AUTH_SERVER' => 'https://my.interserver.net',
                'MCP_CACHE_DIR' => $this->cacheDir,
                'MCP_SESSION_DIR' => $this->cacheDir,
                'MCP_ALLOWED_HOSTS' => 'mcp.interserver.net',
                'MCP_INTROSPECTION_URL' => 'https://my.interserver.net/apiv2/oauth/introspect',
                'MCP_INTROSPECTION_CLIENT_ID' => 'mcp-client-introspect',
                'MCP_INTROSPECTION_CLIENT_SECRET' => 'test-secret',
            ]),
            \dirname(__DIR__, 2),
        );
    }
}
