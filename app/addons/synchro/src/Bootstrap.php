<?php

namespace Tygh\Addons\Synchro;

use Tygh\Core\ApplicationInterface;
use Tygh\Core\BootstrapInterface;
use Tygh\Core\HookHandlerProviderInterface;

/**
 * Loads the synchro add-on services and hook handlers.
 */
class Bootstrap implements BootstrapInterface, HookHandlerProviderInterface
{
    /**
     * @inheritDoc
     */
    public function boot(ApplicationInterface $app)
    {
        $app->register(new ServiceProvider());

        require_once __DIR__ . '/../func.php';
    }

    /**
     * @inheritDoc
     */
    public function getHookHandlerMap()
    {
        return [
            'save_log' => [
                'addons.synchro.hook_handlers.logging',
                'onSaveLog',
            ],
        ];
    }
}
