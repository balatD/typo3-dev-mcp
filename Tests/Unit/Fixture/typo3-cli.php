<?php

declare(strict_types=1);

/*
 * Stand-in for `vendor/bin/typo3` in unit tests: behaves like `devmcp:call`
 * and `cache:flush` without booting TYPO3, chosen by the tool or group name.
 */

use BalatD\DevMcp\Mcp\Support\ToolCallEnvelope;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$command = $argv[1] ?? '';
$subject = $argv[2] ?? '';

if ($command === 'cache:flush') {
    if ($subject === '--group=nope') {
        fwrite(STDERR, "No cache in the specified group 'nope'\n");
        exit(1);
    }
    exit(0);
}

$arguments = ToolCallEnvelope::readArguments((string)stream_get_contents(STDIN));

switch ($subject) {
    case 'echo':
        echo ToolCallEnvelope::result($arguments);
        break;
    case 'pid':
        echo ToolCallEnvelope::result(['pid' => getmypid()]);
        break;
    case 'fails':
        echo ToolCallEnvelope::error('kaputt');
        exit(1);
    case 'crash':
        fwrite(STDERR, "PHP Fatal error: Allowed memory size exhausted\n");
        exit(255);
    case 'noise':
        echo "Deprecated: something\n" . ToolCallEnvelope::result([]);
        break;
    case 'slow':
        sleep(5);
        break;
}
