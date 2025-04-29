<?php

namespace Philharmonic\SyncopateBundle\Model;

class EntityDefinition
{
    public const ID_TYPE_AUTO_INCREMENT = 'auto_increment';
    public const ID_TYPE_UUID = 'uuid';
    public const ID_TYPE_CUID = 'cuid';
    public const ID_TYPE_CUSTOM = 'custom';

    private string $name;
    private array $fields = [];
    private string $idGenerator;
    private ?string $description;

    public function __construct(
        string $name,
        string $idGenerator = self::ID_TYPE_AUTO_INCREMENT,
        ?string $description = null
    ) {
        $this->name = $name;
        $this->idGenerator = $idGenerator;
        $this->description = $description;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getIdGenerator(): string
    {
        return $this->idGenerator;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getFields(): array
    {
        return $this->fields;
    }

    public function addField(FieldDefinition $field): self
    {
        $this->fields[] = $field;
        return $this;
    }

    public function setFields(array $fields): self
    {
        $this->fields = $fields;
        return $this;
    }

    /**
     * Convert to array for API request
     */
    public function toArray(): array
    {
        $fields = array_map(
            fn(FieldDefinition $field) => $field->toArray(),
            $this->fields
        );

        return [
            'name' => $this->name,
            'fields' => $fields,
            'idGenerator' => $this->idGenerator,
        ];
    }

    /**
     * Create from API response
     */
    public static function fromArray(array $data): self
    {
        $instance = new self(
            $data['name'],
            $data['idGenerator'] ?? self::ID_TYPE_AUTO_INCREMENT
        );

        $fields = [];
        foreach ($data['fields'] as $fieldData) {
            // Skip internal fields (those starting with _)
            if (isset($fieldData['name']) && str_starts_with($fieldData['name'], '_')) {
                continue;
            }
            $fields[] = FieldDefinition::fromArray($fieldData);
        }

        $instance->setFields($fields);
        return $instance;
    }
}