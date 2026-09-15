<?php
namespace Areanet\PIM\Classes\Database;

/**
 * What every connection of the framework passes to PDO (000-000-0044).
 *
 * NUMBERS FROM RAW SQL STAY STRINGS, AS THEY WERE ON CONTENTFLY 1.x.
 *
 * Since PHP 8.1, `pdo_mysql` returns integers and floats as native PHP types when prepared statements
 * are emulated — and emulation is what DBAL uses. Nothing in the framework changed, but the wire did:
 * a project that builds a response from `$app['database']` now answers `"isIntern":0` where it
 * answered `"isIntern":"0"` on PHP 7.4. Measured on the existing project UFP (007-005-0004), whose
 * frontend compares strictly with `'1'`/`'0'` in about fifty places. None of them throws; they all
 * just turn false.
 *
 * `ATTR_STRINGIFY_FETCHES` restores the old behaviour for every connection. Entity hydration is not
 * affected: Doctrine converts each column through its mapped type, so an `integer` field is still an
 * `int` and a `boolean` field still a `bool`.
 *
 * A project that wants native types for its own queries casts in PHP or opens its own connection.
 * Switching the default back would silently change every existing client again.
 */
final class ConnectionDefaults
{
    /**
     * @return array<int,mixed> the `driverOptions` for `DriverManager::getConnection()`
     */
    public static function driverOptions(): array
    {
        return array(
            \PDO::ATTR_STRINGIFY_FETCHES => true,
        );
    }
}
