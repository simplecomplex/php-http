<?php
/**
 * KIT/Koncernservice, Københavns Kommune.
 * @link https://kkgit.kk.dk/php-psr.kk-seb/http
 */
declare(strict_types=1);

namespace KkSeb\Http;

/**
 * Http response.
 *
 * @package KkSeb\Http
 */
class HttpResponse
{
    /**
     * Status to be emitted to requestor.
     *
     * @var int
     */
    public $status = 500;

    /**
     * @var array
     */
    public $headers = [];

    /**
     * @var HttpResponseBody
     */
    public $body;

    /**
     * @var int
     */
    public $code = 0;

    /**
     * @param int $status
     * @param array $headers
     * @param HttpResponseBody $body
     * @param int $code
     */
    public function __construct(int $status, array $headers, HttpResponseBody $body, int $code = 0)
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
        $this->code = $code;
    }
}
