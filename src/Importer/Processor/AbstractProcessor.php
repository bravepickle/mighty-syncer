<?php

namespace MightySyncer\Importer\Processor;

use MightySyncer\Event\AbstractEvent;
use MightySyncer\Exception\ImportException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Class AbstractProcessor processes actions
 * @package MightySyncer\Importer\Strategy
 */
abstract class AbstractProcessor
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var EventDispatcher|null
     */
    protected $dispatcher;

    /**
     * Process action according to the rules
     * @return int|null
     * @throws ImportException
     */
    abstract public function process(): ?int;

    /**
     * AbstractProcessor constructor.
     * @param LoggerInterface|null $logger
     * @param EventDispatcher|null $dispatcher
     */
    public function __construct(?LoggerInterface $logger, ?EventDispatcher $dispatcher)
    {
        $this->logger = $logger;
        $this->dispatcher = $dispatcher;
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
}