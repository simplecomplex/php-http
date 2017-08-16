<?php
/**
 * KIT/Koncernservice, Københavns Kommune.
 * @link https://kkgit.kk.dk/php-psr.kk-seb/http
 * @author Jacob Friis Mathiasen <jacob.friis.mathiasen@ks.kk.dk>
 */
declare(strict_types=1);

namespace KkSeb\Http;

/**
 * Http response body.
 *
 * @package KkSeb\Http
 */
class HttpResponseBody
{
    /**
     * @var bool
     */
    public $success = false;

    /**
     * The actual status.
     *
     * Allows making response body (like error message) available for frontend,
     * when error. Send status header 200, set this (response body) status
     * to 500/502/504.
     *
     * Angular HttpClient ignores response body if header status isn't 200/201,
     * thus the 'message' won't be available if sending non-200/201 status.
     * And Angular 'promise' fails if null body.
     *
     * @var int
     */
    public $status = 500;

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

    /**
     * All parameters are optional.
     *
     * @param bool $success
     * @param int $status
     * @param mixed|null $data
     * @param string|null $message
     * @param int $code
     */
    public function __construct(bool $success = false, int $status = 500, $data = null, $message = null, int $code = 0)
    {
        $this->success = $success;
        $this->status = $status;
        $this->data = $data;
        $this->message = $message;
        $this->code = $code;
    }
}
