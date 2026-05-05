<?php
session_start();

function generateCaptchaCode(int $length = 6): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // avoid confusing chars like 0/O/1/I
    $max   = strlen($chars) - 1;
    $code  = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $chars[random_int(0, $max)];
    }
    return $code;
}

$code = generateCaptchaCode();
$_SESSION['login_captcha'] = $code;

// If GD is not available, fall back to plain text so we avoid broken image icons
if (!function_exists('imagecreatetruecolor')) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo $code;
    exit;
}

$width  = 150;
$height = 46;

$image = imagecreatetruecolor($width, $height);

$bgColor      = imagecolorallocate($image, 240, 244, 255); // light background
$textColor    = imagecolorallocate($image, 15, 23, 42);    // dark text
$noiseColor1  = imagecolorallocate($image, 199, 210, 254); // soft blue
$noiseColor2  = imagecolorallocate($image, 148, 163, 184); // neutral gray

imagefilledrectangle($image, 0, 0, $width, $height, $bgColor);

// light lines as background texture
for ($i = 0; $i < 15; $i++) {
    imageline(
        $image,
        random_int(0, $width), random_int(0, $height),
        random_int(0, $width), random_int(0, $height),
        (random_int(0, 1) ? $noiseColor1 : $noiseColor2)
    );
}

// sparse dots
for ($i = 0; $i < 60; $i++) {
    imagesetpixel($image, random_int(0, $width), random_int(0, $height), $noiseColor1);
}

// Draw the captcha text using built-in GD font so it always works and is readable
$font      = 5; // built-in font size
$charWidth = imagefontwidth($font);
$charHeight = imagefontheight($font);
$textWidth = $charWidth * strlen($code);
$textHeight = $charHeight;

$x = (int)(($width - $textWidth) / 2);
$y = (int)(($height - $textHeight) / 2);

imagestring($image, $font, $x, $y, $code, $textColor);

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

imagepng($image);
imagedestroy($image);
