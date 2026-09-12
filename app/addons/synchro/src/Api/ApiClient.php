<?php

namespace Tygh\Addons\Synchro\Api;

use RuntimeException;
use Tygh\Http;

/**
 * Requests data from the Synchro export API.
 */
class ApiClient
{
    public const API_URL = 'https://svetelektro.net/index.php?option=com_vmtools&task=exportall.make';

    /**
     * Temporary key for installations that have not yet configured the add-on.
     *
     * @todo Remove after api_key has been configured on all installations.
     */
    private const FALLBACK_API_KEY = '54ffc087d87dae187499273060174614';

    /** @var string */
    private $api_key;

    /**
     * @param string $api_key API access key from the add-on settings
     */
    public function __construct($api_key)
    {
        $this->api_key = trim($api_key);
    }

    /**
     * Gets data for one export API action.
     *
     * @param string                                          $action       Export action
     * @param array<string, array|bool|float|int|string|null> $request_data Additional request parameters
     *
     * @return array<array-key, array|bool|float|int|string|null>
     *
     * @throws \RuntimeException When the API request or response is invalid.
     */
    public function getData($action, array $request_data = [])
    {
        unset($request_data['action'], $request_data['centerkey']);

        $is_http_logging_enabled = Http::$logging;
        Http::$logging = false;

        try {
            $response = Http::get(self::API_URL, array_merge($request_data, [
                'action'    => $action,
                'centerkey' => $this->getApiKey(),
            ]));
        } finally {
            Http::$logging = $is_http_logging_enabled;
        }

        if (!is_string($response) || Http::getStatus() !== Http::STATUS_OK) {
            throw new RuntimeException(__('synchro.api_request_failed', [
                '[action]' => $action,
                '[error]'  => Http::getError() ?: __('error_occurred'),
            ]));
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new RuntimeException(__('synchro.api_invalid_response', [
                '[action]' => $action,
            ]));
        }

        if (empty($data['ok'])) {
            throw new RuntimeException(__('synchro.api_request_failed', [
                '[action]' => $action,
                '[error]'  => $data['error'],
            ]));
        }

        return $data;
    }

    /**
     * Gets data for one product by its external identifier.
     *
     * @param string|int $external_id External product identifier
     *
     * @return array<array-key, array|bool|float|int|string|null>
     *
     * @throws \RuntimeException When the API request or response is invalid.
     */
    public function getProductData($external_id)
    {
        return $this->getData('products', ['id' => (string) $external_id]);
    }

    /**
     * @return string
     */
    private function getApiKey()
    {
        return $this->api_key !== '' ? $this->api_key : self::FALLBACK_API_KEY;
    }
}
