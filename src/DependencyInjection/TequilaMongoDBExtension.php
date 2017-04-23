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
        if (empty($config['default_connection'])) {
            $connectionAliases = array_keys($config['connections']);
            $config['default_connection'] = reset($connectionAliases);
        }
        $container->setParameter('tequila_mongodb.default_connection', $config['default_connection']);

        if (empty($config['default_database'])) {
            $databaseAliases = array_keys($config['databases']);
            $config['default_database'] = reset($databaseAliases);
        }
        $container->setParameter('tequila_mongodb.default_database', $config['default_database']);

        $this->addConnections($config, $container);
        $this->addDatabases($config, $container);
    }

    /**
     * @param array $config
     * @param ContainerBuilder $container
     */
    private function addConnections(array $config, ContainerBuilder $container)
    {
        $defaultConnectionConfig = ['options' => [], 'driverOptions' => []];
        foreach ($config['connections'] as $name => $connectionConfig) {
            $connectionConfig += $defaultConnectionConfig;

            // Client definition
            $clientId = sprintf('tequila_mongodb.clients.%s', $name);
            $clientDefinition = new Definition(Client::class, [
                $connectionConfig['uri'],
                $connectionConfig['options'],
                $connectionConfig['driverOptions']
            ]);
            $container->setDefinition($clientId, $clientDefinition);

            // Manager definition
            $managerId = $clientId . '.manager';
            $managerDefinition = new Definition(Manager::class);
            $managerDefinition->setFactory([new Reference($clientId), 'getManager']);
            $container->setDefinition($managerId, $managerDefinition);

            if ($name === $container->getParameter('tequila_mongodb.default_connection')) {
                $container->setAlias('tequila_mongodb.client', new Alias($clientId));
            }

            $metadataFactoryId = $clientId . '.metadata_factory';
            $container->setDefinition(
                $metadataFactoryId,
                new Definition(DefaultMetadataFactory::class)
            );

            $bulkBuilderFactoryId = $clientId . '.bulk_builder_factory';
            $container->setDefinition(
                $bulkBuilderFactoryId,
                new Definition(BulkWriteBuilderFactory::class, [new Reference($managerId)])
            );

            $queryListenerId = $clientId . '.query_listener';
            $container->setDefinition(
                $queryListenerId,
                new Definition(SetBulkWriteBuilderListener::class, [
                    new Reference($bulkBuilderFactoryId)
                ])
            );

            $clientDefinition->addMethodCall('addQueryListener', [new Reference($queryListenerId)]);

            $repositoryFactoryId = $clientId . '.repository_factory';
            $container->setDefinition(
                $repositoryFactoryId,
                new Definition(DefaultRepositoryFactory::class, [new Reference($metadataFactoryId)])
            );
        }
    }

    private function addDatabases(array $config, ContainerBuilder $container)
    {
        foreach ($config['databases'] as $alias => $databaseConfig) {
            $databaseOptions = isset($databaseConfig['options']) ? $databaseConfig['options'] : [];
            $databaseOptions = $this->getDatabaseOptions($databaseOptions);

            $dbDefinition = new Definition(Database::class, [
                $databaseConfig['name'],
                $databaseOptions
            ]);

            $clientId = sprintf('tequila_mongodb.clients.%s', $databaseConfig['connection']);
            if (!$container->hasDefinition($clientId)) {
                throw new \LogicException(
                    sprintf(
                        'Connection "%s" for database "%s" is not configured, check your config.',
                        $databaseConfig['connection'],
                        $alias
                    )
                );
            }
            $dbDefinition->setFactory([new Reference($clientId), 'selectDatabase']);
            $databaseId = sprintf('tequila_mongodb.databases.%s', $alias);
            $container->setDefinition($databaseId, $dbDefinition);

            if ($databaseId === $container->getParameter('tequila_mongodb.default_database')) {
                $container->setAlias('tequila_mongodb.db', new Alias($databaseId));
            }

            $dmId = $databaseId . '.dm';
            $bulkBuilderFactoryId = $clientId . '.bulk_builder_factory';
            $repositoryFactoryId = $clientId . '.repository_factory';
            $metadataFactoryId = $clientId . '.metadata_factory';
            $container->setDefinition($dmId, new Definition(DocumentManager::class, [
                new Reference($databaseId),
                new Reference($bulkBuilderFactoryId),
                new Reference($repositoryFactoryId),
                new Reference($metadataFactoryId)
            ]));

            if ($alias === $container->getParameter('tequila_mongodb.default_database')) {
                $container->setAlias('tequila_mongodb.db', new Alias($databaseId));
                $container->setAlias('tequila_mongodb.dm', new Alias($dmId));
            }
        }
    }

    /**
     * @param array $config
     * @return array
     */
    private function getDatabaseOptions(array $config)
    {
        $options = [];

        if (isset($config['readConcern'])) {
            $options['readConcern'] = $this->getReadConcern($config['readConcern']);
        }

        if (isset($config['readPreference'])) {
            $options['readPreference'] = $this->getReadPreference($config['readPreference']);
        }

        if (isset($config['writeConcern'])) {
            $options['writeConcern'] = $this->getWriteConcern($config['writeConcern']);
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
        $tagSets = isset($config['tagSets']) ? $config['tagSets'] : [];
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
