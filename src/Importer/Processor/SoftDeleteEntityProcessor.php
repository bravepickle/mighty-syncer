<?php

namespace MightySyncer\Importer\Processor;

use Doctrine\DBAL;
use MightySyncer\EventListener\Importer\EntityProcessorEvent;
use MightySyncer\Exception\ImportException;
use MightySyncer\Importer\Options\EntityOptions;

/**
 * Class SoftDeleteEntityProcessor
 * @package MightySyncer\Importer\Processor
 */
class SoftDeleteEntityProcessor extends EntityProcessor
{
    /**
     * @inheritdoc
     * @throws DBAL\DBALException|DBAL\Exception
     */
    public function process(): ?int
    {
        if (!$this->options->softDeletable) {
            return null; // soft deletion is not implemented
        }

        if ($this->options->onDelete === EntityOptions::ACTION_IGNORE) {
            return null; // ignore deletion of any rows
        }

        return $this->processAction();
    }

    /**
     * @return int|null
     * @throws ImportException
     * @throws DBAL\DBALException|DBAL\Exception
     */
    protected function processAction(): ?int
    {
        switch ($this->options->onDelete) {
            case EntityOptions::ACTION_IGNORE:
                return null;
            case EntityOptions::ACTION_DELETE:
                return $this->delete();
            case EntityOptions::ACTION_UPDATE:
                return $this->update();
            default:
                throw new ImportException('Unexpected option found: ' . $this->options->onDelete);
        }
    }

    /**
     * Update existing entities with new values
     * @return int|null
     * @throws ImportException
     * @throws DBAL\DBALException|DBAL\Exception
     */
    protected function update(): ?int
    {
        $updateSet = $this->options->onDeleteSet;

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
     * @return int
     * @throws DBAL\Exception
     */
    protected function delete(): int
    {
        $srcAlias = static::TABLE_SOURCE_ALIAS;
        $id = $this->options->identifier;
        $srcTable = $this->conn->quoteIdentifier($this->srcTable);

        // leftJoin does not work properly with deletion in query builder
        // so we do sub select to solve this
        $subConds = [];

        $softDeleteField = $this->conn->quoteIdentifier($this->options->softDeleteField);
        $conflictsField = $this->conn->quoteIdentifier($this->options->conflictsField);
        $subConds[] = "({$srcAlias}.{$softDeleteField} = 1 OR {$srcAlias}.{$conflictsField} = 1)";

        $subConds = $subConds ? implode(' AND ', $subConds) : '1';

        $queryBuilder = $this->conn->createQueryBuilder()
            ->delete($this->options->name)
            ->where("{$id} NOT IN(SELECT {$srcAlias}.{$id} " .
                "FROM {$srcTable} {$srcAlias} WHERE $subConds)")
            ->orWhere("{$softDeleteField} = 0");


        // FIXME: add somehow left JOIN HERE: "INNER JOIN $targetTable $targetAlias ON $targetAlias.$id = $srcAlias.$id " .
        // FIXME: rewrite this query to native SQL request
        // FIXME: soft deletable field condition check not defined. Soft deletable field conflicts with idea onDelete - delete: what should be done?

        $event = new EntityProcessorEvent(
            EntityProcessorEvent::NAME_BEFORE_DELETE,
            $this->srcTable,
            $this->options,
            $queryBuilder
        );

        $this->dispatch($event);

        $queryBuilder = $event->getQuery();
        $affectedRows = (int)$queryBuilder->execute();

        $event->setName(EntityProcessorEvent::NAME_AFTER_DELETE);
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

        $id = $this->conn->quoteIdentifier($this->options->identifier);
        $softDeleteField = $this->conn->quoteIdentifier($this->options->softDeleteField);

        if ($this->isIncremental) {
            // incremental soft deletes possible only if new entity changes came
            $conditions[] = "({$srcAlias}.{$id} IS NOT NULL AND {$srcAlias}.{$softDeleteField} = 0)";
        } else {
            // full import soft deletes possible on entity changes and not existing records found
            $conditions[] = "({$srcAlias}.{$id} IS NULL OR {$srcAlias}.{$softDeleteField} = 0)";
        }

        $conditions[] = "{$targetAlias}.{$softDeleteField} = 1";

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

        // mark field as soft deleted and allow it to be reset later if manually set
        $this->appendValue($this->options->softDeleteField, 0, $srcAlias, $targetAlias, $values);

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
            "LEFT JOIN $srcTable $srcAlias ON {$srcAlias}.{$id} = {$targetAlias}.{$id} " .
            "SET :values WHERE :conditions";

        $values = $this->genUpdateValues($updateSet, $srcAlias, $targetAlias);
        $conditions = $this->genUpdateConditions($targetAlias, $srcAlias);

        $query = str_replace([':values', ':conditions'], [$values, $conditions], $query);

        return $query;
    }

}