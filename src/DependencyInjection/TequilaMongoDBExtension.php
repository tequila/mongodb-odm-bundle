<?php

namespace Tequila\MongoDBBundle\DependencyInjection;

use MongoDB\Client;
use MongoDB\Database;
use MongoDB\Driver\Manager;
use MongoDB\Driver\ReadConcern;
use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\WriteConcern;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;
use Tequila\MongoDB\ODM\BulkWriteBuilderFactory;
use Tequila\MongoDB\ODM\DocumentManager;
use Tequila\MongoDB\ODM\Metadata\Factory\StaticMethodAwareFactory;
use Tequila\MongoDB\ODM\Proxy\Factory\CompiledFactory;
use Tequila\MongoDB\ODM\Proxy\Factory\GeneratorFactory;
use Tequila\MongoDB\ODM\Repository\Factory\DefaultRepositoryFactory;

/**
 * This is the class that loads and manages your bundle configuration.
 *
 * @see http://symfony.com/doc/current/cookbook/bundles/extension.html
 */
class TequilaMongoDBExtension extends ConfigurableExtension implements CompilerPassInterface
{
    /**
     * @var array
     */
    private $config;

    /**
     * {@inheritdoc}
     */
    public function getAlias()
    {
        return 'tequila_mongodb';
    }

    public function process(ContainerBuilder $container)
    {
        $this->addConnections($container);
        $this->addDatabases($container);
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

        $this->config = $config;
    }

    /**
     * @param ContainerBuilder $container
     */
    private function addConnections(ContainerBuilder $container)
    {
        $defaultConnectionConfig = ['options' => [], 'driverOptions' => []];
        foreach ($this->config['connections'] as $name => $connectionConfig) {
            $connectionConfig += $defaultConnectionConfig;

            // Client definition
            $clientId = sprintf('tequila_mongodb.clients.%s', $name);
            $clientDefinition = new Definition(
                Client::class, [
                    $connectionConfig['uri'],
                    $connectionConfig['options'],
                    $connectionConfig['driverOptions'],
                ]
            );
            $container->setDefinition($clientId, $clientDefinition);

            // Manager definition
            $managerId = $clientId.'.manager';
            $managerDefinition = new Definition(Manager::class);
            $managerDefinition->setFactory([new Reference($clientId), 'getManager']);
            $container->setDefinition($managerId, $managerDefinition);

            if ($name === $container->getParameter('tequila_mongodb.default_connection')) {
                $container->setAlias('tequila_mongodb.client', new Alias($clientId));
                $container->setAlias(Client::class, new Alias($clientId));
            }

            $metadataFactoryId = $clientId.'.metadata_factory';
            $metadataFactoryDefinition = new Definition(StaticMethodAwareFactory::class);
            $metadataFactoryDefinition->setPublic(false);
            $container->setDefinition($metadataFactoryId, $metadataFactoryDefinition);

            $bulkBuilderFactoryId = $clientId.'.bulk_builder_factory';
            $bulkBuilderFactoryDefinition = new Definition(BulkWriteBuilderFactory::class);
            $bulkBuilderFactoryDefinition->setPublic(false);
            $container->setDefinition($bulkBuilderFactoryId, $bulkBuilderFactoryDefinition);

            $repositoryFactoryId = $clientId.'.repository_factory';
            $repositoryFactoryDefinition = new Definition(
                DefaultRepositoryFactory::class,
                [new Reference($metadataFactoryId)]
            );
            $repositoryFactoryDefinition->setPublic(false);
            $container->setDefinition($repositoryFactoryId, $repositoryFactoryDefinition);

            $proxyFactoryId = $clientId.'.proxy_factory';

            if (!isset($connectionConfig['proxies_namespace'])) {
                $connectionConfig['proxies_namespace'] = 'Tequila\MongoDB\ODM\Proxies';
            }

            if (!isset($connectionConfig['proxies_dir'])) {
                $cacheDir = $container->getParameter('kernel.cache_dir');
                $connectionConfig['proxies_dir'] = $cacheDir.'/Tequila/Proxies';
            }

            $proxiesNamespace = $connectionConfig['proxies_namespace'];
            $proxiesDir = $connectionConfig['proxies_dir'];

            if (
                $container->getParameter('kernel.debug')
                && 'prod' !== $container->getParameter('kernel.environment')
            ) {
                $proxyFactoryDefinition = new Definition(
                    GeneratorFactory::class,
                    [$proxiesDir, $proxiesNamespace, new Reference($metadataFactoryId)]
                );
            } else {
                $proxyFactoryDefinition = new Definition(
                    CompiledFactory::class,
                    [$proxiesDir, $proxiesNamespace]
                );
            }

            $proxyFactoryDefinition->setPublic(false);
            $container->setDefinition($proxyFactoryId, $proxyFactoryDefinition);
        }
    }

    private function addDatabases(ContainerBuilder $container)
    {
        foreach ($this->config['databases'] as $alias => $databaseConfig) {
            $databaseOptions = isset($databaseConfig['options']) ? $databaseConfig['options'] : [];
            $databaseOptions = $this->getDatabaseOptions($databaseOptions);

            $dbDefinition = new Definition(
                Database::class, [
                    $databaseConfig['name'],
                    $databaseOptions,
                ]
            );

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

            $dmId = $databaseId.'.dm';
            $bulkBuilderFactoryId = $clientId.'.bulk_builder_factory';
            $repositoryFactoryId = $clientId.'.repository_factory';
            $metadataFactoryId = $clientId.'.metadata_factory';
            $proxyFactoryId = $clientId.'.proxy_factory';

            $container->setDefinition(
                $dmId,
                new Definition(DocumentManager::class, [
                    new Reference($databaseId),
                    new Reference($bulkBuilderFactoryId),
                    new Reference($repositoryFactoryId),
                    new Reference($metadataFactoryId),
                    new Reference($proxyFactoryId),
                ])
            );

            if ($alias === $container->getParameter('tequila_mongodb.default_database')) {
                $container->setAlias('tequila_mongodb.db', new Alias($databaseId));
                $container->setAlias(Database::class, new Alias($databaseId));
                $container->setAlias('tequila_mongodb.dm', new Alias($dmId));
                $container->setAlias(DocumentManager::class, new Alias($dmId));
            }
        }
    }

    /**
     * @param array $config
     *
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
     *
     * @return ReadConcern
     */
    private function getReadConcern($readConcernLevel)
    {
        return new ReadConcern($readConcernLevel);
    }

    /**
     * @param array $config
     *
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
     *
     * @return WriteConcern
     */
    private function getWriteConcern(array $config)
    {
        $wTimeout = isset($config['wTimeout']) ? $config['wTimeout'] : 0;
        $journal = isset($config['journal']) ? $config['journal'] : false;

        return new WriteConcern($config['w'], $wTimeout, $journal);
    }
}
