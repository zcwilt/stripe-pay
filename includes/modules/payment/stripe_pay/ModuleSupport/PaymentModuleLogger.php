<?php

namespace Zencart\ModuleSupport;

class PaymentModuleLogger extends ModuleLoggerAbstract
{
    public function __construct($loggerCode, $debugMode = false)
    {
        parent::__construct('payment', $loggerCode, $debugMode);
    }
}
