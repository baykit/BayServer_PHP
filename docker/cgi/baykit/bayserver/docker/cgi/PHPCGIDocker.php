<?php

namespace baykit\bayserver\docker\cgi;


use baykit\bayserver\BayLog;
use baykit\bayserver\bcf\BcfElement;
use baykit\bayserver\docker\base\SecurePort;
use baykit\bayserver\docker\base\SecurePortDockerHelper;
use baykit\bayserver\docker\Docker;
use baykit\bayserver\docker\http\h1\H1InboundHandler_InboundProtocolHandlerFactory;
use baykit\bayserver\util\CGIUtil;


class PHPCGIDocker extends CGIDocker
{
    const ENV_PHP_SELF = "PHP_SELF";
    const ENV_REDIRECT_STATUS = "REDIRECT_STATUS";

    //////////////////////////////////////////////////////
    // Implements Docker
    //////////////////////////////////////////////////////

    public function init(BcfElement $elm, ?Docker $parent): void
    {
        parent::init($elm, $parent);

        if ($this->interpreter == null)
            $this->interpreter = "php-cgi";

        BayLog::info("PHP interpreter: " . $this->interpreter);
    }


    //////////////////////////////////////////////////////
    // Override CGIDocker
    //////////////////////////////////////////////////////

    public function createCommand(array &$env) : string
    {
        $env[PHPCGIDocker::ENV_PHP_SELF] = $env[CGIUtil::SCRIPT_NAME];
        $env[PHPCGIDocker::ENV_REDIRECT_STATUS] = "200";
        return parent::createCommand($env);
    }
}

