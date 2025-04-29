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
                $fieldAttribute->nullable
            );

            $entityDefinition->addField($fieldDefinition);
        }

        return $entityDefinition;
    }

    /**
     * Map entity data to a PHP object
     */
    public function mapToObject(array $entityData, string $className): object
    {
        $reflection = new ReflectionClass($className);
        $object = $reflection->newInstanceWithoutConstructor();

        if (!isset($entityData['id']) || !isset($entityData['fields'])) {
            throw new \InvalidArgumentException("Invalid entity data structure");
        }

        // Map ID to special property if it exists
        if ($reflection->hasProperty('id')) {
            $idProperty = $reflection->getProperty('id');
            $idProperty->setAccessible(true);
            $idProperty->setValue($object, $entityData['id']);
        }

        // Map fields to properties
        foreach ($reflection->getProperties() as $property) {
            $fieldAttributes = $property->getAttributes(Field::class);
            if (empty($fieldAttributes)) {
                continue;
            }

            /** @var Field $fieldAttribute */
            $fieldAttribute = $fieldAttributes[0]->newInstance();
            $fieldName = $fieldAttribute->name ?? $property->getName();

            if (!isset($entityData['fields'][$fieldName])) {
                continue;
            }

            $value = $entityData['fields'][$fieldName];
            $value = $this->convertValueForPhp($value, $property);

            $property->setAccessible(true);
            $property->setValue($object, $value);
        }

        return $object;
    }

    /**
     * Map PHP object to entity data
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
        foreach ($reflection->getProperties() as $property) {
            // Skip id property as it's handled separately
            if ($property->getName() === 'id') {
                continue;
            }

            $fieldAttributes = $property->getAttributes(Field::class);
            if (empty($fieldAttributes)) {
                continue;
            }

            /** @var Field $fieldAttribute */
            $fieldAttribute = $fieldAttributes[0]->newInstance();
            $fieldName = $fieldAttribute->name ?? $property->getName();

            $property->setAccessible(true);
            $value = $property->getValue($object);

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

        foreach ($reflection->getProperties() as $property) {
            $fieldAttributes = $property->getAttributes(Field::class);
            if (empty($fieldAttributes)) {
                continue;
            }

            /** @var Field $fieldAttribute */
            $fieldAttribute = $fieldAttributes[0]->newInstance();
            $property->setAccessible(true);
            $value = $property->getValue($object);

            // Check required fields
            if ($fieldAttribute->required && $value === null) {
                $exception->addViolation(
                    $property->getName(),
                    "Field is required"
                );
                $hasViolations = true;
            }

            // Check nullable fields
            if (!$fieldAttribute->nullable && $value === null) {
                $exception->addViolation(
                    $property->getName(),
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
        if (in_array($typeName, ['DateTime', '\DateTime', 'DateTimeInterface', '\DateTimeInterface'])) {
            if (is_string($value)) {
                return new DateTime($value);
            }
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
            // Last resort, convert to array
            return json_decode(json_encode($value), true);
        }

        return $value;
    }
}