<?php
declare(strict_types = 1);
namespace Loltek\paste2\blobstore1;

require_once (__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . "include_common.inc.php");

$router = new \Loltek\phprouter\Phprouter();
$router->set404(function () {
    echo "404 not found...";
});
$router->get("/favicon\\.ico", function () {
    // ...todo?
    header("Location: https://developer.mozilla.org/favicon.ico", true, 307);
});
$router->get("/", function () {
    echo 'you\'re probably looking for <a href="https://paste.Loltek.net">https://paste.Loltek.net</a>';
});

$router->get("/(p|h)/(\d+)(?:/([\s\S]*))?", function (string $supplied_folder_name, string $supplied_id, string $supplied_hash_and_or_title = null) use ($router): void {
    // var_dump(func_get_args());die();
    handle_download_request($router, $supplied_folder_name, $supplied_id, $supplied_hash_and_or_title);
});

$router->post("/api/1/upload", function () {
    handle_upload_request();
});

$router->run();
