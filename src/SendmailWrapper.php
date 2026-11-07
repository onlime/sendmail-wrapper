<?php

declare(strict_types=1);

namespace Onlime\SendmailWrapper;

use Closure;

/**
 * Sendmail Wrapper by Onlime GmbH webhosting services
 * https://github.com/onlime/sendmail-wrapper
 *
 * Logs and throttles a message passed to sendmail (via PHP's mail() function), before passing
 * it to the original sendmail binary.
 *
 * @copyright Copyright (c) Onlime GmbH (https://www.onlime.ch)
 */
final class SendmailWrapper
{
    /** @var Closure(int, string): mixed */
    private readonly Closure $log;

    /**
     * @param  list<string>  $args  command line arguments passed to sendmail-wrapper (without script name)
     * @param  array<string, string>  $env  environment variables, incl. HTTP_HOST, REMOTE_ADDR, SCRIPT_FILENAME
     *                                      exported by prepend.php
     * @param  (Closure(int, string): mixed)|null  $log  defaults to syslog()
     */
    public function __construct(
        private readonly Config $config,
        private readonly Message $message,
        private readonly array $args = [],
        private readonly array $env = [],
        ?Closure $log = null,
    ) {
        $this->log = $log ?? syslog(...);
    }

    /**
     * Run sendmail wrapper
     *
     * @return int exit status code (0 = success)
     */
    public function run(): int
    {
        $xHeaderPrefix = $this->config->string('wrapper.xHeaderPrefix');
        $syslogPrefix = $this->config->string('wrapper.syslogPrefix');

        // generate a unique, RFC 5322 compliant Message-ID
        $msgId = sprintf('%s.%s@%s', date('YmdHis'), bin2hex(random_bytes(6)), $this->config->string('wrapper.defaultHost'));
        $this->message->addHeader($xHeaderPrefix . 'MsgID', "<$msgId>");

        // parse original headers
        $headers = $this->message->headers();
        foreach ($this->message->malformedHeaders() as $line) {
            ($this->log)(LOG_WARNING, "$syslogPrefix: Could not parse mail header line: $line");
        }

        $site = $this->env['HTTP_HOST'] ?? '';
        $client = $this->env['REMOTE_ADDR'] ?? '';
        $script = $this->env['SCRIPT_FILENAME'] ?? '';

        // throttling
        $status = ThrottleStatus::Ok->value;
        if ($this->config->bool('wrapper.throttleOn')) {
            // pass the whole message including some meta information headers to the throttle command
            $status = $this->execute(
                [...$this->command('wrapper.throttleCmd'), (string) $this->recipientCount($headers)],
                $this->message->toString([
                    'X-Meta-MsgID' => $msgId,
                    'X-Meta-Site' => $site,
                    'X-Meta-Client' => $client,
                    'X-Meta-Script' => $script,
                ]),
            ) ?? $status;
        }

        // Don't write to syslog for blocked user accounts - we still have all meta information in messages
        // table but don't want to fill up syslog.
        if ($status !== ThrottleStatus::Blocked->value) {
            ($this->log)(LOG_INFO, sprintf(
                '%s: uid=%s, msgid=%s, from=%s, to="%s", cc="%s", bcc="%s", subject="%s", site=%s, client=%s, script=%s, throttleStatus=%s',
                $syslogPrefix,
                $this->username(),
                $msgId,
                $headers['from'] ?? '',
                $this->truncate($headers['to'] ?? ''),
                $this->truncate($headers['cc'] ?? ''),
                $this->truncate($headers['bcc'] ?? ''),
                $headers['subject'] ?? '',
                $site,
                $client,
                $script,
                $status,
            ));
        }

        // terminate if message limit exceeded
        if ($status > ThrottleStatus::Ok->value) {
            if ($this->config->bool('throttle.ignoreExceptions') && $status === ThrottleStatus::Exception->value) {
                $status = ThrottleStatus::Ok->value;
            } else {
                return $status;
            }
        }

        $sendmail = $this->command('wrapper.sendmailCmd');

        // Force adding envelope sender address (sendmail -r/-f parameters) from Return-Path or From header.
        // The address must be passed as a separate argument without involving a shell, as
        // FILTER_VALIDATE_EMAIL allows shell metacharacters like ` | & { } $
        if (! in_array('-f', $this->args, true) && ! in_array('-r', $this->args, true)) {
            foreach (['return-path' => 'Return-Path', 'from' => 'From'] as $name => $label) {
                if (filter_var($headers[$name] ?? '', FILTER_VALIDATE_EMAIL)) {
                    array_push($sendmail, '-r', $headers[$name]);
                    $this->message->addHeader($xHeaderPrefix . 'EnvSender', $label);
                    break;
                }
            }
        }

        // pass the message to the original sendmail binary, appending all original arguments unchanged
        $this->execute([...$sendmail, ...$this->args], $this->message->toString());

        return $status;
    }

    /**
     * Count the total number of recipients in To, Cc and Bcc headers.
     */
    private function recipientCount(array $headers): int
    {
        return AddressList::count($headers['to'] ?? '')
            + AddressList::count($headers['cc'] ?? '')
            + AddressList::count($headers['bcc'] ?? '');
    }

    /**
     * Name of the system user running sendmail-wrapper.
     */
    private function username(): string
    {
        return posix_getpwuid(posix_geteuid())['name'] ?? (string) posix_geteuid();
    }

    /**
     * Limit a string to the configured syslog string length.
     */
    private function truncate(string $value): string
    {
        $limit = $this->config->int('syslog.stringLengthLimit');
        $suffix = $this->config->string('syslog.stringCutSuffix');

        return mb_strlen($value) > $limit
            ? mb_substr($value, 0, $limit - mb_strlen($suffix)) . $suffix
            : $value;
    }

    /**
     * Split a configured command line (trusted config value) into its arguments.
     *
     * @return list<string>
     */
    private function command(string $key): array
    {
        return preg_split('/\s+/', trim($this->config->string($key)), -1, PREG_SPLIT_NO_EMPTY);
    }

    /**
     * Execute a command directly (without a shell), pass $input to its STDIN and return
     * its exit status, or null if the process could not be started.
     *
     * @param  list<string>  $command
     */
    private function execute(array $command, string $input): ?int
    {
        $process = proc_open($command, [0 => ['pipe', 'r']], $pipes);
        if (! is_resource($process)) {
            return null;
        }
        fwrite($pipes[0], $input);
        // It is important to close any pipes before calling proc_close() in order to avoid a deadlock
        fclose($pipes[0]);

        return proc_close($process);
    }
}
