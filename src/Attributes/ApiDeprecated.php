<?php
declare(strict_types=1);

namespace MonkeysLegion\OpenApi\Attributes;

use Attribute;

/**
 * MonkeysLegion Framework — OpenAPI Package
 *
 * Marks an endpoint as deprecated in the generated spec.
 *
 * ```php
 * #[ApiDeprecated('Use GET /v2/users instead.')]
 * ```
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class ApiDeprecated
{
    public function __construct(
        public readonly string $reason = '',
    ) {}
}
