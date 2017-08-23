<?php
/**
 * KIT/Koncernservice, Københavns Kommune.
 * @link https://kkgit.kk.dk/php-psr.kk-seb/http
 * @author Jacob Friis Mathiasen <jacob.friis.mathiasen@ks.kk.dk>
 */
declare(strict_types=1);

namespace KkSeb\Http;

use Slim\Http\Response;

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
     * Circumvent CORS (cross origin resource sharing) check in development.
     * Angular serves from other host in development;
     * typically http://localhost:4200 (ng serve|npm start).
     *
     * Set as comma-separated list (no spaces) in file
     * [document root]/.access_control_allow_origin
     *
     * @see HttpService::crossOriginSitesAllowed()
     *
     * @param \Slim\Http\Response $response
     * @param string $allowedSites
     *      Comma-separated, no spaces.
     *
     * @return \Slim\Http\Response
     */
    public static function setCrossOriginHeaders(Response $response, string $allowedSites) : Response
    {
        if ($allowedSites) {
            // Allow requestor to see all relevant response headers;
            // headers already set plus Content-Length (which Slim sets
            // before returning response to requestor.
            $response = $response->withHeader(
                'Access-Control-Expose-Headers',
                join(',', array_keys($response->getHeaders())) . ',Content-Length'
            )
                // Allow CORS for sites.
                ->withHeader('Access-Control-Allow-Origin', $allowedSites);
        }
        return $response;
    }

    /**
     * Append headers to Cross Origin exposable headers header.
     *
     * @param \Slim\Http\Response $response
     * @param array $headers
     *
     * @return Response
     */
    public static function appendCrossOriginExposedHeaders(Response $response, array $headers) : Response {
        if ($headers && $response->hasHeader('Access-Control-Expose-Headers')) {
            // withHeader() overrides previously set headers by that name.
            $response = $response->withHeader(
                'Access-Control-Expose-Headers',
                join(
                    ',',
                    array_unique(
                        array_merge(
                            explode(',', $response->getHeader('Access-Control-Expose-Headers')[0]),
                            array_keys($headers)
                        )
                    )
                )
            );
        }
        return $response;
    }
}
