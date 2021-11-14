<?php
// make a copy of this file called config.php
// and set IS_DEV_SYSTEM to false,
// and set ADMIN_KEY...
// and run deploy.php..
declare(strict_types = 1);
namespace Loltek\paste2\blobstore1;

class Config
{

    // note that this is UTF-8 characters, 1 character=4 bytes worst-case scenario.
    const BASENAME_MAX_LENGTH = 200;

    // refuse uploads if free disk space is less than..
    const MINIMUM_FREE_DISK_SPACE_BYTES = 5 * 1024 * 1024 * 1024;

    // important that the last character is a slash /
    const BASE_URL_WITH_SLASH = 'http://paste1.lan:81/';

    // set this to false on a prod system..
    // with this set to true, you don't need to supply ADMIN_KEY to upload,
    // and security checks is disabled in deploy code.
    const IS_DEV_SYSTEM = true;

    // to create a suitable admin key, run: head -c 12 /dev/urandom | base64
    const ADMIN_KEY = "CHANGE_ME";

    // should be a fast, cryptographically secure hash,
    // ideally blake3, but it's not merged yet: https://github.com/php/php-src/pull/6393
    const DEDUPLICATION_HASH_ALGORITHM = "tiger160,3";

    // to create a suitable pepper, run: head -c 20 /dev/urandom | base64
    const DEDUPLICATION_HASH_PEPPER = "CHANGE_ME";

    // 10 should suffice for INTEGER UNSIGNED PRIMARY KEY,
    // 18 should suffice for BIGINT UNSIGNED PRIMARY KEY
    // 10 and 18 gives you ~0.0007% chance of 1-collision-when-the-database-is-maxxed
    // https://stackoverflow.com/a/69831031/1067003
    const DEDUPLICATION_HASH_TRUNCATE_LENGTH = 10;

    const MODE = self::MODES_METAMYSQL_FILEDISK;

    // too many files in 1 folder causes performance issues on Linux (probably all OSs but idk)
    // 10_000 files = 100 folders per 1 million blobs..
    const MAX_FILES_PER_FOLDER = 10_000;

    const DB_PDO_DSN = "mysql:host=localhost;port=3306;dbname=blobstore1_meta;charset=utf8mb4;";

    const DB_USERNAME = "blobstore1_meta";

    const DB_PASSWORD = "CHANGE_ME";

    const BLOBS_MAIN_FOLDER_WITHOUT_TRAILING_SLASH = __DIR__ . DIRECTORY_SEPARATOR . "blobs";

    const REPLACE_TEXT_X_WITH_TEXT_PLAIN = false;

    public static function front_page_callback(): void
    {
        echo 'you\'re probably looking for <a href="https://paste.Loltek.net">https://paste.loltek.net</a>';
    }

    // here you can customize 404 responses..
    public static function x404_callback(): void
    {
        http_response_code(404);
        header("Content-Type: text/plain; charset=utf-8");
        echo "404 not found... request uri: " . $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI'];
        die();
    }

    // customize 410 Gone/delted-but-not-forgotten responses..
    public static function x410_callback(array $file_data)
    {
        if (false) {
            // $file_data should look something like
            array(
                'raw_blob_id' => 3,
                'deleted_date' => '2021-11-13',
                'basename' => 'untitled.txt',
                'content_type' => 'text/plain; charset=utf-8',
                'hash' => 'Fj����a�"X'
            );
        }
        http_response_code(410);
        echo "HTTP 410 Gone<br/>\nthis file was deleted on " . $file_data["deleted_date"] . "<br/>\n(but the metadata is still in our database for some reason)";
        die();
    }

    /**
     * this function is used by MODES_METAMYSQL_FILEDISK to actually serve files..
     *
     * @param string $filepath_absolute
     * @param string $filepath_relative
     */
    public static function file_serve_callback(string $filepath_absolute, string $filepath_relative): void
    {
        // here you can change how the file is served...
        // if you're using apache/lighthttpd/litespeed, the best way to deliver is probably
        // X-sendfile,
        // header("X-Sendfile: {$filepath_absolute}");return;
        //
        // if you're using nginx, the best way is probably X-accl-redirect:
        // header("X-Accel-Redirect: /blobs_internal/{$filepath_relative}");return;
        //
        // PHP *can* also do it, but will do it slower and use more system resources,
        // also this won't support cloudflare caching/if-modified-since/not-modified/
        // Content-Length/HTTP Range/Partial-content/Range Not Satisfiable/etcetc
        // and you won't solve the C10k problem..
        header("Content-Length: " . filesize($filepath_absolute));
        readfile($filepath_absolute);
    }

    // internal stuff below
    // internal stuff below
    // internal stuff below
    // internal stuff below
    //
    // MODES_METAMYSQL_FILEDISK use mysql to store metadata,
    // and stores blobs in subfolders of the "blobs" folder
    // advantages:
    // supports file deduplication + content-type deduplication + basename deduplication
    // (only 1 copy of every unique file, only 1 copy of "untitled.txt", only 1 copy of "text/plain; charset=UTF-8")
    // dis-advantages:
    // 1 unique file = 1 inode + 2-4 sql records (3 on average)
    // 1 non-unique file = 1-3 sql records (1 on average)
    // compression is not implemented, yet.. (would be easy to implement on nginx with ngx_http_gzip_static_module + ngx_http_gunzip_module though...)
    const MODES_METAMYSQL_FILEDISK = 1;

    const ROOT_DIR_WITHOUT_SLASH = __DIR__;

    public static function db_getPDO(bool $get_cached_connection = true): \PDO
    {
        static $pdo_cache = null;
        if ($get_cached_connection && $pdo_cache !== null) {
            return $pdo_cache;
        }

        $pdo = new \PDO(self::DB_PDO_DSN, self::DB_USERNAME, self::DB_PASSWORD, array(
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
        ));
        if ($get_cached_connection) {
            $pdo_cache = $pdo;
        }
        return $pdo;
    }
}
