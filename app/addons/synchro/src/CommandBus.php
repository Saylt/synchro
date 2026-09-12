<?php

namespace Tygh\Addons\Synchro;

use Tygh\Exceptions\DeveloperException;

/**
 * Dispatches commands through their middleware and handlers.
 */
class CommandBus
{
    /**
     * @var array<string, array{handler: callable, middleware: array<callable>}>
     */
    private $schema;

    /**
     * @param array<string, array{handler: callable, middleware: array<callable>}> $schema Command handlers schema
     */
    public function __construct(array $schema)
    {
        $this->schema = $schema;
    }

    /**
     * Dispatches a command.
     *
     * @param object $command Command
     *
     * @return \Tygh\Common\OperationResult
     */
    public function dispatch(object $command)
    {
        if (!is_object($command)) {
            throw new DeveloperException(__('synchro.exception.command_must_be_object'));
        }

        return $this->handleCommand($command);
    }

    /**
     * @param object $command Command
     *
     * @return array{handler: callable, middleware: array<callable>}
     */
    private function getCommandSchema(object $command)
    {
        $class = $this->getClassName($command);

        if (!isset($this->schema[$class])) {
            throw new DeveloperException(__('synchro.exception.undefined_command_handler', [
                '[command]' => $class,
            ]));
        }

        return $this->schema[$class];
    }

    /**
     * @param object $command Command
     *
     * @return callable
     */
    private function getCommandHandler(object $command)
    {
        $schema = $this->getCommandSchema($command);

        if (!isset($schema['handler']) || !is_callable($schema['handler'])) {
            throw new DeveloperException(__('synchro.exception.undefined_command_handler', [
                '[command]' => $this->getClassName($command),
            ]));
        }

        return $schema['handler'];
    }

    /**
     * @param object $command Command
     *
     * @return array<callable>
     */
    private function getCommandMiddlewareList(object $command)
    {
        $schema = $this->getCommandSchema($command);

        if (empty($schema['middleware'])) {
            return [];
        }

        foreach ($schema['middleware'] as $middleware) {
            if (!is_callable($middleware)) {
                throw new DeveloperException(__('synchro.exception.unrecognized_command_middleware', [
                    '[command]' => $this->getClassName($command),
                ]));
            }
        }

        return $schema['middleware'];
    }

    /**
     * @param object $command Command
     *
     * @return \Tygh\Common\OperationResult
     */
    private function handleCommand(object $command)
    {
        $handler = $this->getCommandHandler($command);
        $middleware_list = $this->getCommandMiddlewareList($command);

        if (!$middleware_list) {
            return $handler($command);
        }

        $middleware = array_shift($middleware_list);

        $next = static function ($command) use (&$middleware_list, &$next, $handler) {
            $middleware = array_shift($middleware_list);

            if ($middleware) {
                return $middleware($command, $next);
            }

            return $handler($command);
        };

        return $middleware($command, $next);
    }

    /**
     * @param object $command Command
     *
     * @return string
     */
    private function getClassName(object $command)
    {
        return trim(get_class($command), '\\');
    }
}
