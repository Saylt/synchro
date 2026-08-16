<?php

namespace Tygh\Addons\Synchro\Commands;

use Tygh\Common\OperationResult;
use Tygh\Exceptions\DeveloperException;

/**
 * Passes external API data to the convertor registered for its entity type.
 */
class ImportDataCommandHandler
{
    /**
     * @var array<string, \Tygh\Addons\Synchro\Convertors\ConvertorInterface>
     */
    private $convertors;

    /**
     * @param array<string, \Tygh\Addons\Synchro\Convertors\ConvertorInterface> $convertors Entity convertors
     */
    public function __construct(array $convertors)
    {
        $this->convertors = $convertors;
    }

    /**
     * @param \Tygh\Addons\Synchro\Commands\ImportDataCommand $command Import command
     *
     * @return \Tygh\Common\OperationResult
     */
    public function handle(ImportDataCommand $command)
    {
        if (!isset($this->convertors[$command->entity_type])) {
            throw new DeveloperException(sprintf('Undefined convertor for entity type %s', $command->entity_type));
        }

        $data = $this->convertors[$command->entity_type]->convert($command->data);

        return new OperationResult(true, $data);
    }
}
