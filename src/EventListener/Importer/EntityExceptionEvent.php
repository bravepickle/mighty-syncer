<?php

namespace MightySyncer\EventListener\Importer;

use Exception;
use MightySyncer\EventListener\AbstractEvent;
use MightySyncer\Importer\Options\EntityOptions;

/**
 * Class EntityExceptionEvent
 * @package MightySyncer\EventListener\Importer
 */
class EntityExceptionEvent extends AbstractEvent
{
    public const NAME = 'mighty_syncer.exception';

    /**
     * @var EntityOptions
     */
    protected $options;

    /**
     * @var Exception
     */
    protected $exception;

    /**
     * EntityImporterExceptionEvent constructor.
     * @param EntityOptions $options
     * @param Exception $exception
     */
    public function __construct(EntityOptions $options, Exception $exception)
    {
        $this->options = $options;
        $this->exception = $exception;
    }

    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * @return Exception
     */
    public function getException(): Exception
    {
        return $this->exception;
    }

    /**
     * @return EntityOptions
     */
    public function getOptions(): EntityOptions
    {
        return $this->options;
    }

}