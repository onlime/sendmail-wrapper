<?php

declare(strict_types=1);

/**
 * Run an entry script in a separate PHP process.
 *
 * @return array{int, string} exit code and STDERR output
 */
function runScript(string $script, array $args, array $env, string $cwd, string $stdin = ''): array
{
    $process = proc_open(
        [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'include_path=.', dirname(__DIR__) . "/$script", ...$args],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
        ['PATH' => (string) getenv('PATH'), ...$env],
    );
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);

    return [proc_close($process), $stderr];
}

it('never loads files from the working directory', function () {
    // PHP resolves relative include paths through include_path (".") first, i.e. the caller's working
    // directory, which is kept by sudo. Place rogue files with all names that were or are being used.
    $cwd = tempDir();
    $rogue = sprintf('<?php file_put_contents(%s, __FILE__ . "\n", FILE_APPEND);', var_export("$cwd/pwned", true));
    foreach (['src', 'app'] as $dir) {
        mkdir("$cwd/$dir");
    }
    foreach (glob(dirname(__DIR__) . '/{,src/}*.php', GLOB_BRACE) as $file) {
        $name = basename($file);
        file_put_contents("$cwd/$name", $rogue);
        file_put_contents("$cwd/src/$name", $rogue);
        file_put_contents("$cwd/app/$name", $rogue);
    }
    foreach (['ConfigLoader.php', 'StdinMailParser.php'] as $name) {
        file_put_contents("$cwd/$name", $rogue);
    }

    [$exitCode] = runScript(
        'sendmail-throttle.php',
        ['1'],
        // no database available
        ['SUDO_USER' => 'web1'],
        $cwd,
        "From: a@example.com\nTo: b@example.com\n\nBody",
    );

    expect($exitCode)->toBe(4)
        ->and(file_exists("$cwd/pwned") ? file_get_contents("$cwd/pwned") : '')->toBe('');
});

it('validates the arguments of sendmail-throttle', function (array $args, array $env) {
    [$exitCode, $stderr] = runScript('sendmail-throttle.php', $args, $env, tempDir());

    expect($exitCode)->toBe(4)
        ->and($stderr)->toStartWith('Usage: sudo -u sendmailwrapper sendmail-throttle <recipient-count>');
})->with([
    'not run via sudo' => [['1'], []],
    'missing recipient count' => [[], ['SUDO_USER' => 'web1']],
    'invalid recipient count' => [['-1'], ['SUDO_USER' => 'web1']],
    'additional arguments' => [['1', '2'], ['SUDO_USER' => 'web1']],
]);
