<?php
namespace Areanet\PIM\Classes\ORM\Mapping;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\DefaultQuoteStrategy;
use Doctrine\ORM\Mapping\JoinColumnMapping;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;

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
 *
 * MIT 010-003-0002 SIND ALLE SIGNATUREN TYPISIERT. ORM 3 deklariert `DefaultQuoteStrategy`
 * durchgehend mit Parameter- und Rueckgabetypen; eine Ableitung, die das nicht trifft, wird
 * beim LADEN abgelehnt. Diese Klasse war damit der zweite von zwei Blockern des Sprungs —
 * ein Fatal, bevor irgendein Test lief.
 *
 * Zwei Parameter sind dabei von `array` zu Objekten geworden (`JoinColumnMapping`,
 * `ManyToManyOwningSideMapping`), und im Rumpf werden aus Array-Zugriffen Objektzugriffe.
 * Gemessen betrifft dieser Wandel im ganzen eigenen Code nur diese Klasse.
 */
class ContentflyQuoteStrategy extends DefaultQuoteStrategy
{
    /**
     * Quotiert einen Bezeichner — ausser `id` und `lang`.
     *
     * `instanceof` statt `getName()` (009-005-0002): AbstractPlatform::getName() ist in
     * DBAL 3 deprecated und faellt in DBAL 4 weg. AbstractMySQLPlatform deckt MySQL,
     * MariaDB und die versionierten Abkoemmlinge ab — getName() lieferte fuer alle 'mysql'.
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
        // ORM 3 macht aus den Mapping-Arrays Objekte: `['columnName']` wird `->columnName`.
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
     * Die Feldnamen, unquotiert.
     *
     * Bleibt ueberschrieben. Doctrines Fassung liefert quotierte Spaltennamen; diese hier die
     * blossen Feldnamen — die Abweichung ist aelter als 009-005 und wird auch beim ORM-3-Sprung
     * nicht angefasst, weil sie mit ihm nichts zu tun hat.
     *
     * @return array<int,string>
     */
    public function getIdentifierColumnNames(ClassMetadata $class, AbstractPlatform $platform): array
    {
        return $class->identifier;
    }
}
