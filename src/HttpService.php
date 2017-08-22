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
     * @var array
     */
    const ERROR_CODES = [
        'unknown' => 1,

        'request-validation' => 95,
    ];

    /**
     * Request (header or argument) validation failure.
     *
     * Recommended values:
     * - 400 Bad Request
     * - 412 Precondition Failed
     * - 422 Unprocessable Entity; WebDAV, but gaining support because exact.
     *
     * @var int
     */
    const HTTP_STATUS_REQUEST_VALIDATION = 400;
}
