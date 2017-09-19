<?php

namespace KkSeb\Http\Exception;

use KkSeb\Http\HttpResponse;

/**
 * Usable for forwarding a HttpResponse produced by HttpClient
 * upon a request or response error, happening deep within the framework.
 *
 * @package KkSeb\Http
 */
class HttpForwardableResponseException extends HttpRuntimeException
{
    /**
     * @var \KkSeb\Http\HttpResponse
     */
    protected $httpResponse;

    /**
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     * @param \KkSeb\Http\HttpResponse $httpResponse
     */
    public function __construct(string $message, int $code, /*?\Throwable*/ $previous, HttpResponse $httpResponse)
    {
        parent::__construct($message, $code, $previous);
        $this->httpResponse = $httpResponse;
    }

    /**
     * @return \KkSeb\Http\HttpResponse
     */
    public function getHttpResponse() : HttpResponse
    {
        return $this->httpResponse;
    }
}
