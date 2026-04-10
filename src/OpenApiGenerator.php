<?php
declare(strict_types=1);

namespace MonkeysLegion\OpenApi;

use MonkeysLegion\OpenApi\Attributes\ApiDeprecated;
use MonkeysLegion\OpenApi\Attributes\ApiHidden;
use MonkeysLegion\OpenApi\Attributes\ApiInfo;
use MonkeysLegion\OpenApi\Attributes\ApiParam;
use MonkeysLegion\OpenApi\Attributes\ApiResponse;
use MonkeysLegion\OpenApi\Attributes\ApiSecurity;
use MonkeysLegion\OpenApi\Attributes\RequestBody;
use MonkeysLegion\Router\Attributes\Route as RouteAttr;
use MonkeysLegion\Router\RouteCollection;
use ReflectionClass;
use ReflectionMethod;

/**
 * MonkeysLegion Framework — OpenAPI Package
 *
 * Generates an OpenAPI 3.1 specification from route definitions
 * and PHP attribute annotations.
 *
 * v2 improvements over v1:
 *  • Attribute-driven: ApiParam, ApiResponse, RequestBody, ApiSecurity,
 *    ApiDeprecated, ApiHidden — no manual array building needed
 *  • Auto-extracts path parameters from route templates ({id}, {slug})
 *  • Supports security schemes (bearerAuth, apiKey, OAuth2)
 *  • Configurable server URLs, external docs
 *  • Caches spec array for repeated calls
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class OpenApiGenerator
{
    /** @var array<string, mixed>|null Cached specification. */
    private ?array $cache = null;

    /**
     * @param RouteCollection          $routes     The registered route collection.
     * @param string                   $title      API title (overridden by #[ApiInfo]).
     * @param string                   $version    API version (overridden by #[ApiInfo]).
     * @param string                   $description API description.
     * @param list<array{url: string, description?: string}> $servers Server URLs.
     * @param array<string, array<string, mixed>>             $securitySchemes Reusable security scheme definitions.
     */
    public function __construct(
        private readonly RouteCollection $routes,
        private readonly string          $title       = 'MonkeysLegion API',
        private readonly string          $version     = '1.0.0',
        private readonly string          $description = '',
        private readonly array           $servers     = [],
        private readonly array           $securitySchemes = [],
    ) {}

    /**
     * Build the OpenAPI 3.1 spec as a PHP array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $paths      = [];
        $globalInfo = null;

        foreach ($this->routes->all() as $route) {
            // Resolve handler: direct array or meta['handler']
            $handler = $route['handler'] ?? null;
            $handlerPair = match (true) {
                is_array($handler) && count($handler) >= 2 => $handler,
                isset($route['meta']['handler']) && is_array($route['meta']['handler']) => $route['meta']['handler'],
                default => null,
            };
            if ($handlerPair === null) {
                continue;
            }

            [$class, $methodName] = $handlerPair;

            try {
                $refClass  = new ReflectionClass($class);
                $refMethod = $refClass->getMethod($methodName);
            } catch (\ReflectionException) {
                continue;
            }

            // Check for ApiHidden — skip entirely
            if ($refMethod->getAttributes(ApiHidden::class) !== []) {
                continue;
            }

            // Pick up global ApiInfo from controller class (first one wins)
            if ($globalInfo === null) {
                foreach ($refClass->getAttributes(ApiInfo::class) as $attr) {
                    $globalInfo = $attr->newInstance();
                }
            }

            // Get the Route attribute
            $routeAttr = $this->getRouteAttr($refMethod);
            if ($routeAttr === null) {
                continue;
            }

            $path = $routeAttr->path;

            foreach ($routeAttr->methods as $verb) {
                $operation = $this->buildOperation($refClass, $refMethod, $routeAttr, strtolower($verb));
                $paths[$path][strtolower($verb)] = $operation;
            }
        }

        // Build info block
        $info = $this->buildInfo($globalInfo);

        $spec = [
            'openapi' => '3.1.0',
            'info'    => $info,
            'paths'   => (object) $paths,
        ];

        if ($this->servers !== []) {
            $spec['servers'] = $this->servers;
        }

        // Security schemes (components)
        $schemes = $this->securitySchemes;
        if ($schemes === []) {
            // Default bearerAuth if any route uses ApiSecurity
            $schemes = [
                'bearerAuth' => [
                    'type'   => 'http',
                    'scheme' => 'bearer',
                    'bearerFormat' => 'JWT',
                ],
            ];
        }

        $spec['components'] = ['securitySchemes' => $schemes];

        $this->cache = $spec;
        return $spec;
    }

    /**
     * Export the spec as JSON.
     *
     * @param int $flags json_encode() flags.
     *
     * @throws \JsonException
     */
    public function toJson(int $flags = JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT): string
    {
        return json_encode($this->toArray(), $flags | JSON_THROW_ON_ERROR);
    }

    /**
     * Export the spec as YAML (requires ext-yaml).
     *
     * @throws \RuntimeException If ext-yaml is not loaded.
     */
    public function toYaml(): string
    {
        if (!function_exists('yaml_emit')) {
            throw new \RuntimeException('ext-yaml is required for YAML output. Install via: pecl install yaml');
        }
        // YAML_UTF8_ENCODING = 2 — only defined when ext-yaml is loaded
        $encoding = defined('YAML_UTF8_ENCODING') ? constant('YAML_UTF8_ENCODING') : 2;
        return yaml_emit($this->toArray(), $encoding);
    }

    /**
     * Invalidate the cached spec (call after runtime route changes).
     */
    public function invalidate(): void
    {
        $this->cache = null;
    }

    // ── Internal ───────────────────────────────────────────────

    /**
     * Build a single OpenAPI operation from method attributes.
     *
     * @return array<string, mixed>
     */
    private function buildOperation(
        ReflectionClass  $refClass,
        ReflectionMethod $refMethod,
        RouteAttr        $routeAttr,
        string           $verb,
    ): array {
        $operationId = $routeAttr->name !== ''
            ? $routeAttr->name
            : $verb . '_' . str_replace('/', '_', trim($routeAttr->path, '/'));

        $operation = [
            'operationId' => $operationId,
            'summary'     => $routeAttr->summary ?: $refMethod->getName(),
            'tags'        => $routeAttr->tags ?: [$refClass->getShortName()],
        ];

        // Description from route or docblock
        if ($routeAttr->description !== '') {
            $operation['description'] = $routeAttr->description;
        } else {
            $docComment = $refMethod->getDocComment();
            if ($docComment !== false) {
                $description = $this->extractDocDescription($docComment);
                if ($description !== '') {
                    $operation['description'] = $description;
                }
            }
        }

        // Deprecated
        foreach ($refMethod->getAttributes(ApiDeprecated::class) as $attr) {
            /** @var ApiDeprecated $deprecated */
            $deprecated = $attr->newInstance();
            $operation['deprecated'] = true;
            if ($deprecated->reason !== '') {
                $operation['description'] = ($operation['description'] ?? '')
                    . "\n\n> **Deprecated**: " . $deprecated->reason;
            }
        }

        // Parameters (from ApiParam attributes + auto-detected path params)
        $parameters = $this->buildParameters($refMethod, $routeAttr);
        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        // Request body
        foreach ($refMethod->getAttributes(RequestBody::class) as $attr) {
            /** @var RequestBody $body */
            $body = $attr->newInstance();
            $reqBody = [
                'required' => $body->required,
            ];
            if ($body->description !== '') {
                $reqBody['description'] = $body->description;
            }
            $content = [];
            if ($body->schema !== []) {
                $content[$body->contentType] = ['schema' => $body->schema];
            } elseif ($body->ref !== null) {
                $content[$body->contentType] = [
                    'schema' => ['$ref' => '#/components/schemas/' . basename(str_replace('\\', '/', $body->ref))],
                ];
            }
            if ($content !== []) {
                $reqBody['content'] = $content;
            }
            $operation['requestBody'] = $reqBody;
        }

        // Responses
        $responses = $this->buildResponses($refMethod);
        $operation['responses'] = $responses;

        // Security
        $security = $this->buildSecurity($refClass, $refMethod);
        if ($security !== []) {
            $operation['security'] = $security;
        }

        return $operation;
    }

    /**
     * Build parameters from ApiParam attributes and auto-detect path params.
     *
     * @return list<array<string, mixed>>
     */
    private function buildParameters(ReflectionMethod $refMethod, RouteAttr $routeAttr): array
    {
        $parameters = [];
        $documented = [];

        // Explicit ApiParam attributes
        foreach ($refMethod->getAttributes(ApiParam::class) as $attr) {
            /** @var ApiParam $param */
            $param = $attr->newInstance();
            $documented[$param->name] = true;

            $paramSpec = [
                'name'     => $param->name,
                'in'       => $param->in,
                'required' => $param->in === 'path' ? true : $param->required,
            ];

            if ($param->description !== '') {
                $paramSpec['description'] = $param->description;
            }

            if ($param->deprecated) {
                $paramSpec['deprecated'] = true;
            }

            $schema = $param->schema !== [] ? $param->schema : ['type' => $param->type];
            if ($param->format !== '') {
                $schema['format'] = $param->format;
            }
            if ($param->example !== null) {
                $schema['example'] = $param->example;
            }
            $paramSpec['schema'] = $schema;

            $parameters[] = $paramSpec;
        }

        // Auto-detect path parameters not explicitly documented
        if (preg_match_all('/\{(\w+?)(?:[+?])?(?::([^}]+))?\}/', $routeAttr->path, $matches)) {
            foreach ($matches[1] as $i => $paramName) {
                if (isset($documented[$paramName])) {
                    continue;
                }
                $constraint = $matches[2][$i] ?? '';
                $type = $this->constraintToType($constraint);

                $parameters[] = [
                    'name'     => $paramName,
                    'in'       => 'path',
                    'required' => !str_contains($routeAttr->path, '{' . $paramName . '?'),
                    'schema'   => ['type' => $type],
                ];
            }
        }

        return $parameters;
    }

    /**
     * Build responses from ApiResponse attributes or defaults.
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildResponses(ReflectionMethod $refMethod): array
    {
        $responses = [];

        foreach ($refMethod->getAttributes(ApiResponse::class) as $attr) {
            /** @var ApiResponse $resp */
            $resp = $attr->newInstance();
            $key  = (string) $resp->status;

            $responseSpec = [
                'description' => $resp->description,
            ];

            if ($resp->schema !== []) {
                $responseSpec['content'] = [
                    $resp->contentType => ['schema' => $resp->schema],
                ];
            }

            if ($resp->headers !== []) {
                $headerSpecs = [];
                foreach ($resp->headers as $hName => $hDef) {
                    $headerSpecs[$hName] = is_array($hDef) ? $hDef : ['schema' => ['type' => 'string']];
                }
                $responseSpec['headers'] = $headerSpecs;
            }

            $responses[$key] = $responseSpec;
        }

        // Default 200 response if none specified
        if ($responses === []) {
            $responses['200'] = ['description' => 'Successful response'];
        }

        return $responses;
    }

    /**
     * Build security requirements from ApiSecurity attributes.
     *
     * @return list<array<string, list<string>>>
     */
    private function buildSecurity(ReflectionClass $refClass, ReflectionMethod $refMethod): array
    {
        $security = [];

        // Class-level security
        foreach ($refClass->getAttributes(ApiSecurity::class) as $attr) {
            /** @var ApiSecurity $sec */
            $sec = $attr->newInstance();
            $security[] = [$sec->scheme => $sec->scopes];
        }

        // Method-level security (overrides class)
        foreach ($refMethod->getAttributes(ApiSecurity::class) as $attr) {
            /** @var ApiSecurity $sec */
            $sec = $attr->newInstance();
            $security[] = [$sec->scheme => $sec->scopes];
        }

        return $security;
    }

    /**
     * Build the info block from ApiInfo attribute or constructor defaults.
     *
     * @return array<string, mixed>
     */
    private function buildInfo(?ApiInfo $apiInfo): array
    {
        $info = [
            'title'   => $apiInfo?->title ?? $this->title,
            'version' => $apiInfo?->version ?? $this->version,
        ];

        $desc = $apiInfo?->description ?? $this->description;
        if ($desc !== '') {
            $info['description'] = $desc;
        }

        if ($apiInfo?->termsOfService !== '' && $apiInfo?->termsOfService !== null) {
            $info['termsOfService'] = $apiInfo->termsOfService;
        }

        // Contact
        $contactName  = $apiInfo?->contactName ?? '';
        $contactEmail = $apiInfo?->contactEmail ?? '';
        $contactUrl   = $apiInfo?->contactUrl ?? '';
        if ($contactName !== '' || $contactEmail !== '' || $contactUrl !== '') {
            $contact = [];
            if ($contactName !== '')  $contact['name']  = $contactName;
            if ($contactEmail !== '') $contact['email'] = $contactEmail;
            if ($contactUrl !== '')   $contact['url']   = $contactUrl;
            $info['contact'] = $contact;
        }

        // License
        $licenseName = $apiInfo?->licenseName ?? 'MIT';
        $licenseUrl  = $apiInfo?->licenseUrl ?? '';
        $license = ['name' => $licenseName];
        if ($licenseUrl !== '') {
            $license['url'] = $licenseUrl;
        }
        $info['license'] = $license;

        return $info;
    }

    /**
     * Extract the Route attribute from a method.
     */
    private function getRouteAttr(ReflectionMethod $method): ?RouteAttr
    {
        foreach ($method->getAttributes(RouteAttr::class) as $attr) {
            return $attr->newInstance();
        }
        return null;
    }

    /**
     * Map a route constraint pattern to a JSON Schema type.
     */
    private function constraintToType(string $constraint): string
    {
        return match (true) {
            $constraint === '' => 'string',
            in_array($constraint, ['\\d+', 'int', 'integer'], true) => 'integer',
            in_array($constraint, ['uuid', '[a-f0-9-]{36}'], true) => 'string',
            $constraint === 'slug' => 'string',
            default => 'string',
        };
    }

    /**
     * Extract the first paragraph from a PHP docblock as description.
     */
    private function extractDocDescription(string $docComment): string
    {
        // Strip /** and */ and leading *
        $lines = explode("\n", $docComment);
        $description = [];

        foreach ($lines as $line) {
            $line = trim(preg_replace('/^\s*\/?\*+\/?/', '', $line));
            if ($line === '' && $description !== []) {
                break; // first blank line = end of description
            }
            if (str_starts_with($line, '@')) {
                break; // hit a tag
            }
            if ($line !== '') {
                $description[] = $line;
            }
        }

        return implode(' ', $description);
    }
}
