<?php
namespace Areanet\PIM\Classes\Annotations;

use Doctrine\Common\Annotations\Annotation;

/**
 * Einfachauswahl auf einer ManyToOne-Beziehung.
 *
 * Bleibt trotz des UI-Rückbaus: die Annotation wählt den `RadioType` aus, der die
 * Beziehung auflöst und die Leseberechtigungen prüft, und `group` benennt die
 * OptionGroup, die dabei angelegt wird. Die reinen Darstellungsfelder sind entfallen.
 *
 * @Annotation
 */
final class Radio extends Annotation
{
    /**
     * @var string
     */
    public $group = null;

}
