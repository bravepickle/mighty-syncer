<?php

namespace MightySyncer\Importer\Processor;

use Doctrine\DBAL;
use MightySyncer\EventListener\Importer\EntityProcessorEvent;
use MightySyncer\Exception\ImportException;
use MightySyncer\Importer\Options\EntityOptions;

class InsertEntityProcessor extends EntityProcessor
{
    /**
     * @inheritdoc
     * @throws DBAL\DBALException|DBAL\Exception
     */
    public function process(): ?int
    {
        if (!$this->sourceHasEntities) {
            return null; // source table is considered empty so no inserts possible
        }

        switch ($this->options->onInsert) {
            case EntityOptions::ACTION_IGNORE:
                return null; // do not add new rows ever
            case EntityOptions::ACTION_UPDATE:
                return $this->insert($this->options->onInsertSet);
            default:
                throw new ImportException('Unexpected option found: ' . $this->options->onInsert);
        }
    }

    /**
     * Insert entities with new values
     * @param array $updateSet
     * @return int|null
     * @throws ImportException
     * @throws DBAL\DBALException|DBAL\Exception
     */
    protected function insert(array $updateSet = []): ?int
    {
        $query = $this->buildInsertQuery($updateSet);

        $event = new EntityProcessorEvent(
            EntityProcessorEvent::NAME_BEFORE_INSERT,
            $this->srcTable,
            $this->options,
            $query
        );
        $this->dispatch($event);

        $query = $event->getQuery();
        $affectedRows = (int)$this->conn->exec($query);

        $event->setName(EntityProcessorEvent::NAME_AFTER_INSERT);
        $this->dispatch($event);

        return $affectedRows;
    }

    /**
     * @param string $srcAlias
     * @return array|string
     */
    protected function genInsertConditions(string $srcAlias)
    {
        $conditions = [];

        $conflictsField = $this->conn->quoteIdentifier($this->options->conflictsField);
        $conditions[] = "{$srcAlias}.{$conflictsField} = 0";

        if ($this->options->softDeletable) {
            $softDeleteField = $this->conn->quoteIdentifier($this->options->softDeleteField);
            $conditions[] = "{$srcAlias}.{$softDeleteField} = 1";
        }

        $conditions = $conditions ? implode(' AND ', $conditions) : '1';

        return $conditions;
    }

    /**
     * @param array $updateSet
     * @return mixed|string
     * @throws ImportException
     */
    protected function buildInsertQuery(array $updateSet): string
    {
        $srcAlias = static::TABLE_SOURCE_ALIAS;
        $srcTable = $this->conn->quoteIdentifier($this->srcTable);
        $targetTable = $this->conn->quoteIdentifier($this->options->name);
        $fieldsMap = $this->buildFieldsMap($updateSet, $srcAlias);

        $query = "INSERT IGNORE INTO $targetTable (:fields) " .
            "SELECT :values FROM $srcTable $srcAlias WHERE :conditions"
        ;

        $fields = implode(', ', array_keys($fieldsMap));
        $values = implode(', ', $fieldsMap);
        $conditions = $this->genInsertConditions($srcAlias);

        $query = str_replace([':fields', ':values', ':conditions'], [$fields, $values, $conditions], $query);

        return $query;
    }

    /**
     * @param array $updateSet
     * @param string $srcAlias
     * @return array
     * @throws ImportException
     */
    protected function buildFieldsMap(array $updateSet, string $srcAlias): array
    {
        $targetFields = array_values($this->options->mapping);
        $fieldsMap = [];
        foreach ($targetFields as $field) {
            $fieldQuoted = $this->conn->quoteIdentifier($field);
            $fieldsMap[$fieldQuoted] = $fieldQuoted;
        }

        // can be overridden or extended by update set
        foreach ($updateSet as $name => $value) {
            $fieldQuoted = $this->conn->quoteIdentifier($name);

            $fieldsMap[$fieldQuoted] = $this->normalizeValue($value, "$srcAlias.$fieldQuoted");
        }

        return $fieldsMap;
    }

}