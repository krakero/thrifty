<?php

use App\Agent\Thumbnailer;

it('pads the crop like the web thumbnail and clamps it to the frame', function () {
    expect(Thumbnailer::paddedCrop(['xMin' => 100, 'yMin' => 200, 'xMax' => 500, 'yMax' => 700], 400, 300))
        ->toBe(['x' => 11, 'y' => 33, 'width' => 218, 'height' => 204]);

    expect(Thumbnailer::paddedCrop(['xMin' => 0, 'yMin' => 0, 'xMax' => 1000, 'yMax' => 1000], 400, 300))
        ->toBe(['x' => 0, 'y' => 0, 'width' => 400, 'height' => 300]);
});

it('returns null for an unreadable frame or an empty box', function () {
    $thumbnailer = new Thumbnailer;

    expect($thumbnailer->crop('not an image', ['xMin' => 0, 'yMin' => 0, 'xMax' => 10, 'yMax' => 10]))->toBeNull()
        ->and($thumbnailer->crop('whatever', ['xMin' => 10, 'yMin' => 0, 'xMax' => 10, 'yMax' => 10]))->toBeNull();
});

it('crops several boxes from one decode', function () {
    $image = imagecreatetruecolor(400, 300);
    ob_start();
    imagejpeg($image);
    $frame = ob_get_clean();

    $crops = (new Thumbnailer)->cropAll($frame, [
        ['xMin' => 100, 'yMin' => 200, 'xMax' => 500, 'yMax' => 700],
        ['xMin' => 500, 'yMin' => 0, 'xMax' => 500, 'yMax' => 10],
    ]);

    expect($crops)->toHaveCount(2)
        ->and(getimagesizefromstring($crops[0])[0])->toBe(218)
        ->and($crops[1])->toBeNull();
});
