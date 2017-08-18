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
     * Headers received from remote service.
     *
     * NB: Do NOT send these to requestor.
     * Set to null (or unset) if sending the whole response object to requestor.
     *
     * @var array
     */
    public $originalHeaders = [];

    /**
     * Validated against rule set; in effect valdated against contract
     * with remote service.
     *
     * @var bool|null
     *      Null: the response has not been validated at all.
     *      Boolean: passed/failed validation.
     */
    public $validated = null;

    /**
     * @param int $status
     * @param array $headers
     * @param HttpResponseBody $body
     * @param array $originalHeaders
     *      Default: empty.
     */
    public function __construct(int $status, array $headers, HttpResponseBody $body, array $originalHeaders = [])
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
        $this->originalHeaders = $originalHeaders;
    }
}
