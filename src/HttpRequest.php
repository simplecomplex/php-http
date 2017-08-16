<?php
/**
 * KIT/Koncernservice, Københavns Kommune.
 * @link https://kkgit.kk.dk/php-psr.kk-seb/http
 * @author Jacob Friis Mathiasen <jacob.friis.mathiasen@ks.kk.dk>
 */
declare(strict_types=1);

namespace KkSeb\Http;

use SimpleComplex\Utils\Utils;
use SimpleComplex\Utils\Dependency;
use SimpleComplex\Utils\PathFileList;
use SimpleComplex\RestMini\Client as RestMiniClient;
use SimpleComplex\Validate\ValidationRuleSet;
use KkSeb\Validate\Validate;
use KkSeb\Cache\CacheBroker;
use KkSeb\Http\Exception\HttpLogicException;
use KkSeb\Http\Exception\HttpConfigurationException;
use KkSeb\Http\Exception\HttpRequestException;
use KkSeb\Http\Exception\HttpResponseValidationException;

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
     * Path to where validation rule set .json-files reside.
     *
     * Relative path is relative to document root.
     *
     * @var string
     */
    const PATH_VALIDATION_RULE_SET = '../conf/json/http-response_validation-rule-sets';

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
        // bool; unexpected 204, 404 + JSON.
        'err_on_resource_not_found',
    ];


    // Public members.----------------------------------------------------------

    /**
     * @var \KkSeb\Http\HttpResponse
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
     * @var array {
     *      @var string $method  METHOD
     *      @var string $operation  [provider][server][endpoint][METHOD]
     *      @var string $appTitle  Localized application title.
     *      @var HttpLogger $httpLogger
     * }
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
     * @var array {
     *      @var int $ttl
     *      @var bool $anybody
     *      @var bool $refresh
     * }
     */
    protected $cacheable = [];


    // Helpers.-----------------------------------------------------------------

    /**
     * @var HttpLogger
     */
    protected $httpLogger;

    /**
     * @var \KkSeb\Cache\FileCache|null
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
     *      @var string $operation  [provider][server][endpoint][METHOD]
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
            $this->response = $this->evaluateResponse(new HttpResponse(500, [], new HttpResponseBody()));
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
                'id' => $properties['operation'] . 'user-',
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
                    return;
                }
            }
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
        $endpoint_path = $this->options['endpoint_path'];

        // Filter non-RestMini Client options off,
        // even option not supported at all.
        $client_options = array_intersect_assoc(
            $this->options,
            array_fill_keys(RestMiniClient::OPTIONS_SUPPORTED, true)
        );

        $client = new RestMiniClient($base_url, $endpoint_path, $client_options);

        // Set RestMini Client log type, for evaluateResponse().
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
            $this->response = $this->evaluateResponse(new HttpResponse(500, [], new HttpResponseBody()));
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
                $body = new HttpResponseBody();
                $body->success = true;
                $body->status = $status;
                $body->data = $data;
                // Do evaluate for:
                // - unexpected status
                // - require_response_headers
                // - err_on_endpoint_not_found
                // - err_on_resource_not_found
                $this->response = $this->evaluateResponse(
                    new HttpResponse(
                        $status,
                        !empty($this->options['get_headers']) ? $client->headers() : [],
                        $body
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
            }
            $body = new HttpResponseBody();
            $body->status = $status;
            // Despite possibly boolean false.
            $body->data = $data;
            $this->response = $this->evaluateResponse(
                new HttpResponse(
                    $status,
                    !empty($this->options['get_headers']) ? $client->headers() : [],
                    $body
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
            $this->response = $this->evaluateResponse(new HttpResponse(500, [], new HttpResponseBody()));
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
    protected function evaluateResponse(HttpResponse $response, array $error = [], array $info = []) : HttpResponse
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
                    $response->status = $body->status = 504;
                    $this->code = HttpClient::ERROR_CODES['timeout'];
                    break;
                case 'host_not_found':
                case 'connection_failed':
                    // 502 Bad Gateway.
                    // Perhaps upon retry (option: 'retry_on_unavailable').
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
                    $response->status = 502;
                    $this->code = HttpClient::ERROR_CODES['benign-status-unexpected'];
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

        return $response;
    }

    /**
     * @param array[] ...$records
     *      Possibly more validation records, if tried against more rule sets.
     */
    public function setValidationFailed(array ...$records)
    {
        // Log if simple validation failure, not some exception.
        if ($this->code == HttpClient::ERROR_CODES['response-validation']) {
            if ($records) {
                $recorded =
            }

            $this->httpLogger->log(
                LOG_ERR,
                'Http response failed validation',
                new HttpResponseValidationException(
                    'Response evaluates to HttpClient error[response-validation].',
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

            if ($records) {

            }
        }
    }

    /**
     * @todo: quite unfinished.
     *
     * Validate response against a predefined or passed validation rule set.
     *
     * Modifies the response if validation fails.
     *
     * Uses cached validation rule set, if empty args
     * ruleSet and ruleSetJsonPath.
     *
     * @param ValidationRuleSet|null $ruleSet
     * @param string $ruleSetJsonPath
     *      Path to directory containing JSON-formatted validation rule sets.
     *      Absolute or relative to document root.
     *      Filename will be
     *      'http[provider][server][endpoint][METHOD].validation-rule-set.json'.
     *
     * @return $this|HttpRequest
     */
    public function validate($ruleSet = null, string $ruleSetJsonPath = '') : HttpRequest
    {
        // Don't validate upon failure.
        if ($this->code) {
            return $this;
        }

        // @todo: don't support arg $ruleSetJsonPath.
        // @todo: do support arg array $variantNames, and allow that base name rule set doesn't exist at all.
        // @todo: if $variantNames, try all variants and stop on first success.

        $container = Dependency::container();
        $utils = Utils::getInstance();

        $failed = false;
        $code = 0;
        if ($ruleSet) {
            $rule_set = $ruleSet;
        } else {
            $rule_set = null;
            $filename = 'http' . $this->properties['operation'] . '.validation-rule-set.json';
            if ($ruleSetJsonPath) {
                try {
                    // Throws various exceptions.
                    $file = null;
                    $path = $utils->resolvePath($ruleSetJsonPath);
                    $file = $path . '/' . $filename;
                    // Throws Utils ParseJsonException and \RuntimeException.
                    $rule_set = $utils->parseJsonFile($file);
                } catch (\SimpleComplex\Utils\Exception\ParseJsonException $xcptn) {
                    $this->code = HttpClient::ERROR_CODES['local-configuration'];
                    $this->httpLogger->log(
                        LOG_ERR,
                        'Http validate response',
                        new \InvalidArgumentException(
                            'Arg ruleSetJsonPath[' . $ruleSetJsonPath . '] file[' . $file
                            . '] is not parsable, see previous.',
                            $this->code + HttpClient::ERROR_CODE_OFFSET,
                            $xcptn
                        )
                    );
                } catch (\Throwable $xcptn) {
                    $this->code = HttpClient::ERROR_CODES['local-use'];
                    $this->httpLogger->log(
                        LOG_ERR,
                        'Http validate response',
                        new \InvalidArgumentException(
                            'Arg ruleSetJsonPath[' . $ruleSetJsonPath . ']'
                            . (!$file ? ' is not a valid path.' :
                                (' file[' . $file . '] is non-existent or unreadable, see previous.')
                            ),
                            $this->code + HttpClient::ERROR_CODE_OFFSET,
                            $xcptn
                        )
                    );
                }
            }
            else {
                /** @var CacheBroker $cache_broker */
                $cache_broker = $container->get('cache-broker');
                /** @var \KkSeb\Cache\PersistentFileCache $cache_store */
                $rule_set_cache_store = $cache_broker->getStore(
                    'http-response_validation-rule-set',
                    CacheBroker::CACHE_PERSISTENT
                );
                unset($cache_broker);
                $rule_set = $rule_set_cache_store->get($this->properties['operation']);
                if (!$rule_set) {
                    // Retrieve JSON file from validation rule set path.
                    $path = $file = '';
                    try {
                        // Throws various exceptions.
                        $path = $utils->resolvePath(static::PATH_VALIDATION_RULE_SET);
                        // Throws \InvalidArgumentException.
                        $files = (new PathFileList($path, 'validation-rule-set.json'))->getArrayCopy();
                        foreach ($files as $path_file) {
                            if (strpos($path_file, '/' . $filename)) {
                                $file = $path_file;
                                break;
                            }
                        }
                        if (!$file) {
                            $this->code = HttpClient::ERROR_CODES['local-configuration'];
                            $this->httpLogger->log(
                                LOG_ERR,
                                'Http validate response',
                                new HttpConfigurationException(
                                    get_class($this) . '::PATH_VALIDATION_RULE_SET path['
                                    . static::PATH_VALIDATION_RULE_SET . '] has no file name[' . $filename
                                    . '] as child or descendant.',
                                    $this->code + HttpClient::ERROR_CODE_OFFSET
                                )
                            );
                        } else {
                            // Throws Utils ParseJsonException and \RuntimeException.
                            $rule_set = $utils->parseJsonFile($file);
                        }
                    } catch (\SimpleComplex\Utils\Exception\ParseJsonException $xcptn) {
                        $this->code = HttpClient::ERROR_CODES['local-configuration'];
                        $this->httpLogger->log(
                            LOG_ERR,
                            'Http validate response',
                            new HttpConfigurationException(
                                'File[' . $file . '] is not parsable, see previous.',
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
                                    'File[' . $file . '] is non-existent or unreadable, see previous.',
                                    $this->code + HttpClient::ERROR_CODE_OFFSET,
                                    $xcptn
                                )
                            );
                        }
                    }
                    if (!$this->code && $rule_set) {
                        $validator = $container->has('validator') ? $container->get('validator') : new Validate();

                    }


                    // @todo: using PathFileList.
                    /**
                     * @see PathFileList
                     */
                    // @todo: and the cache the rule set.
                }
            }
        }


        return $this;
    }
}
