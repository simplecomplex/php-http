<?php
/**
 * SimpleComplex PHP Http
 * @link      https://github.com/simplecomplex/php-http
 * @copyright Copyright (c) 2017-2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-http/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Http;

use Slim\Container;
use Slim\App;
use Slim\Http\Request;
use Slim\Http\Response;

use SimpleComplex\Utils\Dependency;
use SimpleComplex\Utils\Bootstrap;

/**
 * Slim HTTP service (route responder) base class.
 *
 * See the bootstrapper.
 * @see HttpServiceSlim::bootstrap()
 *
 * @uses-dependency-container locale, logger, inspect
 *
 * @package SimpleComplex\Http
 */
abstract class HttpServiceSlim
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
     * @var string
     */
    const CLASS_BOOTSTRAP = Bootstrap::class;

    /**
     * @var string
     */
    const CLASS_HTTP_SETTINGS = HttpSettings::class;

    /**
     * Final extending class _must_ override this.
     *
     * @var array
     */
    const ROUTES = [
        // Slim App route methods' second arg callable with single colon means:
        // - get before-colon-dependency and call it's after-colon-method
        [
            'http-method-lowercased', '/route', 'routeMethodName',
        ]
    ];

    /**
     * @var string
     */
    protected static $crossOriginSiteAllowed;

    /**
     * Declares Slim routes.
     *
     * Has to be static, otherwise redundant instantiation:
     * - request matches this class: double instantiation
     * - request matches other class: one (unneeded) instantiation
     *
     * Also declares OPTION routes, if any cross origin sites allowed.
     *
     * @param \Slim\App $app
     */
    public static function routes($app)
    {
        $cross_origin = !!(static::$crossOriginSiteAllowed = HttpService::crossOriginSiteAllowed());

        foreach (static::ROUTES as $route) {
            $methods = $route[0];
            if (is_array($methods)) {
                $app->map($methods, $route[1], static::DEPENDENCY_ID . ':' . $route[2]);
            }
            else {
                $app->{$methods}($route[1], static::DEPENDENCY_ID . ':' . $route[2]);
            }

            // Set OPTION routes to respond to cross origin 'pre-flight' OPTION
            // request, which a browser issues if a request sends custom headers.
            if ($cross_origin) {
                $app->options($route[1], static::DEPENDENCY_ID . ':crossOriginOptions');
            }
        }
    }

    /**
     * Provides application dependencies, now that we know which application
     * receives the request.
     */
    protected function __construct()
    {
        $container = Dependency::container();
        $application_id = static::APPLICATION_ID;
        $http_settings = static::CLASS_HTTP_SETTINGS;
        Dependency::genericSetMultiple(
            [
                // Make http class settings available.
                'http-settings' => function() use ($http_settings) {
                    return new $http_settings();
                },
                // Application specific.
                'application-id' => $application_id,
                'application-title' => function() use ($container, $application_id) {
                    // Use base application title as fallback,
                    // if the solution's locale text ini file misses
                    // [some-application-id]
                    // application-title = Some Solution.
                    // Non-solution services, will always use base title.
                    /** @var \SimpleComplex\Locale\AbstractLocale $locale */
                    $locale = $container->get('locale');
                    // Cascading: application-id or common or base.
                    return $locale->text(
                        $application_id . ':application-title',
                        [],
                        $locale->text(
                            'common:application-title',
                            [],
                            $locale->text('http:application-title')
                        )
                    );
                },
            ]
        );
    }

    /**
     * Send cache control response headers.
     *
     * See also Typescript angular.kk-base.base KkBaseAbstractHttpService.
     *
     * @param Response $response
     * @param int $timeToLive
     *      In seconds; default zero (prevent browser caching).
     *
     * @return Response
     *
     * @throws \InvalidArgumentException
     *      Propagated; arg timeToLive is negative.
     */
    public function cacheControl(Response $response, int $timeToLive = 0) : Response
    {
        $headers = HttpService::cacheControlHeaders($timeToLive);
        foreach ($headers as $key => $val) {
            $response = $response->withHeader($key, $val);
        }
        return $response;
    }

    /**
     * Make all but unauthorized 'forbidden' responses look the same.
     *
     * @see HttpService::RESPONSE_FORBIDDEN
     *
     * @param Response $response
     *
     * @return Response
     */
    public function respondForbidden(Response $response) : Response
    {
        /** @var HttpSettings $http_settings */
        $http_settings = Dependency::container()->get('http-settings');
        /** @var \Slim\Http\Response $response */
        $response = $response->withStatus($http_settings->serviceStatusCode('forbidden'));
        // Copy.
        $headers = $http_settings->serviceResponseForbidden();
        if (!empty($headers['body'])) {
            $response->write($headers['body']);
        }
        unset($headers['body']);
        foreach ($headers as $key => $val) {
            $response = $response->withHeader($key, $val);
        }
        return $response;
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
     * - Access-Control-Allow-Origin; allow this sites
     * - Access-Control-Expose-Headers; allow that requestor reads
     *   those response headers
     * - Access-Control-Allow-Credentials; allow that requestor sends
     *   login credentials (like NTLM/Kerberos)
     *   Only if arg $requireCredentials.
     *
     * Circumvent CORS (cross origin resource sharing) check in development.
     * Angular serves from other host in development;
     * typically http://localhost:4200 (ng serve|npm start).
     *
     * Allowed sites are set as comma-separated list (including HTTP port)
     * in file [document root]/.cross_origin_allow_sites
     *
     * @see HttpService::crossOriginSiteAllowed()
     *
     * @param \Slim\Http\Response $response
     * @param bool $requireCredentials
     *      Must be true when using Kerberos authentication or likewise.
     *
     * @return \Slim\Http\Response
     */
    public static function crossOriginSetHeaders(Response $response, bool $requireCredentials = false) : Response
    {
        if (static::$crossOriginSiteAllowed) {
            // Allow requestor to see all relevant response headers;
            // headers already set plus Content-Length (which Slim sets
            // before returning response to requestor.
            $response = $response->withHeader(
                'Access-Control-Allow-Origin',
                static::$crossOriginSiteAllowed
            )->withHeader(
                'Access-Control-Expose-Headers',
                join(',', array_keys($response->getHeaders())) . ',Content-Length'
            );
            if ($requireCredentials) {
                $response = $response->withHeader(
                    'Access-Control-Allow-Credentials', 'true'
                );
            }
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
        if (static::$crossOriginSiteAllowed) {
            // Set Access-Control-Allow-Origin, Access-Control-Expose-Headers.
            $response = static::crossOriginSetHeaders(
                $response,
                // Don't ever require authentication for OPTIONS request.
                false
            );
            // If the request contains custom headers, they will be listed
            // in request Access-Control-Request-Headers.
            if ($request->hasHeader('Access-Control-Request-Headers')) {
                // All HTTP methods supported by our HTTP client.
                /** @var Response $response */
                $response = $response->withHeader(
                    'Access-Control-Allow-Methods',
                    join(', ', HttpClient::methodsSupported())
                )->withHeader(
                    'Access-Control-Allow-Headers',
                    // Browser sends Access-Control-Request-Headers lowercased,
                    // and apparantly also understands response
                    // Access-Control-Allow-Headers lowercased.
                    join(', ', $request->getHeader('Access-Control-Request-Headers'))
                );
                $response = $response->withHeader(
                    'Access-Control-Max-Age',
                    '' . Dependency::container()->get('http-settings')->client('cacheable_time_to_live')
                );
            }
        }
        return $response;
    }

    /**
     * Bootstraps Slim, and the SimpleComplex framework for all services.
     *
     * @param Callable|null $customLogger
     *      Custom logger; default is JsonLog.
     *
     * @return \Slim\App
     */
    public static function bootstrap(/*?Callable*/ $customLogger = null)
    {
        // Create Slim dependency injection container.
        $container = new /*\Slim\*/Container;
        // Pass some settings to Slim.
        $container['settings']['displayErrorDetails'] = true;

        // Pass Slim container to SimpleComplex Dependency container.
        // The Slim container itself is still usable directly.
        // SimpleComplex classes use the container via Dependency,
        // to avoid dependency of a particular PSR Container (like Slim's).

        // And prepare base dependencies.
        /**
         * @see \SimpleComplex\Utils\Bootstrap::prepareDependencies()
         */
        $bootstrap = static::CLASS_BOOTSTRAP . '::prepareDependencies';
        $bootstrap($container, $customLogger);

        // Fallback exception handler.
        Bootstrap::setExceptionHandler($container, 'http');

        // PHP warning/notice handler; Slim doesn't handle those.
        Bootstrap::setErrorHandler($container, 'http');

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
                } catch (\Throwable $xcptn) {
                    // Log original exception.
                    error_log(
                        get_class($exception) . '(' . $exception->getCode() . ')@' . $exception->getFile() . ':'
                        . $exception->getLine() . ': ' . addcslashes($exception->getMessage(), "\0..\37")
                    );
                    // Log this exception handler's own exception.
                    error_log(
                        get_class($xcptn) . '(' . $xcptn->getCode() . ')@' . $xcptn->getFile() . ':'
                        . $xcptn->getLine() . ': ' . addcslashes($xcptn->getMessage(), "\0..\37")
                    );
                }
                try {
                    // Even error response may need Cross Origin headers.
                    if (static::$crossOriginSiteAllowed) {
                        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
                            $response = static::crossOriginOptionsSetHeaders($request, $response);
                        } else {
                            $response = static::crossOriginSetHeaders($response);
                        }
                    }
                } catch (\Throwable $ignore) {
                }
                /**
                 * Send status 200, pass real success+status in response body.
                 *
                 * @see \SimpleComplex\Http\HttpResponseBody
                 */
                $response = $response->withHeader('Content-Type', 'application/json');
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
                } catch (\Throwable $xcptn) {
                    // Log original exception.
                    error_log(
                        get_class($exception) . '(' . $exception->getCode() . ')@' . $exception->getFile() . ':'
                        . $exception->getLine() . ': ' . addcslashes($exception->getMessage(), "\0..\37")
                    );
                    // Log this exception handler's own exception.
                    error_log(
                        get_class($xcptn) . '(' . $xcptn->getCode() . ')@' . $xcptn->getFile() . ':'
                        . $xcptn->getLine() . ': ' . addcslashes($xcptn->getMessage(), "\0..\37")
                    );
                }
                try {
                    // Even error response may need Cross Origin headers.
                    if (static::$crossOriginSiteAllowed) {
                        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
                            $response = static::crossOriginOptionsSetHeaders($request, $response);
                        } else {
                            $response = static::crossOriginSetHeaders($response);
                        }
                    }
                } catch (\Throwable $ignore) {
                }
                /**
                 * Send status 200, pass real success+status in response body.
                 *
                 * @see \SimpleComplex\Http\HttpResponseBody
                 */
                $response = $response->withHeader('Content-Type', 'application/json');
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

        // Init Slim application.
        return new /*\Slim\*/App($container);
    }
}
