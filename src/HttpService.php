<?php
/**
 * SimpleComplex PHP Http
 * @link      https://github.com/simplecomplex/php-http
 * @copyright Copyright (c) 2017 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-http/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Http;

use SimpleComplex\Utils\Utils;

/**
 * Utility class for all HTTP services, based on Slim
 * or other service exposure framework.
 *
 * @package SimpleComplex\Http
 */
class HttpService
{
    /**
     * Final numeric values are be affected
     * by HttpSettings::SERVICE['error_code_offset'].
     *
     * Overriding this constant has _no_ effect;
     * http classes call HttpService class constants directly.
     *
     * @see HttpSettings::SERVICE
     *
     * @var int[]
     */
    const ERROR_CODES = [
        'unknown' => 1,

        'request-unacceptable' => 10,

        'unauthenticated' => 20,
        'unauthorized' => 21,

        'request-validation' => 30,

        // Errors only detectable at frontend.
        'frontend-response-format' => 80,
        'frontend-response-validation' => 95,
    ];

    /**
     * Prepare cache control response headers.
     *
     * @param int $timeToLive
     *      In seconds; default zero (prevent browser caching).
     *
     * @return array
     *
     * @throws \InvalidArgumentException
     *      Arg timeTolive is negative.
     */
    public static function cacheControlHeaders(int $timeToLive = 0) : array
    {
        if ($timeToLive < 0) {
            throw new \InvalidArgumentException('Arg timeTolive[' . $timeToLive . '] is not non-negative integer.');
        }
        if (!$timeToLive) {
            return [
                'Cache-Control' => 'private, no-store, no-cache, must-revalidate, post-check=0, pre-check=0',
            ];
        }
        $time = !empty($_SERVER['REQUEST_TIME']) ? $_SERVER['REQUEST_TIME'] : time();
        return [
            'Cache-Control' => 'public, max-age=' . $timeToLive,
            'Expires' => gmdate('D, d M Y H:i:s', ($time + $timeToLive)) . ' GMT',
            'Last-modified' => gmdate('D, d M Y H:i:s', $time) . ' GMT',
        ];
    }

    /**
     * Comma-separated list of sites (including HTTP port).
     *
     * @var string
     */
    protected static $crossOriginSiteAllowed;

    /**
     * Protocol + host + port of remote site, if allowed as cross origin.
     *
     * Configured as comma-separated list (including HTTP port) in file
     * [document root]/.cross_origin_allow_sites
     *
     * NB: cross origin sites allowed are valid for _all_ services.
     * If only some services should be exposed to cross origin, you'll have
     * to make more sites (period!).
     *
     * @return string
     */
    public static function crossOriginSiteAllowed() : string
    {
        if (static::$crossOriginSiteAllowed === null) {
            $allow = '';
            $utils = Utils::getInstance();
            $file = $utils->documentRoot() . '/.cross_origin_allow_sites';
            if (
                file_exists($file)
                && ($sites = trim(file_get_contents($file)))
                && ($origin = $utils->getRequestHeader('Origin'))
                && ($origin === filter_var(
                    $origin,
                    FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH
                ))
            ) {
                // No port is 80.
                $origin_port = $origin;
                if (!strpos($origin_port, ':', 6)) {
                    $origin_port .= ':80';
                }
                $sites = explode(',', str_replace(' ', '', $sites));
                foreach ($sites as $host_port) {
                    if ($host_port . (strpos($host_port, ':', 6) ? '' : ':80') === $origin_port) {
                        $allow = $origin;
                        break;
                    }
                }
            }
            static::$crossOriginSiteAllowed = $allow;
        }
        return static::$crossOriginSiteAllowed;
    }
}
