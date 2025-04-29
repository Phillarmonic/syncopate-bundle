<?php

namespace Phillarmonic\SyncopateBundle\Model;

class QueryFilter
{
    // Filter operators from SyncopateDB
    public const OP_EQUALS = 'eq';
    public const OP_NOT_EQUALS = 'neq';
    public const OP_GREATER_THAN = 'gt';
    public const OP_GREATER_THAN_EQUALS = 'gte';
    public const OP_LESS_THAN = 'lt';
    public const OP_LESS_THAN_EQUALS = 'lte';
    public const OP_CONTAINS = 'contains';
    public const OP_STARTS_WITH = 'startswith';
    public const OP_ENDS_WITH = 'endswith';
    public const OP_IN = 'in';
    public const OP_FUZZY = 'fuzzy';
    public const OP_ARRAY_CONTAINS = 'array_contains';
    public const OP_ARRAY_CONTAINS_ANY = 'array_contains_any';
    public const OP_ARRAY_CONTAINS_ALL = 'array_contains_all';

    private string $field;
    private string $operator;
    private mixed $value;

    public function __construct(string $field, string $operator, mixed $value)
    {
        $this->field = $field;
        $this->operator = $operator;
        $this->value = $value;
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function getOperator(): string
    {
        return $this->operator;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    /**
     * Convert to array for API request
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'operator' => $this->operator,
            'value' => $this->value,
        ];
    }

    /**
     * Create an equals filter
     */
    public static function eq(string $field, mixed $value): self
    {
        return new self($field, self::OP_EQUALS, $value);
    }

    /**
     * Create a not equals filter
     */
    public static function neq(string $field, mixed $value): self
    {
        return new self($field, self::OP_NOT_EQUALS, $value);
    }

    /**
     * Create a greater than filter
     */
    public static function gt(string $field, mixed $value): self
    {
        return new self($field, self::OP_GREATER_THAN, $value);
    }

    /**
     * Create a greater than or equals filter
     */
    public static function gte(string $field, mixed $value): self
    {
        return new self($field, self::OP_GREATER_THAN_EQUALS, $value);
    }

    /**
     * Create a less than filter
     */
    public static function lt(string $field, mixed $value): self
    {
        return new self($field, self::OP_LESS_THAN, $value);
    }

    /**
     * Create a less than or equals filter
     */
    public static function lte(string $field, mixed $value): self
    {
        return new self($field, self::OP_LESS_THAN_EQUALS, $value);
    }

    /**
     * Create a contains filter
     */
    public static function contains(string $field, string $value): self
    {
        return new self($field, self::OP_CONTAINS, $value);
    }

    /**
     * Create a starts with filter
     */
    public static function startsWith(string $field, string $value): self
    {
        return new self($field, self::OP_STARTS_WITH, $value);
    }

    /**
     * Create an ends with filter
     */
    public static function endsWith(string $field, string $value): self
    {
        return new self($field, self::OP_ENDS_WITH, $value);
    }

    /**
     * Create an in filter
     */
    public static function in(string $field, array $values): self
    {
        return new self($field, self::OP_IN, $values);
    }

    /**
     * Create a fuzzy search filter
     */
    public static function fuzzy(string $field, string $value): self
    {
        return new self($field, self::OP_FUZZY, $value);
    }

    /**
     * Create an array contains filter
     */
    public static function arrayContains(string $field, mixed $value): self
    {
        return new self($field, self::OP_ARRAY_CONTAINS, $value);
    }

    /**
     * Create an array contains any filter
     */
    public static function arrayContainsAny(string $field, array $values): self
    {
        return new self($field, self::OP_ARRAY_CONTAINS_ANY, $values);
    }

    /**
     * Create an array contains all filter
     */
    public static function arrayContainsAll(string $field, array $values): self
    {
        return new self($field, self::OP_ARRAY_CONTAINS_ALL, $values);
    }
}