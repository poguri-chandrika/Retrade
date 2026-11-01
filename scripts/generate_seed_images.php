<?php
// Generate simple placeholder images for seeding without external downloads
// Creates files in assets/img/seed/

require_once __DIR__ . '/../config.php';

// Optional: limit to admin
if (!isLoggedIn() || getUserRole() !== 'admin') {
  http_response_code(403);
  echo 'Forbidden: Admin only.';
  exit;
}

$seedDir = __DIR__ . '/../assets/img/seed';
if (!is_dir($seedDir)) {
  mkdir($seedDir, 0755, true);
}

$w = 800; $h = 600;
$count = 40; // number of seed images
$generated = 0;

if (function_exists('imagecreatetruecolor')) {
  // GD path
  $palette = [
    [30, 41, 59], [22, 27, 34], [10, 25, 47], [29, 32, 33], [44, 20, 20],
    [7, 55, 30], [50, 38, 19], [22, 53, 73], [70, 29, 50], [38, 38, 38],
  ];
  for ($i=1; $i<=$count; $i++) {
    $im = imagecreatetruecolor($w, $h);
    [$r,$g,$b] = $palette[array_rand($palette)];
    $bg = imagecolorallocate($im, $r, $g, $b);
    imagefilledrectangle($im, 0, 0, $w, $h, $bg);

    // add lighter panel
    if (function_exists('imagecolorallocatealpha')) {
      $panel = imagecolorallocatealpha($im, 255, 255, 255, 110);
      imagefilledrectangle($im, 40, 420, $w-40, $h-40, $panel);
    }

    // Text label (built-in font)
    $white = imagecolorallocate($im, 240, 240, 240);
    $font = 5;
    $text = 'Retrade Demo Image #' . $i;
    $tw = imagefontwidth($font) * strlen($text);
    $th = imagefontheight($font);
    $x = (imagesx($im) - $tw) / 2;
    $y = (imagesy($im) - $th) / 2;
    imagestring($im, $font, (int)$x, (int)$y, $text, $white);

    $name = 'seed_' . $i . '.jpg';
    imagejpeg($im, $seedDir . '/' . $name, 85);
    imagedestroy($im);
    $generated++;
  }
  echo 'Generated ' . $generated . ' JPEG images using GD in assets/img/seed/' . "\n";
} else {
  // SVG fallback (no GD required)
  $palette = ['#1E293B','#161B22','#0A192F','#1D2021','#2C1414','#07371E','#322613','#163549','#461D32','#262626'];
  for ($i=1; $i<=$count; $i++) {
    $color = $palette[array_rand($palette)];
    $text = 'Retrade Demo Image #' . $i;
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$h.'">'
         . '<rect width="100%" height="100%" fill="'.$color.'" />'
         . '<rect x="40" y="420" width="'.($w-80).'" height="'.($h-460).'" fill="rgba(255,255,255,0.4)" />'
         . '<text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#EDEDED" font-family="Arial, sans-serif" font-size="28">'.$text.'</text>'
         . '</svg>';
    $name = 'seed_' . $i . '.svg';
    file_put_contents($seedDir . '/' . $name, $svg);
    $generated++;
  }
  echo 'GD not available; generated ' . $generated . ' SVG images in assets/img/seed/' . "\n";
}
