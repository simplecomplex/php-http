<?php
/**
 * KIT/Koncernservice, Københavns Kommune.
 * @link https://kkgit.kk.dk/php-psr.kk-seb/http
 */
declare(strict_types=1);

namespace KkSeb\Http;

use SimpleComplex\Utils\Exception\ConfigurationException;
use SimpleComplex\RestMini\Client as RestMiniClient;

/**
 * HTTP request, to be issued by HttpClient.
 *
 * @internal
 *
 * @package KkSeb\Http
 */
class HttpRequest
{
    /**
     * @var array
     */
    public $properties;

    /**
     * @var array
     */
    public $options;

    /**
     * @var array
     */
    public $parameters;

    /**
     * @var int
     */
    public $code = 0;

    /**
     * @var bool
     */
    public $aborted = false;

    /**
     * Executes HTTP request.
     *
     * Constructor arguments are not checked here; must be checked by caller
     * (HttpClient).
     *
     * @param array $properties {
     *      @var string $appTitle
     *      @var string $provider
     *      @var string $service
     *      @var string $endpoint
     *      @var string $method
     * }
     * @param array $options
     * @param array $parameters {
     *      @var array $path  Optional.
     *      @var array $query  Optional.
     *      @var array|object|string $body  Optional.
     * }
     */
    public function __construct(array $properties, array $options, array $parameters)
    {
        $this->properties = $properties;
        $this->options = $options;
        $this->parameters = $parameters;

        // Filter non-RestMini Client properties off.
        $client_options = array_intersect_assoc(
            $this->options,
            array_fill_keys(RestMiniClient::OPTIONS_SUPPORTED, true)
        );
        // Remove constructor args.
        $base_url = $client_options['base_url'];
        $endpoint_path = $client_options['endpoint_path'];
        unset($client_options['base_url'], $client_options['endpoint_path']);

        $client = new RestMiniClient($base_url, $endpoint_path, $client_options);

        // Check for RestMini Client initialisation error.
        $client_error = $client->error();
        if ($client_error) {
            $error_name = $client_error['name'];
            switch ($error_name) {
                case 'server_arg_empty':
                case 'protocol_not_supported':
                case 'option_not_supported':
                case 'option_value_missing':
                case 'option_value_empty':
                case 'option_value_invalid':
                    $this->aborted = true;
                    $this->code = 7913; // @todo
                    // Do log even though RestMini Client also logs (as error),
                    // because we want a trace.
                    // And these errors are unlikely but severe.
                    HttpClient::logException(
                        new ConfigurationException(
                            'HttpRequest abort, underlying client ' . get_class($client) . ' reports'
                            . ' configuration error code[' . $client_error['code'] . '] name[' . $error_name
                            . '] message[' . $client_error['message'] . '].',
                            $this->code
                        ),
                        $this->options
                    );
                    break;
                default:
            }
        }
    }

    /**
     * Convenience method for HttpRequest.
     *
     * @param int $code
     *
     * @return $this|HttpRequest
     */
    public function aborted(int $code)
    {
        $this->aborted = true;
        $this->code = $code;
        return $this;
    }
}
