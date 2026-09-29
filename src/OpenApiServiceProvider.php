<?php
declare(strict_types=1);

namespace MonkeysLegion\OpenApi;

use MonkeysLegion\DI\Attributes\Singleton;
use MonkeysLegion\Router\RouteCollection;

/**
 * MonKeysLegion Framework — OpenAPI Package
 *
 * Service provider that registers OpenAPI services in the DI container.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[Singleton]
final class OpenApiServiceProvider
{
    /**
     * Register OpenAPI services.
     *
     * @param array<string, mixed> $config Configuration from observability.mlc.
     */
    public function register(RouteCollection $routes, array $config = []): OpenApiGenerator
    {
        $generator = new OpenApiGenerator(
            routes: $routes,
            title: $config['title'] ?? 'MonKeysLegion API',
            version: $config['version'] ?? '1.0.0',
            description: $config['description'] ?? '',
            servers: $config['servers'] ?? [],
            securitySchemes: $config['security_schemes'] ?? [],
        );

        return $generator;
    }
}
