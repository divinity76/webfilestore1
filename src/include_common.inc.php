<?php
declare(strict_types = 1);
namespace Loltek\paste2\blobstore1;

require_once (__DIR__ . DIRECTORY_SEPARATOR . "config.php");
require_once (__DIR__ . DIRECTORY_SEPARATOR . "Phprouter.php");
require_once (__DIR__ . DIRECTORY_SEPARATOR . "misc_functions.php");
if (Config::MODE === Config::MODES_METAMYSQL_FILEDISK) {
    require_once (__DIR__ . DIRECTORY_SEPARATOR . "modes" . DIRECTORY_SEPARATOR . "METAMYSQL_FILEDISK" . DIRECTORY_SEPARATOR . "METAMYSQL_FILEDISK.php");
} else {
    throw new \LogicException("unknown Config::MODE!");
}

// require_once (__DIR__.DIRECTORY_SEPARATOR."");
