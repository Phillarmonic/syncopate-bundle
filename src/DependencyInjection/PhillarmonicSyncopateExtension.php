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

        // Configure parameters for the bundle services
        $container->setParameter('phillarmonic_syncopate.base_url', $config['base_url']);
        $container->setParameter('phillarmonic_syncopate.timeout', $config['timeout']);
        $container->setParameter('phillarmonic_syncopate.retry_failed', $config['retry_failed']);
        $container->setParameter('phillarmonic_syncopate.max_retries', $config['max_retries']);
        $container->setParameter('phillarmonic_syncopate.retry_delay', $config['retry_delay']);
        $container->setParameter('phillarmonic_syncopate.entity_paths', $config['entity_paths']);
        $container->setParameter('phillarmonic_syncopate.auto_create_entity_types', $config['auto_create_entity_types']);
        $container->setParameter('phillarmonic_syncopate.cache_entity_types', $config['cache_entity_types']);
        $container->setParameter('phillarmonic_syncopate.cache_ttl', $config['cache_ttl']);
    }

    public function getAlias(): string
    {
        return 'phillarmonic_syncopate';
    }
}