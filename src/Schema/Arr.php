<?php

namespace Neomerx\JsonApi\Schema;

use Neomerx\JsonApi\Contracts\Schema\SchemaInterface;
use Neomerx\JsonApi\Wrappers\Arr as ArrObject;

/**
 * Class Arr
 * @package Neomerx\JsonApi\Schema
 */
class Arr extends BaseSchema implements SchemaInterface
{
    /**
     * Get resource type.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'array';
    }

    /**
     * Get resource attributes.
     *
     * @param mixed $resource
     *
     * @return iterable
     */
    public function getAttributes($resource): iterable
    {
        return array_filter($resource->getData(), function ($item, $key) {
            return !is_array($item) && $key !== 'id';
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Get resource relationship descriptions.
     *
     * @param mixed $resource
     *
     * @return iterable
     */
    public function getRelationships($resource): iterable
    {
        $relationships = [];

        foreach ($resource->getData() as $type => $item) {
            if (is_array($item)) {
                if (isset($item['id'])) {
                    $item = new ArrObject($type, $item);
                } else {
                    $item = array_map(function ($row) use ($type) {
                        return new ArrObject($type, $row);
                    }, $item);
                }

                $relationships[$type] = [
                    self::RELATIONSHIP_DATA => $item,
                ];
            }
        }

        return $relationships;
    }

    /**
     * @inheritdoc
     */
    public function isAddSelfLinkInRelationshipByDefault(string $relationshipName): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function isAddRelatedLinkInRelationshipByDefault(string $relationshipName): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function getLinks($resource): iterable
    {
        return [];
    }

    /**
     * @param mixed $resource
     *
     * @return string
     */
    protected function getSelfSubUrl($resource): string
    {
        return '/' . $this->getTypeByResource($resource) . '/' . $this->getId($resource);
    }

    /**
     * Get resource type.
     *
     * @param object $resource
     *
     * @return string
     */
    public function getTypeByResource($resource): string
    {
        return $resource->getType();
    }

    /**
     * Get resource identity. Newly created objects without ID may return `null` to exclude it from encoder output.
     *
     * @param object $resource
     *
     * @return string|null
     */
    public function getId($resource): ?string
    {
        return $resource->id;
    }
}