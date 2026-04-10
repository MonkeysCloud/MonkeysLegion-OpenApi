<?php
declare(strict_types=1);

namespace MonkeysLegion\OpenApi\Attributes;

use Attribute;

/**
 * MonkeysLegion Framework — OpenAPI Package
 *
 * Hides an endpoint from the generated OpenAPI specification.
 *
 * Useful for internal/debug/admin endpoints that should not
 * appear in public documentation.
 *
 * ```php
 * #[ApiHidden]
 * ```
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class ApiHidden {}
