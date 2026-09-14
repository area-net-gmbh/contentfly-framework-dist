<?php
namespace Areanet\PIM\Classes\ORM\Mapping;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\DefaultQuoteStrategy;
use Doctrine\ORM\Mapping\JoinColumnMapping;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;

/**
 * Quotes every identifier except `id` and `lang` — the framework's own rule.
 *
 * SINCE 009-005-0002 IT INHERITS FROM `DefaultQuoteStrategy` instead of implementing
 * `QuoteStrategy` itself. The reason is `getColumnAlias()`: our own version called
 * `AbstractPlatform::getSQLResultCasing()`, and that method no longer exists in DBAL 3 —
 * every DQL query died on it, i.e. practically the whole application.
 *
 * Doctrine's version does the same for MySQL (`column_counter`), additionally truncates to the
 * platform's maximum identifier length and removes special characters. Inheriting it instead
 * of copying it means that the next Doctrine jump brings it along; copied, it would be wrong
 * again by the jump after that.
 *
 * All other methods remain overridden: Doctrine only quotes what is marked as `quoted` in the
 * metadata, this framework quotes as a matter of principle. That is the purpose of the class
 * and does not change here.
 *
 * WITH 010-003-0002 ALL SIGNATURES ARE TYPED. ORM 3 declares `DefaultQuoteStrategy` with
 * parameter and return types throughout; a subclass that does not match them is rejected
 * at LOAD time. This class was thus the second of two blockers of the jump — a fatal error
 * before any test ran.
 *
 * In the process, two parameters changed from `array` to objects (`JoinColumnMapping`,
 * `ManyToManyOwningSideMapping`), and in the method bodies array accesses become object
 * accesses. Measured, this change affects only this class in all of our own code.
 */
class ContentflyQuoteStrategy extends DefaultQuoteStrategy
{
    /**
     * Quotes an identifier — except `id` and `lang`.
     *
     * `instanceof` instead of `getName()` (009-005-0002): AbstractPlatform::getName() is
     * deprecated in DBAL 3 and is removed in DBAL 4. AbstractMySQLPlatform covers MySQL,
     * MariaDB and the versioned descendants — getName() returned 'mysql' for all of them.
     */
    private function quote(string $token, AbstractPlatform $platform): string
    {
        if ($platform instanceof AbstractMySQLPlatform) {
            return $token === 'id' || $token === 'lang' ? $token : '`' . $token . '`';
        }

        return $token;
    }

    public function getColumnName(string $fieldName, ClassMetadata $class, AbstractPlatform $platform): string
    {
        // ORM 3 turns the mapping arrays into objects: `['columnName']` becomes `->columnName`.
        return $this->quote($class->fieldMappings[$fieldName]->columnName, $platform);
    }

    public function getTableName(ClassMetadata $class, AbstractPlatform $platform): string
    {
        return $this->quote($class->table['name'], $platform);
    }

    public function getSequenceName(array $definition, ClassMetadata $class, AbstractPlatform $platform): string
    {
        return $definition['sequenceName'];
    }

    public function getJoinColumnName(JoinColumnMapping $joinColumn, ClassMetadata $class, AbstractPlatform $platform): string
    {
        return $this->quote($joinColumn->name, $platform);
    }

    public function getReferencedJoinColumnName(
        JoinColumnMapping $joinColumn,
        ClassMetadata $class,
        AbstractPlatform $platform
    ): string {
        return $this->quote($joinColumn->referencedColumnName, $platform);
    }

    public function getJoinTableName(
        ManyToManyOwningSideMapping $association,
        ClassMetadata $class,
        AbstractPlatform $platform
    ): string {
        return $this->quote($association->joinTable->name, $platform);
    }

    /**
     * The field names, unquoted.
     *
     * Remains overridden. Doctrine's version returns quoted column names; this one returns the
     * bare field names — the deviation is older than 009-005 and is not touched during the
     * ORM 3 jump either, because it has nothing to do with it.
     *
     * @return array<int,string>
     */
    public function getIdentifierColumnNames(ClassMetadata $class, AbstractPlatform $platform): array
    {
        return $class->identifier;
    }
}
