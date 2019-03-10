<?php
/**
 * based on:
 *
 * @see       https://github.com/zendframework/zend-expressive-session for the canonical source repository
 * @copyright Copyright (c) 2017 Zend Technologies USA Inc. (https://www.zend.com)
 * @license   https://github.com/zendframework/zend-expressive-session/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace App\Session\Persistence\Ext;

use Psr\Container\ContainerInterface;

use App\Session\Persistence\Ext\PhpSessionPersistence;

class PhpSessionPersistenceFactory
{
    public function __invoke(ContainerInterface $container) : PhpSessionPersistence
    {
        $config = $container->has('config') ? $container->get('config') : null;
        $config = $config['session']['persistence'] ?? null;

        return new PhpSessionPersistence(
            (bool) ($config['use_lazy_session'] ?? true), // PoC, should always be true
            (bool) ($config['ext']['non_locking'] ?? false)
        );
    }
}
