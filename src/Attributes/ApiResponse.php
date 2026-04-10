<?php
declare(strict_types=1);

namespace MonkeysLegion\OpenApi\Attributes;

use Attribute;

/**
 * MonkeysLegion Framework — OpenAPI Package
 *
 * Documents a possible response for an endpoint.
 *
 * This attribute is repeatable — apply multiple times for
 * different status codes.
 *
 * ```php
 * #[ApiResponse(200, 'User retrieved', schema: ['type' => 'object', ...])]
 * #[ApiResponse(404, 'User not found')]
 * #[ApiResponse(422, 'Validation error', schema: [...])]
 * ```
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class ApiResponse
{
    /**
     * @param int                  $status      HTTP status code.
     * @param string               $description Response description.
     * @param string               $contentType Response content type.
     * @param array<string, mixed> $schema      JSON Schema of the body.
     * @param array<string, mixed> $headers     Response headers documentation.
     */
    public function __construct(
        public readonly int    $status      = 200,
        public readonly string $description = 'Successful response',
        public readonly string $contentType = 'application/json',
        public readonly array  $schema      = [],
        public readonly array  $headers     = [],
    ) {}
}
