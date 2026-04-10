<?php
declare(strict_types=1);

namespace MonkeysLegion\OpenApi\Attributes;

use Attribute;

/**
 * MonkeysLegion Framework — OpenAPI Package
 *
 * Documents a security requirement for an endpoint.
 *
 * ```php
 * #[ApiSecurity('bearerAuth')]
 * #[ApiSecurity('apiKey', scopes: ['read', 'write'])]
 * ```
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class ApiSecurity
{
    /**
     * @param string       $scheme Security scheme name (must match a defined securityScheme).
     * @param list<string> $scopes Required scopes for OAuth2 / OpenID Connect.
     */
    public function __construct(
        public readonly string $scheme = 'bearerAuth',
        public readonly array  $scopes = [],
    ) {}
}
