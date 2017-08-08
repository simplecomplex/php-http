<?php
/**
 * KIT/Koncernservice, Københavns Kommune.
 * @link https://kkgit.kk.dk/php-psr.kk-seb/http
 */
declare(strict_types=1);

namespace KkSeb\Http;

use SimpleComplex\Utils\Utils;
use SimpleComplex\Utils\Dependency;
use SimpleComplex\Utils\Exception\ConfigurationException;
use SimpleComplex\RestMini\Client as RestMiniClient;

/**
 * Class HttpClient
 *
 * @package KkSeb\Http
 */
class HttpClient
{
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
     * Range is this +999.
     *
     * @var int
     */
    const ERROR_CODE_OFFSET = 2000;

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
     * @var \KkSeb\Config\IniSectionedConfig
     */
    protected $config;

    /**
     * @var \Throwable|null
     */
    protected $exception;

    /**
     * HTTP client, configured for requesting any endpoint+method
     * of a service.
     *
     * @param string $provider
     *      Lispcased, some-provider.
     * @param string $service
     *      Lispcased, some-service.
     * @param string $appTitle
     *      Default: localeText http:app-title.
     *
     * @uses ConfigurationException
     *      Un-configured provider or service.
     * @throws \Throwable
     *      Propagated; unlikely errors normally detected earlier.
     */
    public function __construct(string $provider, string $service, string $appTitle = '')
    {
        // Do not throw exception here; request() must return
        // HttpRequest->aborted instead.

        $this->provider = $provider;
        $this->service = $service;

        $container = Dependency::container();
        /** @var \KkSeb\Config\IniSectionedConfig $config */
        $this->config = $container->get('config');

        // Config section: http-provider_kki.
        if (!($conf_provider = $this->config->get('http-provider_' . $provider, '*'))) {
            static::logException($this->exception = new ConfigurationException(
                'HttpClient abort, constructor arg provider[' . $provider . '] global config section['
                . 'http-provider_' . $provider . '] is not configured.',
                7913 // @todo
            ));
        }
        // Config section: http-service_kki_seb-personale.
        elseif (!($conf_service = $this->config->get('http-service_' . $provider . '_' . $service, '*'))) {
            static::logException($this->exception = new ConfigurationException(
                'HttpClient abort, constructor arg service[' . $provider . '] global config section['
                . 'http-service_' . $provider . '_' . $service . '] is not configured.',
                7913 // @todo
            ));
        } else {
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
     *      Lisp-cased, some-endpoint.
     * @param string $method
     *      HEAD|GET|POST|PUT|DELETE.
     * @param array $parameters {
     *      @var array $path  Optional.
     *      @var array $query  Optional.
     *      @var array|object|string $body  Optional.
     * }
     * @param array $options
     *
     * @return HttpRequest
     *
     * @uses ConfigurationException
     *      Un-configured endpoint or method.
     * @uses \InvalidArgumentException
     *      Arg method not supported.
     *      Parameters are not nested in path|query|body bucket(s).
     * @throws \Throwable
     *      Propagated; unlikely errors normally detected earlier.
     */
    public function request(string $endpoint, string $method, array $parameters, array $options = []) : HttpRequest
    {
        // Do not throw exception here; return HttpRequest->aborted instead.

        $properties = [
            'appTitle' => $this->appTitle,
            'provider' => $this->provider,
            'service' => $this->service,
            'endpoint' => $endpoint,
            'method' => $method,
        ];
        // Erred in constructor.
        if ($this->exception) {
            return (new HttpRequest($properties, [], []))->aborted($this->exception->getCode());
        }
        // HTTP method supported.
        if (!in_array($method, RestMiniClient::METHODS_SUPPORTED, true)) {
            static::logException($this->exception = new \InvalidArgumentException(
                'HttpClient abort, request() arg method[' . $method . '] is not among supported methods '
                . join('|', RestMiniClient::METHODS_SUPPORTED) . '.',
                7913 // @todo
            ));
            return (new HttpRequest($properties, [], []))->aborted($this->exception->getCode());
        }
        // Config section: http-service_kki_seb-personale_cpr.
        if (!($conf_endpoint = $this->config->get(
            'http-service_' . $this->provider . '_' . $this->service . '_' . $endpoint, '*')
        )) {
            static::logException($this->exception = new ConfigurationException(
                'HttpClient abort, request() arg endpoint[' . $endpoint . '] global config section['
                . 'http-service_' . $this->provider . '_' . $this->service . '_' . $endpoint . '] is not configured.',
                7913 // @todo
            ));
            return (new HttpRequest($properties, [], []))->aborted($this->exception->getCode());
        }
        // Config section: http-service_kki_seb-personale_cpr_GET.
        if (!($conf_method = $this->config->get(
            'http-service_' . $this->provider . '_' . $this->service . '_' . $endpoint . '_' . $method, '*')
        )) {
            static::logException($this->exception = new ConfigurationException(
                'HttpClient abort, request() arg endpoint[' . $endpoint . '] global config section['
                . 'http-service_' . $this->provider . '_' . $this->service . '_' . $endpoint . '_' . $method
                . '] is not configured.',
                7913 // @todo
            ));
            return (new HttpRequest($properties, [], []))->aborted($this->exception->getCode());
        }
        // Check that parameters by type are nested.
        if ($parameters) {
            $keys = array_keys($parameters);
            if (($diff = array_diff($keys, ['path', 'query', 'body']))) {
                static::logException($this->exception = new \InvalidArgumentException(
                    'HttpClient abort, request() arg parameters keys[' . join(', ', $keys)
                    . '] exceed valid keys[path, query, body], perhaps forgot to nest parameters.',
                    7913 // @todo
                ));
                return (new HttpRequest($properties, [], []))->aborted($this->exception->getCode());
            }
        }

        $options = array_replace_recursive($this->settings, $conf_endpoint, $conf_method, $options);
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
                static::logException(
                    $this->exception = new ConfigurationException(
                        'HttpClient abort, settings+options \'' . $name . '\' ' . $msg . '.',
                        7913 // @todo
                    ),
                    // Dump settings/options.
                    $options
                );
                return (new HttpRequest($properties, [], []))->aborted($this->exception->getCode());
            }
        }

        return new HttpRequest($properties, $options, $parameters);
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

    /**
     * Logs exception + trace.
     *
     * @param \Throwable $xcptn
     * @param array $variables
     *
     * @return void
     */
    public static function logException(\Throwable $xcptn, array $variables = []) /*: void*/
    {
        $code = $xcptn->getCode();
        $container = Dependency::container();
        /** @var \SimpleComplex\Inspect\Inspect $inspect */
        $inspect = $container->get('inspector');
        $container->get('logger')->error(
            get_class($xcptn) . '(' . $code . '): ' . $xcptn->getMessage()
            . "\n" . $inspect->trace($xcptn)
            . ($variables ? '' : ("\n" . $inspect->variable($variables))),
            [
                'code' => $code,
                'exception' => $xcptn,
            ]
        );
    }
}
