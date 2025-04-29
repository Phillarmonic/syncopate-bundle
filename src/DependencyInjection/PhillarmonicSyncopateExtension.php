<?php

namespace Phillarmonic\SyncopateBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class PhillarmonicSyncopateExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.yaml');

        // Configure the SyncopateClient with the provided parameters
        $container->setParameter('philharmonic_syncopate.base_url', $config['base_url']);
        $container->setParameter('philharmonic_syncopate.timeout', $config['timeout']);
        $container->setParameter('philharmonic_syncopate.retry_failed', $config['retry_failed']);
        $container->setParameter('philharmonic_syncopate.max_retries', $config['max_retries']);
        $container->setParameter('philharmonic_syncopate.retry_delay', $config['retry_delay']);
        $container->setParameter('philharmonic_syncopate.entity_paths', $config['entity_paths']);
        $container->setParameter('philharmonic_syncopate.auto_create_entity_types', $config['auto_create_entity_types']);
        $container->setParameter('philharmonic_syncopate.cache_entity_types', $config['cache_entity_types']);
        $container->setParameter('philharmonic_syncopate.cache_ttl', $config['cache_ttl']);
    }

    public function getAlias(): string
    {
        return 'philharmonic_syncopate';
    }
}