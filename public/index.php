<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/*
|--------------------------------------------------------------------------
| Where the application lives
|--------------------------------------------------------------------------
|
| TWO LAYOUTS, ONE FILE. On InfinityFree the public folder IS the web
| root and the application proper sits beside it in a private directory
| that the web server will not serve. On this laptop, and on any ordinary
| server, public/ is a subdirectory of the application itself.
|
| This used to name the InfinityFree path outright, which meant the file
| had to be edited by hand every time the site moved — and when it was
| not, every page answered with "Failed to open stream" and nothing else.
| Asking the filesystem which layout is in front of us costs one is_dir()
| per request and removes the whole class of mistake.
|
*/

$private = dirname(__DIR__).'/visitdasol_private/visitdasol';

$appPath = is_dir($private.'/vendor') ? $private : dirname(__DIR__);

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = $appPath.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require $appPath.'/vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once $appPath.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
