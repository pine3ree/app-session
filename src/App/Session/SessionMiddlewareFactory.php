<?php
/**
 * based on:
 *
 * @see       https://github.com/zendframework/zend-expressive-session for the canonical source repository
 * @copyright Copyright (c) 2017 Zend Technologies USA Inc. (https://www.zend.com)
 * @license   https://github.com/zendframework/zend-expressive-session/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace App\Session;

use Psr\Container\ContainerInterface;

use App\Session\SessionMiddleware;
use App\Session\SessionPersistenceInterface;

class SessionMiddlewareFactory
{
    public function __invoke(ContainerInterface $container) : SessionMiddleware
    {
        return new SessionMiddleware(
            $container->get(SessionPersistenceInterface::class)
        );
    }
}
