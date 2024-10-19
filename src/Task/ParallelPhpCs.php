<?php

declare (strict_types=1);

namespace Mishmash\GrumPHPParallelPhpCs\Task;

use GrumPHP\Collection\ProcessArgumentsCollection;
use GrumPHP\Exception\ExecutableNotFoundException;
use GrumPHP\Fixer\Provider\FixableProcessResultProvider;
use GrumPHP\Formatter\ProcessFormatterInterface;
use GrumPHP\Process\TmpFileUsingProcessRunner;
use GrumPHP\Runner\TaskResult;
use GrumPHP\Task\AbstractExternalTask;
use GrumPHP\Task\Config\ConfigOptionsResolver;
use GrumPHP\Task\Context\ContextInterface;
use GrumPHP\Task\Context\GitPreCommitContext;
use GrumPHP\Task\Context\RunContext;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use GrumPHP\Runner\TaskResultInterface;

/**
 * Same as 'Phpcs' task in GrumPHP just that it adds parallel option.
 *
 * @extends AbstractExternalTask<ProcessFormatterInterface>
 */
final class ParallelPhpCs extends AbstractExternalTask {

  protected $formatter;

  /**
   * @return \GrumPHP\Task\Config\ConfigOptionsResolver
   */
  public static function getConfigurableOptions(): ConfigOptionsResolver {
    $resolver = new OptionsResolver();
    $resolver->setDefaults(
      [
        'standard' => [],
        'tab_width' => null,
        'encoding' => null,
        'whitelist_patterns' => [],
        'ignore_patterns' => [],
        'sniffs' => [],
        'severity' => null,
        'error_severity' => null,
        'warning_severity' => null,
        'triggered_by' => ['php'],
        'report' => 'full',
        'report_width' => null,
        'exclude' => [],
        'show_sniffs_error_path' => \true,
        'parallel' => 1,
      ]
    );

    $resolver->addAllowedTypes('standard', ['array', 'null', 'string']);
    $resolver->addAllowedTypes('tab_width', ['null', 'int']);
    $resolver->addAllowedTypes('encoding', ['null', 'string']);
    $resolver->addAllowedTypes('whitelist_patterns', ['array']);
    $resolver->addAllowedTypes('ignore_patterns', ['array']);
    $resolver->addAllowedTypes('sniffs', ['array']);
    $resolver->addAllowedTypes('severity', ['null', 'int']);
    $resolver->addAllowedTypes('error_severity', ['null', 'int']);
    $resolver->addAllowedTypes('warning_severity', ['null', 'int']);
    $resolver->addAllowedTypes('triggered_by', ['array']);
    $resolver->addAllowedTypes('report', ['null', 'string']);
    $resolver->addAllowedTypes('report_width', ['null', 'int']);
    $resolver->addAllowedTypes('exclude', ['array']);
    $resolver->addAllowedTypes('show_sniffs_error_path', ['bool']);
    $resolver->addAllowedTypes('parallel', ['int', 'string']);

    return ConfigOptionsResolver::fromClosure(
      static fn(array $options): array => $resolver->resolve($options)
    );
  }

  public function canRunInContext(ContextInterface $context): bool {
    return $context instanceof GitPreCommitContext || $context instanceof RunContext;
  }

  public function run(ContextInterface $context): TaskResultInterface {
    $config = $this->getConfig()->getOptions();

    $files = $context->getFiles()->extensions($config['triggered_by'])->paths($config['whitelist_patterns'] ?? [])->notPaths($config['ignore_patterns'] ?? []);

    if (0 === \count($files)) {
      return TaskResult::createSkipped($this, $context);
    }

    $process = TmpFileUsingProcessRunner::run(function (string $tmpFile) use ($config): mixed {
      $parallelValue = $this->getParallelValue($config['parallel']);

      $arguments = $this->processBuilder->createArgumentsForCommand('phpcs');
      $arguments = $this->addArgumentsFromConfig($arguments, $config);
      $arguments->add('--report-json');
      $arguments->add('--file-list=' . $tmpFile);
      $arguments->add('--parallel=' . $parallelValue);
      return $this->processBuilder->buildProcess($arguments);
    }, static function () use ($files): \Generator {
      (yield $files->toFileList());
    });

    if (!$process->isSuccessful()) {
      $failedResult = TaskResult::createFailed($this, $context, $this->formatter->format($process));

      try {
        $fixerProcess = $this->createFixerProcess($this->formatter->getSuggestedFiles());
      } catch (CommandNotFoundException|ExecutableNotFoundException $e) {
        return $failedResult->withAppendedMessage(\PHP_EOL . 'Info: phpcbf could not be found. Please consider installing it for auto-fixing.');
      }

      if ($fixerProcess) {
        return FixableProcessResultProvider::provide($failedResult, function () use ($fixerProcess): mixed {
          return $fixerProcess;
        }, [0, 1]);
      }

      return $failedResult;
    }

    return TaskResult::createPassed($this, $context);
  }

  /**
   * @param array<int, string> $suggestedFiles
   */
  private function createFixerProcess(array $suggestedFiles): mixed
  {
    if (!$suggestedFiles) {
      return null;
    }

    $arguments = $this->processBuilder->createArgumentsForCommand('phpcbf');
    $arguments = $this->addArgumentsFromConfig($arguments, $this->config->getOptions());
    $arguments->addArgumentArray('%s', $suggestedFiles);

    return $this->processBuilder->buildProcess($arguments);
  }

  /**
   * @param \GrumPHP\Collection\ProcessArgumentsCollection $arguments
   * @param array $config
   * @return \GrumPHP\Collection\ProcessArgumentsCollection
   */
  private function addArgumentsFromConfig(ProcessArgumentsCollection $arguments, array $config): ProcessArgumentsCollection {
    $parallelValue = $this->getParallelValue($config['parallel']);

    $arguments->addOptionalCommaSeparatedArgument('--standard=%s', (array)$config['standard']);
    $arguments->addOptionalCommaSeparatedArgument('--extensions=%s', (array)$config['triggered_by']);
    $arguments->addOptionalArgument('--tab-width=%s', $config['tab_width']);
    $arguments->addOptionalArgument('--encoding=%s', $config['encoding']);
    $arguments->addOptionalArgument('--report=%s', $config['report']);
    $arguments->addOptionalIntegerArgument('--report-width=%s', $config['report_width']);
    $arguments->addOptionalIntegerArgument('--severity=%s', $config['severity']);
    $arguments->addOptionalIntegerArgument('--error-severity=%s', $config['error_severity']);
    $arguments->addOptionalIntegerArgument('--warning-severity=%s', $config['warning_severity']);
    $arguments->addOptionalCommaSeparatedArgument('--sniffs=%s', $config['sniffs']);
    $arguments->addOptionalCommaSeparatedArgument('--ignore=%s', $config['ignore_patterns']);
    $arguments->addOptionalCommaSeparatedArgument('--exclude=%s', $config['exclude']);
    $arguments->addOptionalIntegerArgument('--parallel=%s', $parallelValue);

    $arguments->addOptionalArgument('-s', $config['show_sniffs_error_path']);

    return $arguments;
  }

  protected function getParallelValue(string|int $option): int {
    if (is_string($option)) {
      if ($option === 'auto') {
        return $this->getNumberOfCpuCores() ?? 1;
      } else {
        throw new \InvalidArgumentException(sprintf("When option 'parallel' is non-numeric it can only be 'auto', got '%s'", $option));
      }
    } elseif ($option < 1) {
      throw new \InvalidArgumentException(sprintf("Invalid number specified for option 'parallel'. Please provide a non-negative integer. Got %d", $option));
    }

    return (int)$option;
  }

  /**
   * @return int|null
   */
  protected function getNumberOfCpuCores(): ?int {
    try {
      if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
        $process = new Process(['wmic', 'cpu', 'get', 'NumberOfCores']);
      }
      elseif (strncasecmp(PHP_OS, 'Linux', 5) === 0 || strncasecmp(PHP_OS, 'Darwin', 6) === 0) {
        $process = new Process(['nproc']);
      } else {
        return null;
      }

      $process->run();

      if (!$process->isSuccessful()) {
        throw new ProcessFailedException($process);
      }

      // Get the output and parse it
      $output = $process->getOutput();

      if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
        // For Windows, the output has to be processed line by line
        $lines = explode("\n", trim($output));
        foreach ($lines as $line) {
          $cores = (int)trim($line);
          if ($cores > 0) {
            return $cores;
          }
        }
        return null;
      }

      // For Linux and macOS, nproc directly returns the number
      return (int)trim($output);
    } catch (\Exception) {
      return null;
    }
  }

}
