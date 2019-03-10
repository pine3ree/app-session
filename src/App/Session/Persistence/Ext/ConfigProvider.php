<?php
/**
 * @see       https://github.com/zendframework/zend-expressive-session-ext for the canonical source repository
 * @copyright Copyright (c) 2017 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   https://github.com/zendframework/zend-expressive-session-ext/blob/master/LICENSE.md New BSD License
 */

namespace App\Session\Persistence\Ext;

use App\Session\SessionPersistenceInterface;
use App\Session\Persistence\Ext\PhpSessionPersistence;
use App\Session\Persistence\Ext\PhpSessionPersistenceFactory;

class ConfigProvider
{
    public function __invoke() : array
    {
        return [
            'dependencies' => $this->getDependencies(),
            'session' => $this->getSessionConfig(),
        ];
    }

    public function getDependencies() : array
    {
        return [
            'aliases' => [
                SessionPersistenceInterface::class => PhpSessionPersistence::class,
            ],
            'factories' => [
                PhpSessionPersistence::class => PhpSessionPersistenceFactory::class,
            ],
        ];
    }

    public function getSessionConfig() : array
    {
        return [
            'persistence' => [
                'use_lazy_session' => true,
                'ext' => [
                    'non_locking' => false,
                ],
            ],
        ];
    }
}
