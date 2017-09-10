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
 * Prefab HttpResponse to send when a request is unacceptable.
 *
 * @uses-dependency-container locale, application-title
 *
 * @package KkSeb\Http
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
        int $status = HttpService::STATUS_CODE['request-unacceptable'],
        array $headers = [],
        $body = null
    ) {
        $final_code = $code ? $code : HttpService::ERROR_CODES['request-unacceptable'] + HttpService::ERROR_CODE_OFFSET;
        $final_status = $status ? $status : HttpService::STATUS_CODE['request-unacceptable'];

        if (!$headers) {
            $headers['X-Kk-Seb-Http-Request-Unacceptable'] = '1';
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
                . '\n' . $locale->text('common:error-suffix_user-report-error', $replacers),
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
