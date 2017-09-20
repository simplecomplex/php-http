<?php
/**
 * KIT/Koncernservice, Københavns Kommune.
 * @link https://kkgit.kk.dk/php-psr.kk-base/http
 * @author Jacob Friis Mathiasen <jacob.friis.mathiasen@ks.kk.dk>
 */
declare(strict_types=1);

namespace KkBase\Http;

use SimpleComplex\Utils\Dependency;

/**
 * Prefab HttpResponse to send upon authorization (permission) failure.
 *
 * @uses-dependency-container locale, application-title
 *
 * @package KkBase\Http
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
        int $status = HttpService::STATUS_CODE['unauthorized'],
        array $headers = [],
        array $messages = []
    ) {
        $final_code = $code ? $code : HttpService::ERROR_CODES['unauthorized'] + HttpService::ERROR_CODE_OFFSET;
        $final_status = $status ? $status : HttpService::STATUS_CODE['unauthorized'];
        if ($messages) {
            $headers['X-Kk-Base-Http-Request-Unathorized'] = str_replace(
                [
                    ':',
                    '[',
                    ']',
                ],
                ' ',
                join(' ', $messages)
            );
        } else {
            $headers['X-Kk-Base-Http-Request-Unathorized'] = '1';
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
