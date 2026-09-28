<?php
namespace Areanet\PIM\Classes\File\Backend;

use Areanet\PIM\Entity\File;
use Areanet\PIM\Classes\Kernel\Paths;
use Areanet\PIM\Classes\File\BackendInterface;
use Areanet\PIM\Classes\File\FilePath;
use Areanet\PIM\Entity\ThumbnailSetting;

class FileSystem implements BackendInterface
{
    public function getPath(File $file)
    {

        if(!is_dir(Paths::data().'/files/'.$file->getId())) mkdir(Paths::data().'/files/'.$file->getId());
        return Paths::data().'/files/'.$file->getId();
    }

    public function getWebPath(File $file)
    {
        if(!is_dir(Paths::data().'/files/'.$file->getId())) mkdir(Paths::data().'/files/'.$file->getId());
        return '/data/files/'.$file->getId();
    }

    public function getUri(File $file, $size = null, $variant = null)
    {
        $fileName = $file->getName();
        $sizeUri  = '';

        switch($variant){
            case '1x':
            case '2x':
                $variant = $variant.'@';
                break;
            default:
                $variant = '';
                break;
        }

        if($size){
            if($size instanceof ThumbnailSetting){

                if($size->getForceJpeg()) {
                    $imgThumbNameList = explode('.', $fileName);
                    $imgThumbNameList[(count($imgThumbNameList) - 1)] = 'jpg';
                    $fileName = implode('.', $imgThumbNameList);
                }
                $sizeUri = $size->getAlias().'-';
            }else{
                $sizeUri = $size.'-';
            }

        }

        if(!is_dir(Paths::data().'/files/'.$file->getId())) mkdir(Paths::data().'/files/'.$file->getId());

        /*
         * THE DELIVERY PATH IS PROVEN, NOT ASSUMED (015-000-0003).
         *
         * This used to return the concatenation. Three of its four parts come from the framework
         * — the directory, the variant, the size prefix —, the fourth is `File.name`, and that
         * column was writable through the generic API with `../` in it.
         *
         * A name that leaves the directory is answered like a file that is not there: both
         * callers of this method check `file_exists()` on the result and raise a 404, and from
         * outside the two cases must stay indistinguishable. `$sizeUri` is checked with it — it
         * carries a `ThumbnailSetting` alias, which is also a column.
         */
        $path = FilePath::within(Paths::data().'/files/'.$file->getId(), $variant.$sizeUri.$fileName);

        return $path ?? Paths::data().'/files/'.$file->getId().'/'.self::NO_SUCH_FILE;
    }

    /**
     * Stands in for a name that leads out of the directory.
     *
     * A name, not an empty string: the callers pass the result to `file_exists()` and to
     * `filemtime()`, and a path ending in a slash would be the directory — which exists.
     */
    private const NO_SUCH_FILE = '.contentfly-no-such-file';

    public function getWebUri(File $file, $size = null, $variant = null)
    {
        $fileName = $file->getName();
        $sizeUri  = '';

        switch($variant){
            case '1x':
            case '2x':
                $variant = $variant.'@';
                break;
            default:
                $variant = '';
                break;
        }

        if($size){
            if($size instanceof ThumbnailSetting){
                if($size->getForceJpeg()) {
                    $imgThumbNameList = explode('.', $fileName);
                    $imgThumbNameList[(count($imgThumbNameList) - 1)] = 'jpg';
                    $fileName = implode('.', $imgThumbNameList);
                }
                $sizeUri = $size->getAlias().'/';
            }else{
                $sizeUri = $size.'/';
            }
        }

        return 'files/get/'.$file->getId().'/'.$variant.$sizeUri.$fileName;
    }


}