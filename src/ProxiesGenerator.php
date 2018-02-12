<?php

namespace Tequila\MongoDBBundle;

use Symfony\Component\Finder\Finder;
use Tequila\MongoDB\ODM\DocumentManager;
use Tequila\MongoDB\ODM\Exception\LogicException;
use Tequila\MongoDB\ODM\Proxy\Factory\GeneratorFactory;
use Tequila\MongoDB\ODM\Proxy\Factory\ProxyFactoryInterface;
use Zend\Code\Reflection\FileReflection;

class ProxiesGenerator
{
    /**
     * @var ProxyFactoryInterface
     */
    private $proxyFactory;

    /**
     * @param GeneratorFactory $proxyFactory
     */
    public function __construct(GeneratorFactory $proxyFactory)
    {
        $this->proxyFactory = $proxyFactory;
    }

    /**
     * @param DocumentManager $dm
     * @param string          $documentsDir
     *
     * @return array|string[]
     */
    public function generateProxies(DocumentManager $dm, string $documentsDir): array
    {
        $finder = new Finder();
        $finder
            ->ignoreUnreadableDirs()
            ->files()
            ->name('*.php')
            ->in($documentsDir);

        $generatedDocumentClasses = [];
        foreach ($finder as $fileInfo) {
            $fileReflection = new FileReflection($fileInfo->getPathname(), true);
            foreach ($fileReflection->getClasses() as $classReflection) {
                try {
                    $dm->getMetadata($classReflection->getName());
                } catch (LogicException $e) {
                    continue;
                }

                $this->proxyFactory->generateProxyClass($classReflection->getName());
                $generatedDocumentClasses[] = $classReflection->getName();
            }
        }

        return $generatedDocumentClasses;
    }
}
