<?php
declare(strict_types = 1);
namespace Loltek\paste2\blobstore1;

if (! file_exists("config.php")) {
    die("config.php does not exist, you must make a copy of DIST.config.php named config.php\n");
}
require_once ('config.php');

function shittyArgvParser(): array
{
    // valid arguments:
    // --perform-clean=1: cleans instead of deploys..
    global $argv;
    $ret = [];
    foreach ($argv as $index => $arg) {
        if ($index === 0) {
            continue;
        }
        $arg = explode("=", $arg, 2);
        if (count($arg) === 2) {
            $ret[$arg[0]] = $arg[1];
        } else {
            $ret[$arg[0]] = ''; // << i guess?
        }
    }
    return $ret;
}

if (! Config::IS_DEV_SYSTEM && Config::ADMIN_KEY === "CHANGE_ME") {
    echo ("error: you must change the config ADMIN_KEY, set it to something like " . strtr(base64_encode(random_bytes(12)), '+/=', 'XYZ') . "\n");
    die();
}
if (! Config::IS_DEV_SYSTEM && Config::DEDUPLICATION_HASH_PEPPER === "CHANGE_ME") {
    die("error: you must change the config DEDUPLICATION_HASH_PEPPER, set it to something like " . strtr(base64_encode(random_bytes(20)), '+/=', 'XYZ') . "\n");
    die();
}
$performClean = ! empty(shittyArgvParser()['--perform-clean']);
switch (Config::MODE) {
    case Config::MODES_METAMYSQL_FILEDISK:
        {
            require_once (implode(DIRECTORY_SEPARATOR, array(
                __DIR__,
                'modes',
                'METAMYSQL_FILEDISK',
                'METAMYSQL_FILEDISK_deploy_routines.php'
            )));
            if ($performClean) {
                clean_MODES_METAMYSQL_FILEDISK();
            } else {
                deploy_MODES_METAMYSQL_FILEDISK();
            }
            break;
        }
    default:
        {
            die("error: unknown config.php MODE: " . var_export(Config::MODE, true));
        }
}
