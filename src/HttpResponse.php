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
     * Status to be sent to requestor.
     *
     * @var int
     */
    public $status = 500;

    /**
     * Headers to be sent to requestor.
     *
     * @var array
     */
    public $headers = [];

    /**
     * Body to be sent to requestor.
     *
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
