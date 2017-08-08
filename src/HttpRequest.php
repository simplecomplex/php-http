<?php
/**
 * KIT/Koncernservice, Københavns Kommune.
 * @link https://kkgit.kk.dk/php-psr.kk-seb/http
 */
declare(strict_types=1);

namespace KkSeb\Http;

use SimpleComplex\Utils\Dependency;
use SimpleComplex\Utils\Exception\ConfigurationException;
use SimpleComplex\RestMini\Client as RestMiniClient;
use KkSeb\Cache\CacheBroker;

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
     * Default cacheable time-to-live.
     *
     * @var int
     */
    const CACHEABLE_TIME_TO_LIVE = 3600;

    /**
     * Options supported:
     * - (bool|arr) cacheable
     * - (arr) require_response_headers: list of response header keys required
     * - (bool) err_on_endpoint_not_found: 404 + HTML
     * - (bool) err_on_resource_not_found: 204, 404 + JSON
     *
     * The cacheable option as array:
     * - (int) ttl: time-to-live, default CACHEABLE_TIME_TO_LIVE
     * - refresh: retrieve new response and cache it, default not
     * - anybody: for any user, default current user only
     *
     * See also underlying client's supported methods.
     * @see \SimpleComplex\RestMini\Client::OPTIONS_SUPPORTED
     *
     * @var string[]
     */
    const OPTIONS_SUPPORTED = [
        // bool|array.
        'cacheable',
        // array.
        'require_response_headers',
        // bool; 404 + HTML.
        'err_on_endpoint_not_found',
        // bool; 204, 404 + JSON.
        'err_on_resource_not_found',
    ];

    /**
     * @var HttpResponse
     */
    public $response;

    /**
     * @var array
     */
    public $properties;

    /**
     * This class' options + RestMini Client's options.
     *
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
     * @var string
     */
    public $logType;


    // This class own options.--------------------------------------------------

    /**
     * @var array
     */
    public $cacheable = [];

    /**
     * @var array|null
     */
    public $requireResponseHeaders;

    /**
     * @var bool|null
     */
    public $errOnEndpointNotFound;

    /**
     * @var bool|null
     */
    public $errOnResourceNotFound;


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
     * @param array $arguments {
     *      @var array $path  Optional.
     *      @var array $query  Optional.
     *      @var mixed $body  Optional.
     * }
     */
    public function __construct(array $properties, array $options, array $arguments)
    {
        $this->properties = $properties;

        if (!$this->aborted) {
            // These two method arguments will be empty if aborted.
            $this->options = $options;
            $this->parameters = $arguments;

            $this->logType = !empty($options['log_type']) ? $options['log_type'] : HttpClient::LOG_TYPE;

            // Get our (non-RestMini Client) options.
            if (!empty($options['cacheable'])) {
                $chbl = $options['cacheable'];
                $this->cacheable = [
                    'ttl' => static::CACHEABLE_TIME_TO_LIVE,
                    'id' => '['
                        . join('][', [
                            $properties['provider'],
                            $properties['service'],
                            $properties['endpoint'],
                            $properties['method'],
                        ])
                        . ']user-',
                    'refresh' => false,
                ];
                if ($chbl === true) {
                    $this->cacheable['id'] .= 'userIdent'; // @todo: get brugerIdent.
                } else {
                    if (!empty($chbl['ttl'])) {
                        $this->cacheable['ttl'] = $chbl['ttl'];
                    }
                    if (!empty($chbl['anybody'])) {
                        $this->cacheable['id'] .= '.';
                    } else {
                        $this->cacheable['id'] .= 'userIdent'; // @todo: get brugerIdent.
                    }
                    if (!empty($chbl['refresh'])) {
                        $this->cacheable['refresh'] = true;
                    }
                }
            }
            if (!empty($options['require_response_headers'])) {
                // RestMini Client needs 'get_header' option, for this to work.
                $this->options['get_headers'] = true;
                $this->requireResponseHeaders = $options['require_response_headers'];
            }
            if (!empty($options['err_on_endpoint_not_found'])) {
                $this->errOnEndpointNotFound = true;
            }
            if (!empty($options['err_on_resource_not_found'])) {
                $this->errOnResourceNotFound = true;
            }

            // Filter non-RestMini Client options off,
            // even option not supported at all.
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
                            // Dump options.
                            [
                                'options passed' => $this->options,
                            ],
                            $this->logType
                        );
                        break;
                    default:
                }
            }
        }
        if ($this->aborted) {
            $body = new HttpResponseBody();
            // @todo: set message.
            $body->message = '';
            $this->response = new HttpResponse(500, [], $body, $this->code);
            return;
        }
        $this->execute();
    }

    /**
     * @return void
     */
    protected function execute() /*: void*/
    {
        // @todo: much.

        if ($this->cacheable) {
            $container = Dependency::container();
            /** @var CacheBroker $cache_broker */
            $cache_broker = $container->get('cache-broker');
            $cache_store = $cache_broker->getStore('http-client', CacheBroker::CACHE_VARIABLE_TTL, [
                'ttlDefault' => static::CACHEABLE_TIME_TO_LIVE,
            ]);
            unset($cache_broker);
        }

        $this->response = new HttpResponse(500, [], new HttpResponseBody(), $this->code);
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
