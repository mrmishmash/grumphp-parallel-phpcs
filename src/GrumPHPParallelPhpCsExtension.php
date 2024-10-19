<?php

declare(strict_types=1);

namespace Mishmash\GrumPHPParallelPhpCs;

use GrumPHP\Extension\ExtensionInterface;

/**
 * Extension loader.
 */
class GrumPHPParallelPhpCsExtension implements ExtensionInterface {

  /**
   * @return iterable
   */
  public function imports(): iterable {
    $configDirectory = dirname(__DIR__) . '/config';

    yield $configDirectory . '/services.yml';
  }

}