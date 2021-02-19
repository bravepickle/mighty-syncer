<?php /** @noinspection PhpUndefinedClassInspection */

namespace MightySyncer\Importer;


use MightySyncer\EventListener\AbstractEvent;
use MightySyncer\Exception\ImportException;
use MightySyncer\Importer\Options\ImportOptions;
use MightySyncer\Entity\EntityNode;
use MightySyncer\Entity\EntityNodeCollection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\Service\ResetInterface;

abstract class AbstractImporter implements ResetInterface
{
    /**
     * @var LoggerInterface|null
     */
    protected $logger;

    /**
     * @var array
     */
    protected $config = [];

    /**
     * @var EventDispatcher|null
     */
    protected $dispatcher;

    /**
     * @var bool
     */
    protected $debug = false;

    /**
     * Import data
     * @param ImportOptions|null $importOptions
     * @return bool
     * @throws ImportException
     */
    abstract public function import(?ImportOptions $importOptions = null): bool;

    /**
     * Get unique name of importer
     * @return string
     */
    abstract public function getName(): string;

    /**
     * @return bool
     */
    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * @param bool $debug
     * @return AbstractImporter
     */
    public function setDebug(bool $debug): AbstractImporter
    {
        $this->debug = $debug;

        return $this;
    }

    /**
     * @param EventDispatcher|null $dispatcher
     */
    public function setDispatcher(?EventDispatcher $dispatcher): void
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * Dispatch event
     * @param AbstractEvent $event
     * @return AbstractEvent|object
     */
    protected function dispatch(AbstractEvent $event): object
    {
        if (!$this->dispatcher) {
            return $event;
        }

        $returnEvent = $this->dispatcher->dispatch($event, $event->getName());

        if (!$returnEvent) {
            return $event;
        }

        return $returnEvent;
    }

    /**
     * @param LoggerInterface|null $logger
     * @return AbstractImporter
     */
    public function setLogger(?LoggerInterface $logger): AbstractImporter
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * @return LoggerInterface
     */
    protected function logger(): LoggerInterface
    {
        if (!$this->logger) {
            $this->logger = new NullLogger();
        }

        return $this->logger;
    }

    public function reset()
    {
        // blank
    }

    /**
     * @param array $config
     * @return AbstractImporter
     */
    public function setConfig(array $config): AbstractImporter
    {
        $this->config = $config;

        return $this;
    }

    /**
     * @param EntityNodeCollection $nodesCollection
     * @return EntityNodeCollection
     */
    protected function excludeNodes(EntityNodeCollection $nodesCollection): EntityNodeCollection
    {
        if (!$this->importOptions->excludeEntities) {
            return $nodesCollection;
        }

        /** @var EntityNode[] $excludeNodes */
        $excludeNodes = [];
        foreach ($this->importOptions->excludeEntities as $excludeEntity) {
            /** @var EntityNode|null $node */
            $node = $nodesCollection->get($excludeEntity);

            if ($node !== null) {
                $excludeNodes[$node->getId()] = $node;
            }
        }

        return $nodesCollection->filter(
            static function (EntityNode $node) use ($excludeNodes) {
                if (isset($excludeNodes[$node->getId()])) {
                    return false; // found in list of excludes
                }

                foreach ($excludeNodes as $excludeNode) {
                    if ($node->hasAncestor($excludeNode)) {
                        return false; // current node has dependency on excluded node
                    }
                }

                return true;
            }
        );
    }

    /**
     * @param EntityNodeCollection $nodesCollection
     */
    protected function addRelations(EntityNodeCollection $nodesCollection): void
    {
        if ($this->importOptions->addRelations) {
            $fnDesc = static function (EntityNode $node) use ($nodesCollection) {
                $nodesCollection->set($node->getId(), $node);
            };

            /** @var EntityNode $node */
            foreach ($nodesCollection as $node) {
                $node->walkChildren($fnDesc);
            }
        }
    }

    /**
     * @param EntityNodeCollection $nodesCollection
     * @return EntityNodeCollection
     */
    protected function filterNodesCollection(EntityNodeCollection $nodesCollection): EntityNodeCollection
    {
        if ($this->importOptions->includeEntities) {
            $nodesCollection = $nodesCollection->filterKeysAndRelations($this->importOptions->includeEntities);
            $this->addRelations($nodesCollection);
        }

        $nodesCollection = $this->excludeNodes($nodesCollection);

        return $nodesCollection;
    }
}
