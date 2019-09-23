<?php

namespace Algolia\SearchBundle;

use Algolia\AlgoliaSearch\Response\AbstractResponse;
use Algolia\AlgoliaSearch\Response\BatchIndexingResponse;
use Algolia\SearchBundle\Entity\Aggregator;
use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\Common\Util\ClassUtils;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Class IndexManager.
 */
final class IndexManager
{
    /**
     * @var AlgoliaEngine
     */
    private $engine;
    /**
     * @var array<string, array|int|string>
     */
    private $configuration;
    /**
     * @var \Symfony\Component\PropertyAccess\PropertyAccessor
     */
    private $propertyAccessor;
    /**
     * @var array<int, string>
     */
    private $searchableEntities;
    /**
     * @var array<int, string>
     */
    private $aggregators;
    /**
     * @var array<string, array>
     */
    private $entitiesAggregators;
    /**
     * @var array<string, string>
     */
    private $classToIndexMapping;
    /**
     * @var array<string, boolean>
     */
    private $classToSerializerGroupMapping;
    /**
     * @var array<string, string|null>
     */
    private $indexIfMapping;
    /**
     * @var mixed
     */
    private $normalizer;

    /**
     * IndexManager constructor.
     *
     * @param mixed                           $normalizer
     * @param AlgoliaEngine                   $engine
     * @param array<string, array|int|string> $configuration
     */
    public function __construct($normalizer, AlgoliaEngine $engine, array $configuration)
    {
        $this->normalizer       = $normalizer;
        $this->engine           = $engine;
        $this->configuration    = $configuration;
        $this->propertyAccessor = PropertyAccess::createPropertyAccessor();

        $this->setSearchableEntities();
        $this->setAggregatorsAndEntitiesAggregators();
        $this->setClassToIndexMapping();
        $this->setClassToSerializerGroupMapping();
        $this->setIndexIfMapping();
    }

    /**
     * @param string $className
     *
     * @return bool
     */
    public function isSearchable($className)
    {
        if (is_object($className)) {
            $className = ClassUtils::getClass($className);
        }

        return in_array($className, $this->searchableEntities);
    }

    /**
     * @return array<int, string>
     */
    public function getSearchableEntities()
    {
        return $this->searchableEntities;
    }

    /**
     * @return array<string, array|int|string>
     */
    public function getConfiguration()
    {
        return $this->configuration;
    }

    /**
     * @param object|array<int, object>       $entities
     * @param ObjectManager                   $objectManager
     * @param array<string, int|string|array> $requestOptions
     *
     * @return array<int, array<string, AbstractResponse>>
     */
    public function index($entities, ObjectManager $objectManager, $requestOptions = [])
    {
        $entities = is_array($entities) ? $entities : [$entities];
        $entities = array_merge($entities, $this->getAggregatorsFromEntities($objectManager, $entities));

        $entitiesToBeIndexed = array_filter($entities, function ($entity) {
            return $this->isSearchable($entity);
        });

        $entitiesToBeRemoved = [];
        foreach ($entitiesToBeIndexed as $key => $entity) {
            if (!$this->shouldBeIndexed($entity)) {
                unset($entitiesToBeIndexed[$key]);
                $entitiesToBeRemoved[] = $entity;
            }
        }

        if (!empty($entitiesToBeRemoved)) {
            $this->remove($entitiesToBeRemoved, $objectManager);
        }

        return $this->forEachChunk($objectManager, $entitiesToBeIndexed, function ($chunk) use ($requestOptions) {
            return $this->engine->save($chunk, $requestOptions);
        });
    }

    /**
     * @param object|array<int, object>       $entities
     * @param ObjectManager                   $objectManager
     * @param array<string, int|string|array> $requestOptions
     *
     * @return array<int, array<string, AbstractResponse>>
     */
    public function remove($entities, ObjectManager $objectManager, $requestOptions = [])
    {
        $entities = is_array($entities) ? $entities : [$entities];
        $entities = array_merge($entities, $this->getAggregatorsFromEntities($objectManager, $entities));

        $entities = array_filter($entities, function ($entity) {
            return $this->isSearchable($entity);
        });

        return $this->forEachChunk($objectManager, $entities, function ($chunk) use ($requestOptions) {
            return $this->engine->remove($chunk, $requestOptions);
        });
    }

    /**
     * @param string $className
     *
     * @return \Algolia\AlgoliaSearch\Response\AbstractResponse
     */
    public function clear($className)
    {
        $this->assertIsSearchable($className);

        return $this->engine->clear($this->getFullIndexName($className));
    }

    /**
     * @param string $className
     *
     * @return \Algolia\AlgoliaSearch\Response\AbstractResponse
     */
    public function delete($className)
    {
        $this->assertIsSearchable($className);

        return $this->engine->delete($this->getFullIndexName($className));
    }

    /**
     * @param string                          $query
     * @param string                          $className
     * @param ObjectManager                   $objectManager
     * @param array<string, int|string|array> $requestOptions
     *
     * @return array<int, object>
     *
     * @throws \Algolia\AlgoliaSearch\Exceptions\AlgoliaException
     */
    public function search($query, $className, ObjectManager $objectManager, $requestOptions = [])
    {
        $this->assertIsSearchable($className);

        $ids = $this->engine->searchIds($query, $this->getFullIndexName($className), $requestOptions);

        $results = [];

        foreach ($ids as $objectID) {
            if (in_array($className, $this->aggregators, true)) {
                $entityClass = $className::getEntityClassFromObjectID($objectID);
                $id          = $className::getEntityIdFromObjectID($objectID);
            } else {
                $id          = $objectID;
                $entityClass = $className;
            }

            $repo   = $objectManager->getRepository($entityClass);
            $entity = $repo->findOneBy(['id' => $id]);

            if ($entity !== null) {
                $results[] = $entity;
            }
        }

        return $results;
    }

    /**
     * @param string                          $query
     * @param string                          $className
     * @param array<string, int|string|array> $requestOptions
     *
     * @return array<string, int|string|array>
     *
     * @throws \Algolia\AlgoliaSearch\Exceptions\AlgoliaException
     */
    public function rawSearch($query, $className, $requestOptions = [])
    {
        $this->assertIsSearchable($className);

        return $this->engine->search($query, $this->getFullIndexName($className), $requestOptions);
    }

    /**
     * @param string                          $query
     * @param string                          $className
     * @param array<string, int|string|array> $requestOptions
     *
     * @return int
     *
     * @throws \Algolia\AlgoliaSearch\Exceptions\AlgoliaException
     */
    public function count($query, $className, $requestOptions = [])
    {
        $this->assertIsSearchable($className);

        return $this->engine->count($query, $this->getFullIndexName($className), $requestOptions);
    }

    /**
     * @param object $entity
     *
     * @return bool
     */
    public function shouldBeIndexed($entity)
    {
        $className = ClassUtils::getClass($entity);

        if ($propertyPath = $this->indexIfMapping[$className]) {
            if ($this->propertyAccessor->isReadable($entity, $propertyPath)) {
                return (bool) $this->propertyAccessor->getValue($entity, $propertyPath);
            }

            return false;
        }

        return true;
    }

    /**
     * @param $className
     *
     * @return bool
     */
    private function canUseSerializerGroup($className)
    {
        return $this->classToSerializerGroupMapping[$className];
    }

    /**
     * @return void
     */
    private function setClassToIndexMapping()
    {
        $mapping = [];
        foreach ($this->configuration['indices'] as $indexName => $indexDetails) {
            $mapping[$indexDetails['class']] = $indexName;
        }

        $this->classToIndexMapping = $mapping;
    }

    /**
     * @return void
     */
    private function setSearchableEntities()
    {
        $searchable = [];

        foreach ($this->configuration['indices'] as $name => $index) {
            $searchable[] = $index['class'];
        }

        $this->searchableEntities = array_unique($searchable);
    }

    /**
     * @return void
     */
    private function setAggregatorsAndEntitiesAggregators()
    {
        $this->entitiesAggregators = [];
        $this->aggregators         = [];

        foreach ($this->configuration['indices'] as $name => $index) {
            if (is_subclass_of($index['class'], Aggregator::class)) {
                foreach ($index['class']::getEntities() as $entityClass) {
                    if (!isset($this->entitiesAggregators[$entityClass])) {
                        $this->entitiesAggregators[$entityClass] = [];
                    }

                    $this->entitiesAggregators[$entityClass][] = $index['class'];
                    $this->aggregators[]                       = $index['class'];
                }
            }
        }

        $this->aggregators = array_unique($this->aggregators);
    }

    /**
     * @param string $className
     *
     * @return string
     */
    public function getFullIndexName($className)
    {
        return $this->configuration['prefix'] . $this->classToIndexMapping[$className];
    }

    /**
     * @param string $className
     *
     * @return void
     */
    private function assertIsSearchable($className)
    {
        if (!$this->isSearchable($className)) {
            throw new Exception('Class ' . $className . ' is not searchable.');
        }
    }

    /**
     * @return void
     */
    private function setClassToSerializerGroupMapping()
    {
        $mapping = [];
        foreach ($this->configuration['indices'] as $indexDetails) {
            $mapping[$indexDetails['class']] = $indexDetails['enable_serializer_groups'];
        }

        $this->classToSerializerGroupMapping = $mapping;
    }

    /**
     * @return void
     */
    private function setIndexIfMapping()
    {
        $mapping = [];
        foreach ($this->configuration['indices'] as $indexDetails) {
            $mapping[$indexDetails['class']] = $indexDetails['index_if'];
        }

        $this->indexIfMapping = $mapping;
    }

    /**
     * For each chunk performs the provided operation.
     *
     * @param \Doctrine\Common\Persistence\ObjectManager $objectManager
     * @param array<int, object>                         $entities
     * @param callable                                   $operation
     *
     * @return array<int, array<string, BatchIndexingResponse>>
     */
    private function forEachChunk(ObjectManager $objectManager, array $entities, $operation)
    {
        $batch = [];
        foreach (array_chunk($entities, $this->configuration['batchSize']) as $chunk) {
            $searchableEntitiesChunk = [];
            foreach ($chunk as $entity) {
                $entityClassName = ClassUtils::getClass($entity);

                $searchableEntitiesChunk[] = new SearchableEntity(
                    $this->getFullIndexName($entityClassName),
                    $entity,
                    $objectManager->getClassMetadata($entityClassName),
                    $this->normalizer,
                    ['useSerializerGroup' => $this->canUseSerializerGroup($entityClassName)]
                );
            }

            $batch[] = $operation($searchableEntitiesChunk);
        }

        return $batch;
    }

    /**
     * Returns the aggregators instances of the provided entities.
     *
     * @param \Doctrine\Common\Persistence\ObjectManager $objectManager
     * @param array<int, object>                         $entities
     *
     * @return array<int, object>
     */
    private function getAggregatorsFromEntities(ObjectManager $objectManager, array $entities)
    {
        $aggregators = [];

        foreach ($entities as $entity) {
            $entityClassName = ClassUtils::getClass($entity);
            if (array_key_exists($entityClassName, $this->entitiesAggregators)) {
                foreach ($this->entitiesAggregators[$entityClassName] as $aggregator) {
                    $aggregators[] = new $aggregator($entity, $objectManager->getClassMetadata($entityClassName)->getIdentifierValues($entity));
                }
            }
        }

        return $aggregators;
    }
}
