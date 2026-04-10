# MonkeysLegion OpenAPI

Automatic **OpenAPI 3.1** specification generator and documentation middleware for the [MonkeysLegion](https://github.com/MonkeysCloud) framework.

Generate a complete API specification from your route definitions and PHP 8.4 attributes — zero manual YAML/JSON editing required.

---

## Features

| Feature | Description |
|---|---|
| **Attribute-driven** | Annotate controllers with `#[ApiParam]`, `#[ApiResponse]`, `#[RequestBody]`, `#[ApiSecurity]`, `#[ApiDeprecated]`, `#[ApiHidden]` |
| **Auto parameter detection** | Path parameters like `{id}` are automatically documented |
| **Security schemes** | Bearer JWT, API key, OAuth2 — configurable per-controller or per-method |
| **Multiple responses** | Document 200, 404, 422, etc. with schemas per endpoint |
| **Request body schemas** | Inline JSON Schema or `$ref` to DTO classes |
| **Swagger UI + ReDoc** | Built-in middleware serves either documentation UI |
| **Dark mode** | Optional dark theme for Swagger UI |
| **Caching** | Spec is cached after first build; invalidate on demand |
| **YAML export** | `toYaml()` when `ext-yaml` is available |
| **Enable/disable** | Toggle docs off in production with a single flag |

---

## Installation

```bash
composer require monkeyscloud/monkeyslegion-openapi
```

### Requirements

- PHP 8.4+
- `monkeyscloud/monkeyslegion-router` ^2.0
- `monkeyscloud/monkeyslegion-http` ^2.0

---

## Quick Start

### 1. Annotate your controller

```php
<?php
declare(strict_types=1);

namespace App\Controllers;

use MonkeysLegion\OpenApi\Attributes\ApiInfo;
use MonkeysLegion\OpenApi\Attributes\ApiParam;
use MonkeysLegion\OpenApi\Attributes\ApiResponse;
use MonkeysLegion\OpenApi\Attributes\ApiSecurity;
use MonkeysLegion\OpenApi\Attributes\RequestBody;
use MonkeysLegion\Router\Attributes\Route;

#[ApiInfo(
    title: 'User Service API',
    version: '2.0.0',
    description: 'Manages user accounts and profiles.',
    contactName: 'MonkeysCloud Team',
    contactEmail: 'support@monkeys.cloud',
)]
#[ApiSecurity('bearerAuth')]
final class UserController
{
    #[Route('GET', '/users', name: 'user_list', summary: 'List users', tags: ['Users'])]
    #[ApiParam(name: 'page', in: 'query', type: 'integer', example: 1)]
    #[ApiParam(name: 'per_page', in: 'query', type: 'integer', example: 25, required: false)]
    #[ApiResponse(200, 'User list', schema: [
        'type' => 'array',
        'items' => ['$ref' => '#/components/schemas/User'],
    ])]
    public function index(): ResponseInterface { /* ... */ }

    #[Route('GET', '/users/{id:\d+}', name: 'user_show', summary: 'Get user', tags: ['Users'])]
    #[ApiParam(name: 'id', in: 'path', type: 'integer')]
    #[ApiResponse(200, 'User found')]
    #[ApiResponse(404, 'User not found')]
    public function show(int $id): ResponseInterface { /* ... */ }

    #[Route('POST', '/users', name: 'user_create', summary: 'Create user', tags: ['Users'])]
    #[RequestBody(
        description: 'User creation payload',
        schema: [
            'type' => 'object',
            'properties' => [
                'name'  => ['type' => 'string'],
                'email' => ['type' => 'string', 'format' => 'email'],
            ],
            'required' => ['name', 'email'],
        ],
    )]
    #[ApiResponse(201, 'User created')]
    #[ApiResponse(422, 'Validation error')]
    public function create(): ResponseInterface { /* ... */ }
}
```

### 2. Register the middleware

```php
use MonkeysLegion\OpenApi\OpenApiGenerator;
use MonkeysLegion\OpenApi\OpenApiMiddleware;

$generator = new OpenApiGenerator(
    routes: $routeCollection,
    title: 'My API',
    version: '1.0.0',
    servers: [
        ['url' => 'https://api.example.com', 'description' => 'Production'],
        ['url' => 'http://localhost:8080',    'description' => 'Local'],
    ],
);

// Add to your middleware pipeline
$handler->pipe(new OpenApiMiddleware(
    generator: $generator,
    jsonPath:  '/openapi.json',    // JSON spec endpoint
    uiPath:    '/docs',            // Swagger UI / ReDoc endpoint
    uiTheme:   'swagger',         // 'swagger' or 'redoc'
    darkMode:  false,              // dark Swagger UI theme
    enabled:   true,               // set false in production
));
```

### 3. Access your docs

- **JSON spec**: `GET /openapi.json`
- **Swagger UI**: `GET /docs`

---

## Attributes Reference

### `#[ApiInfo]` — Controller class

Defines API-level metadata. Place on the controller class.

```php
#[ApiInfo(
    title: 'My API',
    version: '2.0.0',
    description: 'API description',
    contactName: 'Team',
    contactEmail: 'team@example.com',
    contactUrl: 'https://example.com',
    licenseName: 'MIT',
    licenseUrl: 'https://opensource.org/licenses/MIT',
    termsOfService: 'https://example.com/tos',
)]
```

### `#[ApiParam]` — Method (repeatable)

Documents a path, query, header, or cookie parameter.

```php
#[ApiParam(
    name: 'id',
    in: 'path',           // 'path', 'query', 'header', 'cookie'
    type: 'integer',       // JSON Schema type
    format: 'int64',       // JSON Schema format
    required: true,
    description: 'The user ID',
    example: 42,
    deprecated: false,
)]
```

> **Note:** Path parameters from route templates (e.g., `{id}`) are **auto-detected** if not explicitly annotated.

### `#[ApiResponse]` — Method (repeatable)

Documents a response for a specific status code.

```php
#[ApiResponse(
    status: 200,
    description: 'Success',
    contentType: 'application/json',
    schema: ['type' => 'object', 'properties' => [...]],
    headers: ['X-Request-Id' => ['schema' => ['type' => 'string']]],
)]
```

### `#[RequestBody]` — Method

Documents the request body and its schema.

```php
#[RequestBody(
    description: 'User data',
    required: true,
    contentType: 'application/json',
    schema: ['type' => 'object', ...],
    ref: App\DTO\CreateUserDto::class,  // alternative: reference a DTO
)]
```

### `#[ApiSecurity]` — Class or Method (repeatable)

Declares security requirements.

```php
#[ApiSecurity('bearerAuth')]
#[ApiSecurity('oauth2', scopes: ['users:read', 'users:write'])]
```

### `#[ApiDeprecated]` — Method

Marks an endpoint as deprecated in the spec.

```php
#[ApiDeprecated('Use PATCH /v2/users/{id} instead.')]
```

### `#[ApiHidden]` — Method

Excludes an endpoint from the generated specification entirely.

```php
#[ApiHidden]
```

---

## Generator API

```php
$generator = new OpenApiGenerator(
    routes: $routeCollection,
    title: 'My API',
    version: '1.0.0',
    description: 'Optional description',
    servers: [['url' => 'https://api.example.com']],
    securitySchemes: [
        'bearerAuth' => [
            'type'         => 'http',
            'scheme'       => 'bearer',
            'bearerFormat' => 'JWT',
        ],
        'apiKey' => [
            'type' => 'apiKey',
            'in'   => 'header',
            'name' => 'X-API-Key',
        ],
    ],
);

// Get as PHP array
$spec = $generator->toArray();

// Get as JSON
$json = $generator->toJson();

// Get as YAML (requires ext-yaml)
$yaml = $generator->toYaml();

// Clear cached spec
$generator->invalidate();
```

---

## Middleware Options

| Parameter | Type | Default | Description |
|---|---|---|---|
| `generator` | `OpenApiGenerator` | — | Required. The spec generator. |
| `jsonPath` | `string` | `/openapi.json` | URI path for the JSON spec. |
| `uiPath` | `string` | `/docs` | URI path for the documentation UI. |
| `uiTheme` | `string` | `'swagger'` | `'swagger'` or `'redoc'`. |
| `darkMode` | `bool` | `false` | Enable dark Swagger UI theme. |
| `enabled` | `bool` | `true` | Set `false` to disable docs in production. |

---

## Testing

```bash
composer test
```

31 tests, 69 assertions covering:
- Spec generation (version, info, paths, operations)
- All 7 attributes
- Parameter auto-detection
- Request body, responses, security, deprecated, hidden
- Middleware (Swagger UI, ReDoc, passthrough, disabled)
- Caching and invalidation

---

## License

MIT © [MonkeysCloud](https://monkeys.cloud)
