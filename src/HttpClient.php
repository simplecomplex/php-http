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
    public $settings;

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
     * @throws ConfigurationException
     *      Un-configured provider or service.
     */
    public function __construct(string $provider, string $service, string $appTitle = '')
    {
        $container = Dependency::container();
        /** @var \KkSeb\Config\IniSectionedConfig $config */
        $this->config = $container->get('config');

        // Config section: http-provider_kki.
        if (!($conf_provider = $this->config->get('http-provider_' . $provider, '*'))) {
            throw new ConfigurationException(
                'Arg provider[' . $provider . '] global config section['
                . 'http-provider_' . $provider . '] is not configured.'
            );
        }
        $this->provider = $provider;
        // Config section: http-service_kki_seb-personale.
        if (!($conf_service = $this->config->get('http-service_' . $provider . '_' . $service, '*'))) {
            throw new ConfigurationException(
                'Arg service[' . $provider . '] global config section['
                . 'http-service_' . $provider . '_' . $service . '] is not configured.'
            );
        }
        $this->service = $service;

        $this->settings = array_replace_recursive($conf_provider, $conf_service);

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
     * @throws ConfigurationException
     *      Un-configured endpoint or method.
     * @throws \InvalidArgumentException
     *      Arg method not supported.
     *      Parameters are not nested in path|query|body bucket(s).
     */
    public function request(string $endpoint, string $method, array $parameters, array $options = [])
    {
        if (!in_array($method, RestMiniClient::METHODS_SUPPORTED, true)) {
            throw new \InvalidArgumentException(
                'Arg method[' . $method . '] is not among supported methods '
                . join('|', RestMiniClient::METHODS_SUPPORTED) . '.'
            );
        }

        // Config section: http-service_kki_seb-personale_cpr.
        if (!($conf_endpoint = $this->config->get(
            'http-service_' . $this->provider . '_' . $this->service . '_' . $endpoint, '*')
        )) {
            throw new ConfigurationException(
                'Arg endpoint[' . $endpoint . '] global config section['
                . 'http-service_' . $this->provider . '_' . $this->service . '_' . $endpoint . '] is not configured.'
            );
        }
        // Config section: http-service_kki_seb-personale_cpr_GET.
        if (!($conf_method = $this->config->get(
            'http-service_' . $this->provider . '_' . $this->service . '_' . $endpoint . '_' . $method, '*')
        )) {
            throw new ConfigurationException(
                'Arg endpoint[' . $endpoint . '] global config section['
                . 'http-service_' . $this->provider . '_' . $this->service . '_' . $endpoint . '_' . $method
                . '] is not configured.'
            );
        }

        // Check that parameters by type are nested.
        if ($parameters) {
            $keys = array_keys($parameters);
            if (($diff = array_diff($keys, ['path', 'query', 'body']))) {
                throw new \InvalidArgumentException(
                    'Arg parameters keys[' . join(', ', $keys)
                    . '] exceed valid keys[path, query, body], perhaps forgot to nest parameters.'
                );
            }
        }

        return new HttpRequest(
            [
                'provider' => $this->provider,
                'service' => $this->service,
                'endpoint' => $endpoint,
                'method' => $method,
            ],
            array_replace_recursive($this->settings, $conf_endpoint, $conf_method),
            $parameters
        );
    }
}
