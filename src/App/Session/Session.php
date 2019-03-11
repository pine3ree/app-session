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

use InvalidArgumentException;
use App\Session\AbstractSession;
use App\Session\SessionContainer;
use App\Session\SessionInterface;

use function get_class;
use function gettype;
use function is_array;
use function is_object;

class Session extends AbstractSession implements SessionInterface
{
    /**
     * @param string $id The session identifier
     * @param bool $isNew Is this session a new session?
     * @param array|SessionContainer $data Initial session data, wrapped or to
     *      be wrapped inside a container
     */
    public function __construct(string $id, bool $isNew, $data)
    {
        $this->id = $id;
        $this->isNew = $isNew;
        if ($data instanceof SessionContainer) {
            $this->data = $data;
        } elseif (is_array($data)) {
            $this->data = new SessionContainer($data);
        } else {
            throw InvalidArgumentException(sprintf(
                "The data argument must be either a SessionContainer instance"
                . " or an array of initial session data, `%s` given!",
                is_object($data) ? get_class($data) : gettype($data)
            ));
        }
    }

    protected function data(): SessionContainer
    {
        return $this->data;
    }
}
