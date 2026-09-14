<?php
namespace Areanet\PIM\Classes\Metadata;

/**
 * The single access point to an entity's metadata.
 *
 * WHY IT EXISTS (010-001-0002). Until then three places each built their own `AnnotationReader`:
 * `Classes/Api.php`, `Classes/Types/JoinBidirectionalType.php` and an unused import in the
 * `ApiController`. As long as they stood on their own, the switch from annotations to attributes
 * would have had to hit all of them at once — otherwise they would read docblocks that no longer
 * exist. Behind one class the switch was **one** change in **one** place: exactly this file, with
 * 010-001-0003.
 *
 * WHAT IT DOES (010-001-0003). It reads **PHP attributes** via reflection. The `AnnotationReader`
 * has thereby disappeared from the framework.
 *
 * THE SEMANTICS ARE THE SAME, and that is what matters:
 *
 * - **No inheritance.** `getClassAnnotations()` only returned the annotations of the class itself,
 *   not those of its parents; `ReflectionClass::getAttributes()` does the same.
 * - **A list in declaration order**, not a map. `Api.php` re-keys by class name itself and sorts
 *   with `krsort()`. That stays there: 22 files access it with
 *   `$propertyAnnotations['<fully qualified class name>']`, and an attribute's `getName()` returns
 *   exactly that form — proven in 010-001-0001.
 * - **Inherited properties.** `Api.php` passes in `new ReflectionProperty($subclass, $name)`; PHP
 *   resolves that to the declaring class, and the attributes come from there. The
 *   `AnnotationReader` did the same.
 *
 * WHY FOREIGN ATTRIBUTES COME ALONG: **everything** is read, not just `PIM` and `ORM` metadata.
 * The `Type` classes ask for their keys specifically, and `Api.php` dispatches every piece of
 * metadata as an event (`pim.schema.after.propertyAnnotation`) — a project can attach its own
 * metadata to that. Filtering would shut down that extension point.
 */
final class MetadataReader
{
    /**
     * The metadata of a class.
     *
     * @return object[] list, in declaration order
     */
    public function forClass(\ReflectionClass $class): array
    {
        return $this->instantiate($class->getAttributes());
    }

    /**
     * The metadata of a property.
     *
     * @return object[] list, in declaration order
     */
    public function forProperty(\ReflectionProperty $property): array
    {
        return $this->instantiate($property->getAttributes());
    }

    /**
     * Builds the attribute objects.
     *
     * An attribute whose class cannot be loaded is **skipped** instead of throwing. That is
     * deliberate and mirrors the old behaviour: the `AnnotationReader` had an ignore list and
     * skipped everything it could not resolve — `@param`, `@return` and every tool's doc tag. With
     * attributes the case is rarer but not impossible: an attribute from a package that is only
     * installed in development would otherwise bring down the whole schema generation.
     *
     * @param \ReflectionAttribute[] $attributes
     *
     * @return object[]
     */
    private function instantiate(array $attributes): array
    {
        $instances = array();

        foreach ($attributes as $attribute) {
            if (!class_exists($attribute->getName())) {
                continue;
            }

            $instances[] = $attribute->newInstance();
        }

        return $instances;
    }
}
