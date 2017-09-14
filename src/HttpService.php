<?php
/**
 * KIT/Koncernservice, Københavns Kommune.
 * @link https://kkgit.kk.dk/php-psr.kk-seb/http
 * @author Jacob Friis Mathiasen <jacob.friis.mathiasen@ks.kk.dk>
 */
declare(strict_types=1);

namespace KkSeb\Http;

use SimpleComplex\Utils\Utils;
use SimpleComplex\Utils\Dependency;

/**
 * Base class of all HTTP services, based on Slim
 * or other service exposure framework.
 *
 * @package KkSeb\Http
 */
abstract class HttpService
{
    /**
     * Final extending class _must_ override this.
     *
     * @var string
     */
    const APPLICATION_ID = 'application-id-unknown';

    /**
     * Dependency injection container ID.
     *
     * Final extending class _must_ override this.
     *
     * @var string
     */
    const DEPENDENCY_ID = 'http-service.unknown';

    /**
     * Provides application dependencies.
     */
    protected function __construct()
    {
        // Provide application dependencies, now that we know which application
        // receives the request.
        $container = Dependency::container();
        $application_id = static::APPLICATION_ID;
        Dependency::genericSetMultiple(
            [
                'application-id' => $application_id,
                'application-title' => function () use ($container, $application_id) {
                    // Use common application title as fallback,
                    // if the solution's locale text ini file misses
                    // [some-application-id]
                    // application-title = Some Solution.
                    /** @var \SimpleComplex\Locale\AbstractLocale $locale */
                    $locale = $container->get('locale');
                    if (($text = $locale->text($application_id . ':application-title', [], ''))) {
                        return $text;
                    }
                    return $locale->text('common:application-title');
                }
            ]
        );
    }

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

        // Errors only detectable at frontend.
        'frontend-response-format' => 80,
        'frontend-response-validation' => 95,
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
