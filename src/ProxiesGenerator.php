<?php

namespace Tequila\MongoDBBundle;

use Symfony\Component\Finder\Finder;
use Tequila\MongoDB\ODM\DocumentManager;
use Tequila\MongoDB\ODM\Exception\LogicException;
use Tequila\MongoDB\ODM\Proxy\Factory\ProxyFactoryInterface;
use Zend\Code\Reflection\FileReflection;

class ProxiesGenerator
{
    /**
     * @var ProxyFactoryInterface
     */
    private $proxyFactory;

    /**
     * @param ProxyFactoryInterface $proxyFactory
     */
    public function __construct(ProxyFactoryInterface $proxyFactory)
    {
        $this->proxyFactory = $proxyFactory;
    }

    /**
     * @param DocumentManager $dm
     * @param string $documentsDir
     * @return int
     */
    public function generateProxies(DocumentManager $dm, string $documentsDir): int
    {
        $finder = new Finder();
        $finder
            ->ignoreUnreadableDirs()
            ->files()
            ->name('*.php')
            ->in($documentsDir);

        $generatedDocumentsNumber = 0;
        foreach ($finder as $fileInfo) {
            $fileReflection = new FileReflection($fileInfo->getPathname(), true);
            foreach ($fileReflection->getClasses() as $classReflection) {
                try {
                    $dm->getMetadata($classReflection->getName());
                } catch(LogicException $e) {
                    continue;
                }

                $this->proxyFactory->getProxyClass($classReflection->getName());
            }
        }

        return $generatedDocumentsNumber;
    }
}