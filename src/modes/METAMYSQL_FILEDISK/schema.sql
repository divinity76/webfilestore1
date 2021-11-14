-- PS you're not supposed to run this manually,
-- you're supposed to let deploy.php run it for you
SET @@session.sql_mode = 'NO_AUTO_VALUE_ON_ZERO,TRADITIONAL';
CREATE TABLE blobstore1_metadata
(
   id INTEGER UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
   name TEXT,
   value TEXT
)
COMMENT = "stuff like schema_version..";
INSERT INTO blobstore1_metadata SET name= 'schema_version',
value = '0.1';
INSERT INTO blobstore1_metadata SET name= 'deploy_mode',
value = 'MODES_METAMYSQL_FILEDISK';
CREATE TABLE blobstore1_files_public
(
   id INTEGER UNSIGNED PRIMARY KEY AUTO_INCREMENT,
   raw_blob_id INTEGER UNSIGNED NOT NULL,
   content_type_id SMALLINT UNSIGNED NOT NULL,
   basename_id INTEGER UNSIGNED NOT NULL,
   deleted_date DATETIME NULL DEFAULT NULL
)
COMMENT= 'public blobs with names and content types..';
CREATE TABLE blobstore1_files_hidden
(
   id INTEGER UNSIGNED PRIMARY KEY AUTO_INCREMENT,
   raw_blob_id INTEGER UNSIGNED NOT NULL,
   content_type_id SMALLINT UNSIGNED NOT NULL,
   basename_id INTEGER UNSIGNED NOT NULL,
   deleted_date DATETIME NULL DEFAULT NULL
)
COMMENT= 'hidden blobs with names and content types..';
CREATE TABLE blobstore1_content_types
(
   id SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
   content_type VARCHAR (200) CHARACTER SET utf8mb4 UNIQUE
)
COMMENT = 'content_type deduplication scheme..';
--
INSERT INTO blobstore1_content_types SET id= 1,
content_type = 'application/octet-stream; charset=binary';
INSERT INTO blobstore1_content_types SET id= 2,
content_type = 'text/plain; charset=utf-8';
CREATE TABLE blobstore1_basenames
(
   id INTEGER UNSIGNED PRIMARY KEY AUTO_INCREMENT,
   basename VARCHAR (%CONFIG_BASENAME_MAX_LENGTH%) CHARACTER SET utf8mb4 UNIQUE
)
COMMENT = 'basename deduplication scheme..';
INSERT INTO blobstore1_basenames SET id= 1,
basename = 'untitled.bin';
INSERT INTO blobstore1_basenames SET id= 2,
basename = 'untitled.txt';
CREATE TABLE blobstore1_raw_blobs
(
   id INTEGER UNSIGNED PRIMARY KEY AUTO_INCREMENT,
   creation_time DATETIME NOT NULL DEFAULT (CURRENT_TIMESTAMP),
   changed_time DATETIME NOT NULL DEFAULT (CURRENT_TIMESTAMP),
   link_counter INTEGER UNSIGNED NOT NULL DEFAULT (1),
   -- if blobstore1_files.id is INTEGER UNSIGNED, BINARY(10) is probably sufficient,
   -- if its BIGINT UNSIGNED, BINARY(18) is probably sufficient:
   -- 0.0007% chance of collision on a maxed database, 1 in 142,856 completeled-maxed-out-such databases
   -- will have 1 collision, on average..
   -- https://stackoverflow.com/a/69831031/1067003
   hash BINARY (%CONFIG_DEDUPLICATION_HASH_TRUNCATE_LENGTH%) NOT NULL UNIQUE
)
COMMENT "raw_blobs deduplication scheme";
CREATE VIEW blobstore1_files_public_view AS
(
   -- remind me to get a better SQL formatter..
   SELECT
      blobstore1_files_public.*,
      blobstore1_basenames.basename,
      blobstore1_content_types.content_type,
      blobstore1_raw_blobs.creation_time,
      blobstore1_raw_blobs.changed_time,
      blobstore1_raw_blobs.link_counter,
      HEX
   (blobstore1_raw_blobs.hash) AS hash_hex
   FROM
      `blobstore1_files_public`
   LEFT
   JOIN blobstore1_basenames ON blobstore1_basenames.id = blobstore1_files_public.basename_id
   LEFT
   JOIN blobstore1_content_types ON blobstore1_content_types.id = blobstore1_files_public.content_type_id
   LEFT
   JOIN blobstore1_raw_blobs ON blobstore1_raw_blobs.id = blobstore1_files_public.raw_blob_id
);
CREATE VIEW blobstore1_files_hidden_view AS
(
   -- remind me to get a better SQL formatter..
   SELECT
      blobstore1_files_hidden.*,
      blobstore1_basenames.basename,
      blobstore1_content_types.content_type,
      blobstore1_raw_blobs.creation_time,
      blobstore1_raw_blobs.changed_time,
      blobstore1_raw_blobs.link_counter,
      HEX
   (blobstore1_raw_blobs.hash) AS hash_hex
   FROM
      `blobstore1_files_hidden`
   LEFT
   JOIN blobstore1_basenames ON blobstore1_basenames.id = blobstore1_files_hidden.basename_id
   LEFT
   JOIN blobstore1_content_types ON blobstore1_content_types.id = blobstore1_files_hidden.content_type_id
   LEFT
   JOIN blobstore1_raw_blobs ON blobstore1_raw_blobs.id = blobstore1_files_hidden.raw_blob_id
);