<?php

namespace Tequila\MongoDBBundle;

use Symfony\Component\Finder\Finder;
use Tequila\MongoDB\ODM\Code\DocumentGenerator;
use Tequila\MongoDB\ODM\DocumentManager;
use Tequila\MongoDB\ODM\Exception\LogicException;
use Zend\Code\Reflection\FileReflection;

class DocumentsGenerator
{
    /**
     * @param DocumentManager $dm
     * @param string $documentsDir
     * @return int
     */
    public function generateDocuments(DocumentManager $dm, string $documentsDir): int
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
                    $metadata = $dm->getMetadata($classReflection->getName());
                } catch(LogicException $e) {
                    continue;
                }

                $documentGenerator = new DocumentGenerator($metadata);
                $documentGenerator->generateClass();
                ++$generatedDocumentsNumber;
            }
        }

        return $generatedDocumentsNumber;
    }
}