<?php
if (!function_exists('formatoPeso')) {
  function formatoPeso($bytes, $decimales = 2) {
    $size = ['B','KB','MB','GB','TB'];
    $factor = floor((strlen($bytes) - 1) / 3);
    return sprintf("%.{$decimales}f", $bytes / pow(1024, $factor)) . ' ' . $size[$factor];
  }
}
