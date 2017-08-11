<?php
/**
 * KIT/Koncernservice, Københavns Kommune.
 * @link https://kkgit.kk.dk/php-psr.kk-seb/http
 */
declare(strict_types=1);

namespace KkSeb\Http;

use SimpleComplex\Utils\Utils;
use SimpleComplex\Utils\Dependency;
use SimpleComplex\RestMini\Client as RestMiniClient;
use KkSeb\Http\Exception\HttpConfigurationException;

/**
 * Methods of HttpClient and HttpRequest throw no exceptions.
 * Instead use the 'success' attribute of request()'s returned HttpResponse's
 * HttpResponseBody.
 * And use that HttpResponseBody's 'message' as safe and user-friendly error
 * message to user.
 *
 * @see \KkSeb\Http\HttpResponse::status
 * @see \KkSeb\Http\HttpResponseBody::success
 * @see \KkSeb\Http\HttpResponseBody::message
 *
 * @package KkSeb\Http
 */
class HttpClient
{
    /**
     * @var string
     */
    const LOG_TYPE = 'http-client';

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

        // 400 Bad Request, 412 Precondition Failed.
        'remote-validation-bad' => 70,
        'remote-validation-failed' => 71,
        // Content type mismatch.
        'response-type' => 81,
        // Parse error.
        'response-format' => 82,
        // Unsupported non-5xx status.
        'benign-status-unexpected' => 89,

        'response-validation' => 90,
    ];

    /**
     * @var string
     */
    public $provider;

    /**
     * @var string
     */
    public $service;

    /**
     * Provider and service settings merged.
     *
     * @var array
     */
    public $settings = [];

    /**
     * Localized named of requesting application, used for user error messages.
     *
     * @var string
     */
    public $appTitle;

    /**
     * @var string
     */
    public $operation;

    /**
     * @var \KkSeb\Config\IniSectionedConfig
     */
    protected $config;

    /**
     * @var HttpLogger
     */
    protected $httpLogger;

    /**
     * @var \Throwable|null
     */
    protected $initError;

    /**
     * HTTP client, configured for requesting any endpoint+method
     * of a service.
     *
     * @code
     * $client = new HttpClient('provider', 'service', 'Some app');
     * $request = (new HttpClient('provider', 'service', 'Some app'))
     *     ->request('endpoint', 'METHOD', [
     *         'path' => ['path',],
     *         'query' => ['what' => 'ever',],
     *         'body' => ['what' => 'ever',]
     *     ], [
     *         // HttpClient option.
     *         'err_on_resource_not_found' => true,
     *         // Underlying RestMini client option.
     *         'request_timeout' => 60,
     *     ]);
     * $response = $request->response;
     * $validated_response = $request->validateResponse();
     * @endcode
     *
     * @param string $provider
     *      Name [a-zA-Z][a-zA-Z\d_\-]*
     * @param string $service
     *      Name [a-zA-Z][a-zA-Z\d_\-]*
     * @param string $appTitle
     *      Default: localeText http:app-title.
     *
     * @uses HttpConfigurationException
     *      Un-configured provider or service.
     * @throws \Throwable
     *      Propagated; unlikely errors (dependency injection container, config)
     *      normally detected prior to creating a HttpClient.
     */
    public function __construct(string $provider, string $service, string $appTitle = '')
    {
        $this->provider = $provider;
        $this->service = $service;

        $this->operation = '[' . $provider . '][' . $service . ']';

        $container = Dependency::container();
        /** @var \KkSeb\Config\IniSectionedConfig $config */
        $this->config = $container->get('config');

        // Config section: http-provider_kki.
        if (!($conf_provider = $this->config->get('http-provider_' . $provider, '*'))) {
            $this->initError = new HttpConfigurationException(
                'HttpClient abort, constructor arg provider[' . $provider . '] global config section['
                . 'http-provider_' . $provider . '] is not configured.',
                static::ERROR_CODES['local-configuration']
            );
        } elseif (!empty($conf_provider['cacheable'])) {
            $this->initError = new HttpConfigurationException(
                'HttpClient abort, truthy config setting \'cacheable\' type['
                . Utils::getType($conf_provider['cacheable']) . '] is illegal on provider level.',
                static::ERROR_CODES['local-configuration']
            );
        }
        // Config section: http-service_kki_seb-personale.
        elseif (!($conf_service = $this->config->get('http-service_' . $provider . '_' . $service, '*'))) {
            $this->initError = new HttpConfigurationException(
                'HttpClient abort, constructor arg service[' . $provider . '] global config section['
                . 'http-service_' . $provider . '_' . $service . '] is not configured.',
                static::ERROR_CODES['local-configuration']
            );
        } elseif (!empty($conf_service['cacheable'])) {
            $this->initError = new HttpConfigurationException(
                'HttpClient abort, truthy config setting \'cacheable\' type['
                . Utils::getType($conf_service['cacheable']) . '] is illegal on service level.',
                static::ERROR_CODES['local-configuration']
            );
        } else {
            // A-OK, so far.
            $this->settings = array_replace_recursive($conf_provider, $conf_service);
        }

        if (!$appTitle) {
            /** @var \SimpleComplex\Locale\AbstractLocale $locale */
            $locale = Dependency::container()->get('locale');
            $appTitle = $locale->text('http:app-title');
        }
        $this->appTitle = $appTitle;
    }

    /**
     * @param string $endpoint
     *      Name [a-zA-Z][a-zA-Z\d_\-]*
     * @param string $method
     *      HEAD|GET|POST|PUT|DELETE.
     * @param array $arguments {
     *      @var array $path  Optional.
     *      @var array $query  Optional.
     *      @var mixed $body  Optional.
     * }
     * @param array $options
     *
     * @return \KkSeb\Http\HttpResponse
     *
     * @uses HttpConfigurationException
     *      Un-configured endpoint or method.
     * @uses \InvalidArgumentException
     *      Arg method not supported.
     *      Parameters are not nested in path|query|body bucket(s).
     * @throws \Throwable
     *      Propagated; unlikely errors (dependency injection container, config)
     *      normally detected prior to creating a HttpClient.
     */
    public function request(string $endpoint, string $method, array $arguments, array $options = []) : HttpResponse
    {
        $this->operation .= '[' . $endpoint . '][' . $method . ']';
        $this->httpLogger = new HttpLogger(static::LOG_TYPE, $this->operation);
        $properties = [
            'operation' => $this->operation,
            'appTitle' => $this->appTitle,
            'httpLogger' => $this->httpLogger,
        ];

        // Erred in constructor.
        if ($this->initError) {
            $this->httpLogger->log(LOG_ERR, 'Http init', $this->initError);
            return (new HttpRequest($properties, [], [], $this->initError->getCode()))->response;
        }

        // HTTP method supported.
        if (!in_array($method, RestMiniClient::METHODS_SUPPORTED, true)) {
            $code = static::ERROR_CODES['local-use'];
            $this->httpLogger->log(LOG_ERR, 'Http init', new \InvalidArgumentException(
                'Client abort, request() arg method[' . $method . '] is not among supported methods '
                . join('|', RestMiniClient::METHODS_SUPPORTED) . '.',
                $code
            ));
            return (new HttpRequest($properties, [], [], $code))->response;
        }

        // Config section: http-service_kki_seb-personale_cpr.
        if (!($conf_endpoint = $this->config->get(
            'http-service_' . $this->provider . '_' . $this->service . '_' . $endpoint, '*')
        )) {
            $code = static::ERROR_CODES['local-configuration'];
            $this->httpLogger->log(LOG_ERR, 'Http init', new HttpConfigurationException(
                'Client abort, request() arg endpoint[' . $endpoint . '] global config section['
                . 'http-service_' . $this->provider . '_' . $this->service . '_' . $endpoint . '] is not configured.',
                $code
            ));
            return (new HttpRequest($properties, [], [], $code))->response;
        }
        // Config section: http-service_kki_seb-personale_cpr_GET.
        if (!($conf_method = $this->config->get(
            'http-service_' . $this->provider . '_' . $this->service . '_' . $endpoint . '_' . $method, '*')
        )) {
            $code = static::ERROR_CODES['local-configuration'];
            $this->httpLogger->log(LOG_ERR, 'Http init', new HttpConfigurationException(
                'Client abort, request() arg endpoint[' . $endpoint . '] global config section['
                . 'http-service_' . $this->provider . '_' . $this->service . '_' . $endpoint . '_' . $method
                . '] is not configured.',
                $code
            ));
            return (new HttpRequest($properties, [], [], $code))->response;
        }

        // Check that arguments by type are nested.
        if ($arguments) {
            $keys = array_keys($arguments);
            if (($diff = array_diff($keys, ['path', 'query', 'body']))) {
                $code = static::ERROR_CODES['local-use'];
                $this->httpLogger->log(LOG_ERR, 'Http init', new \InvalidArgumentException(
                    'Client abort, request() arg arguments keys[' . join(', ', $keys)
                    . '] don\'t match valid keys[path, query, body], perhaps forgot to nest arguments.',
                    $code
                ));
                return (new HttpRequest($properties, [], [], $code))->response;
            }
            // Don't check that path and query args are array; rely on native
            // strict argument type check by RestMini Client request() method.
        }

        $options = array_replace_recursive($this->settings, $conf_endpoint, $conf_method, $options);

        if (!empty($options['log_type'])) {
            $this->httpLogger->type = $options['log_type'];
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
                        $code
                    ),
                    [
                        'settings+options' => $options,
                    ]
                );
                return (new HttpRequest($properties, [], [], $code))->response;
            }
        }

        return (new HttpRequest($properties, $options, $arguments))->response;
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
