<?php

namespace MightySyncer\Importer\Options;

/**
 * Class ImportOptions contains settings for importing
 * @package MightySyncer\Importer\Options
 */
class ImportOptions
{
    /**
     * Incremental sync
     * @var bool
     */
    public $incremental = true;

    /**
     * Save to syncs table date of sync if all data was imported successfully
     * @var bool
     */
    public $saveSyncDate = true;

    /**
     * List of entities for import
     * If empty then all entities will be imported
     * @var array
     */
    public $includeEntities = [];

    /**
     * List of excluded entities for import. If defined in includes then includes win
     * Excludes also all dependant entities on it ("required" parameter)
     * @var array
     */
    public $excludeEntities = [];

    /**
     * Should we keep temporary tables after import or drop them
     * @var bool
     */
    public $keepTemporaryTables = false;

    /**
     * If includes defined then add related to them entities
     * @var bool
     */
    public $addRelations = false;
}