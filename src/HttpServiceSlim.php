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
class HttpServiceSlim
{
    /**
     * Add Cross Origin headers, preferably only in development.
     *
     * Circumvent CORS (cross origin resource sharing) check in development.
     * Angular serves from other host in development;
     * typically http://localhost:4200 (ng serve|npm start).
     *
     * See ini configuration files:
     * - vendor/kk-seb/http/config-ini/http.dev.override.ini
     * - vendor/kk-seb/http/config-ini/http.prod.override.ini
     *
     * @param \Slim\Http\Response $response
     * @param array|null $allowedSites
     *
     * @return \Slim\Http\Response
     */
    public static function addCrossOriginHeaders(Response $response, $allowedSites) : Response
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
                ->withHeader('Access-Control-Allow-Origin', join(',', $allowedSites));
        }
        return $response;
    }
}
