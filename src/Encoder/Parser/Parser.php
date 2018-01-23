<?php namespace Neomerx\JsonApi\Encoder\Parser;

/**
 * Copyright 2015-2018 info@neomerx.com
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

use InvalidArgumentException;
use Iterator;
use IteratorAggregate;
use Neomerx\JsonApi\Contracts\Encoder\Parser\ParserFactoryInterface;
use Neomerx\JsonApi\Contracts\Encoder\Parser\ParserInterface;
use Neomerx\JsonApi\Contracts\Encoder\Parser\ParserManagerInterface;
use Neomerx\JsonApi\Contracts\Encoder\Parser\ParserReplyInterface;
use Neomerx\JsonApi\Contracts\Encoder\Stack\StackFactoryInterface;
use Neomerx\JsonApi\Contracts\Encoder\Stack\StackFrameReadOnlyInterface;
use Neomerx\JsonApi\Contracts\Encoder\Stack\StackInterface;
use Neomerx\JsonApi\Contracts\Schema\ContainerInterface;
use Neomerx\JsonApi\Contracts\Schema\RelationshipObjectInterface;
use Neomerx\JsonApi\Contracts\Schema\ResourceObjectInterface;
use Neomerx\JsonApi\Contracts\Schema\SchemaFactoryInterface;
use Neomerx\JsonApi\Contracts\Schema\SchemaInterface;
use Neomerx\JsonApi\Factories\Exceptions;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use function Neomerx\JsonApi\I18n\translate as _;

/**
 * The main purpose of the parser is to reach **every resource** that is targeted for inclusion and its
 * relations if data schema describes them as 'included'. Parser manager is managing this decision making.
 * Parser helps to filter resource attributes at the moment of their creation.
 *   ^^^^
 *     This is 'sparse' JSON API feature and 'fields set' feature (for attributes)
 *
 * Parser does not decide if particular resource or its relationships are actually added to final JSON document.
 * Parsing reply interpreter does this job. Parser interpreter might not include some intermediate resources
 * that parser has found while reaching targets.
 *   ^^^^
 *     This is 'sparse' JSON API feature again and 'fields set' feature (for relationships)
 *
 * The final JSON view of an element is chosen by document which uses settings to decide if 'self', 'meta', and
 * other members should be rendered.
 *   ^^^^
 *     This is generic JSON API features
 *
 * Once again, it basically works this way:
 *   - Parser finds all targeted relationships and outputs them with all intermediate results (looks like a tree).
 *     Resource attributes are already filtered.
 *   - Reply interpreter filters intermediate results and resource relationships and then send it to document.
 *   - The document is just a renderer which saves the input data in one of a few variations depending on settings.
 *   - When all data are parsed the document converts collected data to json.
 *
 * @package Neomerx\JsonApi
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class Parser implements ParserInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * Message code.
     */
    const MSG_SCHEME_NOT_REGISTERED = 0;

    /**
     * Default messages.
     */
    const MESSAGES = [
        self::MSG_SCHEME_NOT_REGISTERED => 'Schema is not registered for a resource at path \'%s\'.',
    ];

    /**
     * @var ParserFactoryInterface
     */
    protected $parserFactory;

    /**
     * @var StackFactoryInterface
     */
    protected $stackFactory;

    /**
     * @var SchemaFactoryInterface
     */
    protected $schemaFactory;

    /**
     * @var StackInterface
     */
    protected $stack;

    /**
     * @var ParserManagerInterface
     */
    protected $manager;

    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var array
     */
    private $messages;

    /**
     * @param ParserFactoryInterface $parserFactory
     * @param StackFactoryInterface  $stackFactory
     * @param SchemaFactoryInterface $schemaFactory
     * @param ContainerInterface     $container
     * @param ParserManagerInterface $manager
     * @param array                  $messages
     */
    public function __construct(
        ParserFactoryInterface $parserFactory,
        StackFactoryInterface $stackFactory,
        SchemaFactoryInterface $schemaFactory,
        ContainerInterface $container,
        ParserManagerInterface $manager,
        $messages = self::MESSAGES
    ) {
        $this->manager       = $manager;
        $this->container     = $container;
        $this->stackFactory  = $stackFactory;
        $this->parserFactory = $parserFactory;
        $this->schemaFactory = $schemaFactory;
        $this->messages      = $messages;
    }

    /**
     * @inheritdoc
     */
    public function parse($data): iterable
    {
        $this->stack = $this->stackFactory->createStack();
        $rootFrame   = $this->stack->push();
        $rootFrame->setRelationship(
            $this->schemaFactory->createRelationshipObject(null, $data, [], null, true, true)
        );

        foreach ($this->parseData() as $parseReply) {
            yield $parseReply;
        }

        $this->stack = null;
    }

    /**
     * @return Iterator
     *
     * @SuppressWarnings(PHPMD.ElseExpression)
     */
    private function parseData(): iterable
    {
        list($isEmpty, $isOriginallyArrayed, $traversableData) = $this->analyzeCurrentData();

        /** @var bool $isEmpty */
        /** @var bool $isOriginallyArrayed */

        if ($isEmpty === true) {
            yield $this->createReplyForEmptyData($traversableData);
        } else {
            $curFrame = $this->stack->end();

            // if resource(s) is in primary data section (not in included)
            $isPrimary = $curFrame->getLevel() < 2;

            foreach ($traversableData as $resource) {
                $schema         = $this->getSchema($resource, $curFrame);
                $fieldSet       = $this->getFieldSet($schema->getResourceType());
                $resourceObject = $schema->createResourceObject($resource, $isOriginallyArrayed, $fieldSet);
                $isCircular     = $this->checkCircular($resourceObject);

                $this->stack->setCurrentResource($resourceObject);
                yield $this->createReplyResourceStarted();

                // duplicated are allowed in data however they shouldn't be in includes
                if ($isCircular === true && $isPrimary === false) {
                    continue;
                }

                if ($this->shouldParseRelationships() === true) {
                    $relationships     = $this->getIncludeRelationships();
                    $relObjectIterator = $schema->getRelationshipObjectIterator($resource, $isPrimary, $relationships);
                    foreach ($relObjectIterator as $relationship) {
                        /** @var RelationshipObjectInterface $relationship */
                        $nextFrame = $this->stack->push();
                        $nextFrame->setRelationship($relationship);
                        try {
                            if ($this->isRelationshipIncludedOrInFieldSet() === true) {
                                foreach ($this->parseData() as $parseResult) {
                                    yield $parseResult;
                                }
                            }
                        } finally {
                            $this->stack->pop();
                        }
                    }
                }

                yield $this->createReplyResourceCompleted();
            }
        }
    }

    /**
     * @return array
     */
    protected function analyzeCurrentData(): array
    {
        $data   = $this->getCurrentData();
        $result = $this->analyzeData($data);

        return $result;
    }

    /**
     * @return array|null|object
     */
    protected function getCurrentData()
    {
        $relationship = $this->stack->end()->getRelationship();
        $data         = $relationship->isShowData() === true ? $relationship->getData() : null;

        return $data;
    }

    /**
     * @param array|null|object $data
     *
     * @return array
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     * @SuppressWarnings(PHPMD.ElseExpression)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    protected function analyzeData($data): array
    {
        $isCollection    = true;
        $isEmpty         = true;
        $traversableData = null;

        $isOk = (is_array($data) === true || is_object($data) === true || $data === null);
        $isOk ?: Exceptions::throwInvalidArgument('data', $data);

        if (is_array($data) === true) {
            /** @var array $data */
            $isEmpty         = empty($data);
            $traversableData = $data;
        } elseif (($data instanceof Iterator && ($iterator = $data) !== null) ||
            ($data instanceof IteratorAggregate && ($iterator = $data->getIterator()) !== null)
        ) {
            /** @var Iterator $iterator */
            $iterator->rewind();
            $isEmpty = ($iterator->valid() === false);
            if ($isEmpty === false) {
                $traversableData = $data;
            } else {
                $traversableData = [];
            }
        } elseif (is_object($data) === true) {
            /** @var object $data */
            $isEmpty         = ($data === null);
            $isCollection    = false;
            $traversableData = [$data];
        } elseif ($data === null) {
            $isCollection = false;
            $isEmpty      = true;
        }

        return [$isEmpty, $isCollection, $traversableData];
    }

    /**
     * @param mixed                       $resource
     * @param StackFrameReadOnlyInterface $frame
     *
     * @return SchemaInterface
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    private function getSchema($resource, StackFrameReadOnlyInterface $frame): SchemaInterface
    {
        try {
            $schema = $this->container->getSchema($resource);
        } catch (InvalidArgumentException $exception) {
            $message = _($this->messages[self::MSG_SCHEME_NOT_REGISTERED], $frame->getPath());
            throw new InvalidArgumentException($message, 0, $exception);
        }

        return $schema;
    }

    /**
     * @param array|null $data
     *
     * @return ParserReplyInterface
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    private function createReplyForEmptyData(?array $data): ParserReplyInterface
    {
        ($data === null || (is_array($data) === true && empty($data) === true)) ?: Exceptions::throwLogicException();

        $replyType = ($data === null ? ParserReplyInterface::REPLY_TYPE_NULL_RESOURCE_STARTED :
            ParserReplyInterface::REPLY_TYPE_EMPTY_RESOURCE_STARTED);

        return $this->parserFactory->createEmptyReply($replyType, $this->stack);
    }

    /**
     * @return ParserReplyInterface
     */
    private function createReplyResourceStarted(): ParserReplyInterface
    {
        return $this->parserFactory->createReply(ParserReplyInterface::REPLY_TYPE_RESOURCE_STARTED, $this->stack);
    }

    /**
     * @return ParserReplyInterface
     */
    private function createReplyResourceCompleted(): ParserReplyInterface
    {
        return $this->parserFactory->createReply(ParserReplyInterface::REPLY_TYPE_RESOURCE_COMPLETED, $this->stack);
    }

    /**
     * @return bool
     */
    private function shouldParseRelationships(): bool
    {
        return $this->manager->isShouldParseRelationships($this->stack);
    }

    /**
     * @return string[]
     */
    private function getIncludeRelationships(): array
    {
        return $this->manager->getIncludeRelationships($this->stack);
    }

    /**
     * @return bool
     */
    private function isRelationshipIncludedOrInFieldSet(): bool
    {
        return
            $this->manager->isRelationshipInFieldSet($this->stack) === true ||
            $this->manager->isShouldParseRelationships($this->stack) === true;
    }

    /**
     * @param ResourceObjectInterface $resourceObject
     *
     * @return bool
     */
    private function checkCircular(ResourceObjectInterface $resourceObject): bool
    {
        foreach ($this->stack as $frame) {
            /** @var StackFrameReadOnlyInterface $frame */
            if (($stackResource = $frame->getResource()) !== null &&
                $stackResource->getId() === $resourceObject->getId() &&
                $stackResource->getType() === $resourceObject->getType()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $resourceType
     *
     * @return array <string, int>|null
     */
    private function getFieldSet(string $resourceType): ?array
    {
        return $this->manager->getFieldSet($resourceType);
    }
}
