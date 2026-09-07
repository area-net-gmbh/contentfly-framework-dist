<?php
namespace Areanet\PIM\Classes\Annotations;

use Doctrine\Common\Annotations\Annotation;

/**
 * Mehrfachauswahl auf einer ManyToMany-Beziehung.
 *
 * Bleibt trotz des UI-Rückbaus: die Annotation wählt den `CheckboxType` aus, der die
 * Collection auflöst und die Leseberechtigungen prüft, und `group` benennt die
 * OptionGroup, die dabei angelegt wird. Die reinen Darstellungsfelder sind entfallen.
 *
 * @Annotation
 */
final class Checkbox extends Annotation
{
    /**
     * @var string
     */
    public $group = null;

}
