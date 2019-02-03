<?php
/**
 * SimpleComplex PHP Http
 * @link      https://github.com/simplecomplex/php-http
 * @copyright Copyright (c) 2017-2019 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-http/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Http;

use SimpleComplex\Utils\Dependency;

/**
 * Prefab HttpResponse to send when a request is unacceptable.
 *
 * @uses-dependency-container locale, application-title
 *
 * @package SimpleComplex\Http
 */
class HttpResponseRequestUnacceptable extends HttpResponse
{
    /**
     * @param int $code
     * @param int $status
     * @param string[] $headers
     * @param HttpResponseBody|null $body
     */
    public function __construct(
        int $code = 0,
        int $status = 0,
        array $headers = [],
        $body = null
    ) {
        $container = Dependency::container();
        /** @var HttpSettings $http_settings */
        $http_settings = $container->get('http-settings');
        $final_code = $code ? $code : HttpService::ERROR_CODES['request-unacceptable']
            + $http_settings->service('error_code_offset');
        $final_status = $status ? $status : $http_settings->serviceStatusCode('request-unacceptable');

        if (!$headers) {
            $headers['X-Http-Request-Unacceptable'] = '1';
        }

        if (!($final_body = $body)) {
            $container = Dependency::container();
            /** @var \SimpleComplex\Locale\AbstractLocale $locale */
            $locale = $container->get('locale');
            $replacers = [
                'error' => $final_code . ':http:request-validation',
                'application-title' => $container->get('application-title'),
            ];
            $final_body = new HttpResponseBody(
                false,
                $final_status,
                null,
                $locale->text('http-service:error:reject', $replacers)
                . '\n'
                // Regressive: application-id or common or http.
                . $locale->text(
                    $container->get('application-id') . ':error-suffix_user-report-error_no-log',
                    $replacers,
                    $locale->text(
                        'common:error-suffix_user-report-error_no-log',
                        $replacers,
                        $locale->text('http:error-suffix_user-report-error_no-log', $replacers)
                    )
                ),
                $final_code
            );
        }

        parent::__construct(
            $final_status,
            $headers,
            $final_body
        );
    }
}
