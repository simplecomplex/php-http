<?php
/**
 * KIT/Koncernservice, Københavns Kommune.
 * @link https://kkgit.kk.dk/php-psr.kk-seb/http
 * @author Jacob Friis Mathiasen <jacob.friis.mathiasen@ks.kk.dk>
 */
declare(strict_types=1);

namespace KkSeb\Http;

/**
 * Http service.
 *
 * @package KkSeb\Http
 */
class HttpService
{
    /**
     * Range is this +99.
     *
     * @var int
     */
    const ERROR_CODE_OFFSET = 1000;

    /**
     * @var int[]
     */
    const ERROR_CODES = [
        'unknown' => 1,

        'request-unacceptable' => 10,

        'unauthenticated' => 20,
        'unauthorized' => 21,

        'request-validation' => 30,
    ];

    /**
     * @var int[]
     */
    const STATUS_CODE = [
        // 400 Bad Request.
        'request-unacceptable' => 400,
        // 401 Unauthorized.
        'unauthenticated' => 401,
        // 403 Forbidden.
        'unauthorized' => 403,
        // Recommended values:
        // 400 Bad Request
        // 412 Precondition Failed
        // 422 Unprocessable Entity; WebDAV, but gaining support because exact.
        'request-validation' => 400,
    ];
}
