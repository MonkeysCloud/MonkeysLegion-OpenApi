<?php
declare(strict_types=1);

namespace MonkeysLegion\OpenApi\Attributes;

use Attribute;

/**
 * MonkeysLegion Framework — OpenAPI Package
 *
 * Documents a request body schema for an endpoint.
 *
 * ```php
 * #[RequestBody(
 *     description: 'User creation payload',
 *     required: true,
 *     contentType: 'application/json',
 *     schema: ['type' => 'object', 'properties' => [
 *         'name'  => ['type' => 'string'],
 *         'email' => ['type' => 'string', 'format' => 'email'],
 *     ], 'required' => ['name', 'email']],
 * )]
 * ```
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class RequestBody
{
    /**
     * @param string               $description Short description of the body.
     * @param bool                 $required    Whether the body is mandatory.
     * @param string               $contentType MIME type (default: application/json).
     * @param array<string, mixed> $schema      JSON Schema definition.
     * @param class-string|null    $ref         Reference DTO class for auto-schema.
     */
    public function __construct(
        public readonly string  $description = '',
        public readonly bool    $required    = true,
        public readonly string  $contentType = 'application/json',
        public readonly array   $schema      = [],
        public readonly ?string $ref         = null,
    ) {}
}
