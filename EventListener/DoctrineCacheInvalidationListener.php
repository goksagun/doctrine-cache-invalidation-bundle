<?php

namespace Goksagun\DoctrineCacheInvalidationBundle\EventListener;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use Goksagun\DoctrineCacheInvalidationBundle\Annotation\CacheInvalidation;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

class DoctrineCacheInvalidationListener implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const EVENT_TYPE_INSERT = 'insert';
    const EVENT_TYPE_UPDATE = 'update';
    const EVENT_TYPE_DELETE = 'delete';

    private $annotationReader;
    private $expressionLanguage;

    public function __construct()
    {
        $this->annotationReader = new AnnotationReader();
        $this->expressionLanguage = new ExpressionLanguage();
    }

    public function onFlush(OnFlushEventArgs $args)
    {
        $entityManager = $args->getEntityManager();

        $unitOfWork = $entityManager->getUnitOfWork();

        $resultCache = $entityManager->getConfiguration()->getResultCacheImpl();

        if (null === $resultCache) {
            return;
        }

        $cacheIds = $this->collectCacheIds($unitOfWork, $entityManager);

        if (!$cacheIds) {
            $this->logger->info('No key to delete.');

            return;
        }

        if ($this->logger) {
            $this->logger->info(sprintf('All keys to delete : %s', implode(', ', $cacheIds)));
        }

        $counter = 0;
        foreach ($cacheIds as $key => $cacheId) {
            if (!$resultCache->contains($cacheId)) {
                $this->logger->info(sprintf('Key not found : %s', $cacheId));

                continue;
            }

            $resultCache->delete($cacheId);

            ++$counter;

            if ($this->logger) {
                $this->logger->info(sprintf('Deleted key : %s', $cacheId));
            }
        }

        if ($this->logger) {
            $this->logger->info(sprintf('Count of deleted keys : %d', $counter));
        }
    }

    private function extractCacheIdParameters($id): array
    {
        if (preg_match_all('#\$(\$?\w+)#', $id, $matches)) {
            return $matches[1];
        }

        return [];
    }

    private function getScheduledEntityChanges(UnitOfWork $unitOfWork): array
    {
        return [
            self::EVENT_TYPE_INSERT => $unitOfWork->getScheduledEntityInsertions(),
            self::EVENT_TYPE_UPDATE => $unitOfWork->getScheduledEntityUpdates(),
            self::EVENT_TYPE_DELETE => $unitOfWork->getScheduledEntityDeletions(),
        ];
    }

    private function collectCacheIds(UnitOfWork $unitOfWork, EntityManager $entityManager): array
    {
        $cacheIds = [];
        foreach ($this->getScheduledEntityChanges($unitOfWork) as $eventType => $entities) {
            foreach ($entities as $entity) {
                $changeSet = [];
                if (self::EVENT_TYPE_UPDATE === $eventType) {
                    $changeSet = $unitOfWork->getEntityChangeSet($entity);
                }

                $classMetadata = $entityManager->getClassMetadata(get_class($entity));

                $classAnnotations = $this->annotationReader->getClassAnnotations(
                    $entityClass = $classMetadata->getReflectionClass()
                );

                $entityCacheIds = [];
                foreach ($classAnnotations as $annotation) {
                    if (!$annotation instanceof CacheInvalidation) {
                        continue;
                    }

                    $entityCacheId = $annotation->id;
                    $cacheIdParameters = $this->extractCacheIdParameters($entityCacheId);

                    if ($cacheIdParameters) {
                        if (!$annotation->parameters) {
                            throw new \RuntimeException(
                                sprintf(
                                    'Missing parameters expressions for the cache id "%s" in class "%s".',
                                    $entityCacheId,
                                    $entityClass
                                )
                            );
                        }

                        foreach ($cacheIdParameters as $name) {
                            if (!array_key_exists($name, $annotation->parameters)) {
                                throw new \RuntimeException(
                                    sprintf(
                                        'Missing expression for parameter "%s" for the cache id "%s" in class "%s".',
                                        $name,
                                        $entityCacheId,
                                        $entityClass
                                    )
                                );
                            }

                            try {
                                // TODO: extract as evaluateExpression
                                $paramValue = $this->expressionLanguage->evaluate(
                                    $annotation->parameters[$name],
                                    [
                                        'this' => $entity,
                                        'eventType' => $eventType,
                                        'changeSet' => $changeSet,
                                    ]
                                );
                            } catch (\Exception $e) {
                                throw new \RuntimeException(
                                    sprintf(
                                        'Unable to resolve parameter "%s" for the cache id "%s" - %s in class "%s".',
                                        $name,
                                        $entityCacheId,
                                        $e->getMessage(),
                                        $entityClass
                                    )
                                );
                            }

                            $entityCacheId = str_replace("\$$name", $paramValue, $entityCacheId);
                        }
                    }

                    if ($annotation->validation) {
                        try {
                            // TODO: extract as evaluateExpression
                            $validation = (bool)$this->expressionLanguage->evaluate(
                                $annotation->validation,
                                [
                                    'this' => $entity,
                                    'eventType' => $eventType,
                                    'changeSet' => $changeSet,
                                ]
                            );
                        } catch (\Exception $e) {
                            throw new \RuntimeException(
                                sprintf(
                                    'Unable to validate the cache id "%s" in class "%s" - %s',
                                    $entityCacheId,
                                    $entityClass,
                                    $e->getMessage()
                                )
                            );
                        }

                        if (!$validation) {
                            continue;
                        }
                    }

                    $entityCacheIds[] = $entityCacheId;
                }

                $entityCacheIds = array_unique($entityCacheIds);

                if (!$entityCacheIds) {
                    continue;
                }

                $entityId = 0;
                if (self::EVENT_TYPE_INSERT !== $eventType) {
                    $entityId = $entity->{sprintf('get%s', ucfirst($classMetadata->getSingleIdentifierFieldName()))}();
                }

                foreach ($entityCacheIds as $key => $entityCacheId) {
                    if (!in_array($entityCacheId, $cacheIds)) {
                        $cacheIds[] = $entityCacheId;
                    }

                    if ($this->logger) {
                        $this->logger->info(
                            sprintf(
                                '[%s #%d] [%s] Key to delete : %s.',
                                $entityClass,
                                $entityId,
                                $eventType,
                                $entityCacheId
                            )
                        );
                    }
                }
            }
        }

        return $cacheIds;
    }
}