<?php
namespace Areanet\PIM\Classes\Command;

use Areanet\PIM\Classes\Kernel\Command;

/**
 * The base class for a project's commands.
 *
 * It prefixes the name with `custom:`, so that project commands never collide with those of
 * the framework — that is the contract described by `custom/app.php`.
 *
 * Since 009-001-0006 it extends `Kernel\Command` instead of `Knp\Command\Command` directly.
 */
class CustomCommand extends Command
{

    /**
     * Sets the name and prepends `custom:`.
     *
     * Signature since 009-002-0005 is `(string $name): static`. Symfony Console 7 declares it
     * that way, and an implementation must not loosen it — under Console 4 both were
     * untyped.
     */
    public function setName(string $name): static
    {
        return parent::setName('custom:'.$name);
    }
}