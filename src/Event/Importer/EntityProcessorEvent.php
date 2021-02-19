<?php

namespace MightySyncer\Event\Importer;


use MightySyncer\Event\AbstractEvent;
use MightySyncer\Importer\Options\EntityOptions;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Class EntityProcessorEvent
 * @package MightySyncer\EventListener\Importer
 */
class EntityProcessorEvent extends AbstractEvent
{
    public const NAME_BEFORE_INSERT = 'mighty_syncer.entity_processor.before_insert';
    public const NAME_AFTER_INSERT = 'mighty_syncer.entity_processor.after_insert';

    public const NAME_BEFORE_UPDATE = 'mighty_syncer.entity_processor.before_update';
    public const NAME_AFTER_UPDATE = 'mighty_syncer.entity_processor.after_update';

    public const NAME_BEFORE_DELETE = 'mighty_syncer.entity_processor.before_delete';
    public const NAME_AFTER_DELETE = 'mighty_syncer.entity_processor.after_delete';

    public const NAME_BEFORE_CONFLICT_CHECK = 'mighty_syncer.entity_processor.before_conflict_check';

    public const NAME_BEFORE_CONFLICT_UPDATE = 'mighty_syncer.entity_processor.before_conflict_update';
    public const NAME_AFTER_CONFLICT_UPDATE = 'mighty_syncer.entity_processor.after_conflict_update';

    public const NAME_BEFORE_CONFLICT_DELETE = 'mighty_syncer.entity_processor.before_conflict_delete';
    public const NAME_AFTER_CONFLICT_DELETE = 'mighty_syncer.entity_processor.after_conflict_delete';

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
     * @var QueryBuilder|string|null
     */
    protected $query;

    /**
     * EntityEvent constructor.
     * @param string $name
     * @param string $tmpTable
     * @param EntityOptions $options
     * @param QueryBuilder|string $query query or query builder
     */
    public function __construct(string $name, string $tmpTable, EntityOptions $options, $query)
    {
        $this->name = $name;
        $this->tmpTable = $tmpTable;
        $this->options = $options;
        $this->query = $query;
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
     * @return EntityProcessorEvent
     */
    public function setName(string $name): EntityProcessorEvent
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
     * @return QueryBuilder|string|null
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * @param QueryBuilder|string|null $query
     * @return EntityProcessorEvent
     */
    public function setQuery($query)
    {
        $this->query = $query;

        return $this;
    }

}