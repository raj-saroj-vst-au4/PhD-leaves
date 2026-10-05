<?php
/**
 * Image captcha for the sign-in form.
 *
 * The old check asked "what is 3 + 4?" in the page's HTML, which a script reads
 * as easily as a person. The answer now lives only in the session and reaches
 * the browser only as a distorted PNG (captcha.php). It is a speed bump, not
 * the defence: the real limit on password guessing is includes/rate_limit.php.
 *
 * Drawn with GD's built-in bitmap font, so no TTF file has to exist on the host.
 */

// No 0/O, 1/I/L, 5/S, 2/Z, 8/B: characters people routinely misread.
define('CAPTCHA_ALPHABET', 'ACDEFGHJKMNPQRTUVWXY34679');
define('CAPTCHA_LENGTH', 5);
define('CAPTCHA_TTL_SECONDS', 15 * 60);

/** Can this PHP draw the image? If not, sign-in falls back to the rate limit alone. */
function captchaEnabled(): bool {
    return function_exists('imagecreatetruecolor') && function_exists('imagepng')
        && function_exists('imagerotate');
}

/** Pick a new code and remember it in the session (replacing any earlier one). */
function captchaIssue(): string {
    $code = '';
    $max = strlen(CAPTCHA_ALPHABET) - 1;
    for ($i = 0; $i < CAPTCHA_LENGTH; $i++) {
        $code .= CAPTCHA_ALPHABET[random_int(0, $max)];
    }
    $_SESSION['captcha_answer'] = ['code' => $code, 'issued' => time()];
    return $code;
}

/**
 * Check a submitted answer. The stored code is consumed whatever the outcome,
 * so one image can never be replayed against a second attempt.
 */
function captchaVerify(string $input): bool {
    $stored = $_SESSION['captcha_answer'] ?? null;
    unset($_SESSION['captcha_answer']);

    // Sessions from before the image captcha hold a bare integer: refuse it.
    if (!is_array($stored) || !isset($stored['code'], $stored['issued'])
        || time() - (int) $stored['issued'] > CAPTCHA_TTL_SECONDS) {
        return false;
    }
    $given = strtoupper(preg_replace('/\s+/', '', $input));
    return $given !== '' && hash_equals((string) $stored['code'], $given);
}

/** Render a code as PNG bytes. */
function captchaRenderPng(string $code): string {
    $width = 190;
    $height = 60;
    $img = imagecreatetruecolor($width, $height);
    imagealphablending($img, true);
    imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, imagecolorallocate($img, 244, 246, 250));

    // Background clutter under the glyphs.
    for ($i = 0; $i < 7; $i++) {
        $c = imagecolorallocate($img, random_int(150, 205), random_int(150, 205), random_int(150, 205));
        imageline($img, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $c);
    }
    for ($i = 0; $i < 250; $i++) {
        $c = imagecolorallocate($img, random_int(120, 220), random_int(120, 220), random_int(120, 220));
        imagesetpixel($img, random_int(0, $width - 1), random_int(0, $height - 1), $c);
    }

    // Each glyph: drawn small with the built-in font, scaled up unevenly,
    // rotated, and placed at a jittered position.
    $x = 12;
    foreach (str_split($code) as $ch) {
        $glyph = imagecreatetruecolor(11, 17);
        imagealphablending($glyph, false);
        imagesavealpha($glyph, true);
        $clear = imagecolorallocatealpha($glyph, 0, 0, 0, 127);
        imagefilledrectangle($glyph, 0, 0, 10, 16, $clear);
        $ink = imagecolorallocate($glyph, random_int(10, 90), random_int(10, 90), random_int(40, 130));
        imagestring($glyph, 5, 1, 1, $ch, $ink);

        $gw = random_int(24, 31);
        $gh = random_int(36, 44);
        $big = imagecreatetruecolor($gw, $gh);
        imagealphablending($big, false);
        imagesavealpha($big, true);
        imagefilledrectangle($big, 0, 0, $gw - 1, $gh - 1, imagecolorallocatealpha($big, 0, 0, 0, 127));
        imagecopyresampled($big, $glyph, 0, 0, 0, 0, $gw, $gh, 11, 17);

        $rot = imagerotate($big, random_int(-25, 25), imagecolorallocatealpha($big, 0, 0, 0, 127));
        imagealphablending($rot, false);
        imagesavealpha($rot, true);
        $rw = imagesx($rot);
        $rh = imagesy($rot);
        $y = max(0, intdiv($height - $rh, 2) + random_int(-5, 5));
        imagecopyresampled($img, $rot, $x, $y, 0, 0, $rw, $rh, $rw, $rh);

        $x += random_int(28, 33);
        imagedestroy($glyph);
        imagedestroy($big);
        imagedestroy($rot);
    }

    // A couple of strokes across the glyphs so they do not segment trivially.
    for ($i = 0; $i < 2; $i++) {
        $c = imagecolorallocate($img, random_int(60, 120), random_int(60, 120), random_int(60, 120));
        imagesetthickness($img, 2);
        $y0 = random_int(15, $height - 15);
        $prevX = 0;
        $prevY = $y0;
        $amp = random_int(4, 9);
        $phase = random_int(0, 628) / 100;
        for ($px = 6; $px <= $width; $px += 6) {
            $py = (int) round($y0 + $amp * sin($phase + $px / 18));
            imageline($img, $prevX, $prevY, $px, $py, $c);
            $prevX = $px;
            $prevY = $py;
        }
    }
    imagesetthickness($img, 1);

    ob_start();
    imagepng($img);
    imagedestroy($img);
    return (string) ob_get_clean();
}
