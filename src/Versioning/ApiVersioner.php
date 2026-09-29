<?php
declare(strict_types=1);

namespace MonkeysLegion\OpenApi\Versioning;

use Psr\Http\Message\ServerRequestInterface;

/**
 * MonKeysLegion Framework — OpenAPI Package
 *
 * Resolves the API version from a request using one of three strategies:
 *
 * 1. URL prefix:  /api/v2/users → version "v2"
 * 2. Accept header: Accept: application/json; version=2 → version "2"
 * 3. Query param: /api/users?version=2 → version "2"
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class ApiVersioner
{
    /**
     * Default versioning strategy.
     */
    private readonly string $defaultStrategy;

    public function __construct(
        string $defaultStrategy = 'url',
        private readonly string $queryParam = 'version',
        private readonly string $headerName = 'Accept',
    ) {
        $this->defaultStrategy = $defaultStrategy;
    }

    /**
     * Resolve the API version from the request.
     *
     * @return string|null The resolved version, or null if not versioned.
     */
    public function resolve(ServerRequestInterface $request): ?string
    {
        // Try URL prefix first: /api/v2/...
        $path = $request->getUri()->getPath();
        if (preg_match('#/api/(v?\d+(?:\.\d+)?)/#i', $path, $matches)) {
            return $matches[1];
        }

        // Try Accept header: application/json; version=2
        $accept = $request->getHeaderLine($this->headerName);
        if (preg_match('/version\s*=\s*"?([^";,\s]+)"?/i', $accept, $matches)) {
            return $matches[1];
        }

        // Try query parameter
        $query = $request->getQueryParams();
        if (isset($query[$this->queryParam])) {
            return (string) $query[$this->queryParam];
        }

        return null;
    }

    /**
     * Get the default versioning strategy.
     */
    public function getDefaultStrategy(): string
    {
        return $this->defaultStrategy;
    }

    /**
     * Parse a version string into a normalized numeric form.
     *
     * "v2" → 2.0, "2.1" → 2.1, "v3" → 3.0
     */
    public function normalizeVersion(string $version): float
    {
        $clean = ltrim(strtolower($version), 'v');
        return (float) $clean;
    }

    /**
     * Compare two version strings.
     *
     * @return int -1 if a < b, 0 if equal, 1 if a > b
     */
    public function compareVersions(string $a, string $b): int
    {
        $na = $this->normalizeVersion($a);
        $nb = $this->normalizeVersion($b);
        return $na <=> $nb;
    }
}
