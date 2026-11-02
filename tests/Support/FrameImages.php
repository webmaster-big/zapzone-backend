<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

trait FrameImages
{
    protected function framePng(int $width, int $height, bool $seeThrough = true): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, $seeThrough ? 127 : 0));
        $border = max(1, (int) round(min($width, $height) * 0.05));
        $gold = imagecolorallocatealpha($image, 250, 200, 20, 0);
        imagefilledrectangle($image, 0, 0, $width - 1, $border - 1, $gold);
        imagefilledrectangle($image, 0, $height - $border, $width - 1, $height - 1, $gold);
        imagefilledrectangle($image, 0, 0, $border - 1, $height - 1, $gold);
        imagefilledrectangle($image, $width - $border, 0, $width - 1, $height - 1, $gold);

        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    protected function frameUpload(string $name, int $width, int $height, bool $seeThrough = true): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->framePng($width, $height, $seeThrough));
    }
}
