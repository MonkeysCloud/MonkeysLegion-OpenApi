<?php
declare(strict_types=1);

namespace MonkeysLegion\OpenApi;

use ReflectionClass;
use ReflectionProperty;
use ReflectionNamedType;

/**
 * MonKeysLegion Framework — OpenAPI Package
 *
 * Generates JSON Schema definitions from DTO classes by reading
 * PHP type hints and validation attributes (#[NotBlank], #[Length],
 * #[Range], #[Email], #[Pattern], etc.).
 *
 * The generated schemas are used as components/schemas in the OpenAPI spec.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class SchemaGenerator
{
    /**
     * Generate a JSON Schema for a DTO class.
     *
     * @param class-string $dtoClass The DTO class to reflect on.
     * @return array<string, mixed> JSON Schema definition.
     */
    public function generate(string $dtoClass): array
    {
        $reflection = new ReflectionClass($dtoClass);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        $schema = [
            'type'       => 'object',
            'properties' => [],
        ];

        $required = [];

        foreach ($properties as $property) {
            $name = $property->getName();
            $propSchema = $this->buildPropertySchema($property);

            $schema['properties'][$name] = $propSchema;

            // Check for required attributes
            if ($this->isRequired($property)) {
                $required[] = $name;
            }
        }

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * Generate schemas for multiple DTO classes.
     *
     * @param list<class-string> $dtoClasses
     * @return array<string, array<string, mixed>> Map of class short name => schema.
     */
    public function generateMany(array $dtoClasses): array
    {
        $schemas = [];
        foreach ($dtoClasses as $class) {
            $shortName = basename(str_replace('\\', '/', $class));
            $schemas[$shortName] = $this->generate($class);
        }
        return $schemas;
    }

    /**
     * Build a JSON Schema property definition from a reflected property.
     *
     * @return array<string, mixed>
     */
    private function buildPropertySchema(ReflectionProperty $property): array
    {
        $schema = [];

        // Type from PHP type hint
        $type = $property->getType();
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            // Class type — check for enum
            $className = $type->getName();
            if (enum_exists($className)) {
                $schema['$ref'] = '#/components/schemas/' . basename(str_replace('\\', '/', $className));
                return $schema;
            }
            $schema['$ref'] = '#/components/schemas/' . basename(str_replace('\\', '/', $className));
            return $schema;
        }

        if ($type instanceof ReflectionNamedType) {
            $schema['type'] = $this->phpTypeToJsonSchemaType($type->getName());
            if ($type->allowsNull()) {
                $schema['nullable'] = true;
            }
        } else {
            $schema['type'] = 'string'; // default
        }

        // Apply validation attribute constraints
        foreach ($property->getAttributes() as $attr) {
            $attrClass = $attr->getName();
            $instance = $attr->newInstance();

            $this->applyValidationConstraint($schema, $attrClass, $instance);
        }

        // Default value
        if ($property->hasDefaultValue()) {
            $schema['default'] = $property->getDefaultValue();
        }

        return $schema;
    }

    /**
     * Apply validation attribute constraints to a schema property.
     *
     * @param array<string, mixed> $schema
     * @param string $attrClass Fully qualified attribute class name.
     * @param object $instance Attribute instance.
     */
    private function applyValidationConstraint(array &$schema, string $attrClass, object $instance): void
    {
        // Match by class short name to avoid hard dependency on the validation package
        $shortName = basename(str_replace('\\', '/', $attrClass));

        match ($shortName) {
            'NotBlank'   => $schema['minLength'] = 1,
            'Length'     => $this->applyLength($schema, $instance),
            'Range'      => $this->applyRange($schema, $instance),
            'Min'        => $schema['minimum'] = $instance->value ?? $instance->min ?? 0,
            'Max'        => $schema['maximum'] = $instance->value ?? $instance->max ?? PHP_FLOAT_MAX,
            'Email'      => $schema['format'] = 'email',
            'Url'        => $schema['format'] = 'uri',
            'UuidV4'     => $schema['format'] = 'uuid',
            'Ip'         => $schema['format'] = 'ip',
            'Pattern'    => $schema['pattern'] = $instance->pattern ?? $instance->regex ?? '',
            'Date'       => $schema['format'] = 'date',
            'Decimal'    => $this->applyDecimal($schema, $instance),
            'Choice'     => $this->applyChoice($schema, $instance),
            'Count'      => $this->applyCount($schema, $instance),
            'Json'       => $schema['format'] = 'json',
            default      => null,
        };
    }

    /**
     * Apply Length constraint (min, max).
     */
    private function applyLength(array &$schema, object $instance): void
    {
        if (isset($instance->min)) {
            $schema['minLength'] = $instance->min;
        }
        if (isset($instance->max)) {
            $schema['maxLength'] = $instance->max;
        }
    }

    /**
     * Apply Range constraint (min, max).
     */
    private function applyRange(array &$schema, object $instance): void
    {
        if (isset($instance->min)) {
            $schema['minimum'] = $instance->min;
        }
        if (isset($instance->max)) {
            $schema['maximum'] = $instance->max;
        }
    }

    /**
     * Apply Decimal constraint (precision, scale).
     */
    private function applyDecimal(array &$schema, object $instance): void
    {
        $schema['type'] = 'number';
        if (isset($instance->precision)) {
            $schema['format'] = 'double';
        }
    }

    /**
     * Apply Choice constraint (enum values).
     */
    private function applyChoice(array &$schema, object $instance): void
    {
        $choices = $instance->choices ?? $instance->values ?? [];
        if ($choices !== []) {
            $schema['enum'] = $choices;
        }
    }

    /**
     * Apply Count constraint (min, max items).
     */
    private function applyCount(array &$schema, object $instance): void
    {
        if (isset($instance->min)) {
            $schema['minItems'] = $instance->min;
        }
        if (isset($instance->max)) {
            $schema['maxItems'] = $instance->max;
        }
    }

    /**
     * Check if a property is required (has NotBlank or is non-nullable without default).
     */
    private function isRequired(ReflectionProperty $property): bool
    {
        // Check for NotBlank attribute
        foreach ($property->getAttributes() as $attr) {
            $shortName = basename(str_replace('\\', '/', $attr->getName()));
            if ($shortName === 'NotBlank') {
                return true;
            }
        }

        // Non-nullable, non-default-valued properties are required
        $type = $property->getType();
        if ($type !== null && !$type->allowsNull() && !$property->hasDefaultValue()) {
            return true;
        }

        return false;
    }

    /**
     * Map PHP type to JSON Schema type.
     */
    private function phpTypeToJsonSchemaType(string $phpType): string
    {
        return match ($phpType) {
            'int'       => 'integer',
            'float'     => 'number',
            'bool'      => 'boolean',
            'array'     => 'array',
            'string'    => 'string',
            'mixed'     => 'string',
            default     => 'string',
        };
    }
}
