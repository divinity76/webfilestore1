<?php
declare(strict_types = 1);
namespace Loltek\paste2\blobstore1;

function handle_upload_request(): void
{
    $fakeobj = new class() {

        private $response = [];

        /** @var \PDO $db */
        private $db = null;

        private $final_basename = "";

        private function get_basename_id(string $basename_supplied, bool $first_512_bytes_contains_nulls, string $file_path): int
        {
            $basename = $basename_supplied;
            $response = &$this->response;
            $db = &$this->db;
            static $known_basename_id_table = array(
                1 => 'untitled.bin',
                2 => 'untitled.txt'
            );
            if (strlen($basename) < 1) {
                // "guess the extension"...
                $cmd = implode(" ", array(
                    "file",
                    "--brief",
                    "--extension",
                    escapeshellarg($file_path)
                ));
                $basename = trim(shell_exec($cmd));
                if ($basename !== "???" && ! empty($basename)) {
                    // file returns "slash-separated list of extensions", yeah..
                    $basename = explode("/", $basename, 2)[0];
                    $prettify_table = array(
                        "jpeg" => "jpg"
                    );
                    if (isset($prettify_table[$basename])) {
                        $basename = $prettify_table[$basename];
                    }
                } else {
                    $cmd = implode(" ", array(
                        "file",
                        "--brief",
                        "--mime-type",
                        escapeshellarg($file_path)
                    ));
                    $basename = trim(shell_exec($cmd));

                    $table = array(
                        // this table could be HUGE
                        "text/x-c" => "c",
                        "text/x-c++" => "cpp",
                        "text/x-php" => "php"
                        // "application/octet-stream" => "bin",
                    );
                    if (isset($table[$basename])) {
                        $basename = $table[$basename];
                    } else {
                        // I GIVE UP/fallback
                        if (str_starts_with($basename, "text") || ! $first_512_bytes_contains_nulls) {
                            $basename = "txt";
                        } else {
                            $basename = "bin";
                        }
                    }
                }
                $basename = "untitled." . $basename;
            }

            $ret = array_search($basename, $known_basename_id_table, true);
            if ($ret !== false) {
                $this->final_basename = $known_basename_id_table[$ret];
                return $ret;
            }
            if (! mb_check_encoding($basename, 'UTF-8')) {
                http_response_code(400);
                $response["errors"][] = "basename is not UTF-8";
                jsresponse($response);
                die();
            }
            $max_bytes_per_char = 4; // utf-8 can use up to 4 bytes per character.
            if (strlen($basename) > (Config::BASENAME_MAX_LENGTH * $max_bytes_per_char) || mb_strlen($basename, 'UTF-8') > Config::BASENAME_MAX_LENGTH) {
                $response["warnings"][] = "basename length exceeded max, was truncated to " . Config::BASENAME_MAX_LENGTH . " character(s)";
                $basename = mb_substr($basename, 0, Config::BASENAME_MAX_LENGTH, 'UTF-8');
            }

            // optimization note: combining these 2 queries into 1 *may* sounds good,
            // until you consider the huge id gaps that would ensue.. https://stackoverflow.com/a/59547038/1067003
            $sql = "SELECT id FROM blobstore1_basenames WHERE basename = " . $db->quote($basename);
            $basename_id = $db->query($sql)->fetchAll(\PDO::FETCH_NUM);
            if (isset($basename_id[0][0])) {
                $basename_id = $basename_id[0][0];
                $known_table[$basename_id] = $basename;
                $this->final_basename = $basename;
                return $basename_id;
            }
            $sql = "INSERT INTO blobstore1_basenames SET basename = " . $db->quote($basename);
            $db->exec($sql);
            $ret = (int) $db->lastInsertId();
            $known_table[$ret] = $basename;
            $this->final_basename = $known_table[$ret];
            return $ret;
        }

        private function get_content_type_id(string $content_type, string $file_path): int
        {
            $response = &$this->response;
            $db = &$this->db;

            static $known_table = array(
                1 => "application/octet-stream; charset=binary",
                2 => "text/plain; charset=utf-8"
            );
            $len = strlen($content_type);
            if ($len < 1) {
                $cmd = implode(" ", array(
                    "file",
                    "--brief",
                    "--mime-type",
                    "--mime-encoding",
                    escapeshellarg($file_path)
                ));
                $content_type = trim(shell_exec($cmd));
                $replace_text_x_with_text_plain = true;
                if ($replace_text_x_with_text_plain) {
                    if (preg_match('/^text\\/x\\-[^\\;]+;/', $content_type)) {
                        // its something like "text/x-c++; charset=us-ascii" / "text/x-php; charset=us-ascii";
                        // which browsers (at least Firefox 93) treat as a download..
                        // while chrome treats it as text...
                        $content_type = "text/plain;" . substr($content_type, strpos($content_type, ";") + 1);
                    }
                }

                if (str_ends_with($content_type, "charset=us-ascii")) {
                    $content_type = substr($content_type, 0, - strlen("us-ascii")) . "utf-8";
                }
                $len = strlen($content_type);
            }
            if ($len > 200) {
                http_response_code(400);
                $response["errors"][] = "content-type length above 200 bytes!";
                jsresponse($response);
                die();
            }
            $ret = array_search($content_type, $known_table, true);
            if ($ret !== false) {
                return $ret;
            }
            $db = Config::db_getPDO();

            // optimization note: combining these 2 queries into 1 *may* sounds good,
            // until you consider the huge id gaps that would ensue.. https://stackoverflow.com/a/59547038/1067003

            $sql = "SELECT id FROM `blobstore1_content_types` WHERE content_type = " . $db->quote($content_type);
            $ret = $db->query($sql)->fetchAll(\PDO::FETCH_NUM);
            if (isset($ret[0][0])) {
                $ret = $ret[0][0];
                $known_table[$ret] = $content_type;
                return $ret;
            }
            $sql = 'INSERT INTO blobstore1_content_types SET content_type = ' . $db->quote($content_type) . ';';
            $db->exec($sql);
            $ret = (int) $db->lastInsertId();
            $known_table[$ret] = $content_type;
            return $ret;
        }

        private $upload_file_hash;

        private function get_raw_blob_id(string &$file_path): int
        {
            $response = &$this->response;

            $db = &$this->db;
            /** @var \PDO $db */
            $hash = hash_init(Config::DEDUPLICATION_HASH_ALGORITHM);
            hash_update($hash, Config::DEDUPLICATION_HASH_PEPPER);
            hash_update_file($hash, $file_path);
            $this->upload_file_hash = $hash = substr(hash_final($hash, true), 0, Config::DEDUPLICATION_HASH_TRUNCATE_LENGTH);

            // optimization note: combining these 2 queries into 1 *may* sounds good,
            // until you consider the huge id gaps that would ensue.. https://stackoverflow.com/a/59547038/1067003

            $sql = 'SELECT id FROM blobstore1_raw_blobs WHERE hash = ' . $db->quote($hash);
            $id = $db->query($sql)->fetchAll(\PDO::FETCH_NUM);
            if (isset($id[0][0])) {
                $id = $id[0][0];
                $sql = "UPDATE blobstore1_raw_blobs SET changed_time = NOW(), link_counter = link_counter + 1 WHERE id = " . db_quote($db, $id);
                $db->exec($sql);
                return $id;
            }
            $id = null;

            $sql = 'INSERT INTO blobstore1_raw_blobs SET hash = ' . $db->quote($hash);
            $sql .= ' ON DUPLICATE KEY UPDATE changed_time = NOW(), link_counter = link_counter + 1;';

            $rowsAffected = $db->exec($sql);
            // >In the case of "INSERT ... ON DUPLICATE KEY UPDATE" queries,
            // the return value will be 1 if an insert was performed,
            // or 2 for an update of an existing row.
            $id = (int) $db->lastInsertId();
            if ($rowsAffected === 2) {
                return $id;
            }
            if ($rowsAffected !== 1) {
                // should never happen..
                throw new \LogicException("rowsAffected somehow not 1 and not 2: " . var_export($rowsAffected, true));
            }

            // $data = array(
            // id INTEGER UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            // creation_time DATETIME NOT NULL DEFAULT (CURRENT_TIMESTAMP),
            // changed_time DATETIME NOT NULL DEFAULT (CURRENT_TIMESTAMP),
            // link_counter INTEGER UNSIGNED NOT NULL DEFAULT (1),
            // 'hash' => $hash
            // );
            $folder_id = (int) floor($id / Config::MAX_FILES_PER_FOLDER);
            $folder = implode(DIRECTORY_SEPARATOR, array(
                Config::BLOBS_MAIN_FOLDER_WITHOUT_TRAILING_SLASH,
                $folder_id,
                ''
            ));
            if (! is_dir($folder)) {
                if (! mkdir($folder, 0755, true)) {
                    http_response_code(500);
                    $err = error_get_last();
                    $response["errors"][] = "failed to make folder {$folder}";
                    $response["errors"][] = $err;
                    jsresponse($response);
                    throw new \RuntimeException("failed to make folder \"{$folder}\": " . var_export($err, true));
                }
            }
            $destination = $folder . $id;
            // should we COPY or MOVE? ... well move should be faster
            if (! move_uploaded_file($file_path, $destination)) {
                http_response_code(500);
                $err = error_get_last();
                $response["errors"][] = "failed to move file \"{$file_path}\" => \"{$destination}\"";
                $response["errors"][] = $err;
                jsresponse($response);
                throw new \RuntimeException("failed to move file " . var_export($err, true) . "  \"{$file_path}\" => \"{$destination}\"");
            }
            $file_path = $destination;
            return $id;
        }

        function __construct()
        {
            if (false) {
                // sample $_POST:
                $_POST = array(
                    'admin_key' => Config::ADMIN_KEY,
                    'upload_file_hidden' => false,
                    'upload_file_content_type' => '',
                    'upload_file_basename' => 'untitled.txt'
                );
                // sample $_FILES:
                $_FILES = array(
                    'file_to_upload' => array(
                        'name' => 'testfile.txt',
                        'type' => 'text/plain',
                        'tmp_name' => '/tmp/phpYTE7rg',
                        'error' => 0,
                        'size' => 34
                    )
                );
            }
            $response = &$this->response;

            $key_provided = (string) ($_POST['admin_key'] ?? "");
            if (! Config::IS_DEV_SYSTEM && ! hash_equals(Config::ADMIN_KEY, $key_provided)) {
                http_response_code(403); // 403 forbidden
                $this->response["errors"][] = "invalid admin_key provided.";
                jsresponse($this->response);
                die();
            }
            if (empty($_FILES['file_to_upload'])) {
                http_response_code(400);
                $response["errors"][] = "\$_FILES['file_to_upload'] is missing!";
                $response["errors"][] = $_FILES;
                jsresponse($response);
                die();
            }
            $file = $_FILES['file_to_upload'];
            if (0) {
                // $file should look like:
                array(
                    'name' => 'testfile.txt',
                    'type' => 'text/plain',
                    'tmp_name' => '/tmp/phpYTE7rg',
                    'error' => 0,
                    'size' => 34
                );
            }
            if ($file["error"] !== 0) {
                http_response_code(400);
                $response["errors"][] = "file upload error";
                $response["errors"][] = $file;
                jsresponse($response);
                die();
            }
            if ($file["size"] < 1) {
                http_response_code(400);
                $response["errors"][] = "file is empty!";
                $response["errors"][] = $file;
                jsresponse($response);
                die();
            }
            if (disk_free_space(Config::BLOBS_MAIN_FOLDER_WITHOUT_TRAILING_SLASH) <= ($file["size"] + Config::MINIMUM_FREE_DISK_SPACE_BYTES)) {
                http_response_code(500);
                $errmsg = "server don't have enough free disk space: have " . disk_free_space(Config::BLOBS_MAIN_FOLDER_WITHOUT_TRAILING_SLASH) . " bytes, but minimum configured to " . Config::MINIMUM_FREE_DISK_SPACE_BYTES;
                $response["errors"][] = $errmsg;
                jsresponse($response);
                throw new \RuntimeException($errmsg);
                die();
            }
            $first_512_bytes_contains_nulls = (false !== strpos(file_get_contents($file["tmp_name"], false, null, 0, 512), "\x00"));
            $this->db = Config::db_getPDO();
            $db = &$this->db;
            /** @var \PDO $db */
            ignore_user_abort(true);
            $db->beginTransaction();
            $is_hidden = (bool) ($_POST['upload_file_hidden'] ?? false);
            $basename_supplied = $_POST['upload_file_basename'] ?? ($_FILES['file_to_upload']["name"] ?? "");

            $data = [
                // 'id' => INTEGER UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                'raw_blob_id' => null,
                'content_type_id' => $this->get_content_type_id((string) ($_POST['upload_file_content_type'] ?? ""), $file["tmp_name"]),
                'basename_id' => $this->get_basename_id($basename_supplied, $first_512_bytes_contains_nulls, $file["tmp_name"])
            ];
            // PS after calling get_raw_blob_id(), if there are further errors, we need to manually delete
            // $file["tmp_name"], php will no longer auto-delete it after we moved it..
            $data["raw_blob_id"] = $this->get_raw_blob_id($file["tmp_name"]);
            try {
                $table = ($is_hidden ? "`blobstore1_files_hidden`" : "`blobstore1_files_public`");
                $sql = "INSERT INTO {$table} SET ";
                $glue = ",\n";
                foreach ($data as $col => $val) {
                    $sql .= db_quote_identifier($db, $col) . " = " . db_quote($db, $val) . $glue;
                }
                $sql = substr($sql, 0, - strlen($glue)) . ";";
                if (Config::IS_DEV_SYSTEM) {
                    $response["debug"]["files_sql"] = $sql;
                }
                $db->exec($sql);
                $data["id"] = (int) $db->lastInsertId();
                if (Config::IS_DEV_SYSTEM) {
                    $response["debug"]["data"] = $data;
                }
                $fetch_url_small = "";
                $fetch_url_full = "";
                if ($is_hidden) {
                    $fetch_url_small = "h/" . $data["id"] . "/" . base64url_encode($this->upload_file_hash);
                    $fetch_url_full = $fetch_url_small . "/" . urlencode($this->final_basename);
                } else {
                    $fetch_url_small = "p/" . $data["id"];
                    $fetch_url_full = $fetch_url_small . "/" . urlencode($this->final_basename);
                }
                $db->commit();
                $response["relative_url_small"] = $fetch_url_small;
                $response["relative_url_full"] = $fetch_url_full;
                $response["absolute_url_small"] = Config::BASE_URL_WITH_SLASH . $fetch_url_small;
                $response["absolute_url_full"] = Config::BASE_URL_WITH_SLASH . $fetch_url_full;
                jsresponse($response);
            } catch (\Throwable $ex) {
                // not expecting an exception here, but just in case it does actually happen,
                // we need to manually unlink the file, because we moved it
                unlink($file["tmp_name"]);
                throw $ex;
            }
        }
    };
    unset($fakeobj);
}

function handle_download_request(\Loltek\Phprouter\Phprouter $router, string $supplied_folder_name, string $supplied_id, string $supplied_hash_and_or_title = null): void
{
    if (null === $supplied_hash_and_or_title) {
        $supplied_hash_and_or_title = "";
    }
    $supplied_is_hidden = ($supplied_folder_name === "h");
    // $supplied_is_public = (! $supplied_is_hidden);
    $supplied_hash = null;
    $supplied_title = null;
    if (! $supplied_is_hidden) {
        $supplied_title = $supplied_hash_and_or_title;
    } else {
        $supplied_title = explode("/", $supplied_hash_and_or_title, 2);
        $supplied_hash = base64url_decode($supplied_title[0]);
        $supplied_title = $supplied_title[1] ?? "";
    }

    $db = Config::db_getPDO();
    if ($supplied_is_hidden) {
        $sql = "
SELECT
      blobstore1_files_hidden.raw_blob_id,
      blobstore1_basenames.basename,
      blobstore1_content_types.content_type,
      blobstore1_raw_blobs.hash
   FROM
      `blobstore1_files_hidden`
   LEFT JOIN blobstore1_basenames ON blobstore1_basenames.id = blobstore1_files_hidden.basename_id
   LEFT JOIN blobstore1_content_types ON blobstore1_content_types.id = blobstore1_files_hidden.content_type_id
   LEFT JOIN blobstore1_raw_blobs ON blobstore1_raw_blobs.id = blobstore1_files_hidden.raw_blob_id
WHERE blobstore1_files_hidden.id = " . db_quote($db, $supplied_id);
        $data = $db->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
        if (empty($data)) {
            $router->trigger404();
            return;
        }
        $data = $data[0];
        if (false) {
            // $data now looks like
            array(
                'raw_blob_id' => 3,
                'basename' => 'untitled.txt',
                'content_type' => 'text/plain; charset=utf-8',
                'hash' => 'Fj����a�"X'
            );
        }
        if (! hash_equals($data["hash"], $supplied_hash)) {
            http_response_code(403);
            echo "invalid key/hash to access resource... please don't try to bruteforce it.
it's not that i think you'll actually be able to bruteforce-attack it, but it would waste so much
server resources/bandwidth, also this check is not vulnerable to timing-attack on supplied_hash===actual_hash";
            die();
        }
        if ($data["basename"] !== $supplied_title) {
            // hmmmmmm
            $url = Config::BASE_URL_WITH_SLASH . "h/" . urlencode((string) $supplied_id) . "/" . base64url_encode($supplied_hash) . "/" . urlencode($data["basename"]);
            header("Location: {$url}", true, 307);
            header("Content-Type: text/plain; charset=UTF-8");
            echo "you are being redirected to {$url}";
            die();
        }
        header("Content-Type: " . $data["content_type"]);
        $file_location_relative = implode(DIRECTORY_SEPARATOR, array(
            (int) floor($data["raw_blob_id"] / Config::MAX_FILES_PER_FOLDER),
            $data["raw_blob_id"]
        ));
        $file_location_absolute = Config::BLOBS_MAIN_FOLDER_WITHOUT_TRAILING_SLASH . DIRECTORY_SEPARATOR . $file_location_relative;
        Config::file_serve_callback($file_location_absolute, $file_location_relative);
    } else {
        // for condition $supplied_is_public = true;
        $sql = "
SELECT
      blobstore1_files_public.raw_blob_id,
      blobstore1_basenames.basename,
      blobstore1_content_types.content_type
   FROM
      `blobstore1_files_public`
   LEFT JOIN blobstore1_basenames ON blobstore1_basenames.id = blobstore1_files_public.basename_id
   LEFT JOIN blobstore1_content_types ON blobstore1_content_types.id = blobstore1_files_public.content_type_id
WHERE blobstore1_files_public.id = " . db_quote($db, $supplied_id);
        $data = $db->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
        if (empty($data)) {
            $router->trigger404();
            return;
        }
        $data = $data[0];
        if (false) {
            // $data now looks like
            array(
                'raw_blob_id' => 3,
                'basename' => 'untitled.txt',
                'content_type' => 'text/plain; charset=utf-8'
            );
        }

        if ($data["basename"] !== $supplied_title) {
            // hmmmmmm
            $url = Config::BASE_URL_WITH_SLASH . "p/" . urlencode((string) $supplied_id) . "/" . urlencode($data["basename"]);
            header("Location: {$url}", true, 307);
            header("Content-Type: text/plain; charset=UTF-8");
            echo "you are being redirected to {$url}";
            die();
        }
        header("Content-Type: " . $data["content_type"]);
        $file_location_relative = implode(DIRECTORY_SEPARATOR, array(
            (int) floor($data["raw_blob_id"] / Config::MAX_FILES_PER_FOLDER),
            $data["raw_blob_id"]
        ));
        $file_location_absolute = Config::BLOBS_MAIN_FOLDER_WITHOUT_TRAILING_SLASH . DIRECTORY_SEPARATOR . $file_location_relative;
        Config::file_serve_callback($file_location_absolute, $file_location_relative);
    }
}
