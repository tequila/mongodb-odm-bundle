<?php

namespace Tequila\MongoDBBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tequila\MongoDB\ODM\Proxy\Factory\GeneratorFactory;
use Tequila\MongoDBBundle\DocumentManagerFactory;
use Tequila\MongoDBBundle\ProxiesGenerator;

class GenerateProxiesCommand extends ContainerAwareCommand
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
            ->setName('tequila_mongodb:generate:proxies')
            ->setDescription('Generates proxy classes using class metadata.')
            ->addArgument(
                'path',
                InputArgument::REQUIRED,
                'Path to directory where document classes are located'
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
        $dmAlias = $input->getOption('dm');
        $dmId = $this->dmFactory->getManagerServiceId($dmAlias);
        $path = $input->getArgument('path');
        $dm = $this->dmFactory->getManager($dmAlias);
        $generatorFactoryId = $dmId.'.proxy.generator_factory';
        /** @var GeneratorFactory $generatorFactory */
        $generatorFactory = $this->getContainer()->get($generatorFactoryId);
        $proxiesGenerator = new ProxiesGenerator($generatorFactory);
        $documentClasses = $proxiesGenerator->generateProxies($dm, $path);

        $style = new SymfonyStyle($input, $output);

        if ($numberOfClasses = count($documentClasses)) {
            $out = sprintf(
                'Proxies successfully generated for %d document classes:',
                $numberOfClasses
            );
            $out .= PHP_EOL;
            foreach ($documentClasses as $class) {
                $out .= '    - '.$class.PHP_EOL;
            }

            $style->success($out);
        } else {
            $style->warning(
                sprintf('There is no document classes at location %s.', $path)
            );
        }
    }
}
