<?php
declare(strict_types = 1);
namespace Loltek\paste2\blobstore1;

function clean_MODES_METAMYSQL_FILEDISK(): void
{
    $blob_dir = Config::BLOBS_MAIN_FOLDER_WITHOUT_TRAILING_SLASH;
    if (file_exists($blob_dir)) {
        $cmd = "rm -rfv " . escapeshellarg($blob_dir);
        passthru($cmd);
        echo "\n";
    }
    $db = Config::db_getPDO();
    $tables = [
        'blobstore1_files_public',
        'blobstore1_files_hidden',
        'blobstore1_raw_blobs',
        'blobstore1_content_types',
        'blobstore1_basenames',
        'blobstore1_metadata'
    ];
    $views = array(
        'blobstore1_files_public_view',
        'blobstore1_files_hidden_view'
    );
    $sql = 'DROP TABLE IF EXISTS `' . implode('`, `', $tables) . '`;' . "\n";
    $sql .= 'DROP VIEW IF EXISTS `' . implode('`, `', $views) . '`;';
    echo "{$sql}\n";
    $db->exec($sql);
}

function deploy_MODES_METAMYSQL_FILEDISK(): void
{
    $blobs_folder = Config::BLOBS_MAIN_FOLDER_WITHOUT_TRAILING_SLASH;
    if (! is_dir($blobs_folder)) {
        if (! mkdir($blobs_folder, 0755, true)) {
            throw new \LogicException("unable to create blobs folder: " . $blobs_folder);
        }
    }

    $db = Config::db_getPDO();
    $schema = file_get_contents(implode(DIRECTORY_SEPARATOR, array(
        __DIR__,
        'schema.sql'
    )));
    $schema = strtr($schema, array(
        '%CONFIG_DEDUPLICATION_HASH_TRUNCATE_LENGTH%' => Config::DEDUPLICATION_HASH_TRUNCATE_LENGTH,
        '%CONFIG_BASENAME_MAX_LENGTH%' => Config::BASENAME_MAX_LENGTH
    ));
    // echo $schema;
    var_dump($db->exec($schema));
}