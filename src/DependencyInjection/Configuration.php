<?php

namespace Phillarmonic\SyncopateBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('phillarmonic_syncopate');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('base_url')
                    ->isRequired()
                    ->info('Base URL of the SyncopateDB API (e.g., http://localhost:8080)')
                ->end()
                ->integerNode('timeout')
                    ->defaultValue(30)
                    ->info('Request timeout in seconds')
                ->end()
                ->booleanNode('retry_failed')
                    ->defaultFalse()
                    ->info('Whether to retry failed requests')
                ->end()
                ->integerNode('max_retries')
                    ->defaultValue(3)
                    ->info('Maximum number of retry attempts for failed requests')
                ->end()
                ->integerNode('retry_delay')
                    ->defaultValue(1000)
                    ->info('Delay between retry attempts in milliseconds')
                ->end()
                ->arrayNode('entity_paths')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                    ->info('Paths to scan for entity classes')
                ->end()
                ->booleanNode('auto_create_entity_types')
                    ->defaultTrue()
                    ->info('Whether to automatically create entity types in SyncopateDB')
                ->end()
                ->booleanNode('cache_entity_types')
                    ->defaultTrue()
                    ->info('Whether to cache entity type definitions')
                ->end()
                ->integerNode('cache_ttl')
                    ->defaultValue(3600)
                    ->info('Cache time-to-live in seconds')
                ->end()
            ->end();

        return $treeBuilder;
    }
}