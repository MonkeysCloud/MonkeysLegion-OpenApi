<?php
declare(strict_types=1);

namespace MonkeysLegion\OpenApi\Attributes;

use Attribute;

/**
 * MonkeysLegion Framework — OpenAPI Package
 *
 * Describes the overall API information for a controller group.
 *
 * Place on the controller class to set the API title, version,
 * description, and contact/license metadata in the generated spec.
 *
 * ```php
 * #[ApiInfo(
 *     title: 'User Service API',
 *     version: '2.1.0',
 *     description: 'Manages user accounts and profiles.',
 * )]
 * final class UserController { ... }
 * ```
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class ApiInfo
{
    public function __construct(
        public readonly string  $title       = 'MonkeysLegion API',
        public readonly string  $version     = '1.0.0',
        public readonly string  $description = '',
        public readonly string  $contactName = '',
        public readonly string  $contactEmail = '',
        public readonly string  $contactUrl  = '',
        public readonly string  $licenseName = 'MIT',
        public readonly string  $licenseUrl  = '',
        public readonly string  $termsOfService = '',
    ) {}
}
