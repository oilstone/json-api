<?php

namespace Neomerx\JsonApi\Wrappers;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * Class Arr
 * @package Neomerx\JsonApi\Wrappers
 */
class Arr implements ArrayAccess, Countable, JsonSerializable, IteratorAggregate
{
    /**
     * @var string
     */
    protected $type;

    /**
     * @var array
     */
    protected $data;

    /**
     * @var int
     */
    protected $position = 0;

    /**
     * @var array
     */
    protected $related;

    /**
     * Arr constructor.
     * @param string $type
     * @param array $data
     * @param array $related
     */
    public function __construct(string $type, array $data, array $related = [])
    {
        $this->type = $type;
        $this->data = $data;
        $this->related = $related;
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @param mixed $offset
     * @return void
     */
    public function offsetUnset($offset)
    {
        if ($this->offsetExists($offset)) {
            unset($this->data[$offset]);
        }
    }

    /**
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists($offset)
    {
        return isset($this->data[$offset]);
    }

    /**
     * @return int
     */
    public function count()
    {
        return count($this->data);
    }

    /**
     * @return array|mixed
     */
    public function jsonSerialize()
    {
        return array_merge(['type' => $this->type], $this->data);
    }

    /**
     * @param $name
     * @return mixed|null
     */
    public function __get($name)
    {
        return $this->offsetGet($name);
    }

    /**
     * @param $name
     * @param $value
     * @return void
     */
    public function __set($name, $value)
    {
        $this->offsetSet($name, $value);
    }

    /**
     * @param mixed $offset
     * @return mixed|null
     */
    public function offsetGet($offset)
    {
        return $this->data[$offset] ?? null;
    }

    /**
     * @param mixed $offset
     * @param mixed $value
     * @return void
     */
    public function offsetSet($offset, $value)
    {
        $this->data[$offset] = $value;
    }

    /**
     * @return ArrayIterator|Traversable
     */
    public function getIterator()
    {
        return new ArrayIterator($this->data);
    }

    /**
     * @return array
     */
    public function getRelated(): array
    {
        $related = [];

        foreach ($this->related as $relatedPath) {
            $subRelation = null;
            $subRelationStart = strpos($relatedPath, '.');

            if (!isset($related[$relatedPath])) {
                $related[$relatedPath] = [];
            }

            if ($subRelationStart !== false) {
                $subRelation = substr($relatedPath, $subRelationStart + 1);
                $relatedPath = substr($relatedPath, 0, $subRelationStart);
            }

            if ($subRelation) {
                $related[$relatedPath][] = $subRelation;
            }
        }

        return $related;
    }

    /**
     * @param array $related
     * @return Arr
     */
    public function setRelated(array $related): Arr
    {
        $this->related = $related;

        return $this;
    }
}
