<?php

namespace Phillarmonic\SyncopateBundle;

use Phillarmonic\SyncopateBundle\DependencyInjection\PhillarmonicSyncopateExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class PhillarmonicSyncopateBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // You can add compiler passes here if needed
        // $container->addCompilerPass(new YourCompilerPass());
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        if (null === $this->extension) {
            $this->extension = new PhillarmonicSyncopateExtension();
        }

        return $this->extension;
    }
}