<?php
declare(strict_types=1);

namespace MonkeysLegion\OpenApi\Tests\Unit;

use MonkeysLegion\OpenApi\SchemaGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the SchemaGenerator.
 */
final class SchemaGeneratorTest extends TestCase
{
    private SchemaGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new SchemaGenerator();
    }

    #[Test]
    public function generates_object_schema_for_dto(): void
    {
        $schema = $this->generator->generate(SimpleDto::class);

        self::assertSame('object', $schema['type']);
        self::assertArrayHasKey('properties', $schema);
    }

    #[Test]
    public function maps_php_types_to_json_schema_types(): void
    {
        $schema = $this->generator->generate(TypedDto::class);

        self::assertSame('integer', $schema['properties']['id']['type']);
        self::assertSame('string', $schema['properties']['name']['type']);
        self::assertSame('number', $schema['properties']['price']['type']);
        self::assertSame('boolean', $schema['properties']['active']['type']);
    }

    #[Test]
    public function nullable_property_marked_nullable(): void
    {
        $schema = $this->generator->generate(NullableDto::class);

        self::assertTrue($schema['properties']['optional']['nullable'] ?? false);
    }

    #[Test]
    public function default_values_included(): void
    {
        $schema = $this->generator->generate(DefaultDto::class);

        self::assertSame(true, $schema['properties']['active']['default'] ?? null);
    }

    #[Test]
    public function generates_multiple_schemas(): void
    {
        $schemas = $this->generator->generateMany([SimpleDto::class, TypedDto::class]);

        self::assertCount(2, $schemas);
        self::assertArrayHasKey('SimpleDto', $schemas);
        self::assertArrayHasKey('TypedDto', $schemas);
    }
}

// ── Test Fixtures ──────────────────────────────────────────────

final class SimpleDto
{
    public string $name;
    public int $age;
}

final class TypedDto
{
    public int $id;
    public string $name;
    public float $price;
    public bool $active;
}

final class NullableDto
{
    public ?string $optional = null;
    public string $required;
}

final class DefaultDto
{
    public bool $active = true;
    public string $name = '';
}
