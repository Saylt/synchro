<?php

namespace Tygh\Addons\Synchro;

use Tygh\Core\ApplicationInterface;
use Tygh\Core\BootstrapInterface;

/**
 * Loads the synchro add-on services and hook handlers.
 */
class Bootstrap implements BootstrapInterface
{
    /**
     * @inheritDoc
     */
    public function boot(ApplicationInterface $app)
    {
        $app->register(new ServiceProvider());

        require_once __DIR__ . '/../func.php';
    }
}
