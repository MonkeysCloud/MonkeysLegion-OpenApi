<?php
declare(strict_types=1);

namespace MonkeysLegion\OpenApi;

use MonkeysLegion\Http\Message\Response;
use MonkeysLegion\Http\Message\Stream;
use MonkeysLegion\Router\Attributes\Route;

/**
 * MonKeysLegion Framework — OpenAPI Package
 *
 * Controller that serves the Swagger UI and ReDoc pages.
 * This is an alternative to the OpenApiMiddleware for attribute-routed apps.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class SwaggerUiController
{
    public function __construct(
        private readonly OpenApiGenerator $generator,
        private readonly bool $darkMode = false,
    ) {}

    #[Route('GET', '/_swagger', name: 'openapi.swagger')]
    public function index(): Response
    {
        $jsonPath = '/openapi.json';
        $darkClass = $this->darkMode ? 'dark' : '';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en" class="{$darkClass}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MonKeysLegion API — Swagger UI</title>
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
    <style>
        body { margin: 0; }
        .topbar { display: none; }
        html.dark { background: #1a1a2e; }
    </style>
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
    <script>
        window.onload = function() {
            window.ui = SwaggerUIBundle({
                url: '{$jsonPath}',
                dom_id: '#swagger-ui',
                deepLinking: true,
                presets: [SwaggerUIBundle.presets.apis],
                layout: 'StandaloneLayout',
            });
        };
    </script>
</body>
</html>
HTML;

        return new Response(Stream::createFromString($html), 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    #[Route('GET', '/_redoc', name: 'openapi.redoc')]
    public function redoc(): Response
    {
        $jsonPath = '/openapi.json';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MonKeysLegion API — ReDoc</title>
    <style>
        body { margin: 0; padding: 0; }
        redoc { display: block; }
    </style>
</head>
<body>
    <redoc spec-url="{$jsonPath}"></redoc>
    <script src="https://unpkg.com/redoc@next/bundles/redoc.standalone.js"></script>
</body>
</html>
HTML;

        return new Response(Stream::createFromString($html), 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    #[Route('GET', '/openapi.json', name: 'openapi.spec')]
    public function spec(): Response
    {
        $json = $this->generator->toJson();
        return new Response(Stream::createFromString($json), 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
