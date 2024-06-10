<?php

namespace Zencart\Logger;

use Monolog\Logger as MonologLogger;

abstract class Logger
{
    protected array $options;
    protected MonologLogger $logger;

    public function __construct ($options)
    {
        $this->options = $options;
        $this->logger = new MonologLogger($this->options['channel'] . '-logger');
    }

    public function log($level, string|\Stringable $message, array $context = [])
    {
        $this->logger->log($level, $message, $context);
    }

    public  function getMonologLogger(): MonologLogger
    {
        return $this->logger;
    }
}
