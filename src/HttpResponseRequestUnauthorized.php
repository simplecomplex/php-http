<?php
/**
 * SimpleComplex PHP Http
 * @link      https://github.com/simplecomplex/php-http
 * @copyright Copyright (c) 2017 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-http/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Http;

use SimpleComplex\Utils\Dependency;

/**
 * Prefab HttpResponse to send upon authorization (permission) failure.
 *
 * @uses-dependency-container locale, application-title
 *
 * @package SimpleComplex\Http
 */
class HttpResponseRequestUnauthorized extends HttpResponseRequestUnacceptable
{
    /**
     * @param int $code
     * @param int $status
     * @param string[] $headers
     * @param string[] $messages
     *      Non-user-friendly messages for a header.
     */
    public function __construct(
        int $code = 0,
        int $status = 0,
        array $headers = [],
        array $messages = []
    ) {
        $container = Dependency::container();
        /** @var HttpSettings $http_settings */
        $http_settings = $container->get('http-settings');
        $final_code = $code ? $code : HttpService::ERROR_CODES['unauthorized']
            + $http_settings->service('error_code_offset');
        $final_status = $status ? $status : $http_settings->serviceStatusCode('unauthorized');
        if ($messages) {
            $headers['X-Http-Request-Unathorized'] = str_replace(
                [
                    ':',
                    '[',
                    ']',
                ],
                ' ',
                join(' ', $messages)
            );
        } else {
            $headers['X-Http-Request-Unathorized'] = '1';
        }
        $container = Dependency::container();
        /** @var \SimpleComplex\Locale\AbstractLocale $locale */
        $locale = $container->get('locale');
        $replacers = [
            'error' => $final_code . ':http:unauthorized',
            'application-title' => $container->get('application-title'),
        ];
        $body = new HttpResponseBody(
            false,
            $final_status,
            null,
            // No error message suffix.
            $locale->text('http-service:error:unauthorized', $replacers),
            $final_code
        );

        parent::__construct(
            $final_code,
            $final_status,
            $headers,
            $body
        );
    }
}
