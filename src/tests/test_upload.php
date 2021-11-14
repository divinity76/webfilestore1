<?php
declare(strict_types = 1);

use Loltek\paste2\blobstore1\Config;

require_once ('../config.php');

/**
 * better version of shell_exec(),
 * supporting both stdin and stdout and stderr and os-level return code
 *
 * @param string $cmd
 *            command to execute
 * @param string $stdin
 *            (optional) data to send to stdin, binary data is supported.
 * @param string $stdout
 *            (optional) stdout data generated by cmd
 * @param string $stderr
 *            (optional) stderr data generated by cmd
 * @param bool $print_std
 *            (optional, default false) if you want stdout+stderr to be printed while it's running,
 *            set this to true. (useful for long-running commands)
 * @return int
 */
function hhb_exec(string $cmd, string $stdin = "", string &$stdout = null, string &$stderr = null, bool $print_std = false): int
{
    $stdouth = tmpfile();
    $stderrh = tmpfile();
    $descriptorspec = array(
        0 => array(
            "pipe",
            "rb"
        ), // stdin
        1 => array(
            "file",
            stream_get_meta_data($stdouth)['uri'],
            'ab'
        ),
        2 => array(
            "file",
            stream_get_meta_data($stderrh)['uri'],
            'ab'
        )
    );
    $pipes = array();
    $proc = proc_open($cmd, $descriptorspec, $pipes);
    while (strlen($stdin) > 0) {
        $written_now = fwrite($pipes[0], $stdin);
        if ($written_now < 1 || $written_now === strlen($stdin)) {
            // ... can add more error checking here
            break;
        }
        $stdin = substr($stdin, $written_now);
    }
    fclose($pipes[0]);
    unset($stdin, $pipes[0]);
    if (! $print_std) {
        $proc_ret = proc_close($proc); // this line will stall until the process has exited.
        $stdout = stream_get_contents($stdouth);
        $stderr = stream_get_contents($stderrh);
    } else {
        $stdout = "";
        $stderr = "";
        stream_set_blocking($stdouth, false);
        stream_set_blocking($stderrh, false);
        $fetchstd = function () use (&$stdout, &$stderr, &$stdouth, &$stderrh): bool {
            $ret = false;
            $tmp = stream_get_contents($stdouth); // fread($stdouth, 1); //
            if (is_string($tmp) && strlen($tmp) > 0) {
                $ret = true;
                $stdout .= $tmp;
                fwrite(STDOUT, $tmp);
            }
            $tmp = stream_get_contents($stderrh); // fread($stderrh, 1); //
                                                  // var_dump($tmp);
            if (is_string($tmp) && strlen($tmp) > 0) {
                $ret = true;
                $stderr .= $tmp;
                fwrite(STDERR, $tmp);
            }
            return $ret;
        };
        while (($status = proc_get_status($proc))["running"]) {
            if (! $fetchstd()) {
                // 100 ms
                usleep(100 * 1000);
            }
        }
        $proc_ret = $status["exitcode"];
        proc_close($proc);
        $fetchstd();
    }
    fclose($stdouth);
    fclose($stderrh);
    return $proc_ret;
}

function json_encode_pretty($data, int $extra_flags = 0, int $exclude_flags = 0): string
{
    // prettiest flags for: 7.3.9
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined("JSON_UNESCAPED_LINE_TERMINATORS") ? JSON_UNESCAPED_LINE_TERMINATORS : 0) | JSON_PRESERVE_ZERO_FRACTION | (defined("JSON_THROW_ON_ERROR") ? JSON_THROW_ON_ERROR : 0);
    $flags = ($flags | $extra_flags) & ~ $exclude_flags;
    return (json_encode($data, $flags));
}

function isJson($string)
{
    @json_decode($string);
    return json_last_error() === JSON_ERROR_NONE;
}

function test_exec(string $cmd, string &$stdout = null, string &$stderr = null): void
{
    echo "{$cmd}\n";
    $stdin = $stdout = $stderr = "";
    $print_std = true;
    $ret = hhb_exec($cmd, $stdin, $stdout, $stderr, $print_std);
    // passthru($cmd);
    if (isJson($stdout)) {
        echo "\njson prettified:\n", json_encode_pretty(json_decode($stdout, true)), "\n";
    }
    var_dump($ret);
}

/** @var string[] $argv */
//
$file_to_upload = $argv[1] ?? __FILE__;
$cmd = implode(" ", array(
    '/usr/bin/time',
    '--portability',
    'curl',
    '-v',
    escapeshellarg(Config::BASE_URL_WITH_SLASH . 'api/1/upload'),
    '-F admin_key=' . escapeshellarg(Config::ADMIN_KEY),
    '-F upload_file_hidden=' . random_int(0, 1),
    '-F file_to_upload=@' . escapeshellarg($file_to_upload)
));
$stdout = "";
test_exec($cmd, $stdout);
$decoded = (isJson($stdout) ? json_decode($stdout, true) : null);

if (! empty($decoded["relative_url_small"]) && random_int(0, 1)) {
    echo "testing delete!\n";

    $cmd = implode(" ", array(
        '/usr/bin/time',
        '--portability',
        'curl',
        '-v',
        escapeshellarg(Config::BASE_URL_WITH_SLASH . 'api/1/delete'),
        '-F admin_key=' . escapeshellarg(Config::ADMIN_KEY),
        '-F file_to_delete=' . escapeshellarg((string) $decoded["relative_url_small"])
    ));
    test_exec($cmd, $stdout);
}