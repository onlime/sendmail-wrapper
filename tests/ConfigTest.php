<?php

declare(strict_types=1);

use Onlime\SendmailWrapper\Config;

it('loads config.ini with typed values', function () {
    $config = Config::load(dirname(__DIR__));

    expect($config->get('wrapper.throttleOn'))->toBeTrue()
        ->and($config->get('throttle.ignoreExceptions'))->toBeFalse()
        ->and($config->get('throttle.countMax'))->toBe(1000)
        ->and($config->get('wrapper.xHeaderPrefix'))->toBeString();
});

it('overrides config.ini with config.local.ini and config.private.ini', function () {
    $dir = tempDir();
    file_put_contents("$dir/config.ini", "[db]\nuser = foo\npass = \"default\"\n[global]\nadminTo = a@example.com\n");
    file_put_contents("$dir/config.local.ini", "[global]\nadminTo = b@example.com\n");
    file_put_contents("$dir/config.private.ini", "[db]\npass = \"12345\"\n");

    $config = Config::load($dir);

    expect($config->get('db.user'))->toBe('foo')
        ->and($config->get('db.pass'))->toBe('12345')
        ->and($config->get('global.adminTo'))->toBe('b@example.com');
});

it('skips unreadable config files', function () {
    $dir = tempDir();
    file_put_contents("$dir/config.ini", "[db]\npass = \"default\"\n");
    file_put_contents("$dir/config.private.ini", "[db]\npass = \"secret\"\n");
    chmod("$dir/config.private.ini", 0);

    expect(Config::load($dir)->get('db.pass'))->toBe('default');
})->skip(fn () => posix_geteuid() === 0, 'root can read any file');

it('throws if config.ini is missing', function () {
    Config::load(tempDir());
})->throws(RuntimeException::class);

it('returns defaults and casts values', function () {
    $config = new Config(['a' => ['int' => '42', 'bool' => 1, 'pass' => 12345]]);

    expect($config->get('a.missing', 'default'))->toBe('default')
        ->and($config->get('missing.key'))->toBeNull()
        ->and($config->get('a'))->toBeArray()
        ->and($config->int('a.int'))->toBe(42)
        ->and($config->bool('a.bool'))->toBeTrue()
        ->and($config->string('a.pass'))->toBe('12345')
        ->and($config->string('a.missing', 'x'))->toBe('x');
});
