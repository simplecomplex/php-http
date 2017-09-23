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
 * Prefab HttpResponse to send upon (custom) response validation failure.
 *
 * Not used by HttpClient (HttpRequest).
 *
 * @uses-dependency-container locale, application-title
 *
 * @package SimpleComplex\Http
 */
class HttpResponseResponseInvalid extends HttpResponse
{
    /**
     * @param int $code
     * @param int $status
     * @param string[] $headers
     * @param string[] $messages
     *      Non-user-friendly messages for a header.
     */
    public function __construct(
        int $code = HttpClient::ERROR_CODES['response-validation'],
        int $status = 502,
        array $headers = [],
        array $messages = []
    ) {
        $container = Dependency::container();
        /** @var HttpSettings $http_settings */
        $http_settings = $container->get('http-settings');
        $final_code = $code ? $code : HttpClient::ERROR_CODES['response-validation']
            + $http_settings->client('error_code_offset');
        $headers['X-Http-Final-Status'] = $final_status = $status ? $status : 502;
        if ($messages) {
            $headers['X-Http-Response-Invalid'] = str_replace(
                [
                    ':',
                    '[',
                    ']',
                ],
                ' ',
                join(' ', $messages)
            );
        } else {
            $headers['X-Http-Response-Invalid'] = '1';
        }
        /** @var \SimpleComplex\Locale\AbstractLocale $locale */
        $locale = $container->get('locale');
        $replacers = [
            'error' => $final_code . ':http:response-validation',
            'application-title' => $container->get('application-title'),
        ];

        parent::__construct(
            $final_status,
            $headers,
            new HttpResponseBody(
                false,
                $final_status,
                null,
                $locale->text('http-client:error:response-validation', $replacers)
                . '\n'
                // Regressive: application-id or common or http.
                . $locale->text(
                    $container->get('application-id') . ':error-suffix_user-report-error',
                    $replacers,
                    $locale->text(
                        'common:error-suffix_user-report-error',
                        $replacers,
                        $locale->text('http:error-suffix_user-report-error', $replacers)
                    )
                ),
                $final_code
            )
        );
    }
}
