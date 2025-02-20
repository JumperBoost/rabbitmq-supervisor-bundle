<?php

namespace Phobetor\RabbitMqSupervisorBundle\Exception;

use Symfony\Component\Process\Process;

class ProcessException extends \RuntimeException
{
    /**
     * ProcessException constructor.
     */
    public function __construct(Process $process)
    {
        parent::__construct($process->getExitCodeText(), $process->getExitCode());
    }
}
