<?php

namespace Phillarmonic\SyncopateBundle\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Field
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $type = null,
        public readonly bool $indexed = false,
        public readonly bool $required = false,
        public readonly bool $nullable = true,
        public readonly bool $unique = false
    ) {
    }
}