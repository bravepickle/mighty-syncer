<?php

namespace MightySyncer\Importer;

use Doctrine\DBAL\Types\Types;
use MightySyncer\Entity\EntityNode;
use MightySyncer\Event\Importer\EntityAffectedEvent;
use MightySyncer\Event\Importer\EntityEvent;
use MightySyncer\Exception\EntityExceptionEvent;
use MightySyncer\Exception\ImportAbortedException;
use MightySyncer\Importer\Options\ImportOptions;
use MightySyncer\Importer\Processor\ConflictEntityProcessor;
use MightySyncer\Importer\Processor\DeleteEntityProcessor;
use MightySyncer\Importer\Processor\InsertEntityProcessor;
use MightySyncer\Importer\Processor\SoftDeleteEntityProcessor;
use MightySyncer\Importer\Processor\UpdateEntityProcessor;
use MightySyncer\Service\EntityNodeTreeBuilder;
use DateTime;
use MightySyncer\Exception\ImportException;
use MightySyncer\Importer\Options\EntityConfigurator;
use MightySyncer\Importer\Options\EntityOptions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL;
use Exception;
use GuzzleHttp\Client;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Class AbstractApiImporter interface for handling imports via API requests from remote servers
 * @package MightySyncer\Importer
 */
abstract class AbstractApiImporter extends AbstractImporter
{
    protected const TABLE_SYNC = 'sync_date';
    protected const TMP_TABLE_PREFIX = '_tmp_';
    protected const DESC_FIELD = 'Field';

    /**
     * @var DenormalizerInterface|null
     */
    protected $normalizer;

    /**
     * @var array
     */
    protected $tmpTables = [];

    /**
     * @var bool
     */
    protected $disabledForeignKeyChecks = false;

    /**
     * @var DateTime[]
     */
    protected $lastSyncDates = [];

    /**
     * @var EntityNodeTreeBuilder
     */
    protected $nodesBuilder;

    /**
     * @var ImportOptions|null
     */
    protected $importOptions;

    /**
     * Get HTTP client for sending requests for import
     * @return Client
     * @throws ImportException
     */
    abstract public function getSourceClient(): Client;

    /**
     * Get DBAL connection for public service
     * @return Connection|object
     * @throws ImportException
     */
    abstract public function getTargetConnection();

    /**
     * Make requests to remotes via API and put data to temporary table for imports
     * @param EntityOptions $options
     * @param Client $srcClient
     * @param string $tmpTable
     * @param DateTime|null $syncDate
     * @param Connection $targetConn
     * @return int
     * @throws DBAL\DBALException|DBAL\Exception
     */
    abstract protected function copyDataToTemporaryTable(
        EntityOptions $options,
        Client $srcClient,
        string $tmpTable,
        ?DateTime $syncDate,
        Connection $targetConn
    ): int;

    /**
     * @return EntityNodeTreeBuilder
     */
    public function getNodesBuilder(): EntityNodeTreeBuilder
    {
        return $this->nodesBuilder;
    }

    /**
     * @param EntityNodeTreeBuilder $nodesBuilder
     */
    public function setNodesBuilder(EntityNodeTreeBuilder $nodesBuilder): void
    {
        $this->nodesBuilder = $nodesBuilder;
    }

    /**
     * @return DenormalizerInterface
     * @throws ImportException
     */
    public function getNormalizer(): DenormalizerInterface
    {
        if (!$this->normalizer) {
            throw new ImportException('Normalizer is not defined.');
        }

        return $this->normalizer;
    }

    /**
     * @param DenormalizerInterface|null $normalizer
     * @return AbstractApiImporter
     */
    public function setNormalizer(?DenormalizerInterface $normalizer): AbstractApiImporter
    {
        $this->normalizer = $normalizer;

        return $this;
    }

    /**
     * @return EntityOptions[]
     * @throws ImportException
     * @throws ExceptionInterface
     */
    protected function resolveOptions(): array
    {
        if (!$this->config) {
            throw new ImportException('Configuration options are not defined.');
        }

        $resolver = (new EntityConfigurator())->configure();

        $normalizer = $this->getNormalizer();
        $options = [];
        foreach ($this->config as $name => $tableConfig) {
            $tableConfig['name'] = $name;
            $options[$name] = $normalizer->denormalize($resolver->resolve($tableConfig), EntityOptions::class);
        }

        return $this->prepareOptions($options);
    }

    /**
     * @param ImportOptions|null $importOptions
     * @return bool
     * @throws ImportException
     * @throws DBAL\DBALException|DBAL\Exception
     * @throws ExceptionInterface
     */
    public function import(?ImportOptions $importOptions = null): bool
    {
        $this->reset();

        if ($importOptions === null) {
            $importOptions = new ImportOptions();
        }

        $this->importOptions = $importOptions;
        $srcClient = $this->getSourceClient();

        $importerOptionsList = $this->resolveOptions();
        $importedAll = true;

        // This is important option to avoid cascade deletes during imports!
        // Cleanup for other tables can be handled separately elsewhere
        $this->disableForeignKeyChecks();

        foreach ($importerOptionsList as $entityOptions) {
            $event = new EntityEvent(EntityEvent::NAME_BEFORE_IMPORT, $entityOptions);

            try {
                $this->dispatch($event);
                $this->importTable($event->getOptions(), $srcClient);
                $this->dispatch($event->setName(EntityEvent::NAME_AFTER_IMPORT));

                if ($importOptions->saveSyncDate) {
                    $this->saveSyncDate($entityOptions->name);
                }
            } catch (ImportAbortedException $e) {
                $this->logger()->error($e->getMessage(), ['exception' => $e]);
                $this->dispatch(new EntityExceptionEvent($entityOptions, $e));
                $importedAll = false;
                break; // stop processing other imports due to thrown abort action
            } catch (Exception $e) {
                $this->logger()->error($e->getMessage(), ['exception' => $e]);
                $this->dispatch(new EntityExceptionEvent($entityOptions, $e));
                $importedAll = false;
            }
        }

        $this->enableForeignKeyChecks();

        return $importedAll;
    }

    /**
     * @param string $name
     * @throws ImportException
     * @throws Exception
     */
    protected function saveSyncDate(string $name): void
    {
        $lastSyncDate = $this->getLastSyncDate($name);

        $conn = $this->getTargetConnection();
        $queryBuilder = $conn->createQueryBuilder();

        if ($lastSyncDate === null) {
            $queryBuilder->insert(self::TABLE_SYNC)
                ->values([
                    'title' => $conn->quote($this->genSyncName($name)),
                    'date' => $conn->quote(new DateTime(), Types::DATE_MUTABLE),
                ])
            ;
        } else {
            $queryBuilder->update(self::TABLE_SYNC)
                ->set('date', $conn->quote(new DateTime(), Types::DATE_MUTABLE))
                ->where(
                    $queryBuilder->expr()->eq('title', $conn->quote($this->genSyncName($name)))
                )
            ;
        }

        $queryBuilder->execute();
    }

    /**
     * @param EntityOptions $options
     * @param Client $srcClient
     * @return int
     * @throws ImportAbortedException
     * @throws ImportException
     * @throws DBAL\DBALException|DBAL\Exception
     */
    protected function importTable(EntityOptions $options, Client $srcClient): int
    {
        $lastSyncDate = $this->importOptions->incremental ? $this->getLastSyncDate($options->name) : null;

        $conn = $this->getTargetConnection();

        [$tmpTable, $rawTmpTable] = $this->initTemporaryTable($options->name, $conn, $options);

        $affectedRows = $this->copyDataToTemporaryTable(
            $options,
            $srcClient,
            $tmpTable,
            $lastSyncDate,
            $conn
        );

        $this->runEntityProcessors($options, $rawTmpTable, $conn, $lastSyncDate, $affectedRows > 0);

        return $affectedRows;
    }

    /**
     * Drop temporary tables
     * @return bool
     * @throws DBAL\DBALException|DBAL\Exception
     * @throws ImportException
     */
    protected function dropTemporaryTables(): bool
    {
        if (isset($this->importOptions) && $this->importOptions->keepTemporaryTables) {
            return false;
        }

        if ($this->tmpTables) {
            $tables = implode(', ', $this->tmpTables);
            $this->getTargetConnection()->exec("DROP TABLE IF EXISTS $tables");
        }

        return true;
    }

    /**
     * @param string $name
     * @return DateTime|null
     * @throws ImportException
     * @throws DBAL\DBALException|DBAL\Exception
     */
    protected function getLastSyncDate(string $name): ?DateTime
    {
        if (!array_key_exists($name, $this->lastSyncDates)) {
            $syncDate = $this->getTargetConnection()
                ->executeQuery('SELECT `date` FROM ' . self::TABLE_SYNC .
                    ' WHERE `title` = :name LIMIT 1', ['name' => $this->genSyncName($name)])
                ->fetchColumn()
            ;

            $this->lastSyncDates[$name] = empty($syncDate) ? null :
                DateTime::createFromFormat('Y-m-d H:i:s', $syncDate);
        }

        return $this->lastSyncDates[$name];
    }

    /**
     * Generate name for syncs based on entity name
     * @param string $name
     * @return string
     */
    protected function genSyncName(string $name): string
    {
        return sprintf('%s %s', $this->getName(), $name);
    }

    /**
     * @param string $table
     * @param Connection $conn
     * @param EntityOptions $options
     * @return string[]
     * @throws DBAL\DBALException|DBAL\Exception
     */
    protected function initTemporaryTable(string $table, Connection $conn, EntityOptions $options): array
    {
        $rawTmpTable = self::TMP_TABLE_PREFIX . $table;
        $tmpTable = $conn->quoteIdentifier($rawTmpTable);
        $conn->executeQuery("DROP TABLE IF EXISTS $tmpTable");

        if ($this->importOptions->keepTemporaryTables) {
            $conn->exec("CREATE TABLE IF NOT EXISTS $tmpTable LIKE $table");
        } else {
            $conn->exec("CREATE TEMPORARY TABLE IF NOT EXISTS $tmpTable LIKE $table");
        }

        $this->dropExtraColumns($tmpTable, $conn, $options);

        $conflictsField = $conn->quoteIdentifier($options->conflictsField);

        $conn->exec("ALTER TABLE $tmpTable ADD COLUMN $conflictsField TINYINT(1) NOT NULL DEFAULT 0");

        $this->tmpTables[$rawTmpTable] = $tmpTable;

        return [$tmpTable, $rawTmpTable];
    }
    /**
     * @param string $table
     * @param Connection $conn
     * @param EntityOptions $options
     * @return int|void
     * @throws DBAL\DBALException|DBAL\Exception
     */
    protected function dropExtraColumns($table, Connection $conn, EntityOptions $options)
    {
        $stmt = $conn->executeQuery('DESCRIBE ' . $table);
        $drops = [];

        while ($row = $stmt->fetch()) {
            if (!in_array($row[self::DESC_FIELD], $options->mapping, true)) {
                $drops[] = $row[self::DESC_FIELD];
            }
        }

        if (!$drops) {
            return; // nothing to do
        }

        $sql = 'ALTER TABLE ' . $table . ' DROP COLUMN ' . implode(', DROP COLUMN ', $drops);

        return $conn->exec($sql);
    }

    /**
     * @throws ImportException
     * @throws DBAL\DBALException|DBAL\Exception
     */
    public function reset(): void
    {
        if ($this->disabledForeignKeyChecks) {
            $this->enableForeignKeyChecks();
        }

        $this->dropTemporaryTables();
        $this->tmpTables = [];
        $this->lastSyncDates = [];
        $this->importOptions = null;

        parent::reset();
    }

    /**
     * @throws ImportException
     * @throws DBAL\DBALException|DBAL\Exception
     */
    protected function enableForeignKeyChecks(): void
    {
        $this->getTargetConnection()->exec('SET FOREIGN_KEY_CHECKS = 1');
        $this->disabledForeignKeyChecks = false;
    }

    /**
     * @throws ImportException
     * @throws DBAL\DBALException|DBAL\Exception
     */
    protected function disableForeignKeyChecks(): void
    {
        $this->getTargetConnection()->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->disabledForeignKeyChecks = true;
    }

    /**
     * @throws ImportException
     * @throws DBAL\DBALException|DBAL\Exception
     */
    public function __destruct()
    {
        $this->reset();
    }

    /**
     * @param array $options
     * @return array
     */
    protected function prepareOptions(array $options): array
    {
        $nodesCollection = $this->getNodesBuilder()->build($options);
        $nodesCollection = $this->filterNodesCollection($nodesCollection);
        $nodesCollection = $nodesCollection->sortByRelations();

        $entityOptions = [];
        /** @var EntityNode $node */
        foreach ($nodesCollection as $node) {
            $entityOptions[$node->getId()] = $node->getData();
        }

        return $entityOptions;
    }

    /**
     * @param EntityOptions $options
     * @param string $rawTmpTable
     * @param Connection $conn
     * @param DateTime|null $lastSyncDate
     * @param bool $sourceHasEntities
     * @throws ImportAbortedException
     * @throws ImportException
     * @throws DBAL\DBALException|DBAL\Exception
     */
    protected function runEntityProcessors(
        EntityOptions $options,
        string $rawTmpTable,
        Connection $conn,
        ?DateTime $lastSyncDate,
        bool $sourceHasEntities
    ): void {
        $processorArgs = [
            $this->logger,
            $this->dispatcher,
            $options,
            $rawTmpTable,
            $conn,
            $lastSyncDate,
            $this->importOptions->incremental,
            $sourceHasEntities,
        ];

        $conflicted = (new ConflictEntityProcessor(...$processorArgs))->process();
        if ($conflicted > 0 || $this->isDebug()) {
            $this->dispatch(new EntityAffectedEvent(EntityAffectedEvent::NAME_CONFLICT, $conflicted, $rawTmpTable, $options));
        }

        $deleted = (new DeleteEntityProcessor(...$processorArgs))->process();
        if ($this->isDebug()) {
            $this->dispatch(new EntityAffectedEvent(EntityAffectedEvent::NAME_DELETE, $deleted, $rawTmpTable, $options));
        }

        $softDeleted = (new SoftDeleteEntityProcessor(...$processorArgs))->process();
        if ($this->isDebug()) {
            $this->dispatch(new EntityAffectedEvent(EntityAffectedEvent::NAME_SOFT_DELETE, $softDeleted, $rawTmpTable, $options));
        }

        $updated = (new UpdateEntityProcessor(...$processorArgs))->process();
        if ($this->isDebug()) {
            $this->dispatch(new EntityAffectedEvent(EntityAffectedEvent::NAME_UPDATE, $updated, $rawTmpTable, $options));
        }

        $inserted = (new InsertEntityProcessor(...$processorArgs))->process();
        if ($this->isDebug()) {
            $this->dispatch(new EntityAffectedEvent(EntityAffectedEvent::NAME_INSERT, $inserted, $rawTmpTable, $options));
        }
    }

    /**
     * @param EntityOptions $options
     * @param DateTime|null $syncDate
     * @param Connection $conn
     * @param string $srcAlias
     * @return array
     */
    protected function buildConditions(
        EntityOptions $options,
        ?DateTime $syncDate,
        Connection $conn,
        string $srcAlias
    ): array {
        $conditions = [];
        if ($syncDate) {
            $conditions[] = sprintf(
                '%s.%s > %s',
                $srcAlias,
                $conn->quoteIdentifier($options->dateCheckField),
                $conn->quote($syncDate, Types::DATE_MUTABLE)
            );
        }

        if (!empty($options->config['conditions'])) {
            foreach ($options->config['conditions'] as $key => $cond) {
                if (!is_numeric($key) && is_string($key)) { // is key-value format
                    $conditions[] = sprintf(
                        '%s.%s = %s',
                        $srcAlias,
                        $conn->quoteIdentifier($key),
                        $conn->quote($cond)
                    );
                } else {
                    $conditions[] = '(' . $cond . ')'; // fingers crossed
                }
            }
        }

        return $conditions;
    }

}
