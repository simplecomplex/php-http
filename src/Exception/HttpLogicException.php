<?php

namespace SimpleComplex\Http\Exception;

/**
 * To detect logic exception created by this library.
 *
 * @package SimpleComplex\Http
 */
class HttpLogicException extends \LogicException implements HttpException
{
}
