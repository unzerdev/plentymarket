<?php

namespace UnzerPayment\Traits;

use Plenty\Plugin\Log\Loggable;

trait LoggingTrait
{
    use Loggable;

    public function log(string $class, string $method, string $shortId, string $msg = '', $arg = [], bool $error = false): void
    {
        $logger = $this->getLogger($class . '_' . $method . '_' . $shortId);
        if ($error) {
            $logger->error($msg, $arg);
        } else {
            if (!is_array($arg)) {
                $arg = [$arg];
            }
            $arg[] = $msg;
            $logger->debug('UnzerPayment::Logger.debugCaption', $arg);
        }
    }

    public function error(string $class, string $method, string $shortId, string $msg = '', $arg = []): void
    {
        $this->log($class, $method, $shortId, $msg, $arg, true);
    }
}