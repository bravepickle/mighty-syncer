<?php

namespace MightySyncer\EventListener\Importer;


use MightySyncer\EventListener\AbstractEvent;
use MightySyncer\Importer\Options\EntityOptions;

/**
 * Class ImporterEvent events for handling imports
 * @package MightySyncer\EventListener\Importer
 */
class EntityEvent extends AbstractEvent
{
    public const NAME_BEFORE_IMPORT = 'mighty_syncer.entity.before_import';
    public const NAME_AFTER_IMPORT = 'mighty_syncer.entity.after_import';

    /**
     * @var string
     */
    protected $name;

    /**
     * @var EntityOptions
     */
    protected $options;

    /**
     * EntityEvent constructor.
     * @param string $name
     * @param EntityOptions $options
     */
    public function __construct(string $name, EntityOptions $options)
    {
        $this->name = $name;
        $this->options = $options;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     * @return AbstractEvent
     */
    public function setName(string $name): AbstractEvent
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return EntityOptions
     */
    public function getOptions(): EntityOptions
    {
        return $this->options;
    }

    /**
     * @param EntityOptions $options
     * @return EntityEvent
     */
    public function setOptions(EntityOptions $options): EntityEvent
    {
        $this->options = $options;

        return $this;
    }

}