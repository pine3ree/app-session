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

use Closure;
use App\Session\SessionInterface;

/**
 * Proxy data access and manipultaion to an underlying session data container
 * implementation.
 *
 * In order to delay parsing of session data until it is accessed, use this
 * class. It will call the composed data container factory provided by the
 * persistence layer only on access to any of the various session data methods;
 * otherwise, the session file will not be accessed, and, in most cases, opened
 * or written to.
 *
 * A session identifier will still be created by the persistence layer and sent
 * as a response cookie if none was provided by the server request or if the
 * session is regenerated (marked for regeneration). If a value is provided for
 * the session lifetime, by calling `persistFor`, a response session cookie with
 * the same session id value and with the new expiration time information will
 * be sent to the client.
 *
 */
final class LazySession extends AbstractSession implements SessionInterface
{
     /**
     * A data container factory
     *
     * @var Closure
     */
    private $dataFactory;

    /**
     * @param string $id The session identifier
     * @param bool $isNew Is this session a new session?
     * @param Closure $dataFactory A callable facory for session container instantiation
     */
    public function __construct(string $id, bool $isNew, Closure $dataFactory)
    {
        $this->id = $id;
        $this->isNew = $isNew;
        $this->dataFactory = $dataFactory;
    }
    /**
     * Access the wrapped data container, lazily creating it on first call
     */
    protected function data() : SessionContainer
    {
        if ($this->data === null) {
            $this->data = ($this->dataFactory)();
        }

        return $this->data;
    }
}
