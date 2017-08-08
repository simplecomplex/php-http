<?php
/**
 * KIT/Koncernservice, Københavns Kommune.
 * @link https://kkgit.kk.dk/php-psr.kk-seb/http
 */
declare(strict_types=1);

namespace KkSeb\Http;

/**
 *
 * @package KkSeb\Http
 */
class HttpResponseBody
{
    /**
     * The real status.
     *
     * Allows making response body (like error message) available for frontend,
     * when error.
     * Send status header 200, set this (response body) status to 500/502/504.
     *
     * Angular HttpClient ignores response body if header status isn't 200/201.
     * And Angular 'promise' fails if null body.
     *
     * @var int
     */
    public $status = 500;

    /**
     * @var bool
     */
    public $success = false;

    /**
     * @var mixed|null
     */
    public $data;

    /**
     * Safe and user-friendly error message, when error.
     *
     * @var string|null
     */
    public $message;

    /**
     * Optional error (or other type of) code.
     *
     * @var int|null
     */
    public $code;
}
