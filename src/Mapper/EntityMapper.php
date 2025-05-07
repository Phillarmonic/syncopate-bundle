<?php

namespace Phillarmonic\SyncopateBundle\Mapper;

use Phillarmonic\SyncopateBundle\Attribute\Entity;
use Phillarmonic\SyncopateBundle\Attribute\Field;
use Phillarmonic\SyncopateBundle\Exception\SyncopateValidationException;
use Phillarmonic\SyncopateBundle\Model\EntityDefinition;
use Phillarmonic\SyncopateBundle\Model\FieldDefinition;
use ReflectionClass;
use ReflectionProperty;
use DateTime;
use DateTimeInterface;

class EntityMapper
{
    /**
     * Extract entity definition from class using attributes
     */
    public function extractEntityDefinition(string $className): EntityDefinition
    {
        if (!class_exists($className)) {
            throw new \InvalidArgumentException("Class $className does not exist");
        }

        $reflection = new ReflectionClass($className);
        $attributes = $reflection->getAttributes(Entity::class);

        if (empty($attributes)) {
            throw new \InvalidArgumentException("Class $className is not marked with #[Entity] attribute");
        }

        /** @var Entity $entityAttribute */
        $entityAttribute = $attributes[0]->newInstance();

        $entityName = $entityAttribute->name ?? $this->getDefaultEntityName($reflection);
        $entityDefinition = new EntityDefinition(
            $entityName,
            $entityAttribute->idGenerator ?? EntityDefinition::ID_TYPE_AUTO_INCREMENT,
            $entityAttribute->description
        );

        // Process properties with Field attribute
        foreach ($reflection->getProperties() as $property) {
            $fieldAttributes = $property->getAttributes(Field::class);
            if (empty($fieldAttributes)) {
                continue;
            }

            /** @var Field $fieldAttribute */
            $fieldAttribute = $fieldAttributes[0]->newInstance();

            $fieldName = $fieldAttribute->name ?? $property->getName();
            $fieldType = $fieldAttribute->type ?? $this->inferFieldType($property);

            $fieldDefinition = new FieldDefinition(
                $fieldName,
                $fieldType,
                $fieldAttribute->indexed,
                $fieldAttribute->required,
                $fieldAttribute->nullable,
                $fieldAttribute->unique
            );

            $entityDefinition->addField($fieldDefinition);
        }

        return $entityDefinition;
    }
    /**
     * Map entity data to a PHP object with memory optimization
     */
    public function mapToObject(array $entityData, string $className): object
    {
        $reflection = new ReflectionClass($className);
        $object = $reflection->newInstanceWithoutConstructor();

        if (!isset($entityData['id'])) {
            throw new \InvalidArgumentException("Invalid entity data structure: missing 'id'");
        }

        // Map ID to special property if it exists
        if ($reflection->hasProperty('id')) {
            $idProperty = $reflection->getProperty('id');
            $idProperty->setAccessible(true);
            $idProperty->setValue($object, $entityData['id']);
        }

        // Map fields to properties - only process fields that exist
        if (isset($entityData['fields']) && is_array($entityData['fields'])) {
            $propertyMap = $this->buildPropertyMap($reflection);

            foreach ($entityData['fields'] as $fieldName => $value) {
                // Skip if field not mapped to property
                if (!isset($propertyMap[$fieldName])) {
                    continue;
                }

                $property = $propertyMap[$fieldName]['property'];
                $fieldAttribute = $propertyMap[$fieldName]['attribute'];

                // Convert value based on property type
                $value = $this->convertValueForPhp($value, $property);

                // Set property value
                $property->setAccessible(true);
                $property->setValue($object, $value);
            }
        }

        // Free memory
        gc_collect_cycles();

        return $object;
    }

    /**
     * Map PHP object to entity data with memory optimization
     */
    public function mapFromObject(object $object): array
    {
        $reflection = new ReflectionClass($object);
        $attributes = $reflection->getAttributes(Entity::class);

        if (empty($attributes)) {
            throw new \InvalidArgumentException("Object is not marked with #[Entity] attribute");
        }

        $id = null;
        if ($reflection->hasProperty('id')) {
            $idProperty = $reflection->getProperty('id');
            $idProperty->setAccessible(true);
            $id = $idProperty->getValue($object);
        }

        $fields = [];

        // Only process Field-annotated properties
        $properties = $this->getFieldAnnotatedProperties($reflection);

        foreach ($properties as $property) {
            // Skip id property as it's handled separately
            if ($property['property']->getName() === 'id') {
                continue;
            }

            $fieldProperty = $property['property'];
            $fieldAttribute = $property['attribute'];
            $fieldName = $fieldAttribute->name ?? $fieldProperty->getName();

            $fieldProperty->setAccessible(true);

            // Skip properties that aren't initialized
            if (!$fieldProperty->isInitialized($object)) {
                continue;
            }

            $value = $fieldProperty->getValue($object);

            // Skip null values if not required
            if ($value === null && !$fieldAttribute->required) {
                continue;
            }

            $value = $this->convertValueForApi($value);
            $fields[$fieldName] = $value;
        }

        // For create operation (no ID)
        if ($id === null) {
            return ['fields' => $fields];
        }

        // For update operation
        return [
            'id' => $id,
            'fields' => $fields,
        ];
    }

    /**
     * Validate entity object based on field attributes
     */
    public function validateObject(object $object): void
    {
        $reflection = new ReflectionClass($object);
        $exception = SyncopateValidationException::create();
        $hasViolations = false;

        // Only process Field-annotated properties for validation
        $properties = $this->getFieldAnnotatedProperties($reflection);

        foreach ($properties as $property) {
            $fieldProperty = $property['property'];
            $fieldAttribute = $property['attribute'];

            $fieldProperty->setAccessible(true);

            // Skip validation for uninitialized properties on non-required fields
            if (!$fieldProperty->isInitialized($object)) {
                if ($fieldAttribute->required) {
                    $exception->addViolation(
                        $fieldProperty->getName(),
                        "Field is required but not initialized"
                    );
                    $hasViolations = true;
                }
                continue;
            }

            $value = $fieldProperty->getValue($object);

            // Check required fields
            if ($fieldAttribute->required && $value === null) {
                $exception->addViolation(
                    $fieldProperty->getName(),
                    "Field is required"
                );
                $hasViolations = true;
            }

            // Check nullable fields
            if (!$fieldAttribute->nullable && $value === null) {
                $exception->addViolation(
                    $fieldProperty->getName(),
                    "Field cannot be null"
                );
                $hasViolations = true;
            }
        }

        if ($hasViolations) {
            throw $exception;
        }
    }

    /**
     * Cache for field-annotated properties to avoid repeated reflection
     */
    private array $propertyCache = [];

    /**
     * Clear property cache to free memory
     */
    public function clearCache(): void
    {
        $this->propertyCache = [];
    }

    /**
     * Get all properties with Field attribute
     */
    private function getFieldAnnotatedProperties(ReflectionClass $reflection): array
    {
        $className = $reflection->getName();

        if (isset($this->propertyCache[$className])) {
            return $this->propertyCache[$className];
        }

        $results = [];

        foreach ($reflection->getProperties() as $property) {
            $fieldAttributes = $property->getAttributes(Field::class);
            if (empty($fieldAttributes)) {
                continue;
            }

            /** @var Field $fieldAttribute */
            $fieldAttribute = $fieldAttributes[0]->newInstance();

            $results[] = [
                'property' => $property,
                'attribute' => $fieldAttribute
            ];
        }

        // Cache results for this class
        $this->propertyCache[$className] = $results;

        return $results;
    }

    /**
     * Build a map of field names to properties for faster lookups
     */
    private function buildPropertyMap(ReflectionClass $reflection): array
    {
        $map = [];
        $properties = $this->getFieldAnnotatedProperties($reflection);

        foreach ($properties as $property) {
            $fieldProperty = $property['property'];
            $fieldAttribute = $property['attribute'];
            $fieldName = $fieldAttribute->name ?? $fieldProperty->getName();

            $map[$fieldName] = [
                'property' => $fieldProperty,
                'attribute' => $fieldAttribute
            ];
        }

        return $map;
    }

    /**
     * Infer field type from property
     */
    private function inferFieldType(ReflectionProperty $property): string
    {
        $type = $property->getType();
        if ($type === null) {
            return FieldDefinition::TYPE_STRING;
        }

        $typeName = $type->getName();
        return FieldDefinition::mapPhpTypeToSyncopateType($typeName);
    }

    /**
     * Get default entity name from class name
     */
    private function getDefaultEntityName(ReflectionClass $reflection): string
    {
        $shortName = $reflection->getShortName();
        // Convert CamelCase to snake_case
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $shortName));
    }

    /**
     * Convert value from API format to PHP format
     */
    private function convertValueForPhp(mixed $value, ReflectionProperty $property): mixed
    {
        if ($value === null) {
            return null;
        }

        $type = $property->getType();
        if ($type === null) {
            return $value;
        }

        $typeName = $type->getName();

        // Handle date/time conversions
        if (in_array($typeName, ['DateTime', '\DateTime', 'DateTimeInterface', '\DateTimeInterface']) && is_string($value)) {
            return new DateTime($value);
        }

        // Handle boolean conversions
        if ($typeName === 'bool' || $typeName === 'boolean') {
            return (bool) $value;
        }

        // Handle integer conversions
        if ($typeName === 'int' || $typeName === 'integer') {
            return (int) $value;
        }

        // Handle float conversions
        if ($typeName === 'float' || $typeName === 'double') {
            return (float) $value;
        }

        return $value;
    }

    /**
     * Convert value from PHP format to API format
     */
    private function convertValueForApi(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        // Handle DateTime objects
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        // Convert objects to arrays or strings as needed
        if (is_object($value)) {
            if (method_exists($value, 'toArray')) {
                return $value->toArray();
            }
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }

            // Use json_encode/decode for simple conversion without deep nesting
            // to avoid memory issues with deep objects
            return json_decode(json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR), true);
        }

        return $value;
    }
}