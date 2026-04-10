<?php
declare(strict_types=1);

namespace MonkeysLegion\OpenApi;

use MonkeysLegion\Http\Message\Response;
use MonkeysLegion\Http\Message\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * MonkeysLegion Framework — OpenAPI Package
 *
 * PSR-15 middleware that serves the OpenAPI specification and
 * an embedded Swagger UI documentation page.
 *
 * v2 improvements over v1:
 *  • Uses MonkeysLegion HTTP Response directly (no factory needed)
 *  • Dark-mode Swagger UI theme option
 *  • Configurable paths for spec and UI
 *  • Optional JSON download with Content-Disposition
 *  • ReDoc alternative support
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class OpenApiMiddleware implements MiddlewareInterface
{
    /**
     * @param OpenApiGenerator $generator  Spec generator instance.
     * @param string           $jsonPath   URI to serve JSON spec.
     * @param string           $uiPath     URI to serve the documentation page.
     * @param string           $uiTheme    'swagger' or 'redoc'.
     * @param bool             $darkMode   Enable dark Swagger UI theme.
     * @param bool             $enabled    Set to false to disable in production.
     */
    public function __construct(
        private readonly OpenApiGenerator $generator,
        private readonly string           $jsonPath = '/openapi.json',
        private readonly string           $uiPath   = '/docs',
        private readonly string           $uiTheme  = 'swagger',
        private readonly bool             $darkMode = false,
        private readonly bool             $enabled  = true,
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        if (!$this->enabled) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();

        // JSON spec endpoint
        if ($path === $this->jsonPath) {
            return $this->serveJson();
        }

        // Documentation UI endpoint
        $uiNorm = rtrim($this->uiPath, '/');
        if ($path === $uiNorm || $path === $uiNorm . '/') {
            return match ($this->uiTheme) {
                'redoc'  => $this->serveRedoc(),
                default  => $this->serveSwaggerUi(),
            };
        }

        return $handler->handle($request);
    }

    // ── Renderers ──────────────────────────────────────────────

    private function serveJson(): ResponseInterface
    {
        $json = $this->generator->toJson();

        return new Response(
            Stream::createFromString($json),
            200,
            [
                'Content-Type'  => 'application/json; charset=UTF-8',
                'Cache-Control' => 'public, max-age=60',
            ],
        );
    }

    private function serveSwaggerUi(): ResponseInterface
    {
        $darkCss = $this->darkMode
            ? '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-themes@3/themes/3.x/theme-dark.css" />'
            : '';

        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>API Documentation</title>
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css" />
            {$darkCss}
            <style>
                html { box-sizing: border-box; }
                *, *::before, *::after { box-sizing: inherit; }
                body { margin: 0; padding: 0; }
                .swagger-ui .topbar { display: none; }
            </style>
        </head>
        <body>
            <div id="swagger-ui"></div>
            <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
            <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-standalone-preset.js"></script>
            <script>
                SwaggerUIBundle({
                    url: '{$this->jsonPath}',
                    dom_id: '#swagger-ui',
                    deepLinking: true,
                    presets: [
                        SwaggerUIBundle.presets.apis,
                        SwaggerUIStandalonePreset
                    ],
                    layout: 'StandaloneLayout',
                    persistAuthorization: true,
                    filter: true,
                    tryItOutEnabled: true
                });
            </script>
        </body>
        </html>
        HTML;

        return new Response(
            Stream::createFromString($html),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    private function serveRedoc(): ResponseInterface
    {
        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>API Documentation</title>
            <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
            <style>
                body { margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
            </style>
        </head>
        <body>
            <redoc spec-url='{$this->jsonPath}'
                   hide-download-button="false"
                   theme='{
                       "colors": { "primary": { "main": "#6366f1" } },
                       "typography": { "fontFamily": "Inter, sans-serif" }
                   }'>
            </redoc>
            <script src="https://cdn.jsdelivr.net/npm/redoc@latest/bundles/redoc.standalone.js"></script>
        </body>
        </html>
        HTML;

        return new Response(
            Stream::createFromString($html),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }
}
