<?php

namespace Idephix;

use Idephix\Console\Application;
use Idephix\Console\Command;
use Idephix\Console\InputFactory;
use Idephix\Exception\FailedCommandException;
use Idephix\Exception\MissingMethodException;
use Idephix\Extension\MethodCollection;
use Idephix\Task\Task;
use Idephix\Task\TaskCollection;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Idephix\SSH\SshClient;
use Idephix\Extension\IdephixAwareInterface;
use Idephix\Task\Builtin\SelfUpdate;
use Idephix\Task\Builtin\InitIdxFile;

/**
 * Class Idephix
 * @method InitIdxFile initIdxFile()
 * @method SelfUpdate selfUpdate()
 */
class Idephix implements Builder, TaskExecutor
{
    const VERSION = '@package_version@';
    const RELEASE_DATE = '@release_date@';

    private $application;
    /** @var  TaskCollection */
    private $tasks;

    private $extensionsMethods;
    private $input;
    private $output;
    private $sshClient;
    private $config;
    /** @var  Context */
    protected $context;
    protected $invokerClassName;
    protected $executed = array();

    public function __construct(
        Config $config,
        OutputInterface $output = null,
        InputInterface $input = null)
    {
        $this->config = $config;
        $this->context = Context::dry($this);
        $this->tasks = TaskCollection::dry();
        $this->extensionsMethods = MethodCollection::dry();

        $this->application = new Application(
            'Idephix',
            self::VERSION,
            self::RELEASE_DATE
            );

        $this->sshClient = $config['ssh_client'];

        $this->input = $this->inputOrDefault($input);
        $this->output = $this->outputOrDefault($output);

        $this->addSelfUpdateCommand();
        $this->addInitIdxFileCommand();

        foreach ($config->extensions() as $extension) {
            $this->addExtension($extension);
        }
    }

    public static function create(TaskCollection $tasks, Config $config)
    {
        $idephix = new static($config);

        foreach ($tasks as $task) {
            $idephix->addTask($task);
        }

        return $idephix;
    }

    public function output()
    {
        return $this->output;
    }

    public function __call($name, $arguments = array())
    {
        if ($this->has($name)) {
            return call_user_func_array(array($this, 'execute'), array_merge(array($name), $arguments));
        }

        try {
            return $this->extensionsMethods->execute($name, $arguments);
        } catch (MissingMethodException $e) {
            throw new \BadMethodCallException('Call to undefined method: "' . $name . '"');
        }
    }

    public function __get($name)
    {
        if ($name === 'output' || $name === 'sshClient') {
            return $this->$name;
        }

        $trace = debug_backtrace();
        trigger_error(
            'Undefined property: '.$name.
            ' in '.$trace[0]['file'].
            ' on line '.$trace[0]['line'],
            E_USER_NOTICE
        );

        return null;
    }

    /**
     * @inheritdoc
     */
    public function addTask(Task $task)
    {
        $this->tasks[] = $task;
        $this->application->add(Command::fromTask($task, $this));

        return $this;
    }

    /**
     * @param InputInterface $input
     * @throws \Exception
     */
    protected function buildEnvironment(InputInterface $input)
    {
        $environments = $this->config->environments();

        $userDefinedEnv = $input->getParameterOption(array('--env'));

        if (false !== $userDefinedEnv && !empty($userDefinedEnv)) {
            if (!isset($environments[$userDefinedEnv])) {
                throw new \Exception(
                    sprintf(
                        'Wrong environment "%s". Available [%s]',
                        $userDefinedEnv,
                        implode(', ', array_keys($environments))
                    )
                );
            }

            $this->context = Context::dry($this)
                ->env(
                    $userDefinedEnv,
                    Dictionary::fromArray(
                        array_merge(
                            array('hosts' => array()),
                            $environments[$userDefinedEnv]
                        )
                    )
                );
        }
    }

    protected function openRemoteConnection($host)
    {
        if (!is_null($host)) {
            $this->sshClient->setParameters($this->context['ssh_params']);
            $this->sshClient->setHost($host);
            $this->sshClient->connect();
        }
    }

    protected function closeRemoteConnection()
    {
        if ($this->sshClient->isConnected()) {
            $this->sshClient->disconnect();
        }
    }

    public function getContext()
    {
        return $this->context;
    }

    public function run()
    {
        try {
            $this->buildEnvironment($this->input);
        } catch (\Exception $e) {
            $this->output->writeln('<error>'.$e->getMessage().'</error>');

            return;
        }

        $hasErrors = false;
        foreach ($this->context as $hostContext) {
            $this->context = $hostContext;
            $this->openRemoteConnection($hostContext->currentHost());
            $returnValue = $this->application->run($this->input, $this->output);
            $hasErrors = $hasErrors || !(is_null($returnValue) || ($returnValue == 0));
            $this->closeRemoteConnection();
        }

        if ($hasErrors) {
            throw new FailedCommandException();
        }
    }

    /**
     * @inheritdoc
     */
    public function addExtension(Extension $extension)
    {
        if ($extension instanceof IdephixAwareInterface) {
            $extension->setIdephix($this);
        }

        $this->extensionsMethods = $this->extensionsMethods->merge($extension->methods());

        foreach ($extension->tasks() as $task) {
            if (!$this->has($task->name())) {
                $this->addTask($task);
            }
        }
    }

    /**
     * @param $name
     * @param $extension
     * @deprecated should use addExtension instead
     */
    public function addLibrary($name, $extension)
    {
        $this->addExtension($name, $extension);
    }

    /**
     * @param string $name
     * @return bool
     */
    public function has($name)
    {
        return $this->tasks->has($name) && $this->application->has($name);
    }

    /**
     * RunTask.
     *
     * @param string $name the name of the task you want to call
     * @param (...)  arbitrary number of parameter matching the target task interface
     * @return integer
     * @deprecated should call directly tasks as Idephix methods
     */
    public function execute($name)
    {
        if (!$this->has($name)) {
            throw new \InvalidArgumentException(sprintf('The command "%s" does not exist.', $name));
        }

        $inputFactory = new InputFactory();

        return $this->application->get($name)->run(
            $inputFactory->buildFromUserArgsForTask(func_get_args(), $this->tasks->get($name)),
            $this->output
        );
    }

    /**
     * RunTask only once for multiple context
     *
     * @param string $name the name of the task you want to call
     * @param (...)  arbitrary number of parameter matching the target task interface
     * @return integer
     */
    public function executeOnce($name)
    {
        if (isset($this->executed[$name])) {
            return $this->executed[$name];
        }

        $this->executed[$name] = call_user_func_array(array($this, 'execute'), func_get_args());

        return $this->executed[$name];
    }

    public function addSelfUpdateCommand()
    {
        if ('phar:' === substr(__FILE__, 0, 5)) {
            $selfUpdate = new SelfUpdate();
            $selfUpdate->setIdephix($this);
            $this->addTask($selfUpdate);
        }
    }

    public function addInitIdxFileCommand()
    {
        $init = InitIdxFile::fromDeployRecipe();
        $init->setIdephix($this);
        $this->addTask($init);
    }

    /**
     * Execute remote command.
     *
     * @param string $cmd command
     * @param boolean $dryRun
     * @throws \Exception
     */
    public function remote($cmd, $dryRun = false)
    {
        if (!$this->sshClient->isConnected()) {
            throw new \Exception('Remote function need a valid environment. Specify --env parameter.');
        }
        $this->output->writeln('<info>Remote</info>: '.$cmd);

        if (!$dryRun && !$this->sshClient->exec($cmd)) {
            throw new \Exception('Remote command fail: '.$this->sshClient->getLastError());
        }
        $this->output->writeln($this->sshClient->getLastOutput());
    }

    /**
     * Execute local command.
     *
     * @param string $cmd Command
     * @param bool $dryRun
     * @param int $timeout
     *
     * @return string the command output
     * @throws \Exception
     */
    public function local($cmd, $dryRun = false, $timeout = 600)
    {
        $output = $this->output;
        $output->writeln("<info>Local</info>: $cmd");

        if ($dryRun) {
            return $cmd;
        }

        $process = $this->buildInvoker($cmd, null, null, null, $timeout);

        $result = $process->run(function ($type, $buffer) use ($output) {
            $output->write($buffer);
        });
        if (0 != $result) {
            throw new \Exception('Local command fail: '.$process->getErrorOutput());
        }

        return $process->getOutput();
    }

    /**
     * Set local command invoker
     * @param string $invokerClassName class name of the local command invoker
     */
    public function setInvoker($invokerClassName)
    {
        $this->invokerClassName = $invokerClassName;
    }

    /**
     * Build command invoker
     * @param string  $cmd     The command line to run
     * @param string  $cwd     The working directory
     * @param array   $env     The environment variables or null to inherit
     * @param string  $stdin   The STDIN content
     * @param integer $timeout The timeout in seconds
     * @param array   $options An array of options for proc_open
     *
     * @return string cmd output
     */
    public function buildInvoker($cmd, $cwd = null, array $env = null, $stdin = null, $timeout = 60, array $options = array())
    {
        $invoker = $this->invokerClassName ?: '\Symfony\Component\Process\Process';

        return new $invoker($cmd, $cwd, $env, $stdin, $timeout, $options);
    }

    /**
     * Get application
     *
     * @return Application
     */
    public function getApplication()
    {
        return $this->application;
    }

    protected function removeIdxCustomFileParams()
    {
        while ($argument = current($_SERVER['argv'])) {
            if ($argument == '-f' || $argument == '--file' || $argument == '-c' || $argument == '--config') {
                unset($_SERVER['argv'][key($_SERVER['argv'])]);
                unset($_SERVER['argv'][key($_SERVER['argv'])]);
                reset($_SERVER['argv']);
            }

            next($_SERVER['argv']);
        }
    }

    /**
     * @return SshClient
     */
    public function sshClient()
    {
        return $this->sshClient;
    }

    public function write($messages, $newline = false, $type = self::OUTPUT_NORMAL)
    {
        $this->output()->write($messages, $newline, $type);
    }

    public function writeln($messages, $type = self::OUTPUT_NORMAL)
    {
        $this->output()->writeln($messages, $type);
    }

    /**
     * @param OutputInterface $output
     * @return SymfonyStyle|OutputInterface
     */
    private function outputOrDefault(OutputInterface $output = null)
    {
        if (null === $output) {
            $output = new SymfonyStyle($this->input, new ConsoleOutput());
        }

        return $output;
    }

    /**
     * @param InputInterface $input
     * @return ArgvInput|InputInterface
     */
    private function inputOrDefault(InputInterface $input = null)
    {
        $this->removeIdxCustomFileParams();

        if (null === $input) {
            $input = new ArgvInput();
        }

        return $input;
    }
}
