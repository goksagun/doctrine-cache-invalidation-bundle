<?php

namespace Goksagun\DoctrineCacheInvalidationBundle\Annotation;

/**
 * @Annotation
 * @\Doctrine\Common\Annotations\Annotation\Target("CLASS")
 */
class CacheInvalidation
{
    /**
     * @var string $id The result cache ID.
     *
     * @\Doctrine\Common\Annotations\Annotation\Required()
     */
    public $id;

    /**
     * @var array $parameters Values of potential dynamic ID parameters.
     */
    public $parameters;

    /**
     * @var string $validation Optional validation expression.
     */
    public $validation;
}