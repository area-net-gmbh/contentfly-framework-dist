<?php
/**
 * Created by PhpStorm.
 * User: ms
 * Date: 30.08.16
 * Time: 10:01
 */

namespace Areanet\PIM\Classes;


use Areanet\PIM\Entity\Base;
use Areanet\PIM\Entity\ThumbnailSetting;
use Areanet\PIM\Entity\User;
use Doctrine\ORM\EntityManager;

class Helper
{

    public function convertMomentFormatToPhp($format){
        $replacements = [
            'DD'   => 'd',
            'ddd'  => 'D',
            'D'    => 'j',
            'dddd' => 'l',
            'E'    => 'N',
            'o'    => 'S',
            'e'    => 'w',
            'DDD'  => 'z',
            'W'    => 'W',
            'MMMM' => 'F',
            'MM'   => 'm',
            'MMM'  => 'M',
            'M'    => 'n',
            'YYYY' => 'Y',
            'YY'   => 'y',
            'a'    => 'a',
            'A'    => 'A',
            'h'    => 'g',
            'H'    => 'G',
            'hh'   => 'h',
            'HH'   => 'H',
            'mm'   => 'i',
            'ss'   => 's',
            'SSS'  => 'u',
            'zz'   => 'e',
            'X'    => 'U',
        ];

        $phpFormat = strtr($format, $replacements);

        return $phpFormat;
    }

    public function getFullEntityName($entityName)
    {
        $entityFullName = null;

        if (substr($entityName, 0, 8) == 'Areanet\\' || substr($entityName, 0, 7) == 'Custom\\' || substr($entityName, 0, 8) == 'Plugins\\') {
            $entityFullName = $entityName;
        }elseif(substr($entityName, 0, 3) == 'PIM') {
            $entityFullName = 'Areanet\PIM\Entity\\' . substr($entityName, 4);
        }else{
            $entityFullName = 'Custom\Entity\\' . ucfirst($entityName);
        }

        return $entityFullName;
    }

    public function getShortEntityName($entityName)
    {
        $entityShortName = null;

        if (substr($entityName, 0, 8) == 'Areanet\\') {
            $entityShortName = 'PIM\\' . substr($entityName, 19);
        }elseif(substr($entityName, 0, 7) == 'Custom\\') {
            $entityShortName = substr($entityName, 14);
        }else{
            $entityShortName = ucfirst($entityName);
        }

        return $entityShortName;
    }

    public function getEntityName($entityName)
    {
        $entityNames = explode('\\', $entityName);

        return array_pop($entityNames);
    }

    public function getUsersRemoved(Base $currentObject, array $newData){

        $usersRemoved = array();

        if(isset($newData['userCreated'])){
            $userCreatedNewId = is_array($newData['userCreated']) ? $newData['userCreated']['id'] : $newData['userCreated'];

            if($currentObject->getUserCreated() && $currentObject->getUserCreated()->getId() != $userCreatedNewId){
                $usersRemoved[] = $currentObject->getUserCreated()->getId();
            }
        }

        if(isset($newData['users']) && $currentObject->getUsers(true)){
            $usersOldArr    = explode(',', $currentObject->getUsers(true));
            $usersNewArr    = is_array($newData['users']) ? $newData['users'] : explode(',', $newData['users']);

            $usersToRemove  = array_diff($usersOldArr, $usersNewArr);
            $usersRemoved   = array_merge($usersRemoved, $usersToRemove);
        }

        return $usersRemoved;
    }

    /**
     * The setup routine — base data that must exist.
     *
     * AN EXISTING ADMIN IS LEFT ALONE (015-000-0002).
     *
     * Until now this ran `setAlias`, `setLoginManager('')`, `setPass('admin')` and
     * `setIsAdmin(true)` on EVERY run, on a found account just as on a new one. A second
     * `appcms:setup` on a running instance therefore silently reset a password that had long
     * since been changed — back to `admin`, the value that was hard-wired here. One request to
     * `/auth/login` was then enough for an admin token, on the first attempt and thus far below
     * the login throttle.
     *
     * The account is now only ever written when it is created, and it is created WITHOUT a
     * usable password: `lockPassword()` puts an asterisk in the column, which matches no input.
     * Whoever gives it a password is the command that calls this — `appcms:install` or
     * `appcms:setup` —, because only there is there an output to show it in exactly once.
     *
     * @return bool whether the admin account was created by this run
     */
    public function install(EntityManager $em): bool{
        //Admin user
        $admin = $em->getRepository('Areanet\PIM\Entity\User')->findOneBy(array('alias' => 'admin'));
        $adminCreated = $admin === null;

        if($adminCreated){
            $admin = new User();
            $admin->setAlias("admin");
            $admin->setLoginManager('');
            $admin->setIsAdmin(true);
            $admin->lockPassword();

            $em->persist($admin);
        }

        //Image sizes
        $sizeList = $em->getRepository('Areanet\PIM\Entity\ThumbnailSetting')->findOneBy(array('alias' => 'pim_list'));
        if(!$sizeList){
            $sizeList = new ThumbnailSetting();
        }

        $sizeList->setAlias('pim_list');
        $sizeList->setWidth(200);
        $sizeList->setHeight(200);
        $sizeList->setDoCut(true);
        $sizeList->setIsIntern(true);

        $em->persist($sizeList);

        $sizeSmall = $em->getRepository('Areanet\PIM\Entity\ThumbnailSetting')->findOneBy(array('alias' => 'pim_small'));
        if(!$sizeSmall){
            $sizeSmall = new ThumbnailSetting();
        }

        $sizeSmall->setAlias('pim_small');
        $sizeSmall->setAlias('pim_small');
        $sizeSmall->setWidth(320);
        $sizeSmall->setIsIntern(true);

        $em->persist($sizeSmall);

        $em->flush();

        return $adminCreated;
    }

    /**
     * Gives the admin a password — and hands back the one it made up itself.
     *
     * THE RULE IS: NEVER UNASKED (015-000-0002). Without a given password, a password is only
     * set when the account has just been created. An existing one is not touched, because a
     * setup run is not a password reset.
     *
     * The return value is the GENERATED password and nothing else: a given one the caller
     * already knows, and printing it back would put it in the terminal's scrollback for no
     * reason. `null` therefore means "nothing was generated", not "nothing happened".
     */
    public function applyAdminPassword(EntityManager $em, bool $adminCreated, ?string $password = null): ?string
    {
        if($password === null && !$adminCreated){
            return null;
        }

        $generated = $password === null ? self::generatePassword() : null;

        $admin = $em->getRepository('Areanet\PIM\Entity\User')->findOneBy(array('alias' => 'admin'));
        if(!$admin){
            return null;
        }

        $admin->setPass($password ?? $generated);
        $em->persist($admin);
        $em->flush();

        return $generated;
    }

    /**
     * A password nobody has to think up.
     *
     * 24 hexadecimal characters from `random_bytes()` — 96 bits, unambiguous to read out and to
     * type. It is shown once by the command that created the account and is stored nowhere else.
     */
    public static function generatePassword(): string
    {
        return bin2hex(random_bytes(12));
    }


    public function createSymlink($path, $target, $link){
        if(!is_link($path.$target)){
            $this->deleteFolder($path.$target);

            if(!is_dir($path)){
                mkdir($path);
            }

            if(!chdir($path)){
                return array('symlink', "chdir to $path failed.");
            }
            if(!symlink($link, $target)){
                return array('symlink', "symlink $path.$target failed.");
            }
        }

        return array();
    }

    protected function deleteFolder($dir) {
        if(!file_exists($dir)){
            return null;
        }
        $files = array_diff(scandir($dir), array('.','..'));
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->deleteFolder("$dir/$file") : unlink("$dir/$file");
        }
        return rmdir($dir);
    }
}