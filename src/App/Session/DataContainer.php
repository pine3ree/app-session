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

use function array_fill_keys;
use function array_keys;
use function is_scalar;
use function json_decode;
use function json_encode;

use const JSON_PRESERVE_ZERO_FRACTION;

/**
 * Provides a normalized data container. It is used as lazyily instantiated
 * container in lazy session implementation and as a base class for standard
 * session implementation.
 */
class DataContainer
{
    /**
     * Current data within the session.
     *
     * @var array
     */
    private $data = [];

    /**
     * Current data keys
     *
     * @var array
     */
    private $keys = [];

    /**
     * Original data provided to the constructor.
     *
     * @var array
     */
    private $originalData = [];

    public function __construct(array $data = null)
    {
        if (!empty($data)) {
            $this->data = $this->originalData = $data;
            $this->keys = array_fill_keys(array_keys($data), true);
        }
    }

    public function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key) : bool
    {
        return isset($this->keys[$key]);
    }

    /**
     * @param mixed $value
     */
    public function set(string $key, $value) : void
    {
        $this->data[$key]
            = is_scalar($value) || null === $value
            ? $value
            : self::extractSerializableValue($value);
        $this->keys[$key] = true;
    }

    public function unset(string $key) : void
    {
        unset($this->data[$key]);
        unset($this->keys[$key]);
    }

    public function clear() : void
    {
        $this->data = [];
        $this->keys = [];
    }

    public function toArray() : array
    {
        return $this->data;
    }

    public function hasChanged() : bool
    {
        return $this->data !== $this->originalData;
    }

    /**
     * Convert a value to a JSON-serializable value.
     *
     * This value should be used by `set()` operations to ensure that the values
     * within a session are serializable across any session adapter.
     *
     * @param mixed $value
     * @return null|bool|int|float|string|array|\stdClass
     */
    protected static function extractSerializableValue($value)
    {
        return json_decode(json_encode($value, JSON_PRESERVE_ZERO_FRACTION), true);
    }
}
