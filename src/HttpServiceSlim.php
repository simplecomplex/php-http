<?php
/**
 * KIT/Koncernservice, Københavns Kommune.
 * @link https://kkgit.kk.dk/php-psr.kk-seb/http
 * @author Jacob Friis Mathiasen <jacob.friis.mathiasen@ks.kk.dk>
 */
declare(strict_types=1);

namespace KkSeb\Http;

use Slim\Http\Request;
use Slim\Http\Response;
use SimpleComplex\RestMini\Client as RestMiniClient;

/**
 * Slim Http service utils.
 *
 * @package KkSeb\Http
 */
class HttpServiceSlim extends HttpService
{
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
                    join(', ', RestMiniClient::METHODS_SUPPORTED)
                )->withHeader(
                    'Access-Control-Allow-Headers',
                    // Browser sends Access-Control-Request-Headers lowercased,
                    // and apparantly also understands response
                    // Access-Control-Allow-Headers lowercased.
                    join(', ', $request->getHeader('Access-Control-Request-Headers'))
                )->withHeader(
                    'Access-Control-Max-Age',
                    // @todo: that setting shan't sit on that class.
                    '' . HttpRequest::CACHEABLE_TIME_TO_LIVE
                );
            }
        }
        return $response;
    }
}
