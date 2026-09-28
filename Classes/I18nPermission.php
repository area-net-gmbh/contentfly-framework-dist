<?php
namespace Areanet\PIM\Classes;

use Areanet\PIM\Entity\Group;
use Areanet\PIM\Entity\User;
use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;

/**
 * WHAT `lang` MEANS IS DECIDED HERE (015-000-0011).
 *
 * The parameter used to travel from the request into `Group::langIsWritable()`, where it was
 * looked up as an ARRAY KEY — case-sensitively — while the record was then selected with
 * `a.lang = :lang` under `utf8mb3_unicode_ci`, which ignores case and trailing spaces. `EN`, `En`
 * and `en ` therefore counted as "no restriction configured" and hit the `en` row all the same:
 * a group limited to `{"en": "readable"}` changed, deleted and created English content.
 *
 * Two layers close that. `Language::fromRequest()` normalises the value at the edge and refuses
 * one that names no configured language with a 400. And here, where the application context
 * already is, a value that is not a configured language is refused rather than waved through —
 * because "unknown key means unrestricted" was the second half of the finding, and a second
 * entry point must not be able to reopen it.
 *
 * THE CHECK IS NOT IN `Group`. Those methods rest on depending on nothing but the group itself;
 * reaching for `APP_LANGUAGES` from an entity made the existing unit test raise warnings out of
 * the config factory. `Group` normalises, this class decides.
 */
class I18nPermission
{
    const IS_READABLE       = 'readable';
    const IS_TRANSLATABALE  = 'translatable';

    /**
     * Is this a language this installation knows?
     *
     * Without `APP_LANGUAGES` every value counts as known — see `Language::isConfigured()`. An
     * installation with i18n entities and no configured languages would otherwise be unable to
     * write anything at all.
     */
    private static function isKnownLanguage($lang): bool
    {
        return Language::isConfigured(Language::normalise($lang));
    }

    public static function isWritable(Application $app, $entityName, $lang){
        /** @var $user User */
        $user   = $app['auth.user'];
        $schema = $app['schema'];

        $helper = new Helper();
        $entityShortName = $helper->getShortEntityName($entityName);
        $entitySchema = $schema[$entityShortName];

        if($user->getIsAdmin()){
            return true;
        }

        /** @var $group Group */
        if(!($group = $user->getGroup())){
            return false;
        }

        if(empty($entitySchema['settings']['i18n'])){
            return true;
        }

        if(!self::isKnownLanguage($lang)){
            return false;
        }

        return $group->langIsWritable($lang);

    }

    public static function isTranslatable( Application $app, $entityName, $lang){
        /** @var $user User */
        $user   = $app['auth.user'];
        $schema = $app['schema'];

        $helper = new Helper();
        $entityShortName = $helper->getShortEntityName($entityName);
        $entitySchema = $schema[$entityShortName];

        if($user->getIsAdmin()){
            return true;
        }

        /** @var $group Group */
        if(!($group = $user->getGroup())){
            return false;
        }

        if(empty($entitySchema['settings']['i18n'])){
            return true;
        }

        if(!self::isKnownLanguage($lang)){
            return false;
        }

        return $group->langIsTranslatable($lang);

    }

    public static function isOnlyReadable( Application $app, $entityName, $lang){
        /** @var $user User */
        $user   = $app['auth.user'];
        $schema = $app['schema'];

        $helper = new Helper();
        $entityShortName = $helper->getShortEntityName($entityName);
        $entitySchema = $schema[$entityShortName];

        if($user->getIsAdmin()){
            return false;
        }

        /** @var $group Group */
        if(!($group = $user->getGroup())){
            return true;
        }

        if(empty($entitySchema['settings']['i18n'])){
            return false;
        }

        // Not a language this installation knows: neither writable nor translatable, so the
        // read-only answer is the consistent one — and the one that refuses a write.
        if(!self::isKnownLanguage($lang)){
            return true;
        }

        return $group->langisOnlyReadable($lang);

    }

}