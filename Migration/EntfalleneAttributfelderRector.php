<?php
declare(strict_types=1);

namespace Areanet\PIM\Migration;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Property;
use Rector\Contract\Rector\ConfigurableRectorInterface;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ConfiguredCodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Entfernt benannte Argumente aus einem Attribut, das bleibt (007-002-0004).
 *
 * ── Warum es diese Regel gibt ─────────────────────────────────────────────────────────
 *
 * Mit Epic `012` sind 14 Felder von `@PIM\Config` entfallen, dazu zwei von `@PIM\Checkbox`
 * und drei von `@PIM\Radio`. **Die Annotationen selbst bleiben** — es fallen nur Felder
 * daraus weg. Und dafuer gibt es in Rector keine Regel: `RemoveAnnotationRector` entfernt
 * eine ganze Annotation, `ArgumentRemoverRector` arbeitet auf Methodenaufrufen. Nachgesehen
 * am 2026-09-11 ueber alle konfigurierbaren Regeln.
 *
 * **Was ohne sie passiert, ist kein Schoenheitsfehler.** Nach der Umstellung auf Attribute
 * steht dort `#[PIM\Config(label: 'Artikel')]`, und `Config::__construct()` hat kein
 * `$label` mehr:
 *
 *     Unknown named parameter $label
 *
 * Ein Fatal Error beim Laden der Entity. Die Migration waere also nicht unvollstaendig,
 * sondern kaputt.
 *
 * ── Die Faelle, an denen so etwas scheitert ───────────────────────────────────────────
 *
 * Alle fuenf stehen im Pruefstein und werden einzeln geprueft:
 *
 *   - ein einziges Feld, und es faellt  -> das Attribut bleibt OHNE Klammern stehen
 *   - das erste von mehreren
 *   - das letzte von mehreren
 *   - eine mehrzeilige Angabe
 *   - ein entfallenes und ein bleibendes Feld nebeneinander
 *
 * ── Der Name kommt vollqualifiziert, und das ist der Grund, warum es traegt ───────────
 *
 * `$attribut->name->toString()` liefert den aufgeloesten Namen, nicht den geschriebenen —
 * nachgemessen. Ein Projekt darf die Annotationen also unter jedem Alias importieren; die
 * Regel findet sie trotzdem. Dieselbe Eigenschaft, auf der `007-002-0003` beruht.
 *
 * ── Wo sie liegt ──────────────────────────────────────────────────────────────────────
 *
 * Im Paket, nicht im Entwicklungs-Repo: Das Framework schuldet seinen Benutzern den
 * Migrationsweg, und ein Projekt bekommt die Regel damit mit `composer require`. Sie wird
 * ausschliesslich von einer `rector.php` geladen und nie zur Laufzeit — deshalb steht
 * `rector/rector` im `suggest` des Pakets und nicht im `require`.
 */
final class EntfalleneAttributfelderRector extends AbstractRector implements ConfigurableRectorInterface
{
    /**
     * Attributklasse (vollqualifiziert) => Namen der Felder, die entfallen.
     *
     * @var array<string,array<int,string>>
     */
    private array $entfallen = [];

    /**
     * @param array<string,array<int,string>> $configuration
     */
    public function configure(array $configuration): void
    {
        $this->entfallen = $configuration;
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Entfernt benannte Argumente aus einem Attribut, das bleibt.',
            [
                new ConfiguredCodeSample(
                    "#[PIM\\Config(label: 'Artikel', excludeFromSync: true)]",
                    '#[PIM\\Config(excludeFromSync: true)]',
                    ['Areanet\\PIM\\Classes\\Annotations\\Config' => ['label']]
                ),
            ]
        );
    }

    /**
     * Klasse und Eigenschaft — die beiden Ebenen, auf denen `@PIM`-Angaben stehen.
     *
     * @return array<int,class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [Class_::class, Property::class];
    }

    /**
     * @param Class_|Property $node
     */
    public function refactor(Node $node): ?Node
    {
        $geaendert = false;

        foreach ($node->attrGroups as $gruppe) {
            foreach ($gruppe->attrs as $attribut) {
                $felder = $this->entfallen[$attribut->name->toString()] ?? null;

                if ($felder === null || $attribut->args === []) {
                    continue;
                }

                $bleiben = array_values(array_filter(
                    $attribut->args,
                    static function (Arg $argument) use ($felder): bool {
                        // Ein Argument ohne Namen ist positionsgebunden; es zu entfernen
                        // verschoebe die uebrigen. Solche Angaben bleiben unberuehrt.
                        if ($argument->name === null) {
                            return true;
                        }

                        return !in_array($argument->name->toString(), $felder, true);
                    }
                ));

                if (count($bleiben) === count($attribut->args)) {
                    continue;
                }

                $attribut->args = $bleiben;
                $geaendert      = true;
            }
        }

        return $geaendert ? $node : null;
    }
}
