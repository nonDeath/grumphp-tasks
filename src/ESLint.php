<?php
/**
 * @copyright (c) 2006-2017 Stickee Technology Limited
 */

namespace Stickee\GrumPHP;

use GrumPHP\Collection\FilesCollection;
use GrumPHP\Collection\ProcessArgumentsCollection;
use GrumPHP\Runner\TaskResult;
use GrumPHP\Runner\TaskResultInterface;
use GrumPHP\Task\AbstractExternalTask;
use GrumPHP\Task\Context\ContextInterface;
use GrumPHP\Task\Context\GitPreCommitContext;
use GrumPHP\Task\Context\RunContext;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Class ESLint
 *
 * @package Stickee\GrumPHP
 */
final class ESLint extends AbstractExternalTask
{
    /**
     * getConfigurableOptions
     *
     * @return OptionsResolver
     */
    public static function getConfigurableOptions() : OptionsResolver
    {
        $resolver = new OptionsResolver();
        $resolver->setDefaults(
            [
                'config' => null,
                'debug' => false,
            ]
        );

        $resolver->addAllowedTypes('config', ['null', 'string']);
        $resolver->addAllowedTypes('debug', ['bool']);

        return $resolver;
    }

    /**
     * This methods specifies if a task can run in a specific context.
     *
     * @param ContextInterface $context
     *
     * @return bool
     */
    public function canRunInContext(ContextInterface $context) : bool
    {
        return ($context instanceof GitPreCommitContext || $context instanceof RunContext);
    }

    private function searchBin() : ProcessArgumentsCollection
    {
        // Search executable:
        $this->executableFinder = new ExecutableFinder();
        $executable = $this->executableFinder->find('eslint', null, ['./node_modules/.bin']);
        if (!$executable) {
            throw new RuntimeException(
                sprintf('The executable for "%s" could not be found.', $command)
            );
        }

        return ProcessArgumentsCollection::forExecutable($executable);
    }

    /**
     * @param ContextInterface $context
     *
     * @return TaskResultInterface
     */
    public function run(ContextInterface $context) : TaskResultInterface
    {
        $files = $context->getFiles()->names(['*.js', '*.vue']);
        if (0 === count($files)) {
            return TaskResult::createSkipped($this, $context);
        }

        $config = $this->getConfig()->getOptions();

        $arguments = $this->searchBin();
        $arguments->add('--format=table');
        $arguments->addOptionalArgument('--config %s', $config['config']);
        $arguments->addOptionalArgument('--debug', $config['debug']);

        if ($context instanceof RunContext && $config['config'] !== null) {
            return $this->runOnAllFiles($context, $arguments);
        }

        return $this->runOnChangedFiles($context, $arguments, $files);
    }

    /**
     * @param ContextInterface $context
     * @param ProcessArgumentsCollection $arguments
     * @param FilesCollection $files
     *
     * @return TaskResult
     */
    private function runOnChangedFiles(
        ContextInterface $context,
        ProcessArgumentsCollection $arguments,
        FilesCollection $files
    ) {
        $hasErrors = false;
        $messages = [];

        foreach ($files as $file) {
            $fileArguments = new ProcessArgumentsCollection($arguments->getValues());
            $fileArguments->add($file);
            $process = $this->processBuilder->buildProcess($fileArguments);
            $process->run();

            if (!$process->isSuccessful()) {
                $hasErrors = true;
                $messages[] = $this->formatter->format($process);
            }
        }

        if ($hasErrors) {
            $errorMessage = sprintf("You have ESLint Errors:\n\n%s", implode("\n", $messages));

            return TaskResult::createFailed($this, $context, $errorMessage);
        }

        return TaskResult::createPassed($this, $context);
    }

    /**
     * @param ContextInterface $context
     * @param ProcessArgumentsCollection $arguments
     *
     * @return TaskResult
     */
    private function runOnAllFiles(ContextInterface $context, ProcessArgumentsCollection $arguments)
    {
        $process = $this->processBuilder->buildProcess($arguments);
        $process->run();

        if (!$process->isSuccessful()) {
            $errorMessage = $this->formatter->format($process);

            return TaskResult::createFailed($this, $context, $errorMessage);
        }

        return TaskResult::createPassed($this, $context);
    }
}
