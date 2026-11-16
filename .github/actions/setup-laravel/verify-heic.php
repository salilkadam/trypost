<?php

declare(strict_types=1);

/*
 * CI preflight: the HEIC tests skip when Imagick reports no HEIC format and
 * fail when it reports one it cannot decode. Neither may happen silently in
 * CI, so this proves a real decode of the fixture the tests use and exits
 * non-zero, naming the missing piece, otherwise.
 *
 * usage: php verify-heic.php <path to sample.heic>
 */

$fail = static function (string $message): never {
    fwrite(STDERR, "HEIC preflight FAILED: {$message}\n");
    exit(1);
};

$fixture = $argv[1] ?? '';

if ($fixture === '' || ! is_file($fixture)) {
    $fail("fixture not found: '{$fixture}'");
}

if (! extension_loaded('imagick')) {
    $fail('the imagick extension is not loaded (HEIC tests would be skipped)');
}

if (Imagick::queryFormats('HEIC') === []) {
    $fail('Imagick lists no HEIC format: ImageMagick was built without libheif (HEIC tests would be skipped)');
}

try {
    $image = new Imagick();
    $image->readImage($fixture);
} catch (Throwable $e) {
    $fail('Imagick lists HEIC but cannot decode it, usually a missing libheif HEVC decoder plugin (libheif-plugin-libde265): '.$e->getMessage());
}

$width = $image->getImageWidth();
$height = $image->getImageHeight();

if ($width < 1 || $height < 1) {
    $fail("decoded image has no pixels ({$width}x{$height})");
}

$version = Imagick::getVersion()['versionString'] ?? 'unknown';
fwrite(STDOUT, "HEIC preflight OK: decoded {$fixture} to {$width}x{$height} with {$version}\n");
