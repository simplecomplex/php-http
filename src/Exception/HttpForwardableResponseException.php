<?php

namespace KkBase\Http\Exception;

use KkBase\Http\HttpResponse;

/**
 * Usable for forwarding a HttpResponse produced by HttpClient
 * upon a request or response error, happening deep within the framework.
 *
 * @package KkBase\Http
 */
class HttpForwardableResponseException extends HttpRuntimeException
{
    /**
     * @var \KkBase\Http\HttpResponse
     */
    protected $httpResponse;

    /**
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     * @param \KkBase\Http\HttpResponse $httpResponse
     */
    public function __construct(string $message, int $code, /*?\Throwable*/ $previous, HttpResponse $httpResponse)
    {
        parent::__construct($message, $code, $previous);
        $this->httpResponse = $httpResponse;
    }

    /**
     * @return \KkBase\Http\HttpResponse
     */
    public function getHttpResponse() : HttpResponse
    {
        return $this->httpResponse;
    }
}
