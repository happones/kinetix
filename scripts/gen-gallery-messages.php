<?php

// Dump resources/lang/en/kinetix.php as vue-i18n JSON, converting Laravel
// ":placeholder" interpolation to vue-i18n "{placeholder}".
$messages = require __DIR__.'/../resources/lang/en/kinetix.php';
$out      = [];
foreach ($messages as $key => $value) {
    $out[$key] = is_string($value) ? preg_replace('/:(\w+)/', '{$1}', $value) : $value;
}
file_put_contents(
    __DIR__.'/../gallery/messages.en.json',
    json_encode(['kinetix' => $out], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
);
echo count($out)." keys written\n";
