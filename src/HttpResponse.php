<?php
/**
 * KIT/Koncernservice, Københavns Kommune.
 * @link https://kkgit.kk.dk/php-psr.kk-seb/http
 */
declare(strict_types=1);

namespace KkSeb\Http;

use KkSeb\Http\Exception\HttpException;
use KkSeb\Http\Exception\HttpRuntimeException;

/**
 * Http response.
 *
 * @package KkSeb\Http
 */
class HttpResponse
{
    /**
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

    /**
     * @see HttpClient::ERROR_CODES
     * @see \SimpleComplex\RestMini\Client::ERROR_CODES
     *
     * @param array $error
     *      Empty if RestMini Client didn't report an error.
     * @param bool $fromCache
     * @param array $requirements {
     *      @var array $require_response_headers  If set.
     *      @var bool $err_on_endpoint_not_found  If set.
     *      @var bool $err_on_resource_not_found  If set.
     * }
     *
     * @return array
     *      Buckets 'preface' and 'exception' and/or 'variables'
     *      if to be logged.
     */
    public function evaluate(array $error = [], bool $fromCache = false, array $requirements = []) : array
    {
        // Error name 'request_aborted' is HttpClient, not RestMini Client,
        // and no loggable exception should be created for that (already logged).
        if ($error) {
            if ($error['name'] == 'request_aborted') {
                // ...
            } else {
                // Investigate RestMini Client error.
            }
        }

        // @todo: define error codes in HttpClient
        /**
         * @see HttpClient::ERROR_CODES
         */

        // Return loggable exception on failure (except if request_aborted).

        return [];
        // Or
        /*
        return [
            'preface' => 'Http request|response',
            'exception' => new HttpRuntimeException(),
            'variables' => [],
        ];
        */
    }
}
