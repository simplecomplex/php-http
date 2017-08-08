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

/**
 * Class HttpClient
 *
 * @package KkSeb\Http
 */
class HttpClient
{
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
        // Do not throw exception here; request() must return HttpAbortedRequest
        // instead.

        $this->provider = $provider;
        $this->service = $service;

        $container = Dependency::container();
        /** @var \KkSeb\Config\IniSectionedConfig $config */
        $this->config = $container->get('config');

        // Config section: http-provider_kki.
        if (!($conf_provider = $this->config->get('http-provider_' . $provider, '*'))) {
            $this->logException($this->exception = new ConfigurationException(
                'Arg provider[' . $provider . '] global config section['
                . 'http-provider_' . $provider . '] is not configured.',
                7913 // @todo
            ));
        }
        // Config section: http-service_kki_seb-personale.
        elseif (!($conf_service = $this->config->get('http-service_' . $provider . '_' . $service, '*'))) {
            $this->logException($this->exception = new ConfigurationException(
                'Arg service[' . $provider . '] global config section['
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
     * @return HttpRequest|HttpAbortedRequest
     *
     * @uses ConfigurationException
     *      Un-configured endpoint or method.
     * @uses \InvalidArgumentException
     *      Arg method not supported.
     *      Parameters are not nested in path|query|body bucket(s).
     * @throws \Throwable
     *      Propagated; unlikely errors normally detected earlier.
     */
    public function request(string $endpoint, string $method, array $parameters, array $options = [])
    {
        // Do not throw exception here; return HttpAbortedRequest instead.

        $properties = [
            'appTitle' => $this->appTitle,
            'provider' => $this->provider,
            'service' => $this->service,
            'endpoint' => $endpoint,
            'method' => $method,
        ];
        // Erred in constructor.
        if ($this->exception) {
            return new HttpAbortedRequest($properties, $this->exception->getCode());
        }
        // HTTP method supported.
        if (!in_array($method, RestMiniClient::METHODS_SUPPORTED, true)) {
            $this->logException($this->exception = new \InvalidArgumentException(
                'Arg method[' . $method . '] is not among supported methods '
                . join('|', RestMiniClient::METHODS_SUPPORTED) . '.',
                7913 // @todo
            ));
            return new HttpAbortedRequest($properties, $this->exception->getCode());
        }
        // Config section: http-service_kki_seb-personale_cpr.
        if (!($conf_endpoint = $this->config->get(
            'http-service_' . $this->provider . '_' . $this->service . '_' . $endpoint, '*')
        )) {
            $this->logException($this->exception = new ConfigurationException(
                'Arg endpoint[' . $endpoint . '] global config section['
                . 'http-service_' . $this->provider . '_' . $this->service . '_' . $endpoint . '] is not configured.',
                7913 // @todo
            ));
            return new HttpAbortedRequest($properties, $this->exception->getCode());
        }
        // Config section: http-service_kki_seb-personale_cpr_GET.
        if (!($conf_method = $this->config->get(
            'http-service_' . $this->provider . '_' . $this->service . '_' . $endpoint . '_' . $method, '*')
        )) {
            $this->logException($this->exception = new ConfigurationException(
                'Arg endpoint[' . $endpoint . '] global config section['
                . 'http-service_' . $this->provider . '_' . $this->service . '_' . $endpoint . '_' . $method
                . '] is not configured.',
                7913 // @todo
            ));
            return new HttpAbortedRequest($properties, $this->exception->getCode());
        }
        // Check that parameters by type are nested.
        if ($parameters) {
            $keys = array_keys($parameters);
            if (($diff = array_diff($keys, ['path', 'query', 'body']))) {
                $this->logException($this->exception = new \InvalidArgumentException(
                    'Arg parameters keys[' . join(', ', $keys)
                    . '] exceed valid keys[path, query, body], perhaps forgot to nest parameters.',
                    7913 // @todo
                ));
            }
            return new HttpAbortedRequest($properties, $this->exception->getCode());
        }

        return new HttpRequest(
            $properties,
            array_replace_recursive($this->settings, $conf_endpoint, $conf_method),
            $parameters
        );
    }

    /**
     * Logs exception + trace.
     *
     * @param \Throwable $xcptn
     *
     * @return void
     */
    protected function logException(\Throwable $xcptn) /*: void*/
    {
        $code = $xcptn->getCode();
        $container = Dependency::container();
        $container->get('logger')->error(
            get_class($xcptn) . '(' . $code . '): ' . $xcptn->getMessage()
            . "\n" . $container->get('inspector')->trace($xcptn),
            [
                'code' => $code,
                'exception' => $xcptn,
            ]
        );
    }
}
