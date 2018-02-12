<?php

namespace Tequila\MongoDBBundle;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Tequila\MongoDB\ODM\DocumentManager;

class DocumentManagerFactory
{
    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * @param string $alias
     *
     * @return DocumentManager
     */
    public function getManager(string $alias): DocumentManager
    {
        $dmId = $this->getManagerServiceId($alias);
        if (!$this->container->has($dmId)) {
            throw new \InvalidArgumentException(
                sprintf('Document manager "%s" does not exist.', $alias)
            );
        }

        return $this->container->get($dmId);
    }

    /**
     * @param string $alias
     *
     * @return string
     */
    public function getManagerServiceId(string $alias): string
    {
        return 'tequila_mongodb.dm.'.$alias;
    }
}
