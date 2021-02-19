<?php

namespace MightySyncer\Contract;


use Doctrine\DBAL;
use Doctrine\DBAL\Connection;

trait ForeignKeysTrait
{
    /**
     * @var Connection
     */
    protected $conn;

    protected function getConnection(): Connection
    {
        return $this->conn;
    }

    /**
     * Enable foreign keys check
     *
     * @throws DBAL\DBALException|DBAL\Exception
     */
    protected function enableForeignKeyChecks(): void
    {
        $this->getConnection()->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Disable foreign keys check
     *
     * @throws DBAL\DBALException|DBAL\Exception
     */
    protected function disableForeignKeyChecks(): void
    {
        $this->getConnection()->exec('SET FOREIGN_KEY_CHECKS = 0');
    }

    /**
     * Check if enabled foreign key checks in current session
     * @return bool
     * @throws DBAL\DBALException|DBAL\Exception
     */
    protected function isEnabledForeignKeyChecks(): bool
    {
        return (bool)$this->getConnection()->executeQuery('SELECT @@SESSION.foreign_key_checks')->fetchColumn();
    }
}