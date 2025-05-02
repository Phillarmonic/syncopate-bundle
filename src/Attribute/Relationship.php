<?php

namespace Phillarmonic\SyncopateBundle\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Relationship
{
    public const TYPE_ONE_TO_ONE = 'one_to_one';
    public const TYPE_ONE_TO_MANY = 'one_to_many';
    public const TYPE_MANY_TO_ONE = 'many_to_one';
    public const TYPE_MANY_TO_MANY = 'many_to_many';

    public function __construct(
        public readonly string $targetEntity,
        public readonly string $type,
        public readonly ?string $mappedBy = null,
        public readonly ?string $inversedBy = null,
        public readonly ?string $joinColumn = null,
        public readonly ?string $joinTable = null,
        public readonly ?array $joinColumns = null,
        public readonly ?array $inverseJoinColumns = null
    ) {
    }
}