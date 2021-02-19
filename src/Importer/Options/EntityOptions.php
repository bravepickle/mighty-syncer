<?php

namespace MightySyncer\Importer\Options;


class EntityOptions
{
    /**
     * Allowed values for some options
     */
    public const ACTION_IGNORE = 'ignore';  // do not make any actions
    public const ACTION_UPDATE = 'update';  // update entity data
    public const ACTION_DELETE = 'delete';  // delete entities
    public const ACTION_ABORT = 'abort';    // abort execution

    /**
     * Default values
     */
    public const DEFAULT_DATE_CHECK_FIELD = 'date_updated';
    public const DEFAULT_IDENTIFIER = 'id';
    public const DEFAULT_SOFT_DELETE_FIELD = 'active';
    public const DEFAULT_CONFLICTS_FIELD = 'conflicts';

    /**
     * Entity name in target
     * @var string
     */
    public $name;

    /**
     * Entity name in source
     * @var string
     */
    public $sourceName;

    /**
     * Name of entity field that is primary key and uniquely identifies it
     * @var string
     */
    public $identifier;

    /**
     * What to do when extra rows found in given table.
     *
     * Allowed values:
     *   ignore - keep those entities as they are
     *   update - update some fields for given entities
     *   delete - delete extra entities
     *
     * @var string
     */
    public $onDelete;

    /**
     * What to do when extra rows found in given table.
     * Mapping for field updates
     * @var array
     */
    public $onDeleteSet = [];

    /**
     * What to do on row changes.
     *
     * Allowed values:
     *   ignore - keep those entities as they are
     *   update - update some fields for given entities
     *
     * @var string
     */
    public $onUpdate;

    /**
     * Mapping for field updates
     *
     * @var array|null
     */
    public $onUpdateSet = [];

    /**
     * Extra actions to do when adding new entity.
     *
     * Allowed values:
     *   ignore - do not add new entities
     *   update - add new entities from source and update some fields if onInsertSet field is not empty
     *
     * @var string
     */
    public $onInsert;

    /**
     * Mapping for field updates
     *
     * @var array|null
     */
    public $onInsertSet = [];

    /**
     * Extra actions to do when found conflicts
     *
     * Allowed values:
     *   ignore - keep those entities as they are
     *   update - update some fields for given entities
     *   delete - delete conflicting entities from target
     *   abort  - abort import execution as whole
     *
     * @var string
     */
    public $onConflict;

    /**
     * Mapping for field updates
     *
     * @var array|null
     */
    public $onConflictSet = [];

    /**
     * Name of field that contains flag that this record had conflicts.
     * Is used to avoid other processors to additionally modify data
     *
     * Will be added only when needed, e.g. when onConflict: update and onConflict: update or delete
     *
     * If used, then in source (temporary) table will be added boolean field with given name that
     * marks all fields that had conflicts
     *
     * It will be automatically updated in ConflictsProcessor when needed
     *
     * @var string
     */
    public $conflictsField;

    /**
     * Name of field that contains dates for entities comparison
     *
     * @var string
     */
    public $dateCheckField;

    /**
     * Source-target fields in key-value format mappings
     * @var array|string[]
     */
    public $mapping = [];

    /**
     * Required related entities
     * @var array
     */
    public $required = [];

    /**
     * Configuration settings for given entity.
     * We can pass extra conditions (or filters) that should be passed to source table to import data
     * E.g. conditions for SQL WHERE part to add to filter out some records from imports
     * Importer instance should know how to handle them and can vary based on which one was used
     * @var array
     */
    public $config = [];

    /**
     * Name of field that is used for soft deletes
     *
     * @var string|null
     */
    public $softDeleteField;

    /**
     * Set to TRUE if entity is soft deletable
     * Values for working should be:
     * 1 - active
     * 0 - soft deleted
     *
     * @var bool
     */
    public $softDeletable = false;

    /**
     * Extra unique constraint sets. Affects conflict checks and updates
     * Values for working should be:
     *   - [[email]] -> one extra set constraints with email
     *   - [[product_id, shop_id], [title]] -> unique constraints by pair product_id-shop_id or title
     * @var array
     */
    public $unique = [];

}
