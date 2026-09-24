<?php

namespace Thrifty\Camera\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

/**
 * Live camera preview. While scanning, the renderer writes a JPEG into the
 * frames directory every `interval` seconds and emits FrameCaptured.
 */
class ThriftyCameraView extends Element
{
    public const FACINGS = ['back', 'front', 'off'];

    protected string $type = 'thrifty_camera';

    /** @var array{scanning: bool, interval: int, facing: string, frames_directory: string} */
    protected array $cameraProps = [
        'scanning' => false,
        'interval' => 3,
        'facing' => 'back',
        'frames_directory' => '',
    ];

    public static function make(): static
    {
        return new static;
    }

    public function scanning(bool $scanning = true): static
    {
        $this->cameraProps['scanning'] = $scanning;

        return $this;
    }

    public function interval(int $seconds): static
    {
        $this->cameraProps['interval'] = max(1, $seconds);

        return $this;
    }

    public function facing(string $facing): static
    {
        $this->cameraProps['facing'] = in_array($facing, self::FACINGS, true) ? $facing : 'back';

        return $this;
    }

    public function framesDirectory(string $directory): static
    {
        $this->cameraProps['frames_directory'] = $directory;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    public function applyAttributes(array $attrs): void
    {
        if (array_key_exists('scanning', $attrs)) {
            $this->scanning(filter_var($attrs['scanning'], FILTER_VALIDATE_BOOLEAN));
        }

        if (isset($attrs['interval'])) {
            $this->interval((int) $attrs['interval']);
        }

        if (isset($attrs['facing'])) {
            $this->facing((string) $attrs['facing']);
        }

        $directory = $attrs['frames-directory'] ?? $attrs['framesDirectory'] ?? $attrs['frames_directory'] ?? null;

        if ($directory !== null) {
            $this->framesDirectory((string) $directory);
        }

        $this->applyA11yAttributes($attrs);
    }

    /**
     * @return array{scanning: bool, interval: int, facing: string, frames_directory: string}
     */
    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->cameraProps;
    }
}
