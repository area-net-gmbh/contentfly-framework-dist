<?php
namespace Areanet\PIM\Classes\ORM\Mapping;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\DefaultQuoteStrategy;

/**
 * Quotiert jeden Bezeichner ausser `id` und `lang` — die eigene Regel des Frameworks.
 *
 * ERBT SEIT 009-005-0002 VON `DefaultQuoteStrategy`, statt `QuoteStrategy` selbst zu
 * implementieren. Der Grund ist `getColumnAlias()`: Die eigene Fassung rief
 * `AbstractPlatform::getSQLResultCasing()`, und die Methode gibt es in DBAL 3 nicht mehr —
 * jede DQL-Abfrage starb daran, also praktisch die ganze Anwendung.
 *
 * Doctrines Fassung tut fuer MySQL dasselbe (`spalte_zaehler`), kuerzt zusaetzlich auf die
 * maximale Bezeichnerlaenge der Plattform und entfernt Sonderzeichen. Sie zu erben statt sie
 * abzuschreiben heisst, dass der naechste Doctrine-Sprung sie mitbringt; abgeschrieben waere
 * sie beim uebernaechsten wieder falsch.
 *
 * Alle uebrigen Methoden bleiben ueberschrieben: Doctrine quotiert nur, was in den Metadaten
 * als `quoted` markiert ist, dieses Framework quotiert grundsaetzlich. Das ist der Zweck der
 * Klasse und aendert sich hier nicht.
 */
class ContentflyQuoteStrategy extends DefaultQuoteStrategy
{
    private function quote($token, AbstractPlatform $platform)
    {
        /*
         * `instanceof` statt `getName()` (009-005-0002): AbstractPlatform::getName() ist in
         * DBAL 3 deprecated und faellt in DBAL 4 weg. AbstractMySQLPlatform deckt MySQL,
         * MariaDB und die versionierten Abkoemmlinge ab — getName() lieferte fuer alle 'mysql'.
         */
        if ($platform instanceof AbstractMySQLPlatform) {
            return $token == 'id' || $token == 'lang' ? $token : '`' . $token . '`';
        }

        return $token;
    }

    /**
     * {@inheritdoc}
     */
    public function getColumnName($fieldName, ClassMetadata $class, AbstractPlatform $platform)
    {
        return $this->quote($class->fieldMappings[$fieldName]['columnName'], $platform);
    }

    /**
     * {@inheritdoc}
     */
    public function getTableName(ClassMetadata $class, AbstractPlatform $platform)
    {
        return $this->quote($class->table['name'], $platform);
    }

    /**
     * {@inheritdoc}
     */
    public function getSequenceName(array $definition, ClassMetadata $class, AbstractPlatform $platform)
    {
        return $definition['sequenceName'];
    }

    /**
     * {@inheritdoc}
     */
    public function getJoinColumnName(array $joinColumn, ClassMetadata $class, AbstractPlatform $platform)
    {
        return $this->quote($joinColumn['name'], $platform);
    }

    /**
     * {@inheritdoc}
     */
    public function getReferencedJoinColumnName(array $joinColumn, ClassMetadata $class, AbstractPlatform $platform)
    {
        return $this->quote($joinColumn['referencedColumnName'], $platform);
    }

    /**
     * {@inheritdoc}
     */
    public function getJoinTableName(array $association, ClassMetadata $class, AbstractPlatform $platform)
    {
        return $this->quote($association['joinTable']['name'], $platform);
    }

    /**
     * Die Feldnamen, unquotiert.
     *
     * Bleibt ueberschrieben. Doctrines Fassung liefert quotierte Spaltennamen; diese hier die
     * blossen Feldnamen — die Abweichung ist aelter als 009-005 und wird hier nicht angefasst,
     * weil sie mit dem Schnitt nichts zu tun hat.
     */
    public function getIdentifierColumnNames(ClassMetadata $class, AbstractPlatform $platform)
    {
        return $class->identifier;
    }
}
