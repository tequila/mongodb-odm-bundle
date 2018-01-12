<?php

namespace Tequila\MongoDBBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tequila\MongoDB\ODM\DocumentManager;
use Tequila\MongoDB\ODM\Proxy\Factory\ProxyFactoryInterface;
use Tequila\MongoDBBundle\ProxiesGenerator;

class GenerateProxiesCommand extends ContainerAwareCommand
{
    public function configure()
    {
        $this
            ->setName('tequila_mongodb:generate:proxies')
            ->setDescription('Generates document classes using class metadata.')
            ->addArgument(
                'bundle',
                InputArgument::REQUIRED,
                'Bundle name, string "app" to generate documents in Symfony 4 {ROOT_DIR}/src/Document, or string "all" to generate proxies for all bundles.'
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
        /** @var DocumentManager $dm */
        $dm = $app->get($dmId);

        if ('all' === $bundleName) {
            $bundlesMetadata = $app->getParameter('kernel.bundles_metadata');
            foreach ($bundlesMetadata as $name => $metadata) {
                $documentsPath = $metadata['path'].'/Document';
                if (!is_dir($documentsPath)) {
                    continue;
                }

                /** @var ProxyFactoryInterface $proxyFactory */
                $proxyFactory = $app->get($dmId.'.proxy.generator_factory');
                $proxiesGenerator = new ProxiesGenerator($proxyFactory);
                $proxiesGenerator->generateProxies($dm, $documentsPath);
            }

            exit();
        }

        if ('app' === $bundleName) {
            // Code for Symfony 4 bundle-less application
            $bundlePath = $app->getParameter('kernel.root_dir');
        }  else {
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

        /** @var ProxyFactoryInterface $proxyFactory */
        $proxyFactory = $app->get($dmId.'.proxy.generator_factory');
        $proxiesGenerator = new ProxiesGenerator($proxyFactory);
        $proxiesGenerator->generateProxies($dm, $documentsPath);
    }
}