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
        // Copy.
        $client_options = $this->options;
    }
}
