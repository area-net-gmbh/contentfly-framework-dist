<?php
namespace Areanet\PIM\Classes\File\Backend;

use Areanet\PIM\Entity\File;
use Areanet\PIM\Classes\Kernel\Paths;
use Areanet\PIM\Classes\File\BackendInterface;
use Areanet\PIM\Classes\Exceptions\ContentflyException;
use Areanet\PIM\Classes\File\FilePath;
use Areanet\PIM\Classes\Messages;
use Areanet\PIM\Entity\ThumbnailSetting;

class FileSystem implements BackendInterface
{
    public function getPath(File $file)
    {
        $path = $this->directory($file);

        if(!is_dir($path)) mkdir($path);

        return $path;
    }

    public function getWebPath(File $file)
    {
        $path = $this->directory($file);

        if(!is_dir($path)) mkdir($path);

        return '/data/files/'.$file->getId();
    }

    /**
     * THE RECORD'S OWN DIRECTORY, PROVEN (015-000-0005).
     *
     * Every method here used to concatenate `Paths::data().'/files/'.$file->getId()`. The id
     * comes out of the request — `/file/upload` takes it from the body, `/api/insert` from
     * `data.id` — and with `DB_GUID_STRATEGY` the column is a free string. An id of
     * `../cache/x` pointed this at `data/cache`, and what followed was not reading but writing:
     * `move_uploaded_file()` put the upload there, `Api::doDelete()` emptied it,
     * `FileController::overwriteAction()` did the same to the target's directory.
     *
     * `FileFieldGuard` keeps such an id out of the column now. This is the layer below it, for
     * a record that already carries one — and for every caller that will be written later.
     *
     * @throws ContentflyException 400 when the id cannot name a directory inside `data/files`
     */
    private function directory(File $file): string
    {
        $path = FilePath::within(Paths::data().'/files', (string) $file->getId());

        if($path === null){
            throw new ContentflyException(
                Messages::contentfly_general_invalid_params,
                'PIM\\File::id',
                Messages::contentfly_status_bad_request
            );
        }

        return $path;
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

        $directory = $this->getPath($file);

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
        $path = FilePath::within($directory, $variant.$sizeUri.$fileName);

        return $path ?? $directory.'/'.self::NO_SUCH_FILE;
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