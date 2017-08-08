<?php
/**
 * KIT/Koncernservice, Københavns Kommune.
 * @link https://kkgit.kk.dk/php-psr.kk-seb/http
 */
declare(strict_types=1);

namespace KkSeb\Http;

/**
 * HTTP request which never gets executed, typically due to HttpClient
 * configuration or argument error.
 *
 * @internal
 *
 * @package KkSeb\Http
 */
class HttpAbortedRequest extends HttpRequest
{
    /**
     * Does not execute HTTP request.
     *
     * @param array $ids
     * @param int $code
     */
    public function __construct(array $ids, int $code)
    {
        $this->code = $code;
        parent::__construct($ids, [], []);
    }
}
