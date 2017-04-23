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
        $this->addDatabasesSection($rootNode);

        $rootNode
            ->children()
                ->scalarNode('default_connection')->end()
                ->scalarNode('default_database')->end()
            ->end();

        return $treeBuilder;
    }

    private function addConnectionsSection(ArrayNodeDefinition $rootNode)
    {
        $rootNode
            ->fixXmlConfig('connection')
            ->children()
                ->arrayNode('connections')
                    ->isRequired()
                    ->requiresAtLeastOneElement()
                    ->useAttributeAsKey('alias')
                    ->prototype('array')
                        ->children()
                            ->scalarNode('uri')->isRequired()->end()
                            ->arrayNode('options')
                                ->children()
                                    ->scalarNode('appname')->end()
                                    ->scalarNode('authSource')->end()
                                    ->booleanNode('canonicalizeHostname')->end()
                                    ->integerNode('connectTimeoutMS')->end()
                                    ->scalarNode('gssapiServiceName')->end()
                                    ->integerNode('heartbeatFrequencyMS')
                                        ->min(500)
                                    ->end()
                                    ->booleanNode('journal')->end()
                                    ->integerNode('localThresholdMS')->end()
                                    ->integerNode('maxStalenessSeconds')
                                        ->min(90)
                                    ->end()
                                    ->scalarNode('password')->end()
                                    ->enumNode('readConcernLevel')
                                        ->values(['linearizable', 'local', 'majority'])
                                    ->end()
                                    ->enumNode('readPreference')
                                        ->values([
                                            'primary',
                                            'primaryPreferred',
                                            'secondary',
                                            'secondaryPreferred',
                                            'nearest',
                                        ])
                                    ->end()
                                    ->arrayNode('readPreferenceTags')
                                        ->prototype('array')->end()
                                    ->end()
                                    ->scalarNode('replicaSet')->end()
                                    ->integerNode('serverSelectionTimeoutMS')->end()
                                    ->booleanNode('serverSelectionTryOnce')->end()
                                    ->integerNode('socketCheckIntervalMS')->end()
                                    ->integerNode('socketTimeoutMS')->end()
                                    ->booleanNode('ssl')->end()
                                    ->scalarNode('username')->end()
                                    ->scalarNode('w')->end()
                                    ->integerNode('wTimeoutMS')->end()
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

    private function addDatabasesSection(ArrayNodeDefinition $rootNode)
    {
        $rootNode
            ->fixXmlConfig('database')
            ->children()
                ->arrayNode('databases')
                    ->isRequired()
                    ->requiresAtLeastOneElement()
                    ->useAttributeAsKey('alias')
                    ->prototype('array')
                        ->children()
                            ->scalarNode('connection')->cannotBeEmpty()->end()
                            ->scalarNode('name')
                                ->isRequired()
                                ->cannotBeEmpty()
                            ->end()
                            ->arrayNode('options')
                                ->children()
                                    ->enumNode('readConcern')
                                        ->values(['linearizable', 'local', 'majority'])
                                    ->end()
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
                                                    ->integerNode('maxStalenessSeconds')
                                                        ->min(90)
                                                    ->end()
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
            ->end();
    }
}
