<?php

namespace Phillarmonic\SyncopateBundle;

use Phillarmonic\SyncopateBundle\DependencyInjection\Compiler\RegisterRepositoriesPass;
use Phillarmonic\SyncopateBundle\DependencyInjection\Compiler\RepositoryRegistryPass;
use Phillarmonic\SyncopateBundle\DependencyInjection\PhillarmonicSyncopateExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class PhillarmonicSyncopateBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Register compiler passes
        $container->addCompilerPass(new RepositoryRegistryPass());
        $container->addCompilerPass(new RegisterRepositoriesPass());
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        if (null === $this->extension) {
            $this->extension = new PhillarmonicSyncopateExtension();
        }

        return $this->extension;
    }
}