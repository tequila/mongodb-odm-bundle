<?php

namespace Tequila\MongoDBBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * This is the class that validates and merges configuration from your app/config files.
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/configuration.html}
 */
class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('tequila_mongodb');
        $this->addConnectionsSection($rootNode);

        return $treeBuilder;
    }

    private function addConnectionsSection(ArrayNodeDefinition $rootNode)
    {
        $rootNode
            ->fixXmlConfig('connection')
            ->children()
                ->scalarNode('defaultConnection')->isRequired()->end()
                ->arrayNode('connections')
                    ->requiresAtLeastOneElement()
                    ->prototype('array')
                        ->children()
                            ->scalarNode('defaultDatabase')->isRequired()->end()
                            ->arrayNode('databases')
                                ->requiresAtLeastOneElement()
                                ->prototype('array')
                                    ->children()
                                        ->scalarNode('name')->isRequired()->end()
                                        ->arrayNode('options')
                                            ->children()
                                                ->enumNode('readConcern')->values(['linearizable', 'local', 'majority'])->end()
                                                ->arrayNode('readPreference')
                                                    ->children()
                                                        ->enumNode('mode')
                                                            ->values([
                                                                'primary',
                                                                'primaryPreferred',
                                                                'secondary',
                                                                'secondaryPreferred',
                                                                'nearest',
                                                            ])
                                                            ->isRequired()
                                                        ->end()
                                                        // TODO: improve tag sets configuration
                                                        ->arrayNode('tagSets')
                                                            ->prototype('array')->end()
                                                        ->end()
                                                        ->arrayNode('options')
                                                            ->children()
                                                                ->integerNode('maxStalenessSeconds')->end()
                                                            ->end()
                                                        ->end()
                                                    ->end()
                                                ->end()
                                                ->arrayNode('writeConcern')
                                                    ->children()
                                                        ->scalarNode('w')->isRequired()->end()
                                                        ->integerNode('wTimeout')->end()
                                                        ->booleanNode('journal')->end()
                                                    ->end()
                                                ->end()
                                            ->end()
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()
                            ->scalarNode('uri')->isRequired()->end()
                            ->arrayNode('options')
                                ->children()
                                    ->scalarNode('authMechanism')->end()
                                    ->scalarNode('authSource')->end()
                                    ->integerNode('connectTimeoutMS')->defaultValue(100)->end()
                                    ->scalarNode('gssapiServiceName')->end()
                                    ->scalarNode('password')->end()
                                    ->scalarNode('replicaSet')->end()
                                    ->integerNode('socketTimeoutMS')->defaultValue(100)->end()
                                    ->booleanNode('ssl')->end()
                                    ->scalarNode('username')->end()
                                    ->enumNode('readConcern')->values(['linearizable', 'local', 'majority'])->end()
                                    ->arrayNode('readPreference')
                                        ->children()
                                            ->enumNode('mode')
                                                ->values([
                                                    'primary',
                                                    'primaryPreferred',
                                                    'secondary',
                                                    'secondaryPreferred',
                                                    'nearest',
                                                ])
                                                ->isRequired()
                                            ->end()
                                            // TODO: improve tag sets configuration
                                            ->arrayNode('tagSets')
                                                ->prototype('array')->end()
                                            ->end()
                                            ->arrayNode('options')
                                                ->children()
                                                    ->integerNode('maxStalenessSeconds')->end()
                                                ->end()
                                            ->end()
                                        ->end()
                                    ->end()
                                    ->arrayNode('writeConcern')
                                        ->children()
                                            ->scalarNode('w')->isRequired()->end()
                                            ->integerNode('wTimeout')->end()
                                            ->booleanNode('journal')->end()
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()
                            ->arrayNode('driverOptions')
                                ->children()
                                    ->booleanNode('allow_invalid_hostname')->end()
                                    ->scalarNode('ca_dir')->end()
                                    ->scalarNode('ca_file')->end()
                                    ->scalarNode('crl_file')->end()
                                    ->scalarNode('pem_file')->end()
                                    ->scalarNode('pem_pwd')->end()
                                    ->booleanNode('weak_cert_validation')->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }
}
