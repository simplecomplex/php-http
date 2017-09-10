<?php
/**
 * KIT/Koncernservice, Københavns Kommune.
 * @link https://kkgit.kk.dk/php-psr.kk-seb/http
 * @author Jacob Friis Mathiasen <jacob.friis.mathiasen@ks.kk.dk>
 */
declare(strict_types=1);

namespace KkSeb\Http;

use SimpleComplex\Utils\Dependency;

/**
 * Prefab HttpResponse to send upon (custom) response validation failure.
 *
 * Not used by HttpClient (HttpRequest).
 *
 * @uses-dependency-container locale, application-title
 *
 * @package KkSeb\Http
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
        $final_code = $code ? $code : HttpClient::ERROR_CODES['response-validation'] + HttpClient::ERROR_CODE_OFFSET;
        $headers['X-Kk-Seb-Http-Final-Status'] = $final_status = $status ? $status : 502;
        if ($messages) {
            $headers['X-Kk-Seb-Http-Response-Invalid'] = str_replace(
                [
                    ':',
                    '[',
                    ']',
                ],
                ' ',
                join(' ', $messages)
            );
        } else {
            $headers['X-Kk-Seb-Http-Response-Invalid'] = '1';
        }
        $container = Dependency::container();
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
                . '\n' . $locale->text('common:error-suffix_user-report-error', $replacers),
                $final_code
            )
        );
    }
}