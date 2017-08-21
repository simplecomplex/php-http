<?php
/**
 * KIT/Koncernservice, Københavns Kommune.
 * @link https://kkgit.kk.dk/php-psr.kk-seb/http
 * @author Jacob Friis Mathiasen <jacob.friis.mathiasen@ks.kk.dk>
 */
declare(strict_types=1);

namespace KkSeb\Http;

use SimpleComplex\Utils\Explorable;
use SimpleComplex\Utils\Utils;
use SimpleComplex\Utils\Dependency;
use SimpleComplex\Utils\PathFileListUnique;
use SimpleComplex\RestMini\Client as RestMiniClient;
use SimpleComplex\Validate\ValidationRuleSet;
use KkSeb\Common\Cache\CacheBroker;
use KkSeb\Common\Validate\Validate;
use KkSeb\User\User;
use KkSeb\Http\Exception\HttpLogicException;
use KkSeb\Http\Exception\HttpConfigurationException;
use KkSeb\Http\Exception\HttpRequestException;
use KkSeb\Http\Exception\HttpResponseException;
use KkSeb\Http\Exception\HttpResponseValidationException;

/**
 * HTTP request, to be issued by HttpClient.
 *
 * @internal
 *
 * @property-read string $operation  provider.service.endpoint.METHODorAlias
 * @property-read \KkSeb\Http\HttpResponse $response
 * @property-read int $code
 * @property-read bool $aborted
 * @property-read array $options
 * @property-read array $arguments
 *
 * @package KkSeb\Http
 */
class HttpRequest extends Explorable
{
    // Explorable.--------------------------------------------------------------

    /**
     * @var array
     */
    protected $explorableIndex = [
        'operation',
        'response',
        'code',
        'aborted',
        'options',
        'arguments',
    ];

    /**
     * @param string $name
     *
     * @return mixed
     *
     * @throws \OutOfBoundsException
     *      If no such instance property.
     */
    public function __get($name)
    {
        if (in_array($name, $this->explorableIndex, true)) {
            if ($name == 'operation') {
                return $this->properties['operation'];
            }
            return $this->{$name};
        }
        throw new \OutOfBoundsException(get_class($this) . ' instance exposes no property[' . $name . '].');
    }

    /**
     * @param string $name
     * @param mixed|null $value
     *
     * @return void
     *
     * @throws \OutOfBoundsException
     *      If no such instance property.
     * @throws \RuntimeException
     *      If that instance property is read-only.
     */
    public function __set($name, $value) /*: void*/
    {
        if (in_array($name, $this->explorableIndex, true)) {
            throw new \RuntimeException(get_class($this) . ' instance property[' . $name . '] is read-only.');
        }
        throw new \OutOfBoundsException(get_class($this) . ' instance exposes no property[' . $name . '].');
    }


    // Business.----------------------------------------------------------------

    /**
     * Default cacheable time-to-live.
     *
     * @var int
     */
    const CACHEABLE_TIME_TO_LIVE = 3600;

    /**
     * Path to where response validation rule set .json-files reside.
     *
     * Relative path is relative to document root.
     *
     * @var string
     */
    const PATH_VALIDATION_RULE_SET = '../conf/json/http/response-validation-rule-sets';

    /**
     * Path to where response mock .json-files reside.
     *
     * Relative path is relative to document root.
     *
     * @var string
     */
    const PATH_MOCK = '../conf/json/http/response-mocks';

    /**
     * Options supported:
     * - (bool|arr) cacheable
     * - (bool|arr) validate_response: do validate response against rule set(s)
     * - (bool|arr) mock_response: use response mock
     * - (arr) require_response_headers: list of response header keys required
     * - (bool) err_on_endpoint_not_found: 404 + HTML
     * - (bool) err_on_resource_not_found: 204, 404 + JSON
     *
     * The cacheable option as array:
     * - (int) ttl: time-to-live, default CACHEABLE_TIME_TO_LIVE
     * - refresh: retrieve new response and cache it, default not
     * - anybody: for any user, default current user only
     *
     * The validate_response option as array:
     * - (str) rule_set_variants: comma-separated list of variant names,
     *   'default' meaning the default non-variant rule set
     * - (bool) no_cache_rules: do (not) cache the (JSON-derived) rule set(s)
     *
     * The mock_response option as array:
     * - (str) variant: 'default' means the default mock
     * - (bool) no_cache_mock: do (not) cache the (JSON-derived) mock
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
        // bool|array.
        'validate_response',
        // bool|arr.
        'mock_response',
        // int; milliseconds.
        'retry_on_unavailable',
        // array.
        'require_response_headers',
        // bool; 404 + HTML.
        'err_on_endpoint_not_found',
        // bool; unexpected 204, 404 + JSON.
        'err_on_resource_not_found',
    ];


    // Read-only members.-------------------------------------------------------

    /**
     * @var \KkSeb\Http\HttpResponse
     */
    protected $response;

    /**
     * @var int
     */
    protected $code = 0;

    /**
     * @var bool
     */
    protected $aborted = false;

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


    // Various internal members.------------------------------------------------

    /**
     * @var array {
     *      @var string $method  METHOD
     *      @var string $operation  provider.server.endpoint.METHODorAlias
     *      @var string $appTitle  Localized application title.
     *      @var HttpLogger $httpLogger
     * }
     */
    protected $properties;

    /**
     * @var bool|null
     */
    protected $debugDump;

    /**
     * @var array {
     *      @var int $ttl
     *      @var bool $anybody
     *      @var bool $refresh
     * }
     */
    protected $cacheable = [];

    /**
     * @var array {
     *      @var string $rule_set_variants
     *      @var bool $no_cache_rules
     * }
     */
    protected $validateResponse = [];


    // Helpers.-----------------------------------------------------------------

    /**
     * @var HttpLogger
     */
    protected $httpLogger;

    /**
     * @var \KkSeb\Common\Cache\FileCache|null
     */
    protected $responseCacheStore;


    /**
     * Executes HTTP request.
     *
     * Constructor arguments are not checked here; must be checked by caller
     * (HttpClient).
     *
     * @param array $properties {
     *      @var string $method  METHOD
     *      @var string $operation  provider.server.endpoint.METHODorAlias
     *      @var string $appTitle  Localized application title.
     *      @var HttpLogger $httpLogger
     * }
     * @param array $options
     * @param array $arguments {
     *      @var array $path  Optional.
     *      @var array $query  Optional.
     *      @var mixed $body  Optional.
     * }
     * @param int $abortCode
     *      Abortion error code; the request shan't be executed at all,
     *      due to a previously detected (and logged) error.
     */
    public function __construct(array $properties, array $options, array $arguments, int $abortCode = 0)
    {
        $this->properties = $properties;
        $this->httpLogger = $properties['httpLogger'];

        if ($abortCode) {
            // Previously detected and logged error.
            $this->aborted = true;
            $this->code = $abortCode;
            $this->response = $this->evaluate(new HttpResponse(500, [], new HttpResponseBody()));
            return;
        }

        // These two method arguments would be empty if aborted.
        $this->options = $options;
        $this->arguments = $arguments;

        // Get our (non-RestMini Client) options.
        if (!empty($options['debug_dump'])) {
            $this->debugDump = true;
        }

        // Cacheable - never if mock_response.
        if (
            empty($this->options['mock_response'])
            && !empty($options['cacheable'])
        ) {
            $chbl = $options['cacheable'];
            $this->cacheable = [
                'ttl' => static::CACHEABLE_TIME_TO_LIVE,
                'id' => $properties['operation'] . '[user-',
                'refresh' => false,
            ];
            if ($chbl === true) {
                $this->cacheable['id'] .= User::get()->id . ']';
            } else {
                if (!empty($chbl['ttl'])) {
                    $this->cacheable['ttl'] = $chbl['ttl'];
                }
                if (!empty($chbl['anybody'])) {
                    /**
                     * Use dot for wildcard user, because cache doesn't allow *
                     * @see \SimpleComplex\Cache\CacheKey::VALID_NON_ALPHANUM
                     */
                    $this->cacheable['id'] .= '.]';
                } else {
                    $this->cacheable['id'] .= User::get()->id . ']';
                }
                if (!empty($chbl['refresh'])) {
                    $this->cacheable['refresh'] = true;
                }
            }
            unset($chbl);
            $container = Dependency::container();
            /** @var CacheBroker $cache_broker */
            $cache_broker = $container->get('cache-broker');
            /** @var \KkSeb\Common\Cache\FileCache $cache_store */
            $this->responseCacheStore = $cache_broker->getStore('http-response', CacheBroker::CACHE_VARIABLE_TTL, [
                'ttlDefault' => static::CACHEABLE_TIME_TO_LIVE,
            ]);
            unset($cache_broker);

            // Get cached if exists and not to be refreshed.
            if (!$this->cacheable['refresh']) {
                /** @var HttpResponse|null $cached_response */
                $cached_response = $this->responseCacheStore->get($this->cacheable['id']);
                if ($cached_response) {
                    if ($this->debugDump) {
                        $this->httpLogger->log(LOG_DEBUG, 'Http cached response ◀', null, $cached_response);
                    }
                    $this->response = $cached_response;
                    // Do not evaluate cached response. Assume that response headers
                    // and 204|404 checks are the same as last time.
                    // Do not validate cached response. Assume that validation
                    // rule set(s) are the same as last time.
                    return;
                }
            }
        }

        if (!empty($this->options['validate_response'])) {
            $vldrspns = $this->options['validate_response'];
            $this->validateResponse = [
                'variant_rule_sets' => 'default',
                'no_cache_rules' => false,
            ];
            if (is_array($vldrspns)) {
                if (!empty($vldrspns['variant_rule_sets'])) {
                    $this->validateResponse['variant_rule_sets'] = $vldrspns['variant_rule_sets'];
                }
                if (!empty($vldrspns['no_cache_rules'])) {
                    $this->validateResponse['no_cache_rules'] = true;
                }
            }
            unset($vldrspns);
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
        $base_url = $this->options['base_url'];
        $endpoint_path = $this->options['service_path'] . '/' . $this->options['endpoint_path'];

        if (!empty($this->options['mock_response'])) {
            if ($this->debugDump) {
                $this->httpLogger->log(LOG_DEBUG, 'Http request ▷', null, [
                    'options' => $this->options,
                    'arguments' => $this->arguments,
                ]);
            }
            if (is_array($this->options['mock_response'])) {
                $variant = $this->options['mock_response']['variant'] ?? 'default';
                $no_cache_mock = !empty($this->options['mock_response']['no_cache_mock']);
            } else {
                $variant = 'default';
                $no_cache_mock = false;
            }
            $mock = $this->mock($variant, $no_cache_mock);
            if (!$mock[0]) {
                // Don't pass failed mock product through evalution.
                $this->response = $mock[1];
            } else {
                $this->response = $this->evaluate(
                    $mock[1],
                    [],
                    // Set dummy RestMini Client info.
                    // evaluate() only uses content_type.
                    [
                        'content_type' => 'application/json',
                    ]
                );
            }
            return;
        }

        // Filter non-RestMini Client options off,
        // even option not supported at all.
        $client_options = array_intersect_key(
            $this->options,
            array_fill_keys(RestMiniClient::OPTIONS_SUPPORTED, true)
        );

        $client = new RestMiniClient($base_url, $endpoint_path, $client_options);

        // Set RestMini Client log type, for evaluate().
        $this->properties['clientLogType'] = $client->logType();

        // Check for RestMini Client initialisation error.
        $client_error = $client->error();
        if ($client_error) {
            $error_name = $client_error['name'];
            switch ($error_name) {
                case 'server_arg_empty':
                case 'protocol_not_supported':
                    $this->code = HttpClient::ERROR_CODES['local-configuration'];
                    $exception = new HttpConfigurationException(
                        'Request abort, configuration error code[' . $client_error['code']
                        . '] name[' . $error_name . '] message[' . $client_error['message'] . '].',
                        $this->code
                    );
                    break;
                case 'option_not_supported':
                case 'option_value_missing':
                case 'option_value_empty':
                case 'option_value_invalid':
                    $this->code = HttpClient::ERROR_CODES['local-option'];
                    $exception = new HttpConfigurationException(
                        'Request abort, RestMinit Client option error code[' . $client_error['code']
                        . '] name[' . $error_name . '] message[' . $client_error['message'] . '].',
                        $this->code
                    );
                    break;
                case 'init_connection':
                    $this->code = HttpClient::ERROR_CODES['local-init'];
                    $exception = new HttpConfigurationException(
                        'Request abort, cURL init error code[' . $client_error['code']
                        . '] name[' . $error_name . '] message[' . $client_error['message'] . '].',
                        $this->code
                    );
                    break;
                case 'request_options':
                    $this->code = HttpClient::ERROR_CODES['local-option'];
                    $exception = new HttpConfigurationException(
                        'Request abort, cURL option error code[' . $client_error['code']
                        . '] name[' . $error_name . '] message[' . $client_error['message'] . '].',
                        $this->code
                    );
                    break;
                default:
                    $this->code = HttpClient::ERROR_CODES['local-unknown'];
                    $exception = new HttpLogicException(
                        'Request abort, unexpected error code[' . $client_error['code']
                        . '] name[' . $error_name . '] message[' . $client_error['message'] . '].',
                        $this->code
                    );
            }
            $this->aborted = true;
            // Do log even though RestMini Client also logs (as error),
            // because we want a trace.
            // And these errors are unlikely but severe.
            $this->httpLogger->log(
                LOG_ERR,
                'Http init',
                // Logic exception, because this should not be possible.
                $exception,
                [
                    'options' => $this->options,
                ]
            );
            $this->response = $this->evaluate(new HttpResponse(500, [], new HttpResponseBody()));
            return;
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
            $client->reset();
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

        if (!$client_error) {
            $data = $client->result();
            $client_error = $client->error();
            if (!$client_error) {
                // Apparant success.
                $status = $client->status();
                // Do evaluate for:
                // - unexpected status
                // - require_response_headers
                // - err_on_endpoint_not_found
                // - err_on_resource_not_found
                $this->response = $this->evaluate(
                    new HttpResponse(
                        $status,
                        [
                            'X-KkSeb-Http-Original-Status' => $status,
                            // evaluate() may override final status.
                            'X-KkSeb-Http-Final-Status' => $status,
                        ],
                        new HttpResponseBody(
                            true,
                            $status,
                            $data
                        ),
                        !empty($this->options['get_headers']) ? $client->headers() : []
                    ),
                    [],
                    // We do not need the info when RestMini Client reports error,
                    // because then it logs (warning) all by itself.
                    $client->info()
                );
                return;
            }
        }
        if ($client_error) {
            // Request (or response body parsing) failed.
            $status = $client->status();
            // Status will be zero it RestMini Client failed to init connection.
            if (!$status) {
                $status = 500;
                // No original status; however evaluate() might set it.
                $return_headers = [];
            } else {
                $return_headers = [
                    'X-KkSeb-Http-Original-Status' => $status,
                ];
            }
            $this->response = $this->evaluate(
                new HttpResponse(
                    $status,
                    $return_headers,
                    new HttpResponseBody(
                        false,
                        $status,
                        // Despite possibly boolean false.
                        $data
                    ),
                    !empty($this->options['get_headers']) ? $client->headers() : []
                ),
                $client_error
            );
        }

        // Paranoid.
        if (!$this->response) {
            $this->aborted = true;
            $this->code = HttpClient::ERROR_CODES['local-algo'];
            // Do log even though RestMini Client also logs (as error),
            // because we want a trace.
            // And these errors are unlikely but severe.
            $this->httpLogger->log(LOG_ERR, 'Http request', new HttpLogicException(
                'Algo error in this method, did not set response instance var at all.',
                $this->code
            ));
            $this->response = $this->evaluate(new HttpResponse(500, [], new HttpResponseBody()));
        }
    }

    /**
     * Evaluates response object and modifies it if response status
     * or arg error suggests that i. request failed or ii. response is faulty.
     *
     * Sets the responses's HttpResponseBody->status to false,
     * and HttpResponseBody->message to safe and user-friendly message,
     * if request/response evaluates to failure.
     *
     * @see RestMiniClient::info()
     * @see RestMiniClient::ERROR_CODES
     * @see HttpClient::ERROR_CODES
     *
     * @param HttpResponse $response
     * @param array $error {
     *      @var int $code  If set.
     *      @var string $name  If set.
     *      @var string $message  If set.
     * }
     *      RestMini Client error, if any.
     * @param array $info
     *      Optional RestMini Client info. Only needed when apparantly
     *      successful response.
     *
     * @return \KkSeb\Http\HttpResponse
     */
    protected function evaluate(HttpResponse $response, array $error = [], array $info = []) : HttpResponse
    {
        // Refer the inner HttpResponseBody.
        $body = $response->body;

        if ($this->aborted) {
            $response->status = $body->status = 500;
            $body->code = $this->code;
            $code_names = array_flip(HttpClient::ERROR_CODES);
            /** @var \SimpleComplex\Locale\AbstractLocale $locale */
            $locale = Dependency::container()->get('locale');
            $replacers = [
                'error' => ($this->code + HttpClient::ERROR_CODE_OFFSET) . ':http:' . $code_names[$this->code],
                'app-title' => $this->properties['appTitle'],
            ];
            $body->message = $locale->text('http:error:' . $code_names[$this->code], $replacers)
                // Deliberately '\n' not "\n".
                . '\n' . $locale->text('http:error-suffix_user-report-error', $replacers);

            // Aborted is already logged.
            return $response;
        }

        // Copy original status, we may overwrite it.
        $original_status = $response->status;
        // Investigate RestMini Client error.
        if ($error) {
            // Listed in expected order of expected frequency.
            switch ($error['name']) {
                case 'response_error':
                    // RestMini Client flags that status >=500.
                    switch ($original_status) {
                        case 500:
                            // Set to Bad Gateway; not our fault.
                            $response->status = $body->status = 502;
                            $this->code = HttpClient::ERROR_CODES['remote'];
                            break;
                        case 502:
                            // Bad Gateway; keep status.
                            $this->code = HttpClient::ERROR_CODES['remote-propagated'];
                            break;
                        case 503:
                            // Service unavailable.
                            // Keep status, frontend client may wish to retry later.
                            $this->code = HttpClient::ERROR_CODES['service-unavailable'];
                            break;
                        case 504:
                            // Request timeout; keep status.
                            $this->code = HttpClient::ERROR_CODES['timeout-propagated'];
                            break;
                        default:
                            // Unexpecteds; set to Bad Gateway, not our fault.
                            // But keep the the original status on $body->status.
                            $response->status = 502;
                            $this->code = HttpClient::ERROR_CODES['malign-status-unexpected'];
                    }
                    break;
                case 'request_timed_out':
                    // 504 Gateway Timeout.
                    $response->headers['X-KkSeb-Http-Original-Status'] =
                    $response->status = $body->status = 504;
                    $this->code = HttpClient::ERROR_CODES['timeout'];
                    break;
                case 'host_not_found':
                case 'connection_failed':
                    // 502 Bad Gateway.
                    // Perhaps upon retry (option: 'retry_on_unavailable').
                    $response->headers['X-KkSeb-Http-Original-Status'] =
                    $response->status = $body->status = 502;
                    $this->code = HttpClient::ERROR_CODES['host-unavailable'];
                    break;
                case 'response_false':
                    // 502 Bad Gateway.
                    // RestMini Client's fallback cURL error.
                    $response->status = $body->status = 502;
                    $this->code = HttpClient::ERROR_CODES['response-none'];
                    break;
                case 'content_type_mismatch':
                    // Probably HTML body.
                    $response->status = $body->status = 502;
                    $this->code = HttpClient::ERROR_CODES['response-type'];
                    break;
                case 'response_parse':
                    // Bad JSON.
                    $response->status = $body->status = 502;
                    $this->code = HttpClient::ERROR_CODES['response-format'];
                    break;
                case 'too_many_redirects':
                    // 502 Bad Gateway.
                    $response->status = $body->status = 502;
                    $this->code = HttpClient::ERROR_CODES['too-many-redirects'];
                    break;
                case 'url_malformed':
                    // Apparantly RestMini Client produced a bad URL.
                    $response->status = $body->status = 500;
                    $this->code = HttpClient::ERROR_CODES['local-use'];
                    break;
                default:
                    // Perhaps an SSL error. Probably our fault.
                    $response->status = $body->status = 500;
                    $this->code = HttpClient::ERROR_CODES['unknown'];
            }
            $response->headers['X-KkSeb-Http-Final-Status'] = $response->status;
            // Set body 'code'.
            $body->code = $this->code;
        }
        else {
            // Apparantly successful request/response.
            // Arg $info will be non-empty, and if option 'record_args'
            // $info even contains the request arguments sent.

            // Every status but 200|201|204|304|404 is considered malign.
            switch ($original_status) {
                case 200:
                case 201:
                case 202: // Accepted.
                    // Swell.
                    break;
                case 204: // No Content.
                    if (!empty($this->options['err_on_resource_not_found'])) {
                        // Keep status; flag failure on response body.
                        $body->success = false;
                        $this->code = HttpClient::ERROR_CODES['resource_not_found'];
                    }
                    break;
                case 304: // Not Modified.
                    // Swell.
                    break;
                case 400: // Bad Request.
                    $body->success = false;
                    // Keep status.
                    $this->code = HttpClient::ERROR_CODES['remote-validation-bad'];
                    break;
                case 404:
                    if (
                        !empty($this->options['err_on_endpoint_not_found'])
                        && stripos($info['content_type'], 'JSON') === false
                    ) {
                        // Keep status; flag failure on response body.
                        $body->success = false;
                        $this->code = HttpClient::ERROR_CODES['endpoint-not-found'];
                    }
                    elseif (!empty($this->options['err_on_resource_not_found'])) {
                        // Keep status; flag failure on response body.
                        $body->success = false;
                        $this->code = HttpClient::ERROR_CODES['resource-not-found'];
                    }
                    break;
                case 412: // Precondition Failed.
                    $body->success = false;
                    // Keep status.
                    $this->code = HttpClient::ERROR_CODES['remote-validation-failed'];
                    break;
                default:
                    // Any other status is considered malign; though not as malign
                    // as unsupported 5xx status.
                    $body->success = false;
                    // Unexpecteds; set to Bad Gateway, not our fault.
                    // But keep the the original status on $body->status.
                    $response->headers['X-KkSeb-Http-Final-Status'] =
                    $response->status = 502;
                    $this->code = HttpClient::ERROR_CODES['benign-status-unexpected'];
            }
        }

        // Require response headers.
        if (!$this->code && !empty($this->options['require_response_headers'])) {
            $headers_required =& $this->options['require_response_headers'];
            foreach ($headers_required as $header) {
                if (!isset($response->headers[$header])) {
                    $body->success = false;
                    // Set to Bad Gateway, not our fault.
                    // But keep the the original status on $body->status.
                    $response->headers['X-KkSeb-Http-Final-Status'] =
                    $response->status = 502;
                    $this->code = HttpClient::ERROR_CODES['header-missing'];
                    break;
                }
            }
        }

        // Handle error.
        if ($this->code) {
            // Log.
            $code_names = array_flip(HttpClient::ERROR_CODES);
            $this->httpLogger->log(
                LOG_ERR,
                'Http response',
                new HttpResponseException(
                    'Response evaluates to HttpClient error[' . $code_names[$this->code] . '].',
                    $this->code + HttpClient::ERROR_CODE_OFFSET
                ),
                [
                    'final status' => $response->status,
                    'original status' => $original_status,
                    'info' => $info ? $info :
                        'see previous warning, type or subtype \'' . $this->properties['clientLogType'] . '\',',
                    'response' => $response,
                ]
            );
            // Set body 'message'.
            /** @var \SimpleComplex\Locale\AbstractLocale $locale */
            $locale = Dependency::container()->get('locale');
            $replacers = [
                'error' => ($this->code + HttpClient::ERROR_CODE_OFFSET) . ':http:' . $code_names[$this->code],
                'app-title' => $this->properties['appTitle'],
            ];
            $body->message = $locale->text('http:error:' . $code_names[$this->code], $replacers);
            switch ($code_names[$this->code]) {
                case 'timeout':
                case 'timeout-propagated':
                    // User shan't report issue for 504 Gateway Timeout.
                    break;
                default:
                    // Deliberately '\n' not "\n".
                    $body->message .= '\n' . $locale->text('http:error-suffix_user-report-error', $replacers);
            }
        }
        elseif ($this->validateResponse) {
            $this->validate($response);
        }
        elseif ($this->cacheable) {
            $this->responseCacheStore->set($this->cacheable['id'], $response, $this->cacheable['ttl']);
        }

        if (!$this->code && $this->debugDump) {
            $this->httpLogger->log(
                LOG_DEBUG,
                'Http' . (empty($this->options['mock_response']) ? '' : ' mocked') . ' response ◀',
                null,
                $response
            );
        }

        return $response;
    }

    /**
     * Validate response against one or more predefined validation rule sets.
     *
     * If more variant rule sets, the response will be checked against them
     * in the received order, and validation stops at first pass.
     * NB: do make sure that strict rule sets go before forgiving rule sets.
     *
     * Modifies response + response body if validation fails, setting:
     * - 'code' to HttpClient error code 'response-validation'
     * - 'success' to false
     * - 'status' to 502 Bad Gateway (response only, not response body)
     * - 'message'
     * - 'data' to null
     *
     * @code
     * # CLI delete cached validation rule set.
     * php cli.phpsh cache-delete http-response_validation-rule-set provider.service.endpoint.METHODorAlias
     * @endcode
     *
     * @param HttpResponse $response
     *
     * @return void
     */
    protected function validate(HttpResponse $response) /*: void*/
    {
        $rule_sets = array_fill_keys(
            explode(',', str_replace(' ', '', $this->validateResponse['variant_rule_sets'])),
            // Set rule set here.
            null
        );

        $container = Dependency::container();

        // Get cache broker even if no_cache_rules; truthy no_cache_rules
        // should't be used in production.
        /** @var CacheBroker $cache_broker */
        $cache_broker = $container->get('cache-broker');
        /** @var \KkSeb\Common\Cache\PersistentFileCache $cache_store */
        $rule_set_cache_store = $cache_broker->getStore(
            'http-response_validation-rule-set',
            CacheBroker::CACHE_PERSISTENT
        );
        unset($cache_broker);

        // Retrieve rule sets from cache, or memorize which to build from JSON.
        $base_name = $this->properties['operation'];
        $extension = '.validation-rule-set.json';
        $read_filenames = $found_file_paths = $do_cache_sets = [];
        foreach ($rule_sets as $variant => &$rule_set) {
            if (
                $this->validateResponse['no_cache_rules']
                || !($rule_set = $rule_set_cache_store->get(
                    $base_name . ($variant == 'default' ? '' : ('.' . $variant))
                ))
            ) {
                $read_filenames[$variant] = $base_name . ($variant == 'default' ? '' : ('.' . $variant)) . $extension;
                $found_file_paths[$variant] = null;
                if (!$this->validateResponse['no_cache_rules']) {
                    $do_cache_sets[] = $variant;
                }
            }
        }
        // Iteration ref.
        unset($rule_set);

        // If any to build from JSON.
        if ($read_filenames) {
            // Retrieve JSON files from validation rule set path.
            $utils = Utils::getInstance();
            $path = '';
            try {
                // Throws various exceptions.
                $path = $utils->resolvePath(static::PATH_VALIDATION_RULE_SET);
                // Throws \InvalidArgumentException, \RuntimeException.
                $files = (new PathFileListUnique($path, ['validation-rule-set.json']))->getArrayCopy();
                foreach ($read_filenames as $variant => $filename) {
                    if (isset($files[$filename])) {
                        $found_file_paths[$variant] = $files[$filename];
                        unset($read_filenames[$variant]);
                    }
                }
                // All found?
                if ($read_filenames) {
                    $this->code = HttpClient::ERROR_CODES['local-configuration'];
                    $this->httpLogger->log(
                        LOG_ERR,
                        'Http validate response',
                        new HttpConfigurationException(
                            get_class($this) . '::PATH_VALIDATION_RULE_SET path['
                            . static::PATH_VALIDATION_RULE_SET . '] have no files of variants['
                            . join(', ', array_keys($read_filenames)) . '] as children or descendants.',
                            $this->code + HttpClient::ERROR_CODE_OFFSET
                        ),
                        [
                            'files missing' => $read_filenames,
                            'files found' => array_keys($files),
                        ]
                    );
                }
                else {
                    // Read and parse them.
                    foreach ($found_file_paths as $variant => $file_path) {
                        $rule_sets[$variant] = $utils->parseJsonFile($file_path);
                    }
                }
            } catch (\SimpleComplex\Utils\Exception\ParseJsonException $xcptn) {
                $this->code = HttpClient::ERROR_CODES['local-configuration'];
                $this->httpLogger->log(
                    LOG_ERR,
                    'Http validate response',
                    new HttpConfigurationException(
                        'A rule set JSON file is not parsable, see previous.',
                        $this->code + HttpClient::ERROR_CODE_OFFSET,
                        $xcptn
                    )
                );
            } catch (\Throwable $xcptn) {
                if (!$path) {
                    $this->code = HttpClient::ERROR_CODES['local-algo'];
                    $this->httpLogger->log(
                        LOG_ERR,
                        'Http validate response',
                        new HttpLogicException(
                            get_class($this) . '::PATH_VALIDATION_RULE_SET path['
                            . static::PATH_VALIDATION_RULE_SET . '] is not a valid path.',
                            $this->code + HttpClient::ERROR_CODE_OFFSET,
                            $xcptn
                        )
                    );
                } else {
                    $this->code = HttpClient::ERROR_CODES['local-configuration'];
                    $this->httpLogger->log(
                        LOG_ERR,
                        'Http validate response',
                        new HttpConfigurationException(
                            'Some rule set JSON file is non-unique, non-existent or unreadable, see previous.',
                            $this->code + HttpClient::ERROR_CODE_OFFSET,
                            $xcptn
                        )
                    );
                }
            }
        }

        // If no building from JSON, or no builts failed: do validate.
        if (!$this->code) {
            /** @var Validate $validate */
            $validate = $container->has('validate') ? $container->get('validate') : new Validate();
            $passed = false;
            $records = [];
            // Use rule sets by reference because Validate::challengeRecording()
            // will convert them to ValidationRuleSets; which are faster.
            foreach ($rule_sets as $variant => &$rule_set) {
                $result = $validate->challengeRecording($response->body->data, $rule_set);
                if ($result['passed']) {
                    $passed = true;
                    break;
                }
                $records[$variant] = $result['record'];
            }
            // Iteration ref.
            unset($rule_set);

            if ($do_cache_sets) {
                foreach ($do_cache_sets as $variant) {
                    // Cast outer rule set to actual ValidationRuleSet.
                    Utils::cast($rule_sets[$variant], ValidationRuleSet::class);
                    $rule_set_cache_store->set(
                        $base_name . ($variant == 'default' ? '' : ('.' . $variant)),
                        $rule_sets[$variant]
                    );
                }
            }

            if (!$passed) {
                $this->code = HttpClient::ERROR_CODES['response-validation'];
                $this->httpLogger->log(
                    LOG_ERR,
                    'Http validate response',
                    new HttpResponseValidationException(
                        'Response failed validation.',
                        $this->code + HttpClient::ERROR_CODE_OFFSET
                    ),
                    $records
                );
            }
        }

        // If there was any error, or validation failed.
        if ($this->code) {
            // Flag that response has been validated, and failed.
            $response->validated = false;

            // Validation failure is 502 Bad Gateway.
            // Error is 500 Internal Server Error.
            $response->headers['X-KkSeb-Http-Final-Status'] =
            $response->status = $response->body->status =
                ($this->code == HttpClient::ERROR_CODES['response-validation'] ? 502 : 500);

            $response->body->success = false;
            $response->body->code = $this->code;
            // Clear body data.
            $response->body->data = null;
            // Set body 'message'.
            $code_names = array_flip(HttpClient::ERROR_CODES);
            /** @var \SimpleComplex\Locale\AbstractLocale $locale */
            $locale = Dependency::container()->get('locale');
            $replacers = [
                'error' => ($this->code + HttpClient::ERROR_CODE_OFFSET) . ':http:' . $code_names[$this->code],
                'app-title' => $this->properties['appTitle'],
            ];
            $response->body->message = $locale->text('http:error:' . $code_names[$this->code], $replacers)
                // Deliberately '\n' not "\n".
                . '\n' . $locale->text('http:error-suffix_user-report-error', $replacers);
        }
        else {
            // Flag that response has been validated, and passed.
            $response->validated = true;

            if ($this->cacheable) {
                $this->responseCacheStore->set($this->cacheable['id'], $response, $this->cacheable['ttl']);
            }
        }
    }

    /**
     * Retrieves mock response from cache or JSON file.
     *
     * @code
     * # CLI delete cached mock response.
     * php cli.phpsh cache-delete http-response_mock provider.service.endpoint.METHODorAlias
     * @endcode
     *
     * @param string $variant
     * @param bool $noCacheMock
     *
     * @return array {
     *      @var bool  False on mock production failure.
     *      @var HttpResponse
     * }
     */
    protected function mock(string $variant, $noCacheMock = false) : array
    {
        $container = Dependency::container();
        /** @var CacheBroker $cache_broker */
        $cache_broker = $container->get('cache-broker');
        /** @var \KkSeb\Common\Cache\PersistentFileCache $cache_store */
        $mock_cache_store = $cache_broker->getStore(
            'http-response_mock',
            CacheBroker::CACHE_PERSISTENT
        );
        unset($cache_broker);

        $base_name = $this->properties['operation'];
        if ($variant != 'default') {
            $base_name .= '.' . $variant;
        }
        $mock = null;
        if ($noCacheMock || !($mock = $mock_cache_store->get($base_name))) {
            // Find JSON file in mock path.
            $utils = Utils::getInstance();
            $path = '';
            try {
                // Throws various exceptions.
                $path = $utils->resolvePath(static::PATH_MOCK);
                // Throws \InvalidArgumentException, \RuntimeException.
                $files = (new PathFileListUnique($path, ['mock.json']))->getArrayCopy();
                if (!isset($files[$base_name . '.mock.json'])) {
                    $this->code = HttpClient::ERROR_CODES['local-configuration'];
                    $this->httpLogger->log(
                        LOG_ERR,
                        'Http mock response',
                        new HttpConfigurationException(
                            get_class($this) . '::PATH_MOCK path['
                            . static::PATH_MOCK . '] have no file of variant[' . $variant . '] as child or descendant.',
                            $this->code + HttpClient::ERROR_CODE_OFFSET
                        ),
                        [
                            'file required' => $base_name . '.mock.json',
                            'files found' => array_keys($files),
                        ]
                    );
                } else {
                    $mock = $utils->parseJsonFile($files[$base_name . '.mock.json']);
                    HttpResponse::cast($mock);
                }
            } catch (\SimpleComplex\Utils\Exception\ParseJsonException $xcptn) {
                $this->code = HttpClient::ERROR_CODES['local-configuration'];
                $this->httpLogger->log(
                    LOG_ERR,
                    'Http mock response',
                    new HttpConfigurationException(
                        'Mock JSON file is not parsable, see previous.',
                        $this->code + HttpClient::ERROR_CODE_OFFSET,
                        $xcptn
                    )
                );
            } catch (\Throwable $xcptn) {
                if (!$path) {
                    $this->code = HttpClient::ERROR_CODES['local-algo'];
                    $this->httpLogger->log(
                        LOG_ERR,
                        'Http mock response',
                        new HttpLogicException(
                            get_class($this) . '::PATH_MOCK path[' . static::PATH_MOCK . '] is not a valid path.',
                            $this->code + HttpClient::ERROR_CODE_OFFSET,
                            $xcptn
                        )
                    );
                } else {
                    $this->code = HttpClient::ERROR_CODES['local-configuration'];
                    $this->httpLogger->log(
                        LOG_ERR,
                        'Http mock response',
                        new HttpConfigurationException(
                            'Mock JSON file is non-unique, non-existent, unreadable or can\'t be cast to HttpResponse'
                            . ', see previous.',
                            $this->code + HttpClient::ERROR_CODE_OFFSET,
                            $xcptn
                        )
                    );
                }
            }
            if (!$noCacheMock && !$this->code) {
                $mock_cache_store->set($base_name, $mock);
            }
        }

        if (!$this->code) {
            $mock->headers['X-KkSeb-Http-Mock-Response'] = '1';
            return [
                true,
                $mock
            ];
        }

        // For body 'message'.
        $code_names = array_flip(HttpClient::ERROR_CODES);
        /** @var \SimpleComplex\Locale\AbstractLocale $locale */
        $locale = Dependency::container()->get('locale');
        $replacers = [
            'error' => ($this->code + HttpClient::ERROR_CODE_OFFSET) . ':http:' . $code_names[$this->code],
            'app-title' => $this->properties['appTitle'],
        ];
        return [
            false,
            new HttpResponse(
                500,
                [],
                new HttpResponseBody(
                    false,
                    500,
                    null,
                    $locale->text('http:error:' . $code_names[$this->code], $replacers)
                    // Deliberately '\n' not "\n".
                    . '\n' . $locale->text('http:error-suffix_user-report-error', $replacers),
                    $this->code
                )
            )
        ];
    }
}
