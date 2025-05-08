<?php

namespace Phillarmonic\SyncopateBundle\Model;

class FieldDefinition
{
    // Field types from SyncopateDB
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_DATE = 'date';
    public const TYPE_DATETIME = 'datetime';
    public const TYPE_STRING = 'string';
    public const TYPE_TEXT = 'text';
    public const TYPE_JSON = 'json';
    public const TYPE_INTEGER = 'integer';
    public const TYPE_FLOAT = 'float';

    private string $name;
    private string $type;
    private bool $indexed;
    private bool $required;
    private bool $nullable;
    private bool $unique;

    public function __construct(
        string $name,
        string $type,
        bool $indexed = false,
        bool $required = false,
        bool $nullable = true,
        bool $unique = false
    ) {
        $this->name = $name;
        $this->type = $type;
        $this->indexed = $indexed;
        $this->required = $required;
        $this->nullable = $nullable;
        $this->unique = $unique;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isIndexed(): bool
    {
        return $this->indexed;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function isUnique(): bool
    {
        return $this->unique;
    }

    /**
     * Convert to array for API request
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'indexed' => $this->indexed,
            'required' => $this->required,
            'nullable' => $this->nullable,
            'unique' => $this->unique,
        ];
    }

    /**
     * Create from API response
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['name'],
            $data['type'],
            $data['indexed'] ?? false,
            $data['required'] ?? false,
            $data['nullable'] ?? true,
            $data['unique'] ?? false
        );
    }

    /**
     * Map PHP types to SyncopateDB types
     */
    public static function mapPhpTypeToSyncopateType(string $phpType): string
    {
        return match ($phpType) {
            'int', 'integer' => self::TYPE_INTEGER,
            'float', 'double' => self::TYPE_FLOAT,
            'string' => self::TYPE_STRING,
            'bool', 'boolean' => self::TYPE_BOOLEAN,
            'array' => self::TYPE_JSON,
            'object' => self::TYPE_JSON,
            'DateTime', '\DateTime', 'DateTimeInterface', '\DateTimeInterface' => self::TYPE_DATETIME,
            default => self::TYPE_STRING,
        };
    }
}