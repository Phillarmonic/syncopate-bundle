<?php

namespace Phillarmonic\SyncopateBundle\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Entity
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $idGenerator = null,
        public readonly ?string $description = null
    ) {
    }
}