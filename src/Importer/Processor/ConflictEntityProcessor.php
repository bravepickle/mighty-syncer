<?php

namespace MightySyncer\Importer\Processor;

use MightySyncer\EventListener\Importer\EntityProcessorEvent;
use MightySyncer\Exception\ImportAbortedException;
use MightySyncer\Exception\ImportException;
use MightySyncer\Importer\Options\EntityOptions;
use Doctrine\DBAL;
use Doctrine\DBAL\Types\Type;

/**
 * Class ConflictEntityProcessor handles cases when same row and fields
 * were updated from both sides simultaneously
 * @package MightySyncer\Importer\Processor
 */
class ConflictEntityProcessor extends EntityProcessor
{
    /**
     * @inheritdoc
     * @throws ImportAbortedException
     * @throws ImportException
     * @throws DBAL\DBALException|DBAL\Exception
     */
    public function process(): ?int
    {
        if (!$this->sourceHasEntities) {
            return null; // source table is considered empty so no conflicts possible
        }

        if ($this->options->onConflict === EntityOptions::ACTION_IGNORE) {
            return null; // ignore conflicts check
        }

        if (!$this->hasConflicts()) {
            return null; // no conflicts found
        }

        // TODO: add option that explains what to do on conflict during full sync. How to force push changes?!!

        return $this->processAction();
    }

    /**
     * @return int|null
     * @throws ImportAbortedException
     * @throws ImportException
     * @throws DBAL\DBALException|DBAL\Exception
     */
    protected function processAction(): ?int
    {
        switch ($this->options->onConflict) {
            case EntityOptions::ACTION_UPDATE:
                return $this->update($this->options->onConflictSet);
            case EntityOptions::ACTION_DELETE:
                $this->markConflicted();

                return $this->delete();
            case EntityOptions::ACTION_ABORT:
                throw new ImportAbortedException(
                    "Entity \"{$this->options->name}\" aborted import due to found conflicts."
                );
            default:
                throw new ImportException('Unexpected option found: ' . $this->options->onConflict);
        }
    }

    /**
     * Mark conflicted entities in source table so that other processors keep track
     *
     * @return int|null
     * @throws ImportException
     * @throws DBAL\DBALException|DBAL\Exception
     */
    protected function markConflicted(): ?int
    {
        return $this->update([], false);
    }

    /**
     * @return int|null
     * @throws DBAL\DBALException|DBAL\Exception
     */
    protected function delete(): ?int
    {
        $targetAlias = static::TABLE_TARGET_ALIAS;
        $query = "DELETE $targetAlias ";
        $query .= $this->buildSqlPart();

        $event = new EntityProcessorEvent(
            EntityProcessorEvent::NAME_BEFORE_CONFLICT_DELETE,
            $this->srcTable,
            $this->options,
            $query
        );

        $this->dispatch($event);

        $query = $event->getQuery();

        $affectedRows = (int)$this->conn->exec($query);

        $event->setName(EntityProcessorEvent::NAME_AFTER_CONFLICT_DELETE);
        $this->dispatch($event);

        return $affectedRows;
    }

    /**
     * Check if tables have conflicting rows
     * @return bool
     * @throws DBAL\DBALException|DBAL\Exception
     */
    protected function hasConflicts(): bool
    {
        $srcAlias = static::TABLE_SOURCE_ALIAS;
        $id = $this->options->identifier;

        $query = "SELECT COUNT(DISTINCT $srcAlias.$id) " . $this->buildSqlPart();

        $event = new EntityProcessorEvent(
            EntityProcessorEvent::NAME_BEFORE_CONFLICT_CHECK,
            $this->srcTable,
            $this->options,
            $query
        );
        $this->dispatch($event);

        $query = $event->getQuery();
        $numAffected = (int)$this->conn->executeQuery($query)->fetchColumn();

        if ($numAffected > 0) {
            $this->logger()->warning(sprintf(
                'Found conflicting %d rows between tables "%s" and "%s"',
                $numAffected,
                $this->options->name,
                $this->srcTable
            ));

            return true;
        }

        return false;
    }

    /**
     * Update existing entities with new values
     * @param array $updateSet
     * @param bool $dispatchEvents
     * @return int|null
     * @throws ImportException
     * @throws DBAL\DBALException|DBAL\Exception
     */
    protected function update(array $updateSet, bool $dispatchEvents = true): ?int
    {
        $query = $this->buildUpdateQuery($updateSet);

        if ($dispatchEvents) {
            $event = new EntityProcessorEvent(
                EntityProcessorEvent::NAME_BEFORE_CONFLICT_UPDATE,
                $this->srcTable,
                $this->options,
                $query
            );

            $this->dispatch($event);

            $query = $event->getQuery();

            $affectedRows = (int)$this->conn->exec($query);

            $event->setName(EntityProcessorEvent::NAME_AFTER_CONFLICT_UPDATE);
            $this->dispatch($event);
        } else {
            $affectedRows = (int)$this->conn->exec($query);
        }

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
        $this->appendBasicConditions($srcAlias, $targetAlias, $conditions);

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
        $this->appendConflictsFieldValue($srcAlias, $values);

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
        $srcTable = $this->conn->quoteIdentifier($this->srcTable);
        $targetTable = $this->conn->quoteIdentifier($this->options->name);

        $onCond = $this->buildOnConditions($srcAlias, $targetAlias);

        $query = "UPDATE $targetTable $targetAlias " .
            "INNER JOIN $srcTable $srcAlias ON $onCond " .
            'SET :values WHERE :conditions';

        $values = $this->genUpdateValues($updateSet, $srcAlias, $targetAlias);
        $conditions = $this->genUpdateConditions($targetAlias, $srcAlias);

        $query = str_replace([':values', ':conditions'], [$values, $conditions], $query);

        return $query;
    }

    /**
     * Build reusable part of SQL query
     * @param string $srcAlias
     * @param string $targetAlias
     * @return string
     */
    protected function buildSqlPart(
        string $srcAlias = self::TABLE_SOURCE_ALIAS,
        string $targetAlias = self::TABLE_TARGET_ALIAS
    ): string {
        $srcTable = $this->conn->quoteIdentifier($this->srcTable);
        $targetTable = $this->conn->quoteIdentifier($this->options->name);

        $conditions = [];
        $this->appendBasicConditions($srcAlias, $targetAlias, $conditions);

        $onCond = $this->buildOnConditions($srcAlias, $targetAlias);

        return "FROM $srcTable $srcAlias " .
            "INNER JOIN $targetTable $targetAlias ON $onCond " .
            'WHERE ' . implode(' AND ', $conditions);
    }

    /**
     * Build ON query section
     * @param string $srcAlias
     * @param string $targetAlias
     * @return string
     */
    protected function buildOnConditions(string $srcAlias, string $targetAlias): string
    {
        $id = $this->options->identifier;
        $conditions = ["$targetAlias.$id = $srcAlias.$id"];

        foreach ($this->options->unique as $uniqueSet) {
            $uniqueSet = (array)$uniqueSet;


            $subCond = [];
            foreach ($uniqueSet as $field) {
                $field = $this->conn->quoteIdentifier($field);
                $subCond[] = "$targetAlias.$field = $srcAlias.$field";
            }

            $conditions[] = implode(' AND ', $subCond);
        }

        return '(' . implode(') OR (', $conditions) . ')';
    }

    /**
     * @param string $srcAlias
     * @param string $targetAlias
     * @param array $conditions
     */
    protected function appendBasicConditions(
        string $srcAlias,
        string $targetAlias,
        array &$conditions
    ): void {
        $dateField = $this->conn->quoteIdentifier($this->options->dateCheckField);
        $conditions[] = "$targetAlias.$dateField > $srcAlias.$dateField";

        if ($this->isIncremental && $this->lastSyncDate !== null) { // full sync ignores conflicts check
            $conditions[] = "$targetAlias.$dateField > " . $this->conn->quote($this->lastSyncDate, Type::DATETIME);
        }

        if ($this->options->softDeletable) {
            $softDeleteField = $this->conn->quoteIdentifier($this->options->softDeleteField);
            $conditions[] = "{$targetAlias}.{$softDeleteField} = 1";
//            $conditions[] = "({$targetAlias}.{$softDeleteField} = 1 OR {$srcAlias}.{$softDeleteField} = 1)";
        }
    }

    /**
     * @param string $srcAlias
     * @param array $values
     */
    protected function appendConflictsFieldValue(string $srcAlias, array &$values): void
    {
        $conflictsFieldName = $this->options->conflictsField;
        $conflictsField = $this->conn->quoteIdentifier($conflictsFieldName);
        $values[$conflictsFieldName] = "$srcAlias.$conflictsField = 1";
    }

}