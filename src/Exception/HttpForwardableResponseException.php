<?php
/**
 * SimpleComplex PHP Http
 * @link      https://github.com/simplecomplex/php-http
 * @copyright Copyright (c) 2017 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-http/blob/master/LICENSE (MIT License)
 */

namespace SimpleComplex\Http\Exception;

use SimpleComplex\Http\HttpResponse;

/**
 * Usable for forwarding a HttpResponse produced by HttpClient
 * upon a request or response error, happening deep within the framework.
 *
 * @package SimpleComplex\Http
 */
class HttpForwardableResponseException extends HttpRuntimeException
{
    /**
     * @var \SimpleComplex\Http\HttpResponse
     */
    protected $httpResponse;

    /**
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     * @param \SimpleComplex\Http\HttpResponse $httpResponse
     */
    public function __construct(string $message, int $code, /*?\Throwable*/ $previous, HttpResponse $httpResponse)
    {
        parent::__construct($message, $code, $previous);
        $this->httpResponse = $httpResponse;
    }

    /**
     * @return \SimpleComplex\Http\HttpResponse
     */
    public function getHttpResponse() : HttpResponse
    {
        return $this->httpResponse;
    }
}
