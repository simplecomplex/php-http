<?php
/**
 * KIT/Koncernservice, Københavns Kommune.
 * @link https://kkgit.kk.dk/php-psr.kk-base/http
 * @author Jacob Friis Mathiasen <jacob.friis.mathiasen@ks.kk.dk>
 */
declare(strict_types=1);

namespace KkBase\Http;

use SimpleComplex\Utils\Explorable;
use SimpleComplex\Utils\Utils;
use SimpleComplex\Utils\Dependency;
use SimpleComplex\RestMini\Client as RestMiniClient;
use KkBase\Http\Exception\HttpConfigurationException;

/**
 * Methods of HttpClient and HttpRequest throw no exceptions.
 * Instead use the 'success' attribute of request()'s returned HttpResponse's
 * HttpResponseBody.
 * And use that HttpResponseBody's 'message' as safe and user-friendly error
 * message to user.
 *
 * @see \KkBase\Http\HttpResponse::status
 * @see \KkBase\Http\HttpResponseBody::success
 * @see \KkBase\Http\HttpResponseBody::message
 *
 * @uses-dependency-container config
 *
 * @property-read string $provider
 * @property-read string $service
 * @property-read \Throwable|null $initError
 *
 * @package KkBase\Http
 */
class HttpClient extends Explorable
{
    // Explorable.--------------------------------------------------------------

    /**
     * @var array
     */
    protected $explorableIndex = [
        'provider',
        'service',
        'initError',
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
            if ($name == 'initError' && $this->initError) {
                // Nobody must tamper with this exception.
                return clone $this->initError;
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


    // Reusable instances.------------------------------------------------------

    /**
     * Reference to first object instantiated via the getInstance() method,
     * using specific class and constructor args.
     *
     * @var array
     */
    protected static $instanceByClassAndArgs = [];

    /**
     * First object instantiated via this method, using that class
     * and same args provider and service.
     *
     * @param string $provider
     * @param string $service
     *
     * @return HttpClient|static
     *
     * @throws \Throwable
     *      Propagated; see constructor.
     */
    public static function getInstance(string $provider, string $service)
    {
        $id = static::class . '|' . $provider . '.' . $service;
        if (isset(static::$instanceByClassAndArgs[$id])) {
            return static::$instanceByClassAndArgs[$id];
        }
        // Don't store failed init instance, would result in dupe exceptions;
        // wrong stack/trace for later uses.
        if (!($instance = new static($provider, $service))->initError) {
            static::$instanceByClassAndArgs[$id] = $instance;
        }
        return $instance;
    }


    // Business.----------------------------------------------------------------

    /**
     * @var string
     */
    const LOG_TYPE = 'http-client';

    /**
     * Default cacheable time-to-live.
     *
     * @var int
     */
    const CACHEABLE_TIME_TO_LIVE = 3600;

    /**
     * Settings/options required, and their (scalar) types.
     *
     * If type is string then value can't be empty string.
     *
     * @see Utils::getType()
     *
     * @var string[]
     */
    const OPTIONS_REQUIRED = [
        'base_url' => 'string',
        'endpoint_path' => 'string',
    ];

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
     * NB: cache is not per page/form, like Drupal
     * kk_seb_service_client.
     * Would require that requestor sent a X-Kk-Base-Page-Load-Id
     * header, based on an (backend cached) ID originally issued
     * by a local service; called by Angular root app ngOnit().
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
     * See also required options, which may not be a subset of this list.
     * @see HttpClient::OPTIONS_REQUIRED
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
        // arr; log erroneous response as warning (not error).
        // Key status is string (not integer), value is boolean.
        'log_warning_on_status',
    ];

    /**
     * Range is this +99.
     *
     * @var int
     */
    const ERROR_CODE_OFFSET = 1900;

    /**
     * Actual numeric values may be affected by non-zero ERROR_CODE_OFFSET
     * of classes extending Client.
     *
     * @see Client::ERROR_CODE_OFFSET
     *
     * @var array
     */
    const ERROR_CODES = [
        'unknown' => 1,

        'local-unknown' => 10,
        'local-algo' => 11,
        'local-use' => 12,
        'local-configuration' => 13,
        'local-option' => 14,
        'local-init' => 15,

        'host-unavailable' => 20,
        'service-unavailable' => 21,
        'too-many-redirects' => 23,
        // cURL 504.
        'timeout' => 30,
        // Status 504.
        'timeout-propagated' => 31,
        // cURL 500 (RestMini Client 'response-false').
        'response-none' => 40,
        // Status 500.
        'remote' => 50,
        // Remote says 502 Bad Gateway.
        'remote-propagated' => 51,
        // Unsupported 5xx status.
        'malign-status-unexpected' => 59,

        // 404 + Content-Type not JSON (probably HTML); no such endpoint.
        'endpoint-not-found' => 60,
        // Unexpected 204, 404 + Content-Type JSON; no such resource (object).
        'resource-not-found' => 61,

        // 400 Bad Request, 412 Precondition Failed, 422 Unprocessable Entity.
        'remote-validation-bad' => 70,
        'remote-validation-failed' => 71,
        // Content type mismatch.
        'response-type' => 81,
        // Parse error.
        'response-format' => 82,
        // Unsupported non-5xx status.
        'benign-status-unexpected' => 89,

        'header-missing' => 90,
        'response-validation' => 95,
    ];

    /**
     * @var string
     */
    protected $provider;

    /**
     * @var string
     */
    protected $service;

    /**
     * @var \Throwable|null
     */
    protected $initError;

    /**
     * Provider and service settings merged.
     *
     * @var array
     */
    protected $settings = [];

    /**
     * @var \KkBase\Base\Config\IniSectionedConfig
     */
    protected $config;

    /**
     * @var HttpLogger
     */
    protected $httpLogger;

    /**
     * HTTP client, configured for requesting any endpoint+method of a service.
     *
     * Reusable.
     *
     * @code
     * $client = new HttpClient('provider', 'service', 'Some app');
     * $request = $client->request('endpoint', 'METHOD', [
     *         'path' => ['path',],
     *         'query' => ['what' => 'ever',],
     *         'body' => null
     *     ], [
     *         // HttpClient option.
     *         'err_on_resource_not_found' => true,
     *         // Underlying RestMini client option.
     *         'request_timeout' => 60,
     *     ]);
     * $response = $request->response;
     * @endcode
     *
     * @param string $provider
     *      Name [a-zA-Z][a-zA-Z\d_\-]*
     * @param string $service
     *      Name [a-zA-Z][a-zA-Z\d_\-]*
     *
     * @uses HttpConfigurationException
     *      Un-configured provider or service.
     * @throws \InvalidArgumentException
     *      If arg provider or server contains dot.
     * @throws \Throwable
     *      Propagated; unlikely errors (dependency injection container, config)
     *      normally detected prior to creating a HttpClient.
     */
    public function __construct(string $provider, string $service)
    {
        if (strpos($provider, '.') !== false) {
            throw new \InvalidArgumentException(
                'Arg provider name is invalid.',
                static::ERROR_CODES['local-use'] + static::ERROR_CODE_OFFSET
            );
        }
        $this->provider = $provider;

        if (strpos($service, '.') !== false) {
            throw new \InvalidArgumentException(
                'Arg service name is invalid.',
                static::ERROR_CODES['local-use'] + static::ERROR_CODE_OFFSET
            );
        }
        $this->service = $service;

        $container = Dependency::container();
        /** @var \KkBase\Base\Config\IniSectionedConfig $config */
        $this->config = $container->get('config');

        // Config section: http-provider_kki.
        if (!($conf_provider = $this->config->get('http-provider.' . $provider, '*'))) {
            $this->initError = new HttpConfigurationException(
                'HttpClient abort, constructor arg provider[' . $provider . '] global config section['
                . 'http-provider.' . $provider . '] is not configured.',
                static::ERROR_CODES['local-configuration'] + static::ERROR_CODE_OFFSET
            );
        } elseif (!empty($conf_provider['cacheable'])) {
            $this->initError = new HttpConfigurationException(
                'HttpClient abort, truthy config setting \'cacheable\' type['
                . Utils::getType($conf_provider['cacheable']) . '] is only allowed on method level (or as option).',
                static::ERROR_CODES['local-configuration'] + static::ERROR_CODE_OFFSET
            );
        }
        // Config section: http-service_kki_seb-personale.
        elseif (!($conf_service = $this->config->get('http-service.' . $provider . '.' . $service, '*'))) {
            $this->initError = new HttpConfigurationException(
                'HttpClient abort, constructor arg service[' . $provider . '] global config section['
                . 'http-service.' . $provider . '.' . $service . '] is not configured.',
                static::ERROR_CODES['local-configuration'] + static::ERROR_CODE_OFFSET
            );
        } elseif (!empty($conf_service['cacheable'])) {
            $this->initError = new HttpConfigurationException(
                'HttpClient abort, truthy config setting \'cacheable\' type['
                . Utils::getType($conf_service['cacheable']) . '] is only allowed on method level (or as option).',
                static::ERROR_CODES['local-configuration'] + static::ERROR_CODE_OFFSET
            );
        } else {
            // A-OK, so far.
            $this->settings = array_replace_recursive($conf_provider, $conf_service);
        }
    }

    /**
     * Attempt to send HTTP request, response will be a property
     * of the returned request object.
     *
     * @param string $endpoint
     *      Name [a-zA-Z][a-zA-Z\d_\-]*
     * @param string $methodOrAlias
     *      HEAD|GET|POST|PUT|DELETE or alias index|retrieve (GET),
     *      create (POST), update (PUT), delete (DELETE).
     * @param array $arguments {
     *      @var array $path  Optional.
     *      @var array $query  Optional.
     *      @var mixed $body  Optional.
     * }
     *      Numerically indexed is also supported.
     * @param array $options
     *
     * @return \KkBase\Http\HttpRequest
     *
     * @uses HttpConfigurationException
     *      Un-configured endpoint or method.
     * @uses \InvalidArgumentException
     *      Arg method not supported.
     *      Parameters are neither nested in numerically indexed buckets
     *      nor path|query|body bucket(s).
     * @throws \InvalidArgumentException
     *      If arg provider or server contains dot.
     * @throws \Throwable
     *      Propagated; unlikely errors (dependency injection container, config)
     *      normally detected prior to creating a HttpClient.
     */
    public function request(string $endpoint, string $methodOrAlias, array $arguments, array $options = []) : HttpRequest
    {
        if (strpos($endpoint, '.') !== false) {
            throw new \InvalidArgumentException(
                'Arg endpoint name is invalid.',
                static::ERROR_CODES['local-use'] + static::ERROR_CODE_OFFSET
            );
        }

        $operation = $this->provider . '.' . $this->service . '.' . $endpoint . '.' . $methodOrAlias;

        $this->httpLogger = new HttpLogger(static::LOG_TYPE, $operation);
        $properties = [
            'method' => $methodOrAlias,
            'operation' => $operation,
            'httpLogger' => $this->httpLogger,
        ];

        // Erred in constructor.
        if ($this->initError) {
            $this->httpLogger->log(LOG_ERR, 'Http init', $this->initError);
            return new HttpRequest($properties, [], [], $this->initError->getCode() - static::ERROR_CODE_OFFSET);
        }

        // Support HTTP method aliases.
        switch ($methodOrAlias) {
            case 'index':
            case 'retrieve':
                $properties['method'] = 'GET';
                break;
            case 'create':
                $properties['method'] = 'POST';
                break;
            case 'update':
                $properties['method'] = 'PUT';
                break;
            case 'delete':
                $properties['method'] = 'DELETE';
                break;
            default:
                // HTTP methods supported.
                if (!in_array($methodOrAlias, RestMiniClient::METHODS_SUPPORTED, true)) {
                    $code = static::ERROR_CODES['local-use'];
                    $this->httpLogger->log(LOG_ERR, 'Http init', new \InvalidArgumentException(
                        'Client abort, request() arg method[' . $methodOrAlias . '] is not among supported methods '
                        . join('|', RestMiniClient::METHODS_SUPPORTED) . '.',
                        $code + static::ERROR_CODE_OFFSET
                    ));
                    return new HttpRequest($properties, [], [], $code);
                }
        }

        // Config section: http-service_kki_seb-personale_cpr.
        if (!($conf_endpoint = $this->config->get(
            'http-endpoint.' . $this->provider . '.' . $this->service . '.' . $endpoint, '*')
        )) {
            $code = static::ERROR_CODES['local-configuration'];
            $this->httpLogger->log(LOG_ERR, 'Http init', new HttpConfigurationException(
                'Client abort, request() arg endpoint[' . $endpoint . '] global config section['
                . 'http-endpoint.' . $this->provider . '.' . $this->service . '.' . $endpoint . '] is not configured.',
                $code + static::ERROR_CODE_OFFSET
            ));
            return new HttpRequest($properties, [], [], $code);
        } elseif (!empty($conf_endpoint['cacheable'])) {
            $code = static::ERROR_CODES['local-configuration'];
            $this->httpLogger->log(LOG_ERR, 'Http init', new HttpConfigurationException(
                'Client abort, truthy config setting \'cacheable\' type['
                . Utils::getType($conf_endpoint['cacheable']) . '] is only allowed on method level (or as option).',
                static::ERROR_CODES['local-configuration'],
                $code + static::ERROR_CODE_OFFSET
            ));
        }
        // Config section: http-service_kki_seb-personale_cpr_GET.
        // Method configuration is allowed to empty array.
        if (($conf_method = $this->config->get('http-method.' . $operation, '*')) === null) {
            $code = static::ERROR_CODES['local-configuration'];
            $this->httpLogger->log(LOG_ERR, 'Http init', new HttpConfigurationException(
                'Client abort, request() arg endpoint[' . $endpoint . '] global config section['
                . 'http-method.' . $operation . '] is not configured.',
                $code + static::ERROR_CODE_OFFSET
            ));
            return new HttpRequest($properties, [], [], $code);
        }

        // Check that arguments by type are nested and have valid keys.
        if ($arguments) {
            $args_invalid = $args_indexed = false;
            $keys = array_keys($arguments);
            if (count($keys) > 3) {
                $args_invalid = true;
            }
            $keys_stringed = join('', $keys);
            if ($keys_stringed === '') {
                $args_invalid = true;
            } elseif (ctype_digit($keys_stringed)) {
                $args_indexed = true;
                switch ($keys_stringed) {
                    case '0':
                        $arguments['path'] = $arguments[0];
                        unset($arguments[0]);
                        break;
                    case '01':
                        $arguments['path'] = $arguments[0];
                        $arguments['query'] = $arguments[1];
                        unset($arguments[0], $arguments[1]);
                        break;
                    case '012':
                        $arguments['path'] = $arguments[0];
                        $arguments['query'] = $arguments[1];
                        $arguments['body'] = $arguments[2];
                        unset($arguments[0], $arguments[1], $arguments[2]);
                        break;
                    default:
                        $args_invalid = true;
                }
            }
            if (!$args_invalid && !$args_indexed && ($diff = array_diff($keys, ['path', 'query', 'body']))) {
                $args_invalid = true;
            }
            // Don't check that path and query args are array; rely on native
            // strict argument type check by RestMini Client request() method.
            if ($args_invalid) {
                $code = static::ERROR_CODES['local-use'];
                $this->httpLogger->log(LOG_ERR, 'Http init', new \InvalidArgumentException(
                    'Client abort, request() arg arguments keys[' . join(', ', $keys)
                    . '] don\'t match valid numerically indexed keys or associative keys[path, query, body],'
                    . ' perhaps forgot to nest arguments.',
                    $code + static::ERROR_CODE_OFFSET
                ));
                return new HttpRequest($properties, [], [], $code);
            }
            unset($args_invalid, $args_indexed, $keys, $keys_stringed);
        }

        $options = array_replace_recursive($this->settings, $conf_endpoint, $conf_method, $options);

        if (!empty($options['log_type'])) {
            $this->httpLogger->type = $options['log_type'];
        }

        // Response mocking should be turned off globally in production.
        if ($this->config->get('http', 'response-mocking-disabled')) {
            unset($options['mock_response']);
        }

        // Secure that required options are available.
        foreach (static::OPTIONS_REQUIRED as $name => $type) {
            if (
                !isset($options[$name]) || Utils::getType($options[$name]) != $type
                || ($type == 'string' && $options[$name] === '')
            ) {
                if (!isset($options[$name])) {
                    $msg = 'is missing';
                } elseif (Utils::getType($options[$name]) != $type) {
                    $msg = 'type[' . Utils::getType($options[$name]) . '] is not ' . $type;
                } else {
                    $msg = 'is empty';
                }
                $code = static::ERROR_CODES['local-configuration'];
                $this->httpLogger->log(
                    LOG_ERR,
                    'Http init',
                    new HttpConfigurationException(
                        'Client abort, settings+options \'' . $name . '\' ' . $msg . '.',
                        $code + static::ERROR_CODE_OFFSET
                    ),
                    [
                        'settings+options' => $options,
                    ]
                );
                return new HttpRequest($properties, [], [], $code);
            }
        }

        return new HttpRequest($properties, $options, $arguments);
    }

    /**
     * HTTP methods supported relies solely on the underlying HTTP client,
     * but that shan't be exposed; to prevent lock-in.
     *
     * @return string[]
     */
    public static function methodsSupported() : array
    {
        return RestMiniClient::METHODS_SUPPORTED;
    }

    /**
     * Get error code by name, or code list, or code range.
     *
     * @param string $name
     *      Non-empty: return code by name (defaults to 'unknown')
     *      Default: empty (~ return codes list).
     * @param bool $range
     *      true: return code range [(N-first, N-last].
     *      Default: false (~ ignore argument).
     *
     * @return int|array
     */
    public static function errorCode($name = '', $range = false)
    {
        static $codes;
        if ($name) {
            return static::ERROR_CODE_OFFSET
                + (array_key_exists($name, static::ERROR_CODES) ? static::ERROR_CODES[$name] :
                    static::ERROR_CODES['unknown']
                );
        }
        if ($range) {
            return [
                static::ERROR_CODE_OFFSET,
                static::ERROR_CODE_OFFSET + 999
            ];
        }
        if (!$codes) {
            // Copy.
            $codes = static::ERROR_CODES;
            if (($offset = static::ERROR_CODE_OFFSET)) {
                foreach ($codes as &$code) {
                    $code += $offset;
                }
                // Iteration ref.
                unset($code);
            }
        }
        return $codes;
    }
}
