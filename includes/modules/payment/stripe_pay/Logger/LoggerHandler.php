<?php

namespace Zencart\Logger;

use http\Exception\InvalidArgumentException;

class LoggerHandler
{
    protected array $options = [];

    public function __construct(array $commonOptions)
    {
        if (empty($commonOptions)) {
            throw new \Exception('Common options for LoggerHandler not set');
        }
        if (!is_array($commonOptions)) {
            throw new \Exception('Common options for LoggerHandler must be an array');
        }
        if (!isset($commonOptions['channel'])) {
            throw new \Exception('Common options for LoggerHandler must contain a channel');
        }
        if (!isset($commonOptions['prefix'])) {
            throw new \Exception('Common options for LoggerHandler must contain a prefix');
        }
        $this->options = $commonOptions;
    }
}
