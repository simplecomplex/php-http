<?php
/**
 * KIT/Koncernservice, Københavns Kommune.
 * @link https://kkgit.kk.dk/php-psr.kk-seb/http
 */
declare(strict_types=1);

namespace KkSeb\Http;

use SimpleComplex\Utils\Dependency;
use SimpleComplex\RestMini\Client as RestMiniClient;
use KkSeb\Cache\CacheBroker;
use KkSeb\Http\Exception\HttpLogicException;
use KkSeb\Http\Exception\HttpConfigurationException;
use KkSeb\Http\Exception\HttpRequestException;

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
        // bool.
        'debug_dump',
        // bool|array.
        'cacheable',
        // int; milliseconds.
        'retry_on_unavailable',
        // array.
        'require_response_headers',
        // bool; 404 + HTML.
        'err_on_endpoint_not_found',
        // bool; 204, 404 + JSON.
        'err_on_resource_not_found',
    ];


    // Public members.----------------------------------------------------------

    /**
     * @var HttpResponse
     */
    public $response;

    /**
     * @var int
     */
    public $code = 0;

    /**
     * @var bool
     */
    public $aborted = false;


    // Protected members.-------------------------------------------------------

    /**
     * @var array
     */
    protected $properties;

    /**
     * This class' options + RestMini Client's options.
     *
     * @var array
     */
    protected $options;

    /**
     * @var array
     */
    protected $arguments;


    // This class own options.--------------------------------------------------

    /**
     * @var bool|null
     */
    protected $debugDump;

    /**
     * @var array
     */
    protected $cacheable = [];

    /**
     * @var array {
     *      @var array $require_response_headers  If set.
     *      @var bool $err_on_endpoint_not_found  If set.
     *      @var bool $err_on_resource_not_found  If set.
     * }
     */
    protected $responseRequirements = [];


    // Helpers.-----------------------------------------------------------------

    /**
     * @var HttpLogger
     */
    protected $httpLogger;

    /**
     * @var \KkSeb\Cache\FileCache|null
     */
    protected $cacheStore;


    /**
     * Executes HTTP request.
     *
     * Constructor arguments are not checked here; must be checked by caller
     * (HttpClient).
     *
     * @param array $properties {
     *      @var string $provider
     *      @var string $service
     *      @var string $endpoint
     *      @var string $method
     *      @var string $appTitle
     *      @var HttpLogger $httpLogger
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
        $this->httpLogger = $properties['httpLogger'];

        if ($this->aborted) {
            $this->setAbortedResponse();
            return;
        }
        // These two method arguments would be empty if aborted.
        $this->options = $options;
        $this->arguments = $arguments;

        // Get our (non-RestMini Client) options.
        if (!empty($options['debug_dump'])) {
            $this->debugDump = true;
        }
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
            $container = Dependency::container();
            /** @var CacheBroker $cache_broker */
            $cache_broker = $container->get('cache-broker');
            /** @var \KkSeb\Cache\FileCache $cache_store */
            $this->cacheStore = $cache_broker->getStore('http-client', CacheBroker::CACHE_VARIABLE_TTL, [
                'ttlDefault' => static::CACHEABLE_TIME_TO_LIVE,
            ]);
            unset($cache_broker);
        }

        // Get cached if exists (and not to be refreshed),
        // but do evaluate it anyway.
        if ($this->cacheable && !$this->cacheable['refresh']) {
            /** @var HttpResponse|null $cached_response */
            $cached_response = $this->cacheStore->get($this->cacheable['id']);
            if ($cached_response) {
                if ($this->debugDump) {
                    $this->httpLogger->log(LOG_DEBUG, 'Http cached response ◀', null, $cached_response);
                }
                $this->response = $cached_response;
                // Do not evaluate cached response. Presume that
                // responseRequirements (the only thing possibly worth checking)
                // are the same as last time.

                return;
            }
        }

        if (!empty($options['require_response_headers'])) {
            // RestMini Client needs 'get_header' option, for this to work.
            $this->options['get_headers'] = true;
            $this->responseRequirements['require_response_headers'] = $options['require_response_headers'];
        }
        if (!empty($options['err_on_endpoint_not_found'])) {
            $this->responseRequirements['err_on_endpoint_not_found'] = true;
        }
        if (!empty($options['err_on_resource_not_found'])) {
            $this->responseRequirements['err_on_resource_not_found'] = true;
        }

        $this->execute();
    }

    /**
     * @return void
     *
     * @uses HttpConfigurationException
     * @uses HttpLogicException
     * @uses HttpRequestException
     */
    protected function execute() /*: void*/
    {
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
                    $this->code = HttpClient::ERROR_CODES['local_configuration'];
                    // Do log even though RestMini Client also logs (as error),
                    // because we want a trace.
                    // And these errors are unlikely but severe.
                    $this->httpLogger->log(
                        LOG_ERR,
                        'Http init',
                        new HttpConfigurationException(
                            'request abort, configuration error code[' . $client_error['code']
                            . '] name[' . $error_name . '] message[' . $client_error['message'] . '].',
                            $this->code
                        ),
                        [
                            'options passed' => $this->options,
                        ]
                    );
                    $this->setAbortedResponse();
                    return;
                default:
                    $this->aborted = true;
                    $this->code = HttpClient::ERROR_CODES['local_default'];
                    // Do log even though RestMini Client also logs (as error),
                    // because we want a trace.
                    // And these errors are unlikely but severe.
                    $this->httpLogger->log(
                        LOG_ERR,
                        'Http init',
                        // Logic exception, because this should not be possible.
                        new HttpLogicException(
                            'request abort, unexpected error code[' . $client_error['code']
                            . '] name[' . $error_name . '] message[' . $client_error['message'] . '].',
                            $this->code
                        ),
                        [
                            'options passed' => $this->options,
                        ]
                    );
                    $this->setAbortedResponse();
                    return;
            }
        }

        if ($this->debugDump) {
            $this->httpLogger->log(LOG_DEBUG, 'Http request ▷', null, [
                'options' => $this->options,
                'arguments' => $this->arguments,
            ]);
        }

        $data = null;
        // Init/send request.
        $client->request(
            $this->properties['method'],
            $this->arguments['path'] ?? [],
            $this->arguments['query'] ?? [],
            $this->arguments['body'] ?? null
        );
        $client_error = $client->error();

        // Retry on unavailable.
        if (
            $client_error && !empty($this->options['retry_on_unavailable'])
            && ($client->status() == 503
                || $client_error['name'] == 'host_not_found' || $client_error['name'] == 'connection_failed'
            )
        ) {
            usleep(1000 * $this->options['retry_on_unavailable']);
            // Re-send; RestMini Client resets itself at secondary request().
            $client->request(
                $this->properties['method'],
                $this->arguments['path'] ?? [],
                $this->arguments['query'] ?? [],
                $this->arguments['body'] ?? null
            );
            $client_error = $client->error();
        }

        $response_evaluation = null;

        if (!$client_error) {
            $data = $client->result();
            $client_error = $client->error();
            if (!$client_error) {
                $status = $client->status();
                $body = new HttpResponseBody();
                $body->status = $status;
                $body->data = $data;
                $this->response = new HttpResponse(
                    $status,
                    !empty($this->options['get_headers']) ? $client->headers() : [],
                    $body
                );
                $response_evaluation = $this->response->evaluate([], false, $this->responseRequirements);
            }
        }
        if ($client_error) {
            // Request (or response body parsing) failed.
            $status = $client->status();
            // Don't log if RestMini Client error 'response_error', because that
            // is simply status >= 500, and HttpResponse::evaluate() do those.
            if ($client_error['name'] != 'response_error') {

                // @todo: do not log trace here - let HttpResponse::evaluate produce it.

                // Do log even though RestMini Client also logs (as warning),
                // because we want a trace.
                // But don't log options here; RestMini Client does that.
                $this->code = 7913; // @todo
                $this->httpLogger->log(
                    LOG_ERR,
                    'Http request',
                    new HttpRequestException(
                        'failure, error code[' . $client_error['code'] . '] name[' . $client_error['name']
                        . '] message[' . $client_error['message'] . '].',
                        $this->code
                    )
                );


            }
            // Status will be zero it RestMini Client failed to init connection.
            if (!$status) {
                $status = 500;
            }
            $body = new HttpResponseBody();
            $body->status = $status;
            $body->data = $data;
            $this->response = new HttpResponse(
                $status,
                !empty($this->options['get_headers']) ? $client->headers() : [],
                $body
            );
            $response_evaluation = $this->response->evaluate($client_error);
        }

        // Paranoid.
        if (!$this->response) {
            $this->code = HttpClient::ERROR_CODES['local_algo'];
            // Do log even though RestMini Client also logs (as error),
            // because we want a trace.
            // And these errors are unlikely but severe.
            $this->httpLogger->log(LOG_ERR, 'Http request', new HttpLogicException(
                'algo error in this method, did not set response instance var at all.',
                $this->code
            ));
            $this->response = new HttpResponse(500, [], new HttpResponseBody(), $this->code);
            $response_evaluation = $this->response->evaluate();
        }

        if ($response_evaluation) {
            $this->httpLogger->log(
                LOG_ERR,
                $response_evaluation['preface'],
                $response_evaluation['exception'] ?? null,
                $response_evaluation['variables'] ?? []
            );
        }
    }

    protected function setAbortedResponse()
    {
        // @todo: failry uncessary method; just the next lines of code where the methods is called.
        $this->response = new HttpResponse(500, [], new HttpResponseBody(), $this->code);
        // Do not log aborted once again.
        $this->response->evaluate(
            [
                'name' => 'request_aborted'
            ]
        );
    }

    /**
     * Convenience method for HttpRequest.
     *
     * @param int $code
     *
     * @return $this|HttpRequest
     */
    public function aborted(int $code) : HttpRequest
    {
        $this->aborted = true;
        $this->code = $code;
        return $this;
    }
}
