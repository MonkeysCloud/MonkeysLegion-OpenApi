<?php
declare(strict_types=1);

namespace MonkeysLegion\OpenApi\Attributes;

use Attribute;

/**
 * MonkeysLegion Framework — OpenAPI Package
 *
 * Documents a path/query/header/cookie parameter for an endpoint.
 *
 * Repeatable — apply once per parameter.
 *
 * ```php
 * #[ApiParam(name: 'id', in: 'path', type: 'integer', required: true)]
 * #[ApiParam(name: 'page', in: 'query', type: 'integer', example: 1)]
 * ```
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class ApiParam
{
    /**
     * @param string               $name        Parameter name.
     * @param string               $in          Location: 'path', 'query', 'header', 'cookie'.
     * @param string               $type        JSON Schema type: 'string', 'integer', etc.
     * @param string               $format      JSON Schema format: 'uuid', 'email', 'date-time', etc.
     * @param bool                 $required    Whether the parameter is required.
     * @param string               $description Human-readable description.
     * @param mixed                $example     Example value.
     * @param array<string, mixed> $schema      Override full JSON Schema.
     * @param bool                 $deprecated  Mark parameter as deprecated.
     */
    public function __construct(
        public readonly string  $name        = '',
        public readonly string  $in          = 'path',
        public readonly string  $type        = 'string',
        public readonly string  $format      = '',
        public readonly bool    $required    = true,
        public readonly string  $description = '',
        public readonly mixed   $example     = null,
        public readonly array   $schema      = [],
        public readonly bool    $deprecated  = false,
    ) {}
}
