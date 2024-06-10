<?php
namespace Zencart\Logger;

interface LoggerHandlerContract
{
    public  function setup($logger): void;
}
