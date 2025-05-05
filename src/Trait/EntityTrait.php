<?php

namespace Phillarmonic\SyncopateBundle\Trait;

use Phillarmonic\SyncopateBundle\Attribute\Field;

trait EntityTrait
{
    /**
     * Extract entity fields to an array
     *
     * @param array|null $fields Only include these fields if specified
     * @param array $exclude Fields to exclude
     * @return array
     */
    public function toArray(?array $fields = null, array $exclude = []): array
    {
        $result = [];
        $reflection = new \ReflectionClass($this);

        // Always include ID if it exists
        if ($reflection->hasProperty('id')) {
            $idProperty = $reflection->getProperty('id');
            $idProperty->setAccessible(true);
            $result['id'] = $idProperty->getValue($this);
        }

        // Get all entity properties with Field attribute
        foreach ($reflection->getProperties() as $property) {
            $propertyName = $property->getName();

            // Skip excluded fields and id (already handled)
            if ($propertyName === 'id' || in_array($propertyName, $exclude)) {
                continue;
            }

            // If specific fields are requested, only include those
            if ($fields !== null && !in_array($propertyName, $fields)) {
                continue;
            }

            // Check if property has Field attribute
            $fieldAttributes = $property->getAttributes(Field::class);
            if (empty($fieldAttributes)) {
                continue;
            }

            // Get the field name from attribute or use property name
            $fieldAttribute = $fieldAttributes[0]->newInstance();
            $fieldName = $fieldAttribute->name ?? $propertyName;

            // Get property value
            $property->setAccessible(true);
            $value = $property->getValue($this);

            // Handle special types (DateTime, related entities, etc.)
            if ($value instanceof \DateTimeInterface) {
                $result[$fieldName] = $value->format(\DateTimeInterface::ATOM);
            } elseif (is_object($value) && method_exists($value, 'toArray')) {
                $result[$fieldName] = $value->toArray();
            } else {
                $result[$fieldName] = $value;
            }
        }

        return $result;
    }

    /**
     * Extract only specified fields to an array
     *
     * @param array $fields Fields to include
     * @return array
     */
    public function extract(array $fields): array
    {
        return $this->toArray($fields);
    }

    /**
     * Extract all fields except specified ones
     *
     * @param array $exclude Fields to exclude
     * @return array
     */
    public function extractExcept(array $exclude): array
    {
        return $this->toArray(null, $exclude);
    }
}