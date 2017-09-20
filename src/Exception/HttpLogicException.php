<?php

namespace KkBase\Http\Exception;

/**
 * To detect logic exception created by this library.
 *
 * @package KkBase\Http
 */
class HttpLogicException extends \LogicException implements HttpException
{
}
