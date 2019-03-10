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
use JsonSerializable;

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
abstract class AbstractSession implements SessionInterface, JsonSerializable
{
    /**
     * The session identifier.
     *
     * @var string
     */
    protected $id = '';

    /**
     * Indicates if this instance represents is a new client session
     *
     * @var bool
     */
    protected $isNew;

    /**
     * A custom persistence lifetime in seconds.
     * A null value means no expiry change has been requested.
     *
     * @var null|int
     */
    protected $lifetime;

    /**
     * @var bool
     */
    protected $isRegenerated = false;

    /**
     * The session data container
     *
     * @var null|SessionContainer
     */
    protected $data;

    public function getId() : string
    {
        return $this->id;
    }

    public function isNew() : bool
    {
        return $this->isNew;
    }

    public function get(string $name, $default = null)
    {
        return $this->data()->get($name, $default);
    }

    public function has(string $name) : bool
    {
        return $this->data()->has($name);
    }

    public function set(string $name, $value) : void
    {
        $this->data()->set($name, $value);
    }

    public function unset(string $name) : void
    {
        $this->data()->unset($name);
    }

    public function clear() : void
    {
        $this->data()->clear();
    }

    public function toArray() : array
    {
        return $this->data()->toArray();
    }

    public function hasChanged() : bool
    {
        // Not accessed thus unchanged
        if (null === $this->data) {
            return false;
        }

        return $this->data->hasChanged();
    }

    public function regenerate()
    {
       $this->isRegenerated = true;
    }

    public function isRegenerated() : bool
    {
        return $this->isRegenerated;
    }

    public function persistFor(int $lifetime) : void
    {
        $this->lifetime = $lifetime;
    }

    public function getLifetime() : ?int
    {
        return $this->lifetime;
    }

    /**
     * Access the wrapped data container, lazily creating it on first call
     */
    abstract protected function data() : SessionContainer;

    public function jsonSerialize()
    {
        return $this->toArray();
    }

    /**
     * Just a debug-mode temporary method to be removed in final release
     *
     * @deprecated since the beginning!
     */
    public function getInfo() : array
    {
        return [
            'id'            => $this->id,
            'isNew'         => $this->isNew,
            'isRegenerated' => $this->isRegenerated,
            'lifetime'      => $this->lifetime,
            'data'          => $this->toArray(),
        ];
    }
}
