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
 * Prefab HttpResponse to send upon request argument validation failure.
 *
 * @package KkSeb\Http
 */
class HttpResponseRequestInvalid extends HttpResponse
{
    /**
     * @param int $status
     * @param array $headers
     * @param HttpResponseBody|null $body
     */
    public function __construct(
        int $status = HttpService::HTTP_STATUS_REQUEST_VALIDATION,
        array $headers = [],
        $body = null
    ) {
        $code = HttpService::ERROR_CODES['request-validation'] + HttpService::ERROR_CODE_OFFSET;
        if (!$body) {
            /** @var \SimpleComplex\Locale\AbstractLocale $locale */
            $locale = Dependency::container()->get('locale');
            $replacers = [
                'error' => $code. ':http:request-validation',
                'app-title' => $locale->text('http:app-title'),
            ];
            $body = new HttpResponseBody(
                false,
                $status ? $status : HttpService::HTTP_STATUS_REQUEST_VALIDATION,
                null,
                $locale->text('http-service:error:request-validation', $replacers)
                . '\n' . $locale->text('http:error-suffix_user-report-error', $replacers),
                $code
            );
        }
        parent::__construct(
            $status ? $status : HttpService::HTTP_STATUS_REQUEST_VALIDATION,
            $headers,
            $body
        );
    }
}
