<?php
declare(strict_types=1);

namespace WyriHaximus\ApiClient\Transport;

use Doctrine\Common\Annotations\AnnotationReader;
use GeneratedHydrator\Configuration;
use ReflectionClass;
use WyriHaximus\ApiClient\Annotations\Collection;
use WyriHaximus\ApiClient\Annotations\Nested;
use WyriHaximus\ApiClient\Annotations\Rename;
use WyriHaximus\ApiClient\Resource\ResourceInterface;
use Zend\Hydrator\HydratorInterface;

class Hydrator
{
    /**
     * @var array
     */
    protected $options;

    /**
     * @var Client
     */
    protected $transport;

    /**
     * @var array
     */
    protected $hydrators = [];

    /**
     * @var array
     */
    protected $annotations = [];

    /**
     * @var AnnotationReader
     */
    protected $annotationReader;

    /**
     * @param Client $transport
     * @param array $options
     */
    public function __construct(Client $transport, array $options)
    {
        $this->transport = $transport;
        $this->options = $options;
        $this->annotationReader = new AnnotationReader();
    }

    /**
     * @param string $class
     * @param array $json
     * @return ResourceInterface
     */
    public function hydrateFQCN(string $class, array $json): ResourceInterface
    {
        $hydrator = $this->getHydrator($class);
        $object = $this->createObject($class);
        $json = $this->hydrateNestedResources($object, $json);
        $json = $this->hydrateCollectionResources($object, $json);
        $json = $this->hydrateRenameResourceProperties($object, $json);
        return $hydrator->hydrate($json, $object);
    }

    /**
     * @param ResourceInterface $object
     * @param array $json
     * @return array
     */
    protected function hydrateNestedResources(ResourceInterface $object, array $json)
    {
        $annotation = $this->getAnnotation($object, Nested::class);

        if (!($annotation instanceof Nested)) {
            return $json;
        }

        foreach ($annotation->properties() as $property) {
            if ($json[$property] === null) {
                continue;
            }

            $json[$property] = $this->hydrate($annotation->get($property), $json[$property]);
        }

        return $json;
    }

    /**
     * @param ResourceInterface $object
     * @param array $json
     * @return array
     */
    protected function hydrateCollectionResources(ResourceInterface $object, array $json)
    {
        $annotation = $this->getAnnotation($object, Collection::class);

        if (!($annotation instanceof Collection)) {
            return $json;
        }

        foreach ($annotation->properties() as $property) {
            $array = $json[$property];
            $json[$property] = [];
            foreach ($array as $resource) {
                if ($resource === null) {
                    continue;
                }

                $json[$property][] = $this->hydrate($annotation->get($property), $resource);
            }
        }

        return $json;
    }

    /**
     * @param ResourceInterface $object
     * @param array $json
     * @return array
     */
    protected function hydrateRenameResourceProperties(ResourceInterface $object, array $json)
    {
        $annotation = $this->getAnnotation($object, Rename::class);

        if (!($annotation instanceof Rename)) {
            return $json;
        }

        foreach ($annotation->properties() as $property) {
            $json[$property] = $json[$annotation->get($property)];
            unset($json[$annotation->get($property)]);
        }

        return $json;
    }

    /**
     * @param string $class
     * @param array $json
     * @return ResourceInterface
     */
    public function hydrate(string $class, array $json): ResourceInterface
    {
        $fullClassName = $this->options['namespace'] . '\\' . $this->options['resource_namespace'] . '\\' . $class;
        return $this->hydrateFQCN($fullClassName, $json);
    }

    /**
     * Takes a fully qualified class name and extracts the data for that class from the given $object
     * @param string $class
     * @param ResourceInterface $object
     * @return array
     */
    public function extractFQCN(string $class, ResourceInterface $object): array
    {
        $json = $this->getHydrator($class)->extract($object);
        $json = $this->extractNestedResources($json, $object);
        $json = $this->extractCollectionResources($json, $object);
        $json = $this->extractRenameResourceProperties($json, $object);
        return $json;
    }

    /**
     * @param array $json
     * @param ResourceInterface $object
     * @return array
     */
    protected function extractNestedResources(array $json, ResourceInterface $object)
    {
        $annotation = $this->getAnnotation($object, Nested::class);

        if (!($annotation instanceof Nested)) {
            return $json;
        }

        foreach ($annotation->properties() as $property) {
            $json[$property] = $this->extract($annotation->get($property), $json[$property]);
        }

        return $json;
    }

    /**
     * @param array $json
     * @param ResourceInterface $object
     * @return array
     */
    protected function extractCollectionResources(array $json, ResourceInterface $object)
    {
        $annotation = $this->getAnnotation($object, Collection::class);

        if (!($annotation instanceof Collection)) {
            return $json;
        }

        foreach ($annotation->properties() as $property) {
            $array = $json[$property];
            $json[$property] = [];
            foreach ($array as $resource) {
                $json[$property][] = $this->extract($annotation->get($property), $resource);
            }
        }

        return $json;
    }

    /**
     * @param array $json
     * @param ResourceInterface $object
     * @return array
     */
    protected function extractRenameResourceProperties(array $json, ResourceInterface $object)
    {
        $annotation = $this->getAnnotation($object, Rename::class);

        if (!($annotation instanceof Rename)) {
            return $json;
        }

        foreach ($annotation->properties() as $property) {
            $json[$annotation->get($property)] = $json[$property];
            unset($json[$property]);
        }

        return $json;
    }

    /**
     * @param ResourceInterface $object
     * @param string $annotationClass
     * @return null|object
     */
    protected function getAnnotation(ResourceInterface $object, string $annotationClass)
    {
        $class = get_class($object);
        if (isset($this->annotations[$class][$annotationClass])) {
            return $this->annotations[$class][$annotationClass];
        }

        if (!isset($this->annotations[$class])) {
            $this->annotations[$class] = [];
        }

        $this->annotations[$class][$annotationClass] = $this->annotationReader
            ->getClassAnnotation(
                new ReflectionClass($object),
                $annotationClass
            )
        ;

        if (isset($this->annotations[$class][$annotationClass]) && get_class($this->annotations[$class][$annotationClass]) === $annotationClass) {
            return $this->annotations[$class][$annotationClass];
        }

        $this->annotations[$class][$annotationClass] = $this->annotationReader
            ->getClassAnnotation(
                new ReflectionClass(get_parent_class($object)),
                $annotationClass
            )
        ;

        return $this->annotations[$class][$annotationClass];
    }

    /**
     * @param string $class
     * @param ResourceInterface $object
     * @return array
     */
    public function extract(string $class, ResourceInterface $object): array
    {
        $fullClassName = $this->options['namespace'] . '\\' . $this->options['resource_namespace'] . '\\' . $class;
        return $this->extractFQCN($fullClassName, $object);
    }

    /**
     * @param string $resource
     * @param ResourceInterface $object
     * @return ResourceInterface
     */
    public function buildAsyncFromSync(string $resource, ResourceInterface $object): ResourceInterface
    {
        return $this->hydrateFQCN(
            $this->options['namespace'] . '\\Async\\' . $resource,
            $this->extractFQCN(
                $this->options['namespace'] . '\\Sync\\' . $resource,
                $object
            )
        );
    }

    /**
     * @param string $class
     * @return HydratorInterface
     */
    protected function getHydrator(string $class): HydratorInterface
    {
        if (isset($this->hydrators[$class])) {
            return $this->hydrators[$class];
        }

        $config = new Configuration($class);
        if (isset($this->options['resource_hydrator_cache_dir'])) {
            $config->setGeneratedClassesTargetDir($this->options['resource_hydrator_cache_dir']);
        }
        if (isset($this->options['resource_hydrator_namespace'])) {
            $config->setGeneratedClassesNamespace($this->options['resource_hydrator_namespace']);
        }
        $hydrator = $config->createFactory()->getHydratorClass();
        $this->hydrators[$class] = new $hydrator;

        return $this->hydrators[$class];
    }

    /**
     * @param string $class
     * @return ResourceInterface
     */
    protected function createObject(string $class): ResourceInterface
    {
        $object = new $class();
        $object->setTransport($this->transport);
        if (isset($this->options['setters'])) {
            foreach ($this->options['setters'] as $method => $argument) {
                if (!method_exists($object, $method)) {
                    continue;
                }
                $object->$method($argument);
            }
        }
        return $object;
    }
}
