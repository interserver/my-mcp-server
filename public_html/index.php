<?php

declare(strict_types=1);

/**
 * mcp.interserver.net — every request, every method, one entry point.
 *
 * Deliberately thin. Routing, authentication, the tool catalogue and both
 * protocol eras all live in `interserver/mcp-openapi-core`; this file exists to
 * turn PHP's superglobals into a PSR-7 request and to emit the response.
 *
 * All methods route here, GET and DELETE included: the SDK answers those with the
 * 405 the specification requires, and letting Apache generate its own would
 * produce a 405 with no JSON-RPC body.
 */

use InterServer\Mcp\App\Kernel;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

require dirname(__DIR__).'/vendor/autoload.php';

$psr17 = new Psr17Factory();

$request = (new ServerRequestCreator($psr17, $psr17, $psr17, $psr17))->fromGlobals();

$response = Kernel::fromEnvironment(dirname(__DIR__))
    ->frontController()
    ->handle($request);

(new SapiEmitter())->emit($response);
