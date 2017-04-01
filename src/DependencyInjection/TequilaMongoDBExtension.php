<?php

namespace Tequila\MongoDBBundle\DependencyInjection;

use MongoDB\Driver\ReadConcern;
use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\WriteConcern;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;
use Symfony\Component\DependencyInjection\Loader;
use Tequila\MongoDB\Client;
use Tequila\MongoDB\Database;
use Tequila\MongoDB\Manager;
use Tequila\MongoDB\ODM\BulkWriteBuilderFactory;
use Tequila\MongoDB\ODM\DefaultMetadataFactory;
use Tequila\MongoDB\ODM\DefaultRepositoryFactory;
use Tequila\MongoDB\ODM\DocumentManager;
use Tequila\MongoDB\ODM\QueryListener\SetBulkWriteBuilderListener;

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

        $this->container->setDefinition(
            'tequila_mongodb.metadata_factory',
            new Definition(DefaultMetadataFactory::class)
        );

        $this->addConnections($config);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yml');
    }

    /**
     * @param array $config
     */
    private function addConnections(array $config)
    {
        foreach ($config['connections'] as $name => $connectionConfig) {
            if (isset($connectionConfig['options'])) {
                $clientOptions = $this->getReadWriteOptions($connectionConfig);
                $uriOptions = $connectionConfig['options'];
            } else {
                $clientOptions = [];
                $uriOptions = [];
            }

            $driverOptions = isset($connectionConfig['driverOptions']) ? $connectionConfig['driverOptions'] : [];

            // Low-level Manager definition
            $managerId = sprintf('tequila_mongodb.connections.%s.manager', $name);
            $managerDefinition = new Definition(
                Manager::class,
                [$connectionConfig['uri'], $uriOptions, $driverOptions]
            );

            $this->container->setDefinition(
                $managerId,
                $managerDefinition
            );

            // Mongo client definition
            $clientId = sprintf('tequila_mongodb.connections.%s', $name);
            $this->container->setDefinition(
                $clientId,
                new Definition(Client::class, [new Reference($managerId), $clientOptions])
            );

            if ($name === $config['defaultConnection']) {
                $this->container->setAlias('tequila_mongodb.client', new Alias($clientId));
            }

            $bulkBuilderFactoryId = $clientId . '.bulk_builder_factory';
            $this->container->setDefinition(
                $bulkBuilderFactoryId,
                new Definition(BulkWriteBuilderFactory::class, [
                    new Reference($managerId)
                ])
            );

            $queryListenerId = $clientId . '.query_listener';
            $this->container->setDefinition(
                $queryListenerId,
                new Definition(SetBulkWriteBuilderListener::class, [
                    new Reference($bulkBuilderFactoryId)
                ])
            );

            $managerDefinition->addMethodCall('addQueryListener', [new Reference($queryListenerId)]);

            $repositoryFactoryId = $clientId . '.repository_factory';
            $this->container->setDefinition(
                $repositoryFactoryId,
                new Definition(DefaultRepositoryFactory::class, [
                    new Reference('tequila_mongodb.metadata_factory')
                ])
            );

            foreach ($connectionConfig['databases'] as $alias => $databaseConfig) {
                $databaseId = $clientId . '.db.' . $alias;
                $databaseOptions = isset($databaseConfig['options'])
                    ? $this->getReadWriteOptions($databaseConfig['options'])
                    : [];
                $dbDefinition = new Definition(Database::class, [
                    $databaseConfig['name'],
                    $databaseOptions
                ]);
                $dbDefinition->setFactory([
                    new Reference($clientId),
                    'selectDatabase'
                ]);

                $this->container->setDefinition($databaseId, $dbDefinition);

                $dmId = $databaseId . '.dm';
                $this->container->setDefinition($dmId, new Definition(DocumentManager::class, [
                    new Reference($databaseId),
                    new Reference($bulkBuilderFactoryId),
                    new Reference($repositoryFactoryId),
                    new Reference('tequila_mongodb.metadata_factory')
                ]));

                if ($name === $config['defaultConnection'] && $alias === $connectionConfig['defaultDatabase']) {
                    $this->container->setAlias('tequila_mongodb', new Alias($databaseId));
                    $this->container->setAlias('tequila_mongodb.dm', new Alias($dmId));
                }
            }
        }
    }


    /**
     * @param array $config
     * @return array
     */
    private function getReadWriteOptions(array &$config)
    {
        $options = [];

        if (isset($config['options']['readConcern'])) {
            $options['readConcern'] = $this->getReadConcern(
                $config['options']['readConcern']
            );

            unset($config['options']['readConcern']);
        }

        if (isset($config['options']['readPreference'])) {
            $options['readPreference'] = $this->getReadPreference(
                $config['options']['readPreference']
            );

            unset($config['options']['readPreference']);
        }

        if (isset($config['options']['writeConcern'])) {
            $options['writeConcern'] = $this->getWriteConcern(
                $config['options']['writeConcern']
            );

            unset($config['options']['writeConcern']);
        }

        return $options;
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
