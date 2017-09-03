<?php
/**
 * KIT/Koncernservice, Københavns Kommune.
 * @link https://kkgit.kk.dk/php-psr.kk-seb/http
 * @author Jacob Friis Mathiasen <jacob.friis.mathiasen@ks.kk.dk>
 */
declare(strict_types=1);

namespace KkSeb\Http;

use Slim\Container;
use Slim\App;
use Slim\Http\Request;
use Slim\Http\Response;

use SimpleComplex\Utils\Dependency;
use SimpleComplex\Config\IniSectionedConfig;

/**
 * Slim HTTP service (route responder) base class.
 *
 * @package KkSeb\Http
 */
abstract class HttpServiceSlim extends HttpService
{
    /**
     * Final extending class _must_ override this.
     *
     * @var array
     */
    const ROUTES = [
        [
            'http-method-lowercased', '/route', 'routeMethodName',
        ]
    ];

    /**
     * @param IniSectionedConfig $config
     */
    protected function __construct(IniSectionedConfig $config)
    {
        parent::__construct($config);
    }

    /**
     * Route method for responding to pre-flight OPTIONS request.
     *
     * @param Request $request
     * @param Response $response
     *
     * @return Response
     */
    public function crossOriginOptions(Request $request, Response $response)
    {
        // Allow http://localhost:4200 in development (ng serve, npm start),
        // using custom headers.
        return static::crossOriginOptionsSetHeaders($request, $response);
    }

    /**
     * Set Cross Origin headers, preferably only in development.
     *
     * Headers set:
     * - Access-Control-Allow-Origin; allow those sites
     * - Access-Control-Expose-Headers; allow that requestor reads
     *   those response headers
     *
     * Circumvent CORS (cross origin resource sharing) check in development.
     * Angular serves from other host in development;
     * typically http://localhost:4200 (ng serve|npm start).
     *
     * Allowed sites are set as comma-separated list (no spaces) in file
     * [document root]/.access_control_allow_origin
     *
     * @see HttpService::crossOriginSitesAllowed()
     *
     * @param \Slim\Http\Response $response
     *
     * @return \Slim\Http\Response
     */
    public static function crossOriginSetHeaders(Response $response) : Response
    {
        if (($sites_allowed = static::$crossOriginSitesAllowed)) {
            // Allow requestor to see all relevant response headers;
            // headers already set plus Content-Length (which Slim sets
            // before returning response to requestor.
            $response = $response->withHeader(
                'Access-Control-Allow-Origin',
                $sites_allowed
            )->withHeader(
                'Access-Control-Expose-Headers',
                join(',', array_keys($response->getHeaders())) . ',Content-Length'
            );
        }
        return $response;
    }

    /**
     * Append headers to Cross Origin exposable headers header.
     *
     * @param \Slim\Http\Response $response
     * @param array $responseHeaders
     *
     * @return Response
     */
    public static function crossOriginAppendHeaders(Response $response, array $responseHeaders) : Response {
        if ($responseHeaders && $response->hasHeader('Access-Control-Expose-Headers')) {
            // withHeader() overrides previously set headers by that name.
            $response = $response->withHeader(
                'Access-Control-Expose-Headers',
                join(
                    ',',
                    array_unique(
                        array_merge(
                            explode(',', $response->getHeader('Access-Control-Expose-Headers')[0]),
                            array_keys($responseHeaders)
                        )
                    )
                )
            );
        }
        return $response;
    }

    /**
     * For pre-flight OPTIONS request, sent by browser
     * typically due to custom request header.
     *
     * Headers set:
     * - Access-Control-Allow-Methods: all methods supported by our HTTP client,
     *   because we cannot know here which methods the endpoint supports
     * - Access-Control-Allow-Headers: same as the request's
     *   Access-Control-Request-Headers: list
     * - Access-Control-Max-Age: we don't want to see these requests too often.
     *
     * @param Request $request
     * @param Response $response
     *
     * @return Response
     */
    public static function crossOriginOptionsSetHeaders(
        Request $request,
        Response $response
    ) : Response {
        if (static::$crossOriginSitesAllowed) {
            // Set Access-Control-Allow-Origin, Access-Control-Expose-Headers.
            $response = HttpServiceSlim::crossOriginSetHeaders($response);
            // If the request contains custom headers, they will be listed
            // in request Access-Control-Request-Headers.
            if ($request->hasHeader('Access-Control-Request-Headers')) {
                // All HTTP methods supported by our HTTP client.
                $response = $response->withHeader(
                    'Access-Control-Allow-Methods',
                    join(', ', HttpClient::methodsSupported())
                )->withHeader(
                    'Access-Control-Allow-Headers',
                    // Browser sends Access-Control-Request-Headers lowercased,
                    // and apparantly also understands response
                    // Access-Control-Allow-Headers lowercased.
                    join(', ', $request->getHeader('Access-Control-Request-Headers'))
                )->withHeader(
                    'Access-Control-Max-Age',
                    '' . HttpClient::CACHEABLE_TIME_TO_LIVE
                );
            }
        }
        return $response;
    }

    /**
     * @return \Slim\App
     */
    public static function initSlim()
    {
        // Create Slim dependency injection container.
        $container = new /*\Slim\*/Container;
        // Pass some settings to Slim.
        $container['settings']['displayErrorDetails'] = true;

        // Pass Slim container to SimpleComplex Dependency container.
        // The Slim container itself is still usable directly.
        // KkSeb and SimpleComplex classes use the container via Dependency,
        // to avoid dependency of a particular PSR Container (like Slim's).
        Dependency::injectExternalContainer($container);

        // Provide basic dependencies for error handling.
        Dependency::genericSetMultiple(
            [
                'cache-broker' => function () {
                    return new \KkSeb\Common\Cache\CacheBroker();
                },
                'config' => function() {
                    return new \KkSeb\Common\Config\Config('global');
                },
                'logger' => function() use ($container) {
                    return new \KkSeb\Common\JsonLog\JsonLog($container->get('config'));
                },
                'inspect' => function() use ($container) {
                    return new \SimpleComplex\Inspect\Inspect($container->get('config'));
                },
            ]
        );

        // Fallback exception handler.
        set_exception_handler(function(\Throwable $throwable) use ($container) {
            try {
                $trace = null;
                if ($container->has('inspect')) {
                    $trace = '' . $container->get('inspect')->trace($throwable);
                }
                if ($container->has('logger')) {
                    $container->get('logger')->error($trace ?? $throwable);
                }
            } catch (\Throwable $ignore) {
            }
            header('HTTP/1.1 500 Internal Server Error');
            exit;
        });

        // Slim request PHP error handler.
        Dependency::genericSet('phpErrorHandler', function () use ($container) {
            return function (
                /*\Slim\Http*/Request $request,
                /*\Slim\Http*/Response $response,
                \Throwable $exception
            ) use ($container) {
                try {
                    // Log exception trace and request specifics.
                    $inspect = $container->get('inspect');
                    $container->get('logger')->error(
                        'PHP error'
                        . "\n" . $inspect->trace(null)
                        . "\n" . $inspect->variable([
                            'path' => $request->getUri()->getPath(),
                            'query' => $request->getQueryParams(),
                            'body' => $request->getParsedBody(),
                        ]),
                        [
                            'code' => $exception->getCode(),
                        ]
                    );
                } catch (\Throwable $xc) {
                    error_log(
                        get_class($xc) . '(' . $xc->getCode() . '): ' . $xc->getMessage()
                        . '@' . $xc->getFile() . ':' . $xc->getLine()
                    );
                }
                try {
                    // Even error response may need Cross Origin headers.
                    if (($cors_allowed_sites = HttpServiceSlim::crossOriginSitesAllowed())) {
                        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
                            $response = HttpServiceSlim::crossOriginOptionsSetHeaders($request, $response);
                        } else {
                            $response = HttpServiceSlim::crossOriginSetHeaders($response);
                        }
                    }
                } catch (\Throwable $ignore) {
                }
                /**
                 * Send status 200, pass real success+status in response body.
                 *
                 * @see \KkSeb\Http\HttpResponseBody
                 */
                $response->write(
                    json_encode([
                        'success' => false,
                        'status' => 500,
                        // Empty: frontend will use locale text http-client:error:local-unknown.
                        'message' => '',
                        'data' => null,
                        'code' => 0,
                    ])
                );
                return $response
                    ->withStatus(200);
            };
        });
        // Slim request exception handler.
        Dependency::genericSet('errorHandler', function () use ($container) {
            return function (
                /*\Slim\Http*/Request $request,
                /*\Slim\Http*/Response $response,
                \Throwable $exception
            ) use ($container) {
                try {
                    // Log exception trace and request specifics.
                    $inspect = $container->get('inspect');
                    $container->get('logger')->error(
                        $inspect->trace($exception)
                        . "\n" . $inspect->variable([
                            'path' => $request->getUri()->getPath(),
                            'query' => $request->getQueryParams(),
                            'body' => $request->getParsedBody(),
                        ]),
                        [
                            'code' => $exception->getCode(),
                        ]
                    );
                } catch (\Throwable $xc) {
                    error_log(
                        get_class($xc) . '(' . $xc->getCode() . '): ' . $xc->getMessage()
                        . '@' . $xc->getFile() . ':' . $xc->getLine()
                    );
                }
                try {
                    // Even error response may need Cross Origin headers.
                    if (($cross_origin_sites_allowed = HttpServiceSlim::crossOriginSitesAllowed())) {
                        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
                            $response = HttpServiceSlim::crossOriginOptionsSetHeaders($request, $response);
                        } else {
                            $response = HttpServiceSlim::crossOriginSetHeaders($response);
                        }
                    }
                } catch (\Throwable $ignore) {
                }
                /**
                 * Send status 200, pass real success+status in response body.
                 *
                 * @see \KkSeb\Http\HttpResponseBody
                 */
                $response->write(
                    json_encode([
                        'success' => false,
                        'status' => 500,
                        // Empty: frontend will use locale text http:error-client:local-unknown.
                        'message' => '',
                        'data' => null,
                        'code' => $exception->getCode(),
                    ])
                );
                return $response
                    ->withStatus(200);
            };
        });

        // Provide more generic dependencies.
        Dependency::genericSetMultiple(
            [
                'locale' => function () use ($container) {
                    return \KkSeb\Common\Locale\Locale::create($container->get('config'));
                },
            ]
        );

        // Init Slim application.
        return new /*\Slim\*/App($container);
    }

    /**
     * @todo: important for HTTP response caching - user ID is not sufficient as qualification, because a new page load should mean new caches.
     * @todo: Set responder which delivers X-KkSeb-Page-Load-Id sha256(uniqid()).
     * @todo: frontend root app must ngInit() for page load ID, to be sent as header via interceptor.
     * @todo: backend must set the page load ID as (persistent) cache item, so that later responders can check if it exists.
     * @todo: weekend work :-( :-) because to much backend work to justify.
     *
     * @see \KkSeb\Common\Common::generatePageLoadId()
     */
}
