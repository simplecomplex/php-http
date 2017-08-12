<?php
/**
 * KIT/Koncernservice, Københavns Kommune.
 * @link https://kkgit.kk.dk/php-psr.kk-seb/http
 * @author Jacob Friis Mathiasen <jacob.friis.mathiasen@ks.kk.dk>
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
     * @var \KkSeb\Http\HttpResponseBody
     */
    public $body;

    /**
     * @param int $status
     * @param array $headers
     * @param HttpResponseBody $body
     */
    public function __construct(int $status, array $headers, HttpResponseBody $body)
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
    }
}
