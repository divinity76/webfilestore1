<?php
declare(strict_types = 1);
namespace Loltek\paste2\blobstore1;
//
function jsresponse($data): void
{
    header("Content-Type: application/json");
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function base64url_encode(string $string): string
{
    return rtrim(strtr(base64_encode($string), '+/', '-_'), '=');
}

function base64url_decode(string $string): string
{
    return base64_decode(strtr($string, '-_', '+/'));
}

function db_quote(\PDO $db, $data): string
{
    if (is_bool($data)) {
        return ($data ? "1" : "0");
    }
    if (is_int($data)) {
        return ((string) $data);
    }
    if (is_float($data)) {
        if (! is_finite($data)) {
            throw new \LogicException("i don't know how to represent non-finite floats in MySQL!");
        }
        return number_format($data, 20, '.', '');
    }

    if (is_string($data)) {
        return $db->quote($data);
    }
    throw new \RuntimeException("i don't know how to quote this: " . gettype($data));
}

function db_quote_identifier(\PDO $db, string $identifier): string
{
    // not entirely sure why i want the PDO argument, i don't really use it...
    // identifiers have different escaping rules than other strings
    // https://www.codetinkerer.com/2015/07/08/escaping-column-and-table-names-in-mysql-part2.html
    // return "`" . str_replace("`", "``", $identifier) . "`";
    return '`' . strtr($identifier, array(
        '`' => '``',
        '.' => '`.`'
    )) . '`';
}

if (! function_exists('str_ends_with')) {

    function str_ends_with(string $haystack, string $needle): bool
    {
        $needle_len = strlen($needle);
        return ($needle_len === 0 || 0 === substr_compare($haystack, $needle, - $needle_len));
    }
}
if (! function_exists('str_starts_with')) {

    function str_starts_with(string $haystack, string $needle): bool
    {
        $needle_len = strlen($needle);
        return ($needle_len === 0 || 0 === strncmp($haystack, $needle, $needle_len));
    }
}
