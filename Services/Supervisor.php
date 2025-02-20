<?php

namespace Phobetor\RabbitMqSupervisorBundle\Services;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Phobetor\RabbitMqSupervisorBundle\Exception\ProcessException;
use Symfony\Component\Process\Process;

class Supervisor implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private string $applicationDirectory;

    private string $configurationParameter;

    private string $identifierParameter;

    private bool $waitForSupervisord = false;

    /**
     * Supervisor constructor.
     */
    public function __construct(string $applicationDirectory, string $configuration, string $identifier)
    {
        $this->applicationDirectory = $applicationDirectory;
        $this->configurationParameter = $configuration ? (' --configuration=' . $configuration) : '';
        $this->identifierParameter    = $identifier    ? (' --identifier='    . $identifier)    : '';
    }

    public function setWaitForSupervisord(bool $waitForSupervisord): void {
        $this->waitForSupervisord = $waitForSupervisord;
    }

    /**
     * Execute a supervisorctl command
     *
     * @param $cmd string supervisorctl command
     * @param $failOnError bool indicate id errors should raise an exception
     */
    public function execute(string $cmd, bool $failOnError = true): Process {
        $command = $this->createSupervisorControlCommand($cmd);
        $this->logger->debug('Executing: ' . $command);
        $p = $this->getProcess($command);
        $p->setWorkingDirectory($this->applicationDirectory);
        $p->run();
        if ($failOnError) {
            if ($p->getExitCode() !== 0) {
                $this->logger->critical(sprintf('supervisorctl returns code: %s', $p->getExitCodeText()));
            }
            $this->logger->debug('supervisorctl output: '. $p->getOutput());

            if ($p->getExitCode() !== 0) {
                throw new ProcessException($p);
            }
        }

        return $p;
    }

    private function createSupervisorControlCommand($cmd): string {
        return sprintf(
            'supervisorctl%1$s %2$s',
            $this->configurationParameter,
            $cmd
        );
    }

    /**
     * Update configuration and processes
     */
    public function runAndReload(): void {
        // start supervisor and reload configuration
        $commands = [];
        $commands[] = sprintf(' && %s', $this->createSupervisorControlCommand('reread'));
        $commands[] = sprintf(' && %s', $this->createSupervisorControlCommand('update'));
        $this->run(implode('', $commands));
    }

    /**
     * Start supervisord if not already running
     *
     * @param $followingCommand string command to execute after supervisord was started
     */
    public function run(string $followingCommand = ''): void {
        $result = $this->execute('status', false)->getOutput();
        if (strpos($result, 'sock no such file') || strpos($result, 'refused connection')) {
            $command = sprintf(
                'supervisord%1$s%2$s%3$s',
                $this->configurationParameter,
                $this->identifierParameter,
                $followingCommand
            );
            $this->logger->debug('Executing: ' . $command);
            $p = $this->getProcess($command);
            $p->setWorkingDirectory($this->applicationDirectory);
            if (!$this->waitForSupervisord) {
                $p->start();
            } else {
                $p->run();
                if ($p->getExitCode() !== 0) {
                    $this->logger->critical(sprintf('supervisord returns code: %s', $p->getExitCodeText()));
                    throw new ProcessException($p);
                }
                $this->logger->debug('supervisord output: '. $p->getOutput());
            }
        }
    }

    private function getProcess(string $command): Process {
        return Process::fromShellCommandline($command);
    }
}
