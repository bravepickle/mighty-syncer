<?php

namespace MightySyncer\Event\Importer;


use MightySyncer\Event\AbstractEvent;
use MightySyncer\Importer\Options\EntityOptions;

/**
 * Class EntityAffectedEvent notifies on number affected rows for single action
 * @package MightySyncer\EventListener\Importer
 */
class EntityAffectedEvent extends AbstractEvent
{
    public const NAME_CONFLICT = 'mighty_syncer.entity_affected.conflict';
    public const NAME_UPDATE = 'mighty_syncer.entity_affected.update';
    public const NAME_DELETE = 'mighty_syncer.entity_affected.delete';
    public const NAME_SOFT_DELETE = 'mighty_syncer.entity_affected.soft_delete';
    public const NAME_INSERT = 'mighty_syncer.entity_affected.insert';

    /**
     * @var int|null
     */
    protected $numAffected;

    /**
     * @var string
     */
    protected $tmpTable;

    /**
     * @var EntityOptions
     */
    protected $options;

    /**
     * @var string
     */
    protected $name;

    /**
     * EntityEvent constructor.
     * @param $name
     * @param int|null $numAffected
     * @param string $tmpTable
     * @param EntityOptions $options
     */
    public function __construct($name, ?int $numAffected, string $tmpTable, EntityOptions $options)
    {
        $this->name = $name;
        $this->numAffected = $numAffected;
        $this->tmpTable = $tmpTable;
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
     * @return EntityOptions
     */
    public function getOptions(): EntityOptions
    {
        return $this->options;
    }

    /**
     * @param EntityOptions $options
     * @return $this
     */
    public function setOptions(EntityOptions $options): self
    {
        $this->options = $options;

        return $this;
    }

    /**
     * @return string
     */
    public function getTmpTable(): string
    {
        return $this->tmpTable;
    }

    /**
     * @return int
     */
    public function getNumAffected(): ?int
    {
        return $this->numAffected;
    }

}