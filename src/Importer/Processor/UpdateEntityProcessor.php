<?php

namespace MightySyncer\Importer\Processor;

use MightySyncer\Event\Importer\EntityProcessorEvent;
use MightySyncer\Exception\ImportException;
use MightySyncer\Importer\Options\EntityOptions;
use Doctrine\DBAL;

class UpdateEntityProcessor extends EntityProcessor
{
    /**
     * @inheritdoc
     * @throws DBAL\DBALException|DBAL\Exception
     */
    public function process(): ?int
    {
        if (!$this->sourceHasEntities) {
            return null; // source table is considered empty so no updates possible
        }

        switch ($this->options->onUpdate) {
            case EntityOptions::ACTION_IGNORE:
                return null;
            case EntityOptions::ACTION_UPDATE:
                return $this->update($this->options->onUpdateSet);
            default:
                throw new ImportException('Unexpected option found: ' . $this->options->onUpdate);
        }
    }

    /**
     * Update existing entities with new values
     * @param array $updateSet
     * @return int|null
     * @throws ImportException
     * @throws DBAL\DBALException|DBAL\Exception
     */
    protected function update(array $updateSet): ?int
    {
        if (!$updateSet) {
            return null; // no fields specified
        }

        $query = $this->buildUpdateQuery($updateSet);

        $event = new EntityProcessorEvent(
            EntityProcessorEvent::NAME_BEFORE_UPDATE,
            $this->srcTable,
            $this->options,
            $query
        );
        $this->dispatch($event);

        $query = $event->getQuery();
        $affectedRows = (int)$this->conn->exec($query);

        $event->setName(EntityProcessorEvent::NAME_AFTER_UPDATE);
        $this->dispatch($event);

        return $affectedRows;
    }

    /**
     * @param string $targetAlias
     * @param string $srcAlias
     * @return array|string
     */
    protected function genUpdateConditions(string $targetAlias, string $srcAlias)
    {
        $conditions = [];

        $conflictsField = $this->conn->quoteIdentifier($this->options->conflictsField);
        $conditions[] = "{$srcAlias}.{$conflictsField} = 0";

        // TODO: is dates comparison check required always?
        // TODO: if date field is empty then skip date check
        // conflicting dates should be updated in ConflictEntityProcessor to avoid multiple updates
        $dateField = $this->conn->quoteIdentifier($this->options->dateCheckField);
        $conditions[] = "$targetAlias.$dateField <= $srcAlias.$dateField";

//        if ($this->isIncremental && $this->lastSyncDate !== null) { // full sync ignores conflicts check
//            $conditions[] = "$targetAlias.$dateField > " . $this->conn->quote($this->lastSyncDate, Type::DATETIME);
//        }

        if ($this->options->softDeletable) {
            $softDeleteField = $this->conn->quoteIdentifier($this->options->softDeleteField);
            $conditions[] = "{$srcAlias}.{$softDeleteField} = 1";
//            $conditions[] = "({$targetAlias}.{$softDeleteField} = 1 OR {$srcAlias}.{$softDeleteField} = 1)";
        }

        $conditions = $conditions ? implode(' AND ', $conditions) : '1';

        return $conditions;
    }

    /**
     * @param array $updateSet
     * @param string $srcAlias
     * @param string $targetAlias
     * @return array|string
     * @throws ImportException
     */
    protected function genUpdateValues(array $updateSet, string $srcAlias, string $targetAlias)
    {
        $values = [];

        if ($this->options->softDeletable) {
            // update soft deleted field value and allow it to be reset later if manually set
            $this->appendValue($this->options->softDeleteField, null, $srcAlias, $targetAlias, $values);
        }

        foreach ($updateSet as $field => $value) {
            $this->appendValue($field, $value, $srcAlias, $targetAlias, $values);
        }

        $values = implode(', ', $values);

        return $values;
    }

    /**
     * @param array $updateSet
     * @return mixed|string
     * @throws ImportException
     */
    protected function buildUpdateQuery(array $updateSet): string
    {
        $targetAlias = static::TABLE_TARGET_ALIAS;
        $srcAlias = static::TABLE_SOURCE_ALIAS;
        $id = $this->options->identifier;
        $srcTable = $this->conn->quoteIdentifier($this->srcTable);
        $targetTable = $this->conn->quoteIdentifier($this->options->name);

        $query = "UPDATE $targetTable $targetAlias " .
            "INNER JOIN $srcTable $srcAlias ON {$srcAlias}.{$id} = {$targetAlias}.{$id} " .
            "SET :values WHERE :conditions";

        $values = $this->genUpdateValues($updateSet, $srcAlias, $targetAlias);
        $conditions = $this->genUpdateConditions($targetAlias, $srcAlias);

        $query = str_replace([':values', ':conditions'], [$values, $conditions], $query);

        return $query;
    }

}