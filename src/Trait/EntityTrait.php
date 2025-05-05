<?php

namespace Phillarmonic\SyncopateBundle\Trait;

trait EntityTrait
{
    /**
     * Extract entity fields to an array
     *
     * @param array|null $fields Only include these fields if specified
     * @param array $exclude Fields to exclude
     * @param array $mapping Map property names to custom keys in result (['newKey' => 'originalProperty'])
     * @return array
     */
    public function toArray(?array $fields = null, array $exclude = [], array $mapping = []): array
    {
        $result = [];
        $reflection = new \ReflectionClass($this);

        // Always include ID if it exists
        if ($reflection->hasProperty('id') && !in_array('id', $exclude)) {
            $idProperty = $reflection->getProperty('id');
            $idProperty->setAccessible(true);
            $idValue = $idProperty->getValue($this);

            // Use mapped name if available
            $idKey = isset($mapping['id']) ? $mapping['id'] : 'id';
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

            // Use mapped name if available
            $resultKey = isset($mapping[$propertyName]) ? $mapping[$propertyName] : $fieldName;

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
     * @param array $mapping Map property names to custom keys in result
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
     * @param array $mapping Map property names to custom keys in result
     * @return array
     */
    public function extractExcept(array $exclude, array $mapping = []): array
    {
        return $this->toArray(null, $exclude, $mapping);
    }
}