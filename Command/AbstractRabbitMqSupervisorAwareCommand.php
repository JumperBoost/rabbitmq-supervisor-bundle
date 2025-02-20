<?php

namespace Phobetor\RabbitMqSupervisorBundle\Command;

use Phobetor\RabbitMqSupervisorBundle\Services\RabbitMqSupervisor;
use Symfony\Component\Console\Command\Command;

abstract class AbstractRabbitMqSupervisorAwareCommand extends Command
{
    protected RabbitMqSupervisor $rabbitMqSupervisor;

    public function __construct(RabbitMqSupervisor $rabbitMqSupervisor, ?string $name = null)
    {
        parent::__construct($name);
        $this->rabbitMqSupervisor = $rabbitMqSupervisor;
    }
}
