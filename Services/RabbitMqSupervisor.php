<?php

namespace Phobetor\RabbitMqSupervisorBundle\Services;

use Phobetor\RabbitMqSupervisorBundle\Helpers\ConfigurationHelper;

/**
 * @license MIT
 */
class RabbitMqSupervisor
{
    private Supervisor $supervisor;

    private array $paths;

    private array $commands;

    private array $consumers;

    private array $multipleConsumers;

    private array $batchConsumers;

    private array $rpcServers;

    private array $config;

    private string $rootDir;

    private string $environment;

    private string $sockFilePermissions;

    /**
     * Initialize Handler
     */
    public function __construct(Supervisor $supervisor, array $paths, array $commands, array $consumers, array $multipleConsumers, array $batchConsumers, array $rpcServers, array $config, string $sockFilePermissions, string $kernelRootDir, string $environment)
    {
        $this->supervisor = $supervisor;
        $this->paths = $paths;
        $this->commands = $commands;
        $this->consumers = $consumers;
        $this->multipleConsumers = $multipleConsumers;
        $this->batchConsumers = $batchConsumers;
        $this->rpcServers = $rpcServers;
        $this->config = $config;
        $this->sockFilePermissions = $sockFilePermissions;
        $this->rootDir = dirname($kernelRootDir);
        $this->environment = $environment;
    }

    public function setWaitForSupervisord(bool $waitForSupervisord): void {
        $this->supervisor->setWaitForSupervisord($waitForSupervisord);
    }

    /**
     * Build supervisor configuration
     */
    public function init(): void {
        $this->generateSupervisorConfiguration();
    }

    /**
     * Build all supervisor worker configuration files
     */
    public function build(): void {
        $this->createPathDirectories();

        if (!is_file($this->createSupervisorConfigurationFilePath())) {
            $this->generateSupervisorConfiguration();
        }

        // remove old worker configuration files
        /** @var \SplFileInfo $item */
        foreach (new \DirectoryIterator($this->paths['worker_configuration_directory']) as $item) {
            if ($item->isDir()) {
                continue;
            }

            if ('conf' !== $item->getExtension()) {
                continue;
            }

            unlink($item->getRealPath());
        }

        // generate program configuration files for all consumers
        $this->generateWorkerConfigurations(array_keys($this->consumers), $this->commands['rabbitmq_consumer']);

        // generate program configuration files for all multiple consumers
        $this->generateWorkerConfigurations(array_keys($this->multipleConsumers), $this->commands['rabbitmq_multiple_consumer']);

        // generate program configuration files for all batch consumers
        $this->generateWorkerConfigurations(array_keys($this->batchConsumers), $this->commands['rabbitmq_batch_consumer']);

        //generate program configuration files for all rpc_server consumers
        $this->generateWorkerConfigurations(array_keys($this->rpcServers), $this->commands['rabbitmq_rpc_server']);

        // start supervisord and reload configuration
        $this->supervisor->runAndReload();
    }

    /**
     * Stop, build configuration for and start supervisord
     */
    public function rebuild(): void {
        $this->stop();
        $this->build();
    }

    /**
     * Stop and start supervisord to force all processes to restart
     */
    public function restart(): void {
        $this->stop();
        $this->start();
    }

    /**
     * Stop supervisord and all processes
     */
    public function stop(): void {
        $this->kill('', true);
    }

    /**
     * Start supervisord and all processes
     */
    public function start(): void {
        $this->supervisor->run();
    }

    /**
     * Send -HUP to supervisord to gracefully restart all processes
     */
    public function hup(): void {
        $this->kill('HUP');
    }

    /**
     * Send kill signal to supervisord
     */
    public function kill(string $signal = '', bool $waitForProcessToDisappear = false): void {
        $pid = $this->getSupervisorPid();
        if (!empty($pid) && $this->isProcessRunning($pid)) {
            if (!empty($signal)) {
                $signal = sprintf('-%s', $signal);
            }

            $command = sprintf('kill %s %d', $signal, $pid);

            passthru($command);

            if ($waitForProcessToDisappear) {
                $this->wait();
            }
        }
    }

    /**
     * Wait for supervisord process to disappear
     */
    public function wait(): void {
        $pid = $this->getSupervisorPid();
        if (!empty($pid)) {
            while ($this->isProcessRunning($pid)) {
                sleep(1);
            }
        }
    }

    /**
     * Check if a process with the given pid is running
     */
    private function isProcessRunning(int $pid): bool {
        $state = array();
        exec(sprintf('ps -o pid %d', $pid), $state);

        // remove alignment spaces from PIDs
        $state = array_map('trim', $state);

        /*
         * ps will return at least one row, the column labels.
         * If the process is running ps will return a second row with its status.
         *
         * check if pid is in that list to work even if some systems ignore the pid filter parameter
         */
        return 1 < count($state) && in_array($pid, $state);
    }

    /**
     * Determines the supervisord process id
     */
    private function getSupervisorPid(): ?int {
        $pidPath = $this->paths['pid_file'];

        $pid = null;
        if (is_file($pidPath) && is_readable($pidPath)) {
            $pid = (int)file_get_contents($pidPath);
        }

        return $pid;
    }

    private function createPathDirectories(): void {
        foreach ($this->paths as $key => $path) {
            if ('php_executable' === $key) {
                continue;
            }

            if (!str_ends_with($path, '/')) {
                $path = dirname($path);
            }

            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
    }

    public function generateSupervisorConfiguration(): void {
        $configurationHelper = new ConfigurationHelper();
        $content = $configurationHelper->getConfigurationStringFromDataArray(array(
            'unix_http_server' => array(
                'file' => $this->paths['sock_file'],
                'chmod' => $this->sockFilePermissions
            ),
            'supervisord' => array(
                'logfile' => $this->paths['log_file'],
                'pidfile' => $this->paths['pid_file']
            ),
            'rpcinterface:supervisor' => array(
                'supervisor.rpcinterface_factory' => 'supervisor.rpcinterface:make_main_rpcinterface'
            ),
            'supervisorctl' => array(
                'serverurl' => sprintf('unix://%s', $this->paths['sock_file'])
            ),
            'include' => array(
                'files' => sprintf('%s*.conf', $this->paths['worker_configuration_directory'])
            ),
            'group:all' => array(
                'programs' => implode(',', array_merge(
                    array_keys($this->consumers),
                    array_keys($this->multipleConsumers),
                    array_keys($this->batchConsumers),
                    array_keys($this->rpcServers)
                ))
            )
        ));
        file_put_contents(
            $this->createSupervisorConfigurationFilePath(),
            $content
        );
    }

    private function generateWorkerConfigurations($names, $baseCommand): void {
        // try different possible console paths (realpath() will throw away the not existing ones)
        $consolePaths = [];
        foreach (['bin', 'app'] as $consoleDirectory) {
            $consolePath = sprintf('%s/%s/console', $this->rootDir, $consoleDirectory);
            if (!empty(realpath($consolePath))) {
                $consolePaths[] = $consolePath;
            }
        }

        // fall back to standard console path if none of the paths was valid
        if (empty($consolePaths)) {
            $consolePaths[] = sprintf('%s/%s/console', $this->rootDir, 'bin');
        }

        $executablePath = $consolePaths[0];

        foreach ($names as $name) {
            // override command when set in consumer configuration
            $consumerCommand = $this->getConsumerOption($name, 'command');
            if (!empty($consumerCommand)) {
                $commandName = $consumerCommand;
            } else {
                $commandName = $baseCommand;
            }

            // build flags from consumer configuration
            $flags = array();

            //rabbitmq:rpc-server does not support options below
            if ($baseCommand !== 'rabbitmq:batch:consumer') {
                $messages = $this->getConsumerOption($name, 'messages');
                if (!empty($messages)) {
                    $flags['messages'] = sprintf('--messages=%d', $messages);
                }
            }

            $debug = $this->getConsumerOption($name, 'debug');
            if (!empty($debug)) {
                $flags['debug'] = '--debug';
            }

            //rabbitmq:rpc-server does not support options below
            if ($baseCommand !== 'rabbitmq:rpc-server') {
                $memoryLimit = $this->getConsumerOption($name, 'memory-limit');
                if (!empty($memoryLimit)) {
                    $flags['memory-limit'] = sprintf('--memory-limit=%d', $memoryLimit);
                }

                $withoutSignals = $this->getConsumerOption($name, 'without-signals');
                if (!empty($withoutSignals)) {
                    $flags['without-signals'] = '--without-signals';
                }
            }

            $command = sprintf('%s %s %s', $commandName, $name, implode(' ', $flags));

            $programOptions = array(
                'command' => sprintf('%s %s %s --env=%s', $this->paths['php_executable'], $executablePath, $command, $this->environment),
                'process_name' => '%(program_name)s%(process_num)02d',
                'numprocs' => (int) $this->getConsumerWorkerOption($name, 'count'),
                'startsecs' => $this->getConsumerWorkerOption($name, 'startsecs'),
                'startretries' => $this->getConsumerWorkerOption($name, 'startretries'),
                'autorestart' => $this->transformBoolToString($this->getConsumerWorkerOption($name, 'autorestart')),
                'stopsignal' => $this->getConsumerWorkerOption($name, 'stopsignal'),
                'stopasgroup' => $this->transformBoolToString($this->getConsumerWorkerOption($name, 'stopasgroup')),
                'stopwaitsecs' => $this->getConsumerWorkerOption($name, 'stopwaitsecs'),
				'stdout_logfile' => $this->workerSpecificLogFile($this->paths['worker_output_log_file'], $name),
                'stderr_logfile' => $this->workerSpecificLogFile($this->paths['worker_error_log_file'], $name)            
            );

            if ($this->getGeneralConsumerWorkerOption('user')) {
                $programOptions['user'] = $this->getGeneralConsumerWorkerOption('user');
            }

            $this->generateWorkerConfiguration(
                $name,
                array(
                    sprintf('program:%s', $name) => $programOptions
                )
            );
        }
    }

    private function workerSpecificLogFile($originalFileName, $suffix): string {
        $extension = ".log";
        $pos = strrpos($originalFileName, $extension); 

        if ($pos === false) {
            return $originalFileName;
        }

        return substr_replace($originalFileName, "_" . $suffix, $pos, 0);
    }

    private function getConsumerOption($consumer, $key): mixed
    {
        $option = $this->getIndividualConsumerOption($consumer, $key);
        if (null !== $option) {
            return $option;
        }

        return $this->getGeneralConsumerOption($key);
    }

    private function getIndividualConsumerOption($consumer, $key): mixed
    {
        if (empty($this->config['consumer']['individual'])) {
            return null;
        }

        if (!array_key_exists($consumer, $this->config['consumer']['individual'])) {
            return null;
        }

        if (!array_key_exists($key, $this->config['consumer']['individual'][$consumer])) {
            return null;
        }

        return $this->config['consumer']['individual'][$consumer][$key];
    }

    private function getGeneralConsumerOption($key): mixed
    {
        if (!array_key_exists($key, $this->config['consumer']['general'])) {
            return null;
        }

        return $this->config['consumer']['general'][$key];
    }

    private function getConsumerWorkerOption($consumer, $key): mixed
    {
        $option = $this->getIndividualConsumerWorkerOption($consumer, $key);
        if (null !== $option) {
            return $option;
        }

        return $this->getGeneralConsumerWorkerOption($key);
    }

    private function getIndividualConsumerWorkerOption($consumer, $key): mixed
    {
        if (empty($this->config['consumer']['individual'])) {
            return null;
        }

        if (!array_key_exists($consumer, $this->config['consumer']['individual'])) {
            return null;
        }

        if (!array_key_exists('worker', $this->config['consumer']['individual'][$consumer])) {
            return null;
        }

        if (!array_key_exists($key, $this->config['consumer']['individual'][$consumer]['worker'])) {
            return null;
        }

        return $this->config['consumer']['individual'][$consumer]['worker'][$key];
    }

    private function getGeneralConsumerWorkerOption($key): mixed
    {
        if (!array_key_exists('worker', $this->config['consumer']['general'])) {
            return null;
        }

        if (!array_key_exists($key, $this->config['consumer']['general']['worker'])) {
            return null;
        }

        return $this->config['consumer']['general']['worker'][$key];
    }


    /**
     * Transform bool value to string representation.
     */
    private function transformBoolToString(bool $value): string {
        return $value ? 'true' : 'false';
    }

    /**
     * @param string $fileName file in app/supervisor dir
     */
    public function generateWorkerConfiguration(string $fileName, array $vars): void {
        $configurationHelper = new ConfigurationHelper();
        $content = $configurationHelper->getConfigurationStringFromDataArray($vars);
        file_put_contents(
            sprintf('%s%s.conf', $this->paths['worker_configuration_directory'], $fileName),
            $content
        );
    }

    private function createSupervisorConfigurationFilePath(): string
    {
        return $this->paths['configuration_file'];
    }
}
