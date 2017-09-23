<?php
/**
 * SimpleComplex PHP Http
 * @link      https://github.com/simplecomplex/php-http
 * @copyright Copyright (c) 2017 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-http/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Http;

use SimpleComplex\Utils\Utils;

/**
 * Http response.
 *
 * @package SimpleComplex\Http
 */
class HttpResponse
{
    /**
     * Status to be sent to requestor.
     *
     * @var int
     */
    public $status = 500;

    /**
     * Headers to be sent to requestor.
     *
     * @var array
     */
    public $headers = [];

    /**
     * Body to be sent to requestor.
     *
     * @var \SimpleComplex\Http\HttpResponseBody
     */
    public $body;

    /**
     * Headers received from remote service.
     *
     * NB: Do NOT send these to requestor.
     * Set to null (or unset) if sending the whole response object to requestor.
     *
     * @var array
     */
    public $originalHeaders = [];

    /**
     * Validated against rule set; in effect valdated against contract
     * with remote service.
     *
     * @var bool|null
     *      Null: the response has not been validated at all.
     *      Boolean: passed/failed validation.
     */
    public $validated = null;

    /**
     * @param int $status
     * @param array $headers
     * @param HttpResponseBody $body
     * @param array $originalHeaders
     *      Default: empty.
     */
    public function __construct(int $status, array $headers, HttpResponseBody $body, array $originalHeaders = [])
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
        $this->originalHeaders = $originalHeaders;
    }

    /**
     * @return string
     */
    public function __toString() : string
    {
        return json_encode($this, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * Cast object or array to instance of this class.
     *
     * @param object|array $subject
     *
     * @throws \TypeError
     *      Arg subject is not object|array.
     *      Arg subject is object whose class isn't a parent of this class.
     */
    public static function cast(&$subject) /*:void*/
    {
        if (is_object($subject)) {
            $from_class_name = get_class($subject);
            if ($from_class_name != \stdClass::class && !in_array($from_class_name, class_parents(static::class))) {
                throw new \TypeError(
                    'Can\'t cast arg subject, class[' . $from_class_name
                    . '] is not parent class of this class[' . static::class . '].'
                );
            }
            $source_props = get_object_vars($subject);
        } elseif (!is_array($subject)) {
            throw new \TypeError(
                'Arg subject type[' . Utils::getType($subject) . '] is not object or array.'
            );
        } else {
            // Copy.
            $source_props = $subject;
        }
        if (isset($source_props['body'])) {
            $body = $source_props['body'];
            Utils::cast($body, HttpResponseBody::class);
        } else {
            $body = new HttpResponseBody();
        }
        $subject = new static(
            $source_props['status'] ?? 500,
            isset($source_props['headers']) ? (array) $source_props['headers'] : [],
            $body,
            isset($source_props['originalHeaders']) ? (array) $source_props['originalHeaders'] : []
        );
    }
}
