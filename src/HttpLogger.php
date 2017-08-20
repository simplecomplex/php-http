<?php
/**
 * KIT/Koncernservice, Københavns Kommune.
 * @link https://kkgit.kk.dk/php-psr.kk-seb/http
 * @author Jacob Friis Mathiasen <jacob.friis.mathiasen@ks.kk.dk>
 */
declare(strict_types=1);

namespace KkSeb\Http;

use SimpleComplex\Utils\Utils;
use SimpleComplex\Utils\Dependency;
use KkSeb\Http\Exception\HttpResponseValidationException;

/**
 * Non a PSR logger, but uses such.
 *
 * @internal
 *
 * @package KkSeb\Http
 */
class HttpLogger
{
    /**
     * @var string
     */
    public $type;

    /**
     * @var string
     */
    public $operation;

    /**
     * @param string $type
     * @param string $operation
     */
    public function __construct(string $type, string $operation)
    {
        $this->type = $type;
        $this->operation = $operation;
    }

    /**
     * @param int $severity
     * @param string $preface
     * @param \Throwable|null $exception
     * @param array $variables
     */
    public function log(int $severity, string $preface, /*?\Throwable*/ $exception = null, $variables = [])
    {
        $container = Dependency::container();
        /** @var \SimpleComplex\Inspect\Inspect $inspect */
        $inspect = $container->get('inspect');

        $context = [
            'type' => $this->type,
            'subType' => $this->type,
        ];
        $msg = $preface . ' ' . $this->operation;

        if ($exception) {
            $code = $exception->getCode();
            $context['code'] = $code;
            $context['exception'] = $exception;
        }
        // Validation failure: log validation record before exception.
        if ($exception && $exception instanceof HttpResponseValidationException && $variables) {
            $msg .= "\n" . 'Discrepancies recorded vs. rule set(s):';
            foreach ($variables as $variant => $record) {
                $msg .= "\n· " . $variant . ":\n    " . join("\n", $record);
            }
            $msg .= "\n" . $inspect->trace($exception, ['wrappers' => 1]);
        } else {
            if ($exception) {
                $msg .= "\n" . $inspect->trace($exception, ['wrappers' => 1]);
            }
            if ($variables) {
                $msg .= (!$exception ? "\n" : "\nVariables:\n")
                    . $inspect->variable($variables, ['wrappers' => 1])->toString(!!$exception);
            }
        }

        $container->get('logger')->log(
            Utils::getInstance()->logLevelToString($severity),
            $msg,
            $context
        );
    }
}
