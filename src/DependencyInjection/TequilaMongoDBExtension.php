<?php

namespace Tequila\MongoDBBundle\DependencyInjection;

use MongoDB\Client;
use MongoDB\Database;
use MongoDB\Driver\ReadConcern;
use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\WriteConcern;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;
use Tequila\MongoDB\ODM\BulkWriteBuilderFactory;
use Tequila\MongoDB\ODM\DocumentManager;
use Tequila\MongoDB\ODM\Metadata\Factory\StaticMethodAwareFactory;
use Tequila\MongoDB\ODM\Proxy\Factory\CompiledFactory;
use Tequila\MongoDB\ODM\Proxy\Factory\GeneratorFactory;
use Tequila\MongoDB\ODM\Repository\Factory\DefaultRepositoryFactory;


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
        $this->addDocumentManagers($container);
    }

    /**
     * {@inheritdoc}
     */
    protected function loadInternal(array $config, ContainerBuilder $container)
    {
        $locator = new FileLocator(__DIR__.'/../Resources/config');
        $loader = new YamlFileLoader($container, $locator);
        $loader->load('services.yaml');
        $this->config = $config;
    }

    /**
     * @param ContainerBuilder $container
     */
    private function addConnections(ContainerBuilder $container)
    {
        if (empty($this->config['default_connection'])) {
            $connectionAliases = array_keys($this->config['connections']);
            $this->config['default_connection'] = reset($connectionAliases);
        }

        $connectionDefaultConfig = ['options' => [], 'driverOptions' => []];
        foreach ($this->config['connections'] as $name => $connectionConfig) {
            $connectionConfig += $connectionDefaultConfig;

            // Client definition
            $clientId = sprintf('tequila_mongodb.clients.%s', $name);
            $clientDefinition = new Definition(
                Client::class, [
                    $connectionConfig['server'],
                    $connectionConfig['options'],
                    $connectionConfig['driverOptions'],
                ]
            );
            $clientDefinition->setPublic(true);
            $container->setDefinition($clientId, $clientDefinition);

            if ($name === $this->config['default_connection']) {
                $container->setParameter(
                    'tequila_mongodb.default_connection',
                    $clientId
                );
                $container->setAlias('tequila_mongodb.client', new Alias($clientId));
                $container->setAlias(Client::class, new Alias($clientId));
            }
        }
    }

    private function addDocumentManagers(ContainerBuilder $container)
    {
        $defaultConnection = $this->config['default_connection'];
        $cacheDir = $container->getParameter('kernel.cache_dir');
        $dmDefaultConfig = [
            'connection' => $this->config['default_connection'],
            'database' => $this->config['connections'][$defaultConnection]['default_database'],
            'database_options' => [],
            'proxies_dir' => $cacheDir.'/Tequila/MongoDBBundle/Proxies',
            'proxies_namespace' => 'Tequila\MongoDBBundle\Proxies',
        ];
        if (empty($this->config['document_managers'])) {
            $this->config['document_managers'] = [
                'default' => $dmDefaultConfig,
            ];
        }

        if (empty($this->config['default_document_manager'])) {
            $documentManagerAliases = array_keys($this->config['document_managers']);
            $this->config['default_document_manager'] = reset($documentManagerAliases);
        }

        foreach ($this->config['document_managers'] as $alias => $dmConfig) {
            $dmId = 'tequila_mongodb.dm.'.$alias;
            $dmConfig += $dmDefaultConfig;


            $metadataFactoryId = $dmId.'.metadata_factory';
            $metadataFactoryDefinition = new Definition(StaticMethodAwareFactory::class);
            $metadataFactoryDefinition->setPublic(false);
            $metadataFactoryDefinition->setLazy(true);
            $metadataFactoryDefinition->setPublic(true);
            $container->setDefinition($metadataFactoryId, $metadataFactoryDefinition);

            $bulkBuilderFactoryId = $dmId.'.bulk_builder_factory';
            $bulkBuilderFactoryDefinition = new Definition(BulkWriteBuilderFactory::class);
            $bulkBuilderFactoryDefinition->setPublic(false);
            $bulkBuilderFactoryDefinition->setLazy(true);
            $bulkBuilderFactoryDefinition->setPublic(true);
            $container->setDefinition($bulkBuilderFactoryId, $bulkBuilderFactoryDefinition);

            $repositoryFactoryId = $dmId.'.repository_factory';
            $repositoryFactoryDefinition = new Definition(DefaultRepositoryFactory::class);
            $repositoryFactoryDefinition->setPublic(false);
            $repositoryFactoryDefinition->setLazy(true);
            $repositoryFactoryDefinition->setPublic(true);
            $container->setDefinition($repositoryFactoryId, $repositoryFactoryDefinition);

            $generatorFactoryId = $dmId.'.proxy.generator_factory';
            $generatorFactoryDefinition = new Definition(
                GeneratorFactory::class,
                [
                    $dmConfig['proxies_dir'],
                    $dmConfig['proxies_namespace'],
                    new Reference($metadataFactoryId)
                ]
            );
            $generatorFactoryDefinition->setLazy(true);
            $generatorFactoryDefinition->setPublic(true);
            $container->setDefinition($generatorFactoryId, $generatorFactoryDefinition);

            $proxyFactoryId = $dmId.'.proxy_factory';
            $isDebug = $container->getParameter('kernel.debug');
            $isDevEnv = 'dev' === $container->getParameter('kernel.environment');
            if ($isDebug && $isDevEnv) {
                $container->setAlias($proxyFactoryId, new Alias($generatorFactoryId));
            } else {
                $proxyFactoryDefinition = new Definition(
                    CompiledFactory::class,
                    [$dmConfig['proxies_dir'], $dmConfig['proxies_namespace']]
                );
                $proxyFactoryDefinition->setPublic(false);
                $container->setDefinition($proxyFactoryId, $proxyFactoryDefinition);
            }

            $clientId = sprintf('tequila_mongodb.clients.%s', $dmConfig['connection']);
            if (!$container->hasDefinition($clientId)) {
                throw new \LogicException(
                    sprintf(
                        'Document manager "%s" depends on connection "%s", which is not configured, check your config.',
                        $alias,
                        $dmConfig['connection']
                    )
                );
            }

            $databaseId = $dmId.'.database';
            $databaseDefinition = new Definition(Database::class, [
                $dmConfig['database'],
                $this->getDatabaseOptions($dmConfig['database_options'])
            ]);
            $databaseDefinition->setFactory([new Reference($clientId), 'selectDatabase']);
            $databaseDefinition->setLazy(true);
            $databaseDefinition->setPublic(true);
            $container->setDefinition($databaseId, $databaseDefinition);

            $dmDefinition = new Definition(DocumentManager::class, [
                new Reference($databaseId),
                new Reference($bulkBuilderFactoryId),
                new Reference($repositoryFactoryId),
                new Reference($metadataFactoryId),
                new Reference($proxyFactoryId),
            ]);
            $dmDefinition->setPublic(true);
            $container->setDefinition($dmId, $dmDefinition);

            if ($alias === $this->config['default_document_manager']) {
                $container->setAlias('tequila_mongodb.dm', new Alias($dmId));
                $container->setAlias(DocumentManager::class, (new Alias($dmId))->setPublic(true));
                $container->setAlias(Database::class, $databaseId);
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
