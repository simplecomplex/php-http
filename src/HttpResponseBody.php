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
     * The actual status to be emitted to frontend client.
     *
     * Allows making response body (like error message) available for frontend,
     * when error; send status header 200, and set this to 500/502/504 etc.
     *
     * Angular HttpClient ignores response body if header status isn't 200/201.
     * And Angular promise fails if null body.
     *
     * @var int
     */
    public $status = 200;

    /**
     * @var bool
     */
    public $success = true;

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



    const MESSAGE_ERROR = '';

    /**
     * @param string $message
     */
    public function setMessage(string $message) /*:void*/
    {
        if (defined('static::' . $message)) {

        }
    }
}
