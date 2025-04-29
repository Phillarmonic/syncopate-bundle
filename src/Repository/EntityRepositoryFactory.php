<?php

namespace Phillarmonic\SyncopateBundle\Repository;

use Phillarmonic\SyncopateBundle\Mapper\EntityMapper;
use Phillarmonic\SyncopateBundle\Service\SyncopateService;

class EntityRepositoryFactory
{
    private SyncopateService $syncopateService;
    private EntityMapper $entityMapper;
    private array $repositories = [];

    public function __construct(
        SyncopateService $syncopateService,
        EntityMapper $entityMapper
    ) {
        $this->syncopateService = $syncopateService;
        $this->entityMapper = $entityMapper;
    }

    /**
     * Get repository for entity class
     */
    public function getRepository(string $entityClass): EntityRepository
    {
        if (!isset($this->repositories[$entityClass])) {
            $this->repositories[$entityClass] = new EntityRepository(
                $this->syncopateService,
                $this->entityMapper,
                $entityClass
            );
        }

        return $this->repositories[$entityClass];
    }
}