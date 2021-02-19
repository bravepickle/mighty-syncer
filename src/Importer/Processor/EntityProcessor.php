<?php

namespace MightySyncer\Importer\Processor;

use MightySyncer\Contract\ForeignKeysTrait;
use MightySyncer\Exception\ImportException;
use DateTime;
use MightySyncer\Importer\Options\EntityOptions;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Class EntityProcessor
 * @package MightySyncer\Importer\Processor
 */
abstract class EntityProcessor extends AbstractProcessor
{
    use ForeignKeysTrait;

    public const TABLE_TARGET_ALIAS = '_t';
    public const TABLE_SOURCE_ALIAS = '_s';

    /**
     * @var EntityOptions
     */
    protected $options;

    /**
     * @var string
     */
    protected $srcTable;

    /**
     * @var DateTime|null
     */
    protected $lastSyncDate;

    /**
     * @var bool
     */
    protected $isIncremental;

    /**
     * Set to FALSE to notify processor that no entities exist
     * in source (temporary) table. Is used to optimize performance
     * of running processors.
     *
     * @var bool
     */
    protected $sourceHasEntities;

    /**
     * EntityProcessor constructor.
     * @param LoggerInterface|null $logger
     * @param EventDispatcher|null $dispatcher
     * @param EntityOptions $options
     * @param string $srcTable
     * @param Connection $conn
     * @param DateTime|null $lastSyncDate
     * @param bool $incremental
     * @param bool $sourceHasEntities
     */
    public function __construct(
        ?LoggerInterface $logger,
        ?EventDispatcher $dispatcher,
        EntityOptions $options,
        string $srcTable,
        Connection $conn,
        ?DateTime $lastSyncDate,
        bool $incremental,
        bool $sourceHasEntities = true
    ) {
        parent::__construct($logger, $dispatcher);

        $this->options = $options;
        $this->srcTable = $srcTable;
        $this->conn = $conn;
        $this->lastSyncDate = $lastSyncDate;
        $this->isIncremental = $incremental;
        $this->sourceHasEntities = $sourceHasEntities;
    }

    /**
     * @param string $fieldName
     * @param $value
     * @param string $srcAlias
     * @param string $targetAlias
     * @param array $values
     * @throws ImportException
     */
    protected function appendValue(
        string $fieldName,
        $value,
        string $srcAlias,
        string $targetAlias,
        array &$values
    ): void {
        $field = $this->conn->quoteIdentifier($fieldName);
        $normalizedValue = $this->normalizeValue($value, "$srcAlias.$field");
        $values[$fieldName] = "$targetAlias.$field = $normalizedValue";
    }

    /**
     * Normalize value for SET query
     * @param $value
     * @param string|null $srcFieldName initial field name. Set NULL to handle its usage in different way
     * @param string|null $srcAlias
     * @return string
     * @throws ImportException
     */
    protected function normalizeValue($value, ?string $srcFieldName, ?string $srcAlias = null)
    {
        switch (true) {
            case $value === null: // copy source value
                return $srcFieldName;

            // known functions and aliases
            case $value === 'NOW()': // current timestamp
                return $value;

            case $value === 'NULL()':  // NULL value
                return 'NULL';

            // IF NULL value then set to this value, else to another normalized, like IFNULL() SQL function
            // Examples:
            //   IFNULL(, NOW()) - set the same field value from source, otherwise set to NOW()
            //   IFNULL($username, 'default value') - if (src.username IS NULL) then return 'default value' else return src.username;
            //   IFNULL(, 'default value') - if (src.username IS NULL) then return 'default value' else return src.username;
            case is_string($value) && strpos($value, 'IFNULL(') === 0:
                return $this->normalizeIfNullExpression($value, $srcFieldName, $srcAlias);

            // Similar to IF() SQL function
            // Examples:
            //   IF(_s.date_updated IS NULL, NOW(), _s.date_updated) - set the same field value from source, otherwise set to NOW()
            //   IF(a > b, $username, 'default value') - if (src.username IS NULL) then return 'default value' else return src.username;
            //   IF(_s.status < 5, 'outdated', ) - if (status < 5) then return 'outdated' else return source.$field_name
            case is_string($value) && strpos($value, 'IF(') === 0:
                return $this->normalizeIfExpression($value, $srcFieldName, $srcAlias);

            case is_scalar($value):
                return $this->conn->quote($value);

            default: // set value as it is
                throw new ImportException(sprintf(
                    'Cannot set value "%s" for field "%s".',
                    var_export($value, true),
                    $srcFieldName
                ));
        }
    }

    /**
     * @param $value
     * @param string|null $srcFieldName
     * @param string|null $srcAlias
     * @return string
     * @throws ImportException
     */
    protected function normalizeIfNullExpression($value, ?string $srcFieldName, ?string $srcAlias): string
    {
        if (!preg_match('~^IFNULL\((.*)\)$~', $value, $matches)) {
            throw new ImportException(sprintf(
                'Failed to parse value %s for field "%s".',
                var_export($value, true),
                $srcFieldName
            ));
        }

        $args = array_map('trim', explode(',', $matches[1], 2));

        if (!$args || count($args) !== 2) {
            throw new ImportException(sprintf(
                'Failed to parse arguments of value %s for field "%s".',
                var_export($value, true),
                $srcFieldName
            ));
        }

        if ($args[0] === '') {
            $field = $srcFieldName;

            if ($srcFieldName === null) {
                throw new ImportException('Source field name must be set.');
            }
        } elseif ($args[0]{0} === '$') {
            if ($srcAlias === null) {
                $srcAlias = $this->conn->quoteIdentifier(self::TABLE_SOURCE_ALIAS);
            }

            $field = $this->conn->quoteIdentifier(mb_substr($args[0], 1));
            $field = sprintf('%s.%s', $srcAlias, $field);
        } else {
            $field = $args[0]; // fingers crossed
        }

        $altValue = $args[1] === '' ? null : trim($args[1], '"\'');

        return sprintf(
            'IFNULL(%s, %s)',
            $field,
            $this->normalizeValue($altValue, $field, $srcAlias)
        );
    }

    /**
     * @param $value
     * @param string|null $srcFieldName
     * @param string|null $srcAlias
     * @return string
     * @throws ImportException
     */
    protected function normalizeIfExpression($value, ?string $srcFieldName, ?string $srcAlias): string
    {
        if (!preg_match('~^IF\((.+),(.*),(.*)\)$~', $value, $matches)) {
            throw new ImportException(sprintf(
                'Failed to parse value %s for field "%s".',
                var_export($value, true),
                $srcFieldName
            ));
        }

        $args = array_map('trim', [$matches[1], $matches[2], $matches[3]]);

        for ($i = 1; $i <= 2; $i++) {
            $arg = $args[$i];

            if ($arg === '') {
                $fieldArg = $srcFieldName;

                if ($srcFieldName === null) {
                    throw new ImportException('Source field name must be set.');
                }
            } elseif ($arg{0} === '$') {
                if ($srcAlias === null) {
                    $srcAlias = $this->conn->quoteIdentifier(self::TABLE_SOURCE_ALIAS);
                }

                $fieldArg = $this->conn->quoteIdentifier(mb_substr($arg, 1));
                $fieldArg = sprintf('%s.%s', $srcAlias, $fieldArg);
            } else {
                $fieldArg = $arg; // keep as it is
            }

            $args[$i] = $fieldArg;
        }

        return sprintf(
            'IF(%s, %s, %s)',
            $args[0],
            $args[1],
            $args[2]
        );
    }

}