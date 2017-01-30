<?php

namespace Tequila\MongoDBBundle\DependencyInjection;

use MongoDB\Driver\ReadConcern;
use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\WriteConcern;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;
use Symfony\Component\DependencyInjection\Loader;
use Tequila\MongoDB\Client;
use Tequila\MongoDB\Manager;
use Tequila\MongoDB\ODM\Connection;

/**
 * This is the class that loads and manages your bundle configuration.
 *
 * @link http://symfony.com/doc/current/cookbook/bundles/extension.html
 */
class TequilaMongoDBExtension extends ConfigurableExtension
{
    /**
     * @var ContainerBuilder
     */
    private $container;

    /**
     * @inheritdoc
     */
    public function getAlias()
    {
        return 'tequila_mongodb';
    }

    /**
     * {@inheritdoc}
     */
    protected function loadInternal(array $config, ContainerBuilder $container)
    {
        $this->container = $container;

        if (!isset($config['connections']['default'])) {
            throw new \LogicException('No configuration for default connection provided.');
        }

        $this->addConnections($config['connections']);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yml');
    }

    /**
     * @param array $connectionsConfig
     */
    private function addConnections(array $connectionsConfig)
    {
        foreach ($connectionsConfig as $name => $config) {
            if (isset($config['options'])) {
                $clientOptions = $this->getClientOptions($config);
                $uriOptions = $config['options'];
            } else {
                $clientOptions = [];
                $uriOptions = [];
            }

            $driverOptions = isset($config['driverOptions']) ? $config['driverOptions'] : [];

            $this->container->setParameter(
                sprintf('tequila_mongodb.connections.%s.default_db', $name),
                $config['defaultDatabaseName']
            );

            // Low-level Manager definition
            $managerId = sprintf('tequila_mongodb.connections.%s.manager', $name);
            $this->container->setDefinition(
                $managerId,
                new Definition(Manager::class, [$config['uri'], $uriOptions, $driverOptions])
            );

            // Mongo client definition
            $clientId = sprintf('tequila_mongodb.connections.%s.client', $name);
            $this->container->setDefinition(
                $clientId,
                new Definition(Client::class, [new Reference($managerId)])
            );

            // ODM connection definition
            $this->container->setDefinition(
                sprintf('tequila_mongodb.connections.%s', $name),
                new Definition(Connection::class, [new Reference($clientId), $clientOptions])
            );
        }
    }

    /**
     * @param array $config
     * @return array
     */
    private function getClientOptions(array &$config)
    {
        $clientOptions = [];

        if (isset($config['options']['readConcern'])) {
            $clientOptions['readConcern'] = $this->getReadConcern(
                $config['options']['readConcern']
            );

            unset($config['options']['readConcern']);
        }

        if (isset($config['options']['readPreference'])) {
            $clientOptions['readPreference'] = $this->getReadPreference(
                $config['options']['readPreference']
            );

            unset($config['options']['readPreference']);
        }

        if (isset($config['options']['writeConcern'])) {
            $clientOptions['writeConcern'] = $this->getWriteConcern(
                $config['options']['writeConcern']
            );

            unset($config['options']['writeConcern']);
        }

        return $clientOptions;
    }

    /**
     * @param string $readConcernLevel
     * @return ReadConcern
     */
    private function getReadConcern($readConcernLevel)
    {
        return new ReadConcern($readConcernLevel);
    }

    /**
     * @param array $config
     * @return ReadPreference
     */
    private function getReadPreference(array $config)
    {
        $modeMap = [
            'primary' => ReadPreference::RP_PRIMARY,
            'primaryPreferred' => ReadPreference::RP_PRIMARY_PREFERRED,
            'secondary' => ReadPreference::RP_SECONDARY,
            'secondaryPreferred' => ReadPreference::RP_SECONDARY_PREFERRED,
            'nearest' => ReadPreference::RP_NEAREST,
        ];

        $mode = $modeMap[$config['mode']];
        $tagSets = isset($config['tagSets']) ? $config['tagSets'] : null;
        $options = isset($config['options']) ? $config['options'] : [];

        return new ReadPreference($mode, $tagSets, $options);
    }

    /**
     * @param array $config
     * @return WriteConcern
     */
    private function getWriteConcern(array $config)
    {
        $wTimeout = isset($config['wTimeout']) ? $config['wTimeout'] : 0;
        $journal = isset($config['journal']) ? $config['journal'] : false;

        return new WriteConcern($config['w'], $wTimeout, $journal);
    }
}
