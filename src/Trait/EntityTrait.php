<?php

namespace Phillarmonic\SyncopateBundle\Trait;

trait EntityTrait
{
    /**
     * Extract entity fields to an array
     *
     * @param array|null $fields Only include these fields if specified
     * @param array $exclude Fields to exclude
     * @param array $mapping Map original property names to custom keys in result
     * @return array
     */
    public function toArray(?array $fields = null, array $exclude = [], array $mapping = []): array
    {
        $result = [];
        $reflection = new \ReflectionClass($this);

        // Always include ID if it exists
        if ($reflection->hasProperty('id')) {
            $idProperty = $reflection->getProperty('id');
            $idProperty->setAccessible(true);
            $idValue = $idProperty->getValue($this);

            // Check if ID is in the mapping
            $idKey = array_search('id', $mapping) ?: 'id';
            $result[$idKey] = $idValue;
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
            $fieldAttributes = $property->getAttributes(\Phillarmonic\SyncopateBundle\Attribute\Field::class);
            if (empty($fieldAttributes)) {
                continue;
            }

            // Get the field name from attribute or use property name
            $fieldAttribute = $fieldAttributes[0]->newInstance();
            $fieldName = $fieldAttribute->name ?? $propertyName;

            // Apply mapping if exists
            $resultKey = array_search($propertyName, $mapping) ?: $fieldName;

            // Get property value
            $property->setAccessible(true);
            $value = $property->getValue($this);

            // Handle special types (DateTime, related entities, etc.)
            if ($value instanceof \DateTimeInterface) {
                $result[$resultKey] = $value->format(\DateTimeInterface::ATOM);
            } elseif (is_object($value) && method_exists($value, 'toArray')) {
                $result[$resultKey] = $value->toArray();
            } else {
                $result[$resultKey] = $value;
            }
        }

        return $result;
    }

    /**
     * Extract only specified fields to an array
     *
     * @param array $fields Fields to include
     * @param array $mapping Map original property names to custom keys in result
     * @return array
     */
    public function extract(array $fields, array $mapping = []): array
    {
        return $this->toArray($fields, [], $mapping);
    }

    /**
     * Extract all fields except specified ones
     *
     * @param array $exclude Fields to exclude
     * @param array $mapping Map original property names to custom keys in result
     * @return array
     */
    public function extractExcept(array $exclude, array $mapping = []): array
    {
        return $this->toArray(null, $exclude, $mapping);
    }

    /**
     * Extract fields with custom field name mapping
     *
     * @param array $mapping Map of 'resultKey' => 'propertyName'
     * @return array
     */
    public function extractAs(array $mapping): array
    {
        // For extractAs, we only want to include the fields in the mapping
        $fields = array_values($mapping);
        return $this->toArray($fields, [], array_flip($mapping));
    }
}