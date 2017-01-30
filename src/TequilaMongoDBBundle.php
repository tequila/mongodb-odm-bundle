<?php

namespace Tequila\MongoDBBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;
use Tequila\MongoDBBundle\DependencyInjection\TequilaMongoDBExtension;

class TequilaMongoDBBundle extends Bundle
{
    public function getContainerExtension()
    {
        return new TequilaMongoDBExtension();
    }
}
