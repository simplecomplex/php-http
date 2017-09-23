<?php
/**
 * SimpleComplex PHP Http
 * @link      https://github.com/simplecomplex/php-http
 * @copyright Copyright (c) 2017 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-http/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Http;

/**
 * A means of eliminating class constants in Http classes,
 * which must be overridable.
 *
 * @dependency-injection-container-id http-settings
 *
 * @package SimpleComplex\Http
 */
class HttpSettings
{
    /**
     * Service defaults.
     *
     * @var array
     */
    const SERVICE = [
        'error_code_offset' => 1000,
    ];

    /**
     * Client defaults.
     *
     * @var array
     */
    const CLIENT = [
        'error_code_offset' => 1900,
        'log_type' => 'http-client',
        'cacheable_time_to_live' => 1800,
    ];

    /**
     * @var int[]
     */
    const SERVICE_STATUS_CODE = [
        // 400 Bad Request.
        'request-unacceptable' => 400,
        // 401 Unauthorized.
        'unauthenticated' => 401,
        // 403 Forbidden.
        'forbidden' => 403,
        'unauthorized' => 403,
        // Recommended values:
        // 400 Bad Request
        // 412 Precondition Failed
        // 422 Unprocessable Entity; WebDAV, but gaining support because exact.
        'request-validation' => 400,
    ];

    /**
     * Make all but unauthorized 'forbidden' responses look the same.
     *
     * List of headers, except the 'body' bucket.
     *
     * @var string[]
     */
    const SERVICE_RESPONSE_FORBIDDEN = [
        'Connection' => 'close',
        'Content-Type' => 'text/plain',
        'body' => 'Go away.'
    ];

    /**
     * Get service setting default.
     *
     * @param string $setting
     *
     * @return mixed
     *
     * @throws \InvalidArgumentException
     *      Arg setting doesn't exist.
     */
    public function service(string $setting)
    {
        if (isset(static::SERVICE[$setting])) {
            return static::SERVICE[$setting];
        }
        throw new \InvalidArgumentException('Arg setting is not a supported service setting.');
    }

    /**
     * Get client setting default.
     *
     * @param string $setting
     *
     * @return mixed
     *
     * @throws \InvalidArgumentException
     *      Arg setting doesn't exist.
     */
    public function client(string $setting)
    {
        if (isset(static::CLIENT[$setting])) {
            return static::CLIENT[$setting];
        }
        throw new \InvalidArgumentException('Arg setting is not a supported client setting.');
    }

    /**
     * Get service status code.
     *
     * @param string $name
     *
     * @return int
     *
     * @throws \InvalidArgumentException
     *      Arg name doesn't exist.
     */
    public function serviceStatusCode(string $name)
    {
        if (isset(static::SERVICE_STATUS_CODE[$name])) {
            return static::SERVICE_STATUS_CODE[$name];
        }
        throw new \InvalidArgumentException('Arg name is not a supported service status code name.');
    }

    /**
     * @return string[]
     */
    public function serviceResponseForbidden()
    {
        return static::SERVICE_RESPONSE_FORBIDDEN;
    }
}
