<?php
namespace Areanet\PIM\Classes\File\Processing;

use Areanet\PIM\Classes\Config\Adapter;
use Areanet\PIM\Classes\Exceptions\ContentflyException;
use Areanet\PIM\Classes\Messages;
use Areanet\PIM\Classes\File\ProcessingInterface;
use Areanet\PIM\Classes\File\BackendInterface;
use Areanet\PIM\Entity\File;
use Areanet\PIM\Entity\ThumbnailSetting;

class Image implements ProcessingInterface
{

    protected $thumbnailSettings = array();

    protected $mimeMapping = array(
        'image/jpeg' => 'jpeg',
        'image/jpg' => 'jpeg',
        'image/gif' => 'gif',
        'image/png' => 'png'
    );
    protected $qualityMapping = null;

    function __construct(){
        $this->qualityMapping = array(
            'image/jpeg'    => Adapter::getConfig()->FILE_IMAGE_QUALITY_JPEG,
            'image/jpg'     => Adapter::getConfig()->FILE_IMAGE_QUALITY_JPEG,
            'image/gif'     => null,
            'image/png'     => Adapter::getConfig()->FILE_IMAGE_QUALITY_PNG
        );
    }

    public function registerImageSize(ThumbnailSetting $thumbnailSetting): void
    {
        $this->thumbnailSettings[$thumbnailSetting->getAlias()] = $thumbnailSetting;
    }

    public function getMimeTypes()
    {
        return array('image/jpeg', 'image/gif', 'image/png');
    }

    public function execute(BackendInterface $backend, File $fileObject, $fileSizeAlias = null, $variant = null): void
    {
        if(!isset($this->mimeMapping[$fileObject->getType()])){
            return;
        }

        if($fileSizeAlias && !isset($this->thumbnailSettings[$fileSizeAlias])){
            throw new \Areanet\PIM\Classes\Exceptions\FileNotFoundException("FileSizeSetting not found");
        }

        $type           = $this->mimeMapping[$fileObject->getType()];
        $loadMethodName = "imagecreatefrom$type";
        $saveMethodName = "image$type";

        if(!function_exists($loadMethodName)){
            throw new \Exception("GDLib function $loadMethodName is not available on the server.");
        }

        $imgName = $backend->getPath($fileObject).'/'.$fileObject->getName();
        $img     = null;

        if($type == 'jpeg' && function_exists("exif_read_data")){

            $exif = null;
            try {
                $exif = exif_read_data($imgName);
            }catch(\Exception $e){

            }

            if($exif && !empty($exif['Orientation'])) {
                switch($exif['Orientation']) {
                    case 8:
                        $img0 = $this->load($loadMethodName, $imgName);
                        $img  = imagerotate($img0,90,0);
                        break;
                    case 3:
                        $img0 = $this->load($loadMethodName, $imgName);
                        $img  = imagerotate($img0,180,0);
                        break;
                    case 6:
                        $img0 = $this->load($loadMethodName, $imgName);
                        $img  = imagerotate($img0,-90,0);
                        break;
                    default:
                        $img = $this->load($loadMethodName, $imgName);
                        break;
                }
            }else{
                $img = $this->load($loadMethodName, $imgName);
            }
        }else{
            $img = $this->load($loadMethodName, $imgName);
        }

        /*
         * A HEADER THAT PARSES IS NOT AN IMAGE THAT DECODES (000-000-0068).
         *
         * `UploadValidator` rejects what the header already gives away. A file with a valid header
         * and a broken body still gets here, and GD answers with `false` — which the lines below
         * handed to `imagesx()` as if it were an image: a TypeError and a 500. Now it is the same
         * 415 the validator gives, and the caller removes what was stored.
         */
        if(!$img){
            throw new ContentflyException(Messages::contentfly_file_invalid_type, $fileObject->getType(), 415);
        }

        if($fileObject->getType() == 'image/png') {
            imagealphablending($img, false);
            imagesavealpha($img, true);
        }

        foreach($this->thumbnailSettings as $thumbnailSetting){


            if($fileSizeAlias && $fileSizeAlias != $thumbnailSetting->getAlias()){
                continue;
            }

            $imgThumbName   = $backend->getPath($fileObject).'/'.$thumbnailSetting->getAlias().'-'.$fileObject->getName();

            if($thumbnailSetting->getForceJpeg()){
                $imgThumbNameList = explode('.', $imgThumbName);
                $imgThumbNameList[(count($imgThumbNameList) -  1)] = 'jpg';
                $imgThumbName = implode('.', $imgThumbNameList);
            }

            if(!$variant || !file_exists($imgThumbName)) {
                $orig_width = imagesx($img);
                $orig_height = imagesy($img);

                $thumb = null;

                if ($thumbnailSetting->getWidth() && $thumbnailSetting->getHeight()) {
                    if ($thumbnailSetting->getDoCut()) {
                        $thumb = $this->resizeByCutting($fileObject, $img, $thumbnailSetting);
                    } else {
                        if ($orig_width > $orig_height) {
                            $thumb = $this->resizeByWidth($fileObject, $img, $thumbnailSetting);
                        } else {
                            $thumb = $this->resizeByHeight($fileObject, $img, $thumbnailSetting);
                        }
                    }
                } elseif ($thumbnailSetting->getWidth()) {
                    $thumb = $this->resizeByWidth($fileObject, $img, $thumbnailSetting);
                } elseif ($thumbnailSetting->getHeight()) {
                    $thumb = $this->resizeByHeight($fileObject, $img, $thumbnailSetting);
                } elseif ($thumbnailSetting->getPercent()) {
                    $thumb = $this->resizePercentual($fileObject, $img, $thumbnailSetting);
                }

                if ($thumb) {
                    $imgThumbName = $backend->getPath($fileObject) . '/' . $thumbnailSetting->getAlias() . '-' . $fileObject->getName();
                    if ($thumbnailSetting->getForceJpeg()) {
                        $imgThumbNameList = explode('.', $imgThumbName);
                        $imgThumbNameList[(count($imgThumbNameList) - 1)] = 'jpg';
                        $imgThumbName = implode('.', $imgThumbNameList);
                        imagejpeg($thumb, $imgThumbName, $this->qualityMapping['image/jpeg']);
                    } else {
                        $this->save($saveMethodName, $thumb, $imgThumbName, $this->qualityMapping[$fileObject->getType()]);
                    }

                }
            }


            if($thumbnailSetting->getIsResponsive() &&  $variant != '1x' || $variant == '2x' ) {
                $quality        = $this->qualityMapping[$fileObject->getType()];
                $imgThumbName   = $backend->getPath($fileObject).'/'.$thumbnailSetting->getAlias().'-'.$fileObject->getName();

                if($thumbnailSetting->getForceJpeg()){
                    $imgThumbNameList = explode('.', $imgThumbName);
                    $imgThumbNameList[(count($imgThumbNameList) -  1)] = 'jpg';
                    $imgThumbName = implode('.', $imgThumbNameList);
                }

                if($thumbnailSetting->getForceJpeg()){
                    $loadMethodName = 'imagecreatefromjpeg';
                    $saveMethodName = 'imagejpeg';
                    $quality        = $this->qualityMapping['image/jpeg'];
                }

                $imgThumb   = $loadMethodName($imgThumbName);
                $thumb = $this->resizeByPercent($fileObject, $imgThumb, 2/3*100);

                $imgThumbNameList = explode("/", $imgThumbName);
                $imgThumbNameList[count($imgThumbNameList) - 1] = "2x@" . $imgThumbNameList[count($imgThumbNameList) - 1];
                $imgThumbName2x = implode('/', $imgThumbNameList);

                $this->save($saveMethodName, $thumb, $imgThumbName2x, $quality);


            }

            if($thumbnailSetting->getIsResponsive() &&  $variant != '2x' || $variant == '1x' ) {
                $quality        = $this->qualityMapping[$fileObject->getType()];
                $imgThumbName   = $backend->getPath($fileObject).'/'.$thumbnailSetting->getAlias().'-'.$fileObject->getName();


                if($thumbnailSetting->getForceJpeg()){
                    $imgThumbNameList = explode('.', $imgThumbName);
                    $imgThumbNameList[(count($imgThumbNameList) -  1)] = 'jpg';
                    $imgThumbName = implode('.', $imgThumbNameList);
                }

                if($thumbnailSetting->getForceJpeg()){
                    $loadMethodName = 'imagecreatefromjpeg';
                    $saveMethodName = 'imagejpeg';
                    $quality        = $this->qualityMapping['image/jpeg'];
                }

                $imgThumb   = $loadMethodName($imgThumbName);
                $thumb = $this->resizeByPercent($fileObject, $imgThumb, 1/3*100);

                $imgThumbNameList = explode("/", $imgThumbName);
                $imgThumbNameList[count($imgThumbNameList) - 1] = "1x@" . $imgThumbNameList[count($imgThumbNameList) - 1];
                $imgThumbName2x = implode('/', $imgThumbNameList);

                $this->save($saveMethodName, $thumb, $imgThumbName2x, $quality);


            }



        }
    }


    /**
     * Decodes the uploaded original. GD reports a broken file with a warning AND `false`; the
     * warning would become an exception in debug mode before `execute()` can turn the `false`
     * into a 415, so it is silenced here and the `false` is the whole answer.
     */
    protected function load(string $loadMethodName, string $path): \GdImage|false
    {
        return @$loadMethodName($path);
    }

    /**
     * Writes a thumbnail. `imagegif()` takes no quality and since PHP 8 refuses a third argument
     * even when it is null — every GIF upload ended in 500 (found in 000-000-0068). The quality is
     * passed only where there is one.
     */
    protected function save(string $saveMethodName, \GdImage $image, string $path, ?int $quality): void
    {
        if ($quality === null) {
            $saveMethodName($image, $path);
            return;
        }

        $saveMethodName($image, $path, $quality);
    }

    protected function resizeByWidth($fileObject, $img, ThumbnailSetting $thumbnailSetting){
        $orig_width  = imagesx($img);
        $orig_height = imagesy($img);

        $width       = (int) $thumbnailSetting->getWidth() < $orig_width ? $thumbnailSetting->getWidth() : $orig_width;
        $height      = (int) (($orig_height * $width) / $orig_width);

        $thumb = imagecreatetruecolor($width, $height);

        if($fileObject->getType() == 'image/png') {

            if($thumbnailSetting->getBackgroundColor()){
                $colorName          = str_replace('#', '', $thumbnailSetting->getBackgroundColor());
                list($r, $g, $b)    = array_map('hexdec',str_split($colorName,2));

                $bgcolor = imageColorAllocate($thumb, $r, $g, $b);
                imagefilledrectangle($thumb, 0, 0, $width, $height, $bgcolor);
            }else{
                imagealphablending($thumb, false);
                imagesavealpha($thumb, true);
            }

        }

        imagecopyresampled($thumb, $img,
            0, 0, 0, 0,
            $width, $height,
            $orig_width, $orig_height);

        return $thumb;
    }

    protected function resizeByHeight($fileObject, $img, ThumbnailSetting $thumbnailSetting){
        $orig_width  = imagesx($img);
        $orig_height = imagesy($img);

        $height     = $thumbnailSetting->getHeight() < $orig_height ? $thumbnailSetting->getHeight() : $orig_height;
        $width      = max(1, (int) round(($orig_width * $height) / $orig_height));

        $thumb = imagecreatetruecolor($width, $height);

        if($fileObject->getType() == 'image/png') {
            if($thumbnailSetting->getBackgroundColor()){
                $colorName          = str_replace('#', '', $thumbnailSetting->getBackgroundColor());
                list($r, $g, $b)    = array_map('hexdec',str_split($colorName,2));

                $bgcolor = imageColorAllocate($thumb, $r, $g, $b);
                imagefilledrectangle($thumb, 0, 0, $width, $height, $bgcolor);
            }else{
                imagealphablending($thumb, false);
                imagesavealpha($thumb, true);
            }
        }

        imagecopyresampled($thumb, $img,
            0, 0, 0, 0,
            $width, $height,
            $orig_width, $orig_height);

        return $thumb;
    }

    protected function resizeByCutting($fileObject, $img, ThumbnailSetting $thumbnailSetting){
        $orig_width  = imagesx($img);
        $orig_height = imagesy($img);

        $width  = $thumbnailSetting->getWidth();
        $height  = $thumbnailSetting->getHeight();

        if($width > $height){
            if($orig_height/$orig_width >=  $height/$width){
                $width_resized  = $width;
                $height_resized = round($width/$orig_width * $orig_height);
            }else{
                $height_resized  = $height;
                $width_resized   = round($height/$orig_height * $orig_width);
            }
        }else{
            if($orig_width/$orig_height >=  $width/$height){
                $width_resized  = round($height/$orig_height * $orig_width);
                $height_resized = $height;
            }else{
                $height_resized = round($height/$orig_width * $orig_height);
                $width_resized  = $height;
            }
        }

        $image_resized = imagecreatetruecolor($width_resized, $height_resized);
        if($fileObject->getType() == 'image/png') {
            if($thumbnailSetting->getBackgroundColor()){
                $colorName          = str_replace('#', '', $thumbnailSetting->getBackgroundColor());
                list($r, $g, $b)    = array_map('hexdec',str_split($colorName,2));

                $bgcolor = imageColorAllocate($image_resized, $r, $g, $b);
                imagefilledrectangle($image_resized, 0, 0, $width_resized, $height_resized, $bgcolor);
            }else{
                imagealphablending($image_resized, false);
                imagesavealpha($image_resized, true);
            }
        }

        imagecopyresampled($image_resized, $img, 0, 0, 0, 0, $width_resized, $height_resized, $orig_width, $orig_height);

        $width  = $width_resized <  $width ? $width_resized : $width;
        $height = $height_resized <  $height ? $height_resized : $height;

        $thumb = imagecreatetruecolor($width, $height);
        if($fileObject->getType() == 'image/png') {
            if($thumbnailSetting->getBackgroundColor()){
                $colorName          = str_replace('#', '', $thumbnailSetting->getBackgroundColor());
                list($r, $g, $b)    = array_map('hexdec',str_split($colorName,2));

                $bgcolor = imageColorAllocate($image_resized, $r, $g, $b);
                imagefilledrectangle($thumb, 0, 0, $width, $height, $bgcolor);
            }else{
                imagealphablending($image_resized, false);
                imagesavealpha($thumb, true);
            }
        }
        $src_y = 0;
        $src_x = round(($width_resized - $width)/2);

        imagecopyresampled($thumb, $image_resized, 0, 0, $src_x, $src_y, $width, $height, $width, $height);


        return $thumb;
    }

    protected function resizePercentual($fileObject, $img, ThumbnailSetting $thumbnailSetting){

        return $this->resizeByPercent($fileObject, $img, $thumbnailSetting->getPercent());
    }

    protected function resizeByPercent($fileObject, $img, $sizePerCent){
        $sizeFactor  = $sizePerCent/100;

        $orig_width  = imagesx($img);
        $orig_height = imagesy($img);

        // Whole pixels, rounded (000-000-0068). The product is a float — 300 × 2/3 is 199.99… —
        // and GD truncated it silently, with a deprecation notice per call since PHP 8.1.
        $width       = max(1, (int) round($orig_width * $sizeFactor));
        $height      = max(1, (int) round($orig_height * $sizeFactor));

        $thumb = imagecreatetruecolor($width, $height);

        if($fileObject->getType() == 'image/png') {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
        }

        imagecopyresampled($thumb, $img,
            0, 0, 0, 0,
            $width, $height,
            $orig_width, $orig_height);

        return $thumb;
    }
}