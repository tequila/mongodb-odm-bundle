<?php

namespace Tequila\MongoDBBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tequila\MongoDB\ODM\Code\DocumentGenerator;
use Tequila\MongoDBBundle\DocumentManagerFactory;

class GenerateDocumentsCommand extends Command
{
    /**
     * @var DocumentManagerFactory
     */
    private $dmFactory;

    /**
     * @param DocumentManagerFactory $dmFactory
     */
    public function __construct(DocumentManagerFactory $dmFactory)
    {
        $this->dmFactory = $dmFactory;

        parent::__construct();
    }

    public function configure()
    {
        $this
            ->setName('tequila_mongodb:generate:documents')
            ->setDescription('Generates document class using class metadata.')
            ->addArgument(
                'documentClass',
                InputArgument::REQUIRED,
                'Fully-qualified class name of the document to generate'
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
        $documentClass = $input->getArgument('documentClass');
        $dm = $this->dmFactory->getManager($input->getOption('dm'));
        $metadata = $dm->getMetadata($documentClass);

        $documentGenerator = new DocumentGenerator($metadata);
        $documentGenerator->generateClass();

        $style = new SymfonyStyle($input, $output);
        $style->success(sprintf('Document %s generated successfully', $documentClass));
    }
}
