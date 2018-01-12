<?php

namespace Tequila\MongoDBBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tequila\MongoDB\ODM\DocumentManager;
use Tequila\MongoDBBundle\DocumentsGenerator;

class GenerateDocumentsCommand extends ContainerAwareCommand
{
    public function configure()
    {
        $this
            ->setName('tequila_mongodb:generate:documents')
            ->setDescription('Generates document classes using class metadata.')
            ->addArgument(
                'bundle',
                InputArgument::REQUIRED,
                'Bundle name or string "app" to generate documents in Symfony 4 {ROOT_DIR}/src/Document'
            )
            ->addOption(
                'dm',
                null,
                InputOption::VALUE_REQUIRED,
                'Document manager alias that you want to use to get document class metadata.',
                'default'
            );
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $bundleName = $input->getArgument('bundle');
        $dmId = 'tequila_mongodb.dm.'.$input->getOption('dm');
        $app = $this->getContainer();
        if ('app' === $bundleName) {
            // Code for Symfony 4 bundle-less application
            $bundlePath = $app->getParameter('kernel.root_dir');
        } else {
            $bundlesMetadata = $app->getParameter('kernel.bundles_metadata');
            $bundlePath = null;
            foreach ($bundlesMetadata as $name => $metadata) {
                if (strtolower($bundleName) === strtolower($name)) {
                    $bundlePath = $metadata['path'];
                }
            }

            if (null === $bundlePath) {
                throw new \InvalidArgumentException(
                    sprintf('Bundle "%s" does not exist.', $bundleName)
                );
            }
        }

        $documentsPath = $bundlePath.'/Document';
        if (!is_dir($documentsPath)) {
            $output->writeln(
                sprintf(
                    'Documents directory "%s" for bundle "%s" does not exist.',
                    $documentsPath,
                    $bundleName
                )
            );
            exit();
        }

        /** @var DocumentManager $dm */
        $dm = $app->get($dmId);

        $documentsGenerator = new DocumentsGenerator();
        $generatedDocumentsNumber = $documentsGenerator->generateDocuments($dm, $documentsPath);

        $output->writeln(sprintf('Generated %d document classes.', $generatedDocumentsNumber));
    }
}