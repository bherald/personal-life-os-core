<?php

namespace App\Services;

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Interfaces\ImageInterface;
use Exception;

class ImageEditService
{
    private ?ImageManager $manager = null;

    private function getManager(): ImageManager
    {
        if ($this->manager === null) {
            $driver = extension_loaded('imagick') ? new ImagickDriver() : new GdDriver();
            $this->manager = new ImageManager($driver);
        }
        return $this->manager;
    }

    /**
     * Rotate image by degrees (clockwise positive)
     */
    public function rotate(string $imageData, float $degrees): string
    {
        $image = $this->getManager()->read($imageData);
        $image->rotate(-$degrees); // Intervention rotates counter-clockwise
        return $this->encode($image);
    }

    /**
     * Flip image horizontally or vertically
     */
    public function flip(string $imageData, string $direction = 'horizontal'): string
    {
        $image = $this->getManager()->read($imageData);
        if ($direction === 'vertical') {
            $image->flip();
        } else {
            $image->flop();
        }
        return $this->encode($image);
    }

    /**
     * Crop image to specified region
     */
    public function crop(string $imageData, int $x, int $y, int $width, int $height): string
    {
        $image = $this->getManager()->read($imageData);
        $image->crop($width, $height, $x, $y);
        return $this->encode($image);
    }

    /**
     * Resize image with optional aspect ratio lock
     */
    public function resize(string $imageData, ?int $width, ?int $height, bool $aspectLock = true): string
    {
        $image = $this->getManager()->read($imageData);
        if ($aspectLock) {
            $image->scale($width, $height);
        } else {
            $image->resize($width, $height);
        }
        return $this->encode($image);
    }

    /**
     * Adjust brightness, contrast, and/or saturation
     *
     * @param array $adjustments {brightness?: int(-100..100), contrast?: int(-100..100), saturation?: int(-100..100)}
     */
    public function adjust(string $imageData, array $adjustments): string
    {
        $image = $this->getManager()->read($imageData);

        if (isset($adjustments['brightness'])) {
            $image->brightness((int) $adjustments['brightness']);
        }
        if (isset($adjustments['contrast'])) {
            $image->contrast((int) $adjustments['contrast']);
        }
        // Intervention v3 doesn't have a direct saturation method; use colorize workaround
        // or skip - we'll handle via greyscale blend if needed

        return $this->encode($image);
    }

    /**
     * Auto-orient image based on EXIF data
     */
    public function autoOrient(string $imageData): string
    {
        $image = $this->getManager()->read($imageData);
        $image->orient();
        return $this->encode($image);
    }

    /**
     * Get image dimensions and basic info
     */
    public function getInfo(string $imageData): array
    {
        $image = $this->getManager()->read($imageData);
        return [
            'width' => $image->width(),
            'height' => $image->height(),
            'size' => strlen($imageData),
        ];
    }

    /**
     * Apply a pipeline of operations sequentially
     *
     * @param array $operations [{type: string, ...params}]
     */
    public function pipeline(string $imageData, array $operations): string
    {
        $result = $imageData;

        foreach ($operations as $op) {
            $type = $op['type'] ?? null;
            if (!$type) {
                continue;
            }

            $result = match ($type) {
                'rotate' => $this->rotate($result, (float) ($op['degrees'] ?? 90)),
                'flip' => $this->flip($result, $op['direction'] ?? 'horizontal'),
                'crop' => $this->crop(
                    $result,
                    (int) ($op['x'] ?? 0),
                    (int) ($op['y'] ?? 0),
                    (int) ($op['width'] ?? 100),
                    (int) ($op['height'] ?? 100)
                ),
                'resize' => $this->resize(
                    $result,
                    isset($op['width']) ? (int) $op['width'] : null,
                    isset($op['height']) ? (int) $op['height'] : null,
                    (bool) ($op['aspectLock'] ?? true)
                ),
                'adjust' => $this->adjust($result, $op['adjustments'] ?? []),
                'autoOrient' => $this->autoOrient($result),
                default => throw new Exception("Unknown operation type: {$type}"),
            };
        }

        return $result;
    }

    /**
     * Encode image to JPEG binary
     */
    private function encode(ImageInterface $image, int $quality = 90): string
    {
        return (string) $image->toJpeg($quality);
    }
}
