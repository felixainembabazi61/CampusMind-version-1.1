<?php
// Router for PHP's built-in web server (see start-server.bat).
// Blocks direct access to the database file, configuration and PHP classes; everything else is served normally.
$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (preg_match('#^/(storage|api/lib|database)(/|$)|^/api/(config|tests-lib)\.php$|/\.|\.(md|bat|sqlite)$#i', $path)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}
return false;
