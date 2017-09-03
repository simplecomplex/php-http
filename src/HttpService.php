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
use SimpleComplex\Config\IniSectionedConfig;

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
     * @var IniSectionedConfig
     */
    protected $config;

    /**
     * @param IniSectionedConfig $config
     */
    protected function __construct(IniSectionedConfig $config)
    {
        $this->config = $config;
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
     * Comma-separated list of sites.
     *
     * @var string
     */
    protected static $crossOriginSitesAllowed;

    /**
     * Cross origin sites allowed.
     *
     * Set as comma-separated list (no spaces) in file
     * [document root]/.access_control_allow_origin
     *
     * NB: cross origin sites allowed are valid for _all_ services.
     * If only some services should be exposed to cross origin, you'll have
     * to make more sites (period!).
     *
     * @return string
     */
    public static function crossOriginSitesAllowed() : string
    {
        if (static::$crossOriginSitesAllowed === null) {
            $file = Utils::getInstance()->documentRoot() . '/.access_control_allow_origin';
            static::$crossOriginSitesAllowed = !file_exists($file) ? '' : trim(file_get_contents($file));
        }
        return static::$crossOriginSitesAllowed;
    }
}
