<?php

declare(strict_types=1);

/*
 * A clamd stand-in for protocol tests: listens on a free port, prints it, and answers one connection as told.
 *   limit <bytes>   reads an INSTREAM and hangs up once more than <bytes> arrived, like StreamMaxLength
 *   reply <text>    reads a command (and its stream) and answers <text>
 *   silent <secs>   reads a command and says nothing for <secs> seconds
 */
$arguments = $_SERVER['argv'] ?? [];
$mode = is_array($arguments) && is_string($arguments[1] ?? null) ? $arguments[1] : '';
$argument = is_array($arguments) && is_string($arguments[2] ?? null) ? $arguments[2] : '';

$server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if (false === $server) {
    fwrite(\STDERR, $errorMessage ?? 'Cannot listen.');
    exit(1);
}
echo substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1), "\n";

$client = stream_socket_accept($server, 10);
if (false === $client) {
    exit(1);
}

$readExactly = static function (mixed $client, int $length): string {
    if (!is_resource($client)) {
        return '';
    }
    $data = '';
    while (strlen($data) < $length) {
        $chunk = fread($client, max(1, $length - strlen($data)));
        if (false === $chunk || '' === $chunk) {
            break;
        }
        $data .= $chunk;
    }

    return $data;
};

$command = '';
while (!str_ends_with($command, "\0")) {
    $command .= $readExactly($client, 1);
}
if ("zINSTREAM\0" === $command) {
    $received = 0;
    while (true) {
        $header = unpack('N', $readExactly($client, 4));
        $length = is_array($header) && is_int($header[1] ?? null) ? $header[1] : 0;
        if (0 === $length) {
            break;
        }
        $received += strlen($readExactly($client, $length));
        if ('limit' === $mode && $received > (int) $argument) {
            fwrite($client, "INSTREAM size limit exceeded. ERROR\0");
            fclose($client);
            exit(0);
        }
    }
}
if ('silent' === $mode) {
    sleep((int) $argument);
    fclose($client);
    exit(0);
}
fwrite($client, $argument."\0");
fclose($client);
