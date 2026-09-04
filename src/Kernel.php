<?php

declare(strict_types=1);

namespace InterServer\Mcp\App;

use InterServer\Mcp\Core\Auth\IntrospectionClient;
use InterServer\Mcp\Core\Auth\IntrospectionTokenValidator;
use InterServer\Mcp\Core\Http\FrontController;
use InterServer\Mcp\Core\OpenApi\ToolCache;
use InterServer\Mcp\Core\Profile\Profile;
use InterServer\Mcp\Core\Profile\ProfileRegistry;
use InterServer\Mcp\Core\Profile\ProfileResolver;
use InterServer\Mcp\Core\Server\ServerFactory;
use InterServer\Mcp\Core\Support\Config;
use InterServer\Mcp\Core\Support\RedisCache;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Session\Psr16SessionStore;
use Mcp\Server\Session\SessionStoreInterface;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Wires this application's configuration into the shared core.
 *
 * Everything here is assembly. The behaviour lives in
 * `interserver/mcp-openapi-core`, so this file and the admin server's equivalent
 * differ only in which config directory they read — which is exactly the property
 * that stopped being true in the two repos this replaced.
 */
final class Kernel
{
    public function __construct(
        private readonly Config $config,
        private readonly string $projectRoot,
    ) {
    }

    public static function fromEnvironment(string $projectRoot): self
    {
        return new self(Config::fromEnvironment(), rtrim($projectRoot, '/'));
    }

    public function frontController(): FrontController
    {
        $psr17 = new Psr17Factory();
        $registry = ProfileRegistry::fromArray($this->profileRows());

        $resolver = new ProfileResolver(
            $registry,
            [
                '/client' => 'client',
                '/public' => 'public',
            ],
            // The bare host is an alias for the client surface, for people who
            // paste `mcp.interserver.net` and expect it to work.
            'client',
        );

        return new FrontController(
            resolver: $resolver,
            factory: new ServerFactory($this->toolCache(), $this->logger()),
            responseFactory: $psr17,
            streamFactory: $psr17,
            authorizationServer: $this->config->get('MCP_AUTH_SERVER', 'https://my.interserver.net'),
            validatorFactory: $this->validatorFactory(),
            sessionStore: $this->sessionStore(),
            logger: $this->logger(),
            allowedOrigins: $this->list('MCP_ALLOWED_ORIGINS', ['https://claude.ai', 'https://claude.com']),
            allowedHosts: $this->list('MCP_ALLOWED_HOSTS', [
                'mcp.interserver.net',
                'mcpstage.interserver.net',
                'localhost',
                '127.0.0.1',
            ]),
        );
    }

    /**
     * The profile rows, built against *this* kernel's configuration.
     *
     * `config/profiles.php` returns a closure taking a Config rather than reading
     * the environment itself, so a kernel constructed with explicit settings — a
     * test, a warm-cache run, a staging host — actually gets them. Reading the
     * environment inside that file meant staging advertised the production
     * resource URL in its PRM, and Claude compares that literally.
     *
     * @return array<string, array<string, mixed>>
     */
    public function profileRows(): array
    {
        $definition = require $this->projectRoot.'/config/profiles.php';

        return $definition instanceof \Closure ? $definition($this->config) : $definition;
    }

    public function toolCache(): ToolCache
    {
        return new ToolCache($this->config->get('MCP_CACHE_DIR', $this->projectRoot.'/var/cache'));
    }

    /**
     * @return (\Closure(Profile): ?IntrospectionTokenValidator)|null
     */
    private function validatorFactory(): ?\Closure
    {
        $endpoint = $this->config->get('MCP_INTROSPECTION_URL');
        $clientId = $this->config->get('MCP_INTROSPECTION_CLIENT_ID');
        $clientSecret = $this->config->get('MCP_INTROSPECTION_CLIENT_SECRET');

        // Refuse to half-configure. A server with no validator answers 401 to every
        // token, which is at least honest; a server that silently skips validation
        // would accept every token on the internet.
        if (null === $endpoint || null === $clientId || null === $clientSecret) {
            $this->logger()->warning(
                'Token introspection is not configured; every bearer token will be refused.',
                ['missing' => array_keys(array_filter([
                    'MCP_INTROSPECTION_URL' => null === $endpoint,
                    'MCP_INTROSPECTION_CLIENT_ID' => null === $clientId,
                    'MCP_INTROSPECTION_CLIENT_SECRET' => null === $clientSecret,
                ]))],
            );

            return null;
        }

        $client = new IntrospectionClient(
            endpoint: $endpoint,
            clientId: $clientId,
            clientSecret: $clientSecret,
            cache: $this->introspectionCache(),
            logger: $this->logger(),
        );

        return static fn (Profile $profile): IntrospectionTokenValidator
            => new IntrospectionTokenValidator($client, $profile);
    }

    private function introspectionCache(): ?CacheInterface
    {
        $dsn = $this->config->get('MCP_REDIS_DSN');

        if (null === $dsn) {
            return null;
        }

        // Prefixed per application, so the client and admin servers can share a
        // Redis instance without either being able to read the other's sessions.
        return RedisCache::fromDsn($dsn, $this->config->get('MCP_REDIS_PREFIX', 'mcp:client:'));
    }

    /**
     * Session storage for the handshake era.
     *
     * Not optional under PHP-FPM. With the SDK's default `InMemorySessionStore`
     * every request is a fresh process, so the session created by `initialize` is
     * gone by the next call: run the official conformance suite that way and every
     * scenario fails with "Session not found or has expired". A file store turned
     * that into 8 passing with no other change; Redis is the same fix that also
     * survives more than one web node.
     *
     * The modern era needs none of this — and this server speaks both.
     */
    private function sessionStore(): SessionStoreInterface
    {
        $ttl = $this->config->int('MCP_SESSION_TTL', 3600);
        $cache = $this->introspectionCache();

        if (null !== $cache) {
            return new Psr16SessionStore($cache, 'session:', $ttl);
        }

        $dir = $this->config->get('MCP_SESSION_DIR', $this->projectRoot.'/var/sessions');
        if (!is_dir($dir)) {
            @mkdir($dir, 0o750, true);
        }

        return new FileSessionStore($dir, $ttl);
    }

    private function logger(): LoggerInterface
    {
        static $logger = null;

        if (null === $logger) {
            $logger = new Logger('mcp');
            $logger->pushHandler(new StreamHandler(
                $this->config->get('MCP_LOG_FILE', $this->projectRoot.'/var/log/mcp.log'),
                $this->config->get('MCP_LOG_LEVEL', 'warning'),
            ));
        }

        return $logger;
    }

    /**
     * @param list<string> $default
     *
     * @return list<string>
     */
    private function list(string $key, array $default): array
    {
        $value = $this->config->get($key);

        if (null === $value) {
            return $default;
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $value)), static fn (string $v): bool => '' !== $v));
    }
}
