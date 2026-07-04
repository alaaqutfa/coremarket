<?php

/**
 * Laravel - A PHP Framework For Web Artisans
 *
 * @package  Laravel
 * @author   Taylor Otwell <taylor@laravel.com>
 */

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)
);

$publicIndex = __DIR__.'/public/index.php';
$rootIndex = __DIR__.'/index.php';
$entrypoint = file_exists($publicIndex) ? $publicIndex : $rootIndex;
$documentRoot = file_exists($publicIndex) ? __DIR__.'/public' : __DIR__;

// This file allows us to emulate Apache's "mod_rewrite" functionality from the
// built-in PHP web server. This provides a convenient way to test a Laravel
// application without having installed a "real" web server software here.
if ($uri !== '/' && ! file_exists($publicIndex) && is_file(__DIR__.$uri)) {
    if (function_exists('mime_content_type')) {
        header('Content-Type: '.mime_content_type(__DIR__.$uri));
    }

    readfile(__DIR__.$uri);
    return true;
}

if ($uri !== '/' && file_exists($documentRoot.$uri)) {
    return false;
}

require_once $entrypoint;
