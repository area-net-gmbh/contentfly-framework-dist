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
 * Removes named arguments from an attribute that stays (007-002-0004).
 *
 * ── Why this rule exists ──────────────────────────────────────────────────────────────
 *
 * With Epic `012`, 14 fields of `@PIM\Config` were dropped, plus two of `@PIM\Checkbox`
 * and three of `@PIM\Radio`. **The annotations themselves stay** — only fields are dropped
 * from them. And Rector has no rule for that: `RemoveAnnotationRector` removes an entire
 * annotation, `ArgumentRemoverRector` works on method calls. Checked on 2026-09-11 across all
 * configurable rules.
 *
 * **What happens without it is no cosmetic flaw.** After the switch to attributes, the code
 * reads `#[PIM\Config(label: 'Article')]`, and `Config::__construct()` no longer has a
 * `$label`:
 *
 *     Unknown named parameter $label
 *
 * A fatal error when loading the entity. The migration would therefore not be incomplete,
 * but broken.
 *
 * ── The cases on which something like this fails ──────────────────────────────────────
 *
 * All five are in the touchstone fixture and are checked individually:
 *
 *   - a single field, and it is dropped  -> the attribute remains WITHOUT parentheses
 *   - the first of several
 *   - the last of several
 *   - a multi-line declaration
 *   - a dropped and a remaining field side by side
 *
 * ── The name arrives fully qualified, and that is why this holds up ───────────────────
 *
 * `$attribute->name->toString()` returns the resolved name, not the written one — verified by
 * measurement. A project may therefore import the annotations under any alias; the rule still
 * finds them. The same property that `007-002-0003` relies on.
 *
 * ── Where it lives ────────────────────────────────────────────────────────────────────
 *
 * In the package, not in the development repo: the framework owes its users the migration
 * path, and a project thus gets the rule with `composer require`. It is loaded exclusively by
 * a `rector.php` and never at runtime — which is why `rector/rector` is in the package's
 * `suggest` and not in `require`.
 */
final class RemovedAttributeFieldsRector extends AbstractRector implements ConfigurableRectorInterface
{
    /**
     * Attribute class (fully qualified) => names of the fields that are dropped.
     *
     * @var array<string,array<int,string>>
     */
    private array $removed = [];

    /**
     * @param array<string,array<int,string>> $configuration
     */
    public function configure(array $configuration): void
    {
        $this->removed = $configuration;
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Removes named arguments from an attribute that stays.',
            [
                new ConfiguredCodeSample(
                    "#[PIM\\Config(label: 'Article', excludeFromSync: true)]",
                    '#[PIM\\Config(excludeFromSync: true)]',
                    ['Areanet\\PIM\\Classes\\Annotations\\Config' => ['label']]
                ),
            ]
        );
    }

    /**
     * Class and property — the two levels on which `@PIM` declarations appear.
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
        $changed = false;

        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $fields = $this->removed[$attribute->name->toString()] ?? null;

                if ($fields === null || $attribute->args === []) {
                    continue;
                }

                $remaining = array_values(array_filter(
                    $attribute->args,
                    static function (Arg $argument) use ($fields): bool {
                        // An argument without a name is positional; removing it would shift
                        // the remaining ones. Such arguments are left untouched.
                        if ($argument->name === null) {
                            return true;
                        }

                        return !in_array($argument->name->toString(), $fields, true);
                    }
                ));

                if (count($remaining) === count($attribute->args)) {
                    continue;
                }

                $attribute->args = $remaining;
                $changed      = true;
            }
        }

        return $changed ? $node : null;
    }
}
