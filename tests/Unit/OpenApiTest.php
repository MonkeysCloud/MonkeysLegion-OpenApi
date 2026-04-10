<?php
declare(strict_types=1);

namespace MonkeysLegion\OpenApi\Tests\Unit;

use MonkeysLegion\Http\Message\Response;
use MonkeysLegion\Http\Message\ServerRequest;
use MonkeysLegion\Http\Message\Stream;
use MonkeysLegion\Http\Message\Uri;
use MonkeysLegion\OpenApi\Attributes\ApiDeprecated;
use MonkeysLegion\OpenApi\Attributes\ApiHidden;
use MonkeysLegion\OpenApi\Attributes\ApiInfo;
use MonkeysLegion\OpenApi\Attributes\ApiParam;
use MonkeysLegion\OpenApi\Attributes\ApiResponse;
use MonkeysLegion\OpenApi\Attributes\ApiSecurity;
use MonkeysLegion\OpenApi\Attributes\RequestBody;
use MonkeysLegion\OpenApi\OpenApiGenerator;
use MonkeysLegion\OpenApi\OpenApiMiddleware;
use MonkeysLegion\Router\Attributes\Route;
use MonkeysLegion\Router\RouteCollection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

// ── Test Controllers ───────────────────────────────────────────

#[ApiInfo(
    title: 'Test API',
    version: '2.0.0',
    description: 'Unit test API',
    contactName: 'MonkeysCloud',
    contactEmail: 'test@monkeys.cloud',
)]
#[ApiSecurity('bearerAuth')]
final class TestController
{
    /**
     * List all users.
     *
     * Returns a paginated list of users.
     */
    #[Route('GET', '/users', name: 'user_list', summary: 'List users', tags: ['Users'])]
    #[ApiParam(name: 'page', in: 'query', type: 'integer', example: 1)]
    #[ApiParam(name: 'per_page', in: 'query', type: 'integer', example: 25, required: false)]
    #[ApiResponse(200, 'User list', schema: ['type' => 'array', 'items' => ['type' => 'object']])]
    public function index(): void {}

    #[Route('GET', '/users/{id:\d+}', name: 'user_show', summary: 'Get user', tags: ['Users'])]
    #[ApiParam(name: 'id', in: 'path', type: 'integer')]
    #[ApiResponse(200, 'User found')]
    #[ApiResponse(404, 'User not found')]
    public function show(): void {}

    #[Route('POST', '/users', name: 'user_create', summary: 'Create user', tags: ['Users'])]
    #[RequestBody(
        description: 'User creation data',
        required: true,
        schema: [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'email' => ['type' => 'string', 'format' => 'email'],
            ],
            'required' => ['name', 'email'],
        ],
    )]
    #[ApiResponse(201, 'User created')]
    #[ApiResponse(422, 'Validation error')]
    public function create(): void {}

    #[Route('DELETE', '/users/{id}', name: 'user_delete', summary: 'Delete user', tags: ['Users'])]
    #[ApiDeprecated('Use PATCH /users/{id}/deactivate instead.')]
    #[ApiResponse(204, 'User deleted')]
    public function delete(): void {}

    #[Route('GET', '/internal/health', name: 'health', summary: 'Health check')]
    #[ApiHidden]
    public function health(): void {}
}

final class NoInfoController
{
    #[Route('GET', '/ping', name: 'ping', summary: 'Ping')]
    #[ApiResponse(200, 'pong')]
    public function ping(): void {}
}

// ── Passthrough Handler ────────────────────────────────────────

final class PassthroughHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Response::text('passthrough');
    }
}

// ── Test Suite ─────────────────────────────────────────────────

final class OpenApiTest extends TestCase
{
    /**
     * Build a RouteCollection + Generator using the TestController.
     */
    private function makeGenerator(string $controllerClass = TestController::class): OpenApiGenerator
    {
        $collection = new RouteCollection();
        $ref = new \ReflectionClass($controllerClass);

        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $routeAttrs = $method->getAttributes(Route::class);
            if ($routeAttrs === []) {
                continue;
            }
            /** @var Route $route */
            $route = $routeAttrs[0]->newInstance();

            // Wrap in a closure that preserves handler identity
            $handlerPair = [$controllerClass, $method->getName()];
            $handler = \Closure::fromCallable(fn() => $handlerPair);

            foreach ($route->methods as $verb) {
                $collection->add(
                    $verb,
                    $route->path,
                    $handler,
                    $route->name,
                    meta: ['handler' => $handlerPair],
                );
            }
        }

        return new OpenApiGenerator(
            $collection,
            servers: [['url' => 'https://api.example.com', 'description' => 'Production']],
        );
    }

    // ── Spec Structure ─────────────────────────────────────────

    #[Test]
    public function generates_valid_openapi_version(): void
    {
        $spec = $this->makeGenerator()->toArray();
        $this->assertSame('3.1.0', $spec['openapi']);
    }

    #[Test]
    public function info_from_api_info_attribute(): void
    {
        $spec = $this->makeGenerator()->toArray();
        $this->assertSame('Test API', $spec['info']['title']);
        $this->assertSame('2.0.0', $spec['info']['version']);
        $this->assertSame('Unit test API', $spec['info']['description']);
        $this->assertSame('MonkeysCloud', $spec['info']['contact']['name']);
        $this->assertSame('test@monkeys.cloud', $spec['info']['contact']['email']);
    }

    #[Test]
    public function info_falls_back_to_constructor_defaults(): void
    {
        $spec = $this->makeGenerator(NoInfoController::class)->toArray();
        $this->assertSame('MonkeysLegion API', $spec['info']['title']);
    }

    #[Test]
    public function servers_included(): void
    {
        $spec = $this->makeGenerator()->toArray();
        $this->assertCount(1, $spec['servers']);
        $this->assertSame('https://api.example.com', $spec['servers'][0]['url']);
    }

    #[Test]
    public function security_schemes_in_components(): void
    {
        $spec = $this->makeGenerator()->toArray();
        $this->assertArrayHasKey('bearerAuth', $spec['components']['securitySchemes']);
    }

    // ── Paths ──────────────────────────────────────────────────

    #[Test]
    public function generates_paths_from_routes(): void
    {
        $spec = $this->makeGenerator()->toArray();
        $paths = (array) $spec['paths'];

        $this->assertArrayHasKey('/users', $paths);
        $this->assertArrayHasKey('get', $paths['/users']);
        $this->assertArrayHasKey('post', $paths['/users']);
    }

    #[Test]
    public function operation_id_from_route_name(): void
    {
        $spec = $this->makeGenerator()->toArray();
        $paths = (array) $spec['paths'];
        $this->assertSame('user_list', $paths['/users']['get']['operationId']);
    }

    #[Test]
    public function summary_from_route_attribute(): void
    {
        $spec = $this->makeGenerator()->toArray();
        $paths = (array) $spec['paths'];
        $this->assertSame('List users', $paths['/users']['get']['summary']);
    }

    #[Test]
    public function tags_from_route_attribute(): void
    {
        $spec = $this->makeGenerator()->toArray();
        $paths = (array) $spec['paths'];
        $this->assertSame(['Users'], $paths['/users']['get']['tags']);
    }

    // ── Parameters ─────────────────────────────────────────────

    #[Test]
    public function explicit_query_parameters(): void
    {
        $spec = $this->makeGenerator()->toArray();
        $params = ((array) $spec['paths'])['/users']['get']['parameters'];

        $names = array_column($params, 'name');
        $this->assertContains('page', $names);
        $this->assertContains('per_page', $names);

        // Find page param
        $page = $params[array_search('page', $names)];
        $this->assertSame('query', $page['in']);
        $this->assertSame('integer', $page['schema']['type']);
        $this->assertSame(1, $page['schema']['example']);
    }

    #[Test]
    public function auto_detected_path_parameters(): void
    {
        $spec = $this->makeGenerator()->toArray();
        $paths = (array) $spec['paths'];

        // /users/{id} should have an auto-detected path param
        $deletePath = $paths['/users/{id}'] ?? null;
        $this->assertNotNull($deletePath);

        $params = $deletePath['delete']['parameters'] ?? [];
        $names  = array_column($params, 'name');
        $this->assertContains('id', $names);
    }

    // ── Request Body ───────────────────────────────────────────

    #[Test]
    public function request_body_from_attribute(): void
    {
        $spec = $this->makeGenerator()->toArray();
        $paths = (array) $spec['paths'];

        $post = $paths['/users']['post'] ?? null;
        $this->assertNotNull($post);
        $this->assertArrayHasKey('requestBody', $post);
        $this->assertTrue($post['requestBody']['required']);
        $this->assertSame('User creation data', $post['requestBody']['description']);

        $schema = $post['requestBody']['content']['application/json']['schema'];
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('properties', $schema);
    }

    // ── Responses ──────────────────────────────────────────────

    #[Test]
    public function multiple_responses(): void
    {
        $spec = $this->makeGenerator()->toArray();
        $paths = (array) $spec['paths'];

        $show = $paths['/users/{id:\d+}']['get'] ?? null;
        $this->assertNotNull($show);
        $this->assertArrayHasKey('200', $show['responses']);
        $this->assertArrayHasKey('404', $show['responses']);
        $this->assertSame('User not found', $show['responses']['404']['description']);
    }

    #[Test]
    public function response_with_schema(): void
    {
        $spec = $this->makeGenerator()->toArray();
        $paths = (array) $spec['paths'];

        $get = $paths['/users']['get'];
        $content = $get['responses']['200']['content'] ?? null;
        $this->assertNotNull($content);
        $this->assertArrayHasKey('application/json', $content);
        $this->assertSame('array', $content['application/json']['schema']['type']);
    }

    // ── Deprecated ─────────────────────────────────────────────

    #[Test]
    public function deprecated_endpoint(): void
    {
        $spec = $this->makeGenerator()->toArray();
        $paths = (array) $spec['paths'];

        $delete = $paths['/users/{id}']['delete'] ?? null;
        $this->assertNotNull($delete);
        $this->assertTrue($delete['deprecated']);
        $this->assertStringContainsString('Deprecated', $delete['description']);
    }

    // ── Hidden ─────────────────────────────────────────────────

    #[Test]
    public function hidden_endpoint_excluded(): void
    {
        $spec = $this->makeGenerator()->toArray();
        $paths = (array) $spec['paths'];

        $this->assertArrayNotHasKey('/internal/health', $paths);
    }

    // ── Security ───────────────────────────────────────────────

    #[Test]
    public function class_level_security_applied(): void
    {
        $spec = $this->makeGenerator()->toArray();
        $paths = (array) $spec['paths'];

        $get = $paths['/users']['get'];
        $this->assertArrayHasKey('security', $get);
        $this->assertSame([['bearerAuth' => []]], $get['security']);
    }

    // ── Output Formats ─────────────────────────────────────────

    #[Test]
    public function to_json_returns_valid_json(): void
    {
        $json = $this->makeGenerator()->toJson();
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('3.1.0', $decoded['openapi']);
        $this->assertArrayHasKey('paths', $decoded);
    }

    #[Test]
    public function caching_returns_same_reference(): void
    {
        $gen = $this->makeGenerator();
        $a = $gen->toArray();
        $b = $gen->toArray();
        $this->assertSame($a, $b);
    }

    #[Test]
    public function invalidate_clears_cache(): void
    {
        $gen = $this->makeGenerator();
        $gen->toArray();
        $gen->invalidate();
        $fresh = $gen->toArray();
        $this->assertSame('3.1.0', $fresh['openapi']); // still valid
    }

    // ── Middleware ──────────────────────────────────────────────

    #[Test]
    public function middleware_serves_json_at_spec_path(): void
    {
        $gen = $this->makeGenerator();
        $mw  = new OpenApiMiddleware($gen);

        $request  = new ServerRequest('GET', new Uri('http://localhost/openapi.json'), Stream::empty());
        $response = $mw->process($request, new PassthroughHandler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('3.1.0', $data['openapi']);
    }

    #[Test]
    public function middleware_serves_swagger_ui(): void
    {
        $gen = $this->makeGenerator();
        $mw  = new OpenApiMiddleware($gen);

        $request  = new ServerRequest('GET', new Uri('http://localhost/docs'), Stream::empty());
        $response = $mw->process($request, new PassthroughHandler());

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('swagger-ui', $body);
        $this->assertStringContainsString('/openapi.json', $body);
    }

    #[Test]
    public function middleware_serves_redoc(): void
    {
        $gen = $this->makeGenerator();
        $mw  = new OpenApiMiddleware($gen, uiTheme: 'redoc');

        $request  = new ServerRequest('GET', new Uri('http://localhost/docs'), Stream::empty());
        $response = $mw->process($request, new PassthroughHandler());

        $body = (string) $response->getBody();
        $this->assertStringContainsString('redoc', $body);
    }

    #[Test]
    public function middleware_passes_through_other_paths(): void
    {
        $gen = $this->makeGenerator();
        $mw  = new OpenApiMiddleware($gen);

        $request  = new ServerRequest('GET', new Uri('http://localhost/api/users'), Stream::empty());
        $response = $mw->process($request, new PassthroughHandler());

        $this->assertSame('passthrough', (string) $response->getBody());
    }

    #[Test]
    public function middleware_disabled_passes_through(): void
    {
        $gen = $this->makeGenerator();
        $mw  = new OpenApiMiddleware($gen, enabled: false);

        $request  = new ServerRequest('GET', new Uri('http://localhost/openapi.json'), Stream::empty());
        $response = $mw->process($request, new PassthroughHandler());

        $this->assertSame('passthrough', (string) $response->getBody());
    }

    // ── Attribute Unit Tests ───────────────────────────────────

    #[Test]
    public function api_info_attribute_defaults(): void
    {
        $info = new ApiInfo();
        $this->assertSame('MonkeysLegion API', $info->title);
        $this->assertSame('1.0.0', $info->version);
    }

    #[Test]
    public function request_body_attribute(): void
    {
        $body = new RequestBody(
            description: 'Test',
            required: false,
            contentType: 'multipart/form-data',
        );
        $this->assertSame('Test', $body->description);
        $this->assertFalse($body->required);
        $this->assertSame('multipart/form-data', $body->contentType);
    }

    #[Test]
    public function api_response_attribute(): void
    {
        $resp = new ApiResponse(201, 'Created', headers: ['X-Id' => ['schema' => ['type' => 'string']]]);
        $this->assertSame(201, $resp->status);
        $this->assertSame('Created', $resp->description);
        $this->assertArrayHasKey('X-Id', $resp->headers);
    }

    #[Test]
    public function api_param_attribute(): void
    {
        $param = new ApiParam(name: 'limit', in: 'query', type: 'integer', format: 'int32', deprecated: true);
        $this->assertSame('limit', $param->name);
        $this->assertSame('int32', $param->format);
        $this->assertTrue($param->deprecated);
    }

    #[Test]
    public function api_security_attribute(): void
    {
        $sec = new ApiSecurity('oauth2', ['read', 'write']);
        $this->assertSame('oauth2', $sec->scheme);
        $this->assertSame(['read', 'write'], $sec->scopes);
    }

    #[Test]
    public function api_deprecated_attribute(): void
    {
        $dep = new ApiDeprecated('Use v2 endpoint.');
        $this->assertSame('Use v2 endpoint.', $dep->reason);
    }
}
