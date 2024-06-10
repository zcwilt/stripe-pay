<?php

namespace Zencart\ModuleSupport;

use Monolog\Handler\BrowserConsoleHandler;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

abstract class ModuleLoggerAbstract
{
     protected array $loggers = [];
     protected string $defaultLogger;
     protected bool $debugMode;
     public function __construct(string $loggerType, string $loggerPrefix, bool $debugMode)
     {
         $this->debugMode = $debugMode;
         $debugLogFile = $this->buildLogFileName($loggerType, $loggerPrefix);
         $fileLogger = new Logger($loggerType. '-logger');
         $fileLogger->pushHandler(new StreamHandler($debugLogFile));
         $this->loggers['file-logger'] = $fileLogger;
         $consoleLogger = new Logger($loggerType. '-logger');
         $consoleLogger->pushHandler(new BrowserConsoleHandler());
         $this->loggers['console-logger'] = $consoleLogger;
         $this->defaultLogger = 'file-logger';
     }

     protected function buildLogFileName(string $loggerType, string $loggerPrefix): string
     {
         if (IS_ADMIN_FLAG === false) {
             $logfile_suffix = 'c-' . ($_SESSION['customer_id'] ?? 'na') . '-' . substr($_SESSION['customer_first_name'] ?? 'na', 0, 3) . substr($_SESSION['customer_last_name'] ?? 'na', 0, 3);
         } else {
             $logfile_suffix = 'adm-a' . ($_SESSION['admin_id'] ?? 'na');
             global $order;
             if (isset($order)) {
                 $logfile_suffix .= '-o' . $order->info['order_id'];
             }
         }
         $debugLogFile = DIR_FS_LOGS . '/' . $loggerType . '-'. $loggerPrefix . '-' . $logfile_suffix . '-' . date('Ymd') . '.log';
         return $debugLogFile;
     }

}
