<?php

declare(strict_types=1);

namespace Onlime\SendmailWrapper;

use Closure;
use DateTimeImmutable;
use PDO;
use PDOException;

/**
 * Sendmail Wrapper by Onlime GmbH webhosting services
 * https://github.com/onlime/sendmail-wrapper
 *
 * Counts messages and recipients per user and day, checks them against the user's limits and
 * logs the message meta information to the database.
 *
 * @copyright Copyright (c) Onlime GmbH (https://www.onlime.ch)
 */
final class SendmailThrottle
{
    /** @var Closure(int, string): mixed */
    private readonly Closure $log;

    /** @var Closure(string, string, string, string): mixed */
    private readonly Closure $mail;

    /**
     * @param  PDO|null  $pdo  database connection, created from configuration if null
     * @param  array<string, string>  $env  environment variables, incl. SUDO_UID, SUDO_GID, SUDO_COMMAND set by sudo
     * @param  (Closure(int, string): mixed)|null  $log  defaults to syslog()
     * @param  (Closure(string, string, string, string): mixed)|null  $mail  defaults to mail()
     */
    public function __construct(
        private readonly Config $config,
        private readonly Message $message,
        private ?PDO $pdo = null,
        private readonly array $env = [],
        private readonly DateTimeImmutable $now = new DateTimeImmutable,
        ?Closure $log = null,
        ?Closure $mail = null,
    ) {
        $this->log = $log ?? syslog(...);
        $this->mail = $mail ?? mail(...);
    }

    /**
     * Count the message and its recipients and check the user's limits.
     */
    public function run(string $username, int $rcptCount): ThrottleStatus
    {
        $syslogPrefix = $this->config->string('throttle.syslogPrefix');

        try {
            $this->pdo ??= new PDO(
                $this->config->string('db.dsn'),
                $this->config->string('db.user'),
                $this->config->string('db.pass'),
            );

            $now = $this->now->format('Y-m-d H:i:s');
            $status = ThrottleStatus::Ok;

            $stmt = $this->pdo->prepare('SELECT * FROM throttle WHERE username = :username');
            $stmt->execute(['username' => $username]);
            $throttle = $stmt->fetchObject();

            if ($throttle) {
                // reset counters on new day (after midnight)
                if (str_starts_with((string) $throttle->updated_ts, $this->now->format('Y-m-d'))) {
                    $countCur = (int) $throttle->count_cur + 1;
                    $rcptCur = (int) $throttle->rcpt_cur + $rcptCount;
                } else {
                    $countCur = 1;
                    $rcptCur = 1;
                }

                $countMax = (int) $throttle->count_max;
                $countTot = (int) $throttle->count_tot + 1;
                $rcptMax = (int) $throttle->rcpt_max;
                $rcptTot = (int) $throttle->rcpt_tot + $rcptCount;

                // check email or recipient count
                if ($countCur > $countMax || $rcptCur > $rcptMax) {
                    // only report reaching the limit once (if previous status was ok)
                    $status = (int) $throttle->status === ThrottleStatus::Ok->value
                        ? ThrottleStatus::QuotaReached
                        : ThrottleStatus::OverQuota;
                }

                $this->pdo->prepare(
                    'UPDATE throttle SET updated_ts = :now, count_cur = :countCur, count_tot = :countTot,
                        rcpt_cur = :rcptCur, rcpt_tot = :rcptTot, status = :status
                    WHERE username = :username'
                )->execute([
                    'now' => $now,
                    'countCur' => $countCur,
                    'countTot' => $countTot,
                    'rcptCur' => $rcptCur,
                    'rcptTot' => $rcptTot,
                    'status' => $status->value,
                    'username' => $username,
                ]);
                $id = (int) $throttle->id;

                // if user is blocked, override previous status
                if ($throttle->blocked) {
                    $status = ThrottleStatus::Blocked;
                }
            } else {
                $countMax = $this->config->int('throttle.countMax');
                $countCur = 1;
                $countTot = 1;
                $rcptMax = $this->config->int('throttle.rcptMax');
                $rcptCur = $rcptCount;
                $rcptTot = $rcptCount;

                $this->pdo->prepare(
                    'INSERT INTO throttle (updated_ts, username, count_max, rcpt_max, rcpt_cur, rcpt_tot)
                    VALUES (:now, :username, :countMax, :rcptMax, :rcptCur, :rcptTot)'
                )->execute([
                    'now' => $now,
                    'username' => $username,
                    'countMax' => $countMax,
                    'rcptMax' => $rcptMax,
                    'rcptCur' => $rcptCur,
                    'rcptTot' => $rcptTot,
                ]);
                $id = (int) $this->pdo->lastInsertId();
            }

            $syslogMsg = sprintf(
                '%s: user=%s (%s:%s), rcpts=%s, status=%s, command=%s, '
                . 'count_max=%s, count_cur=%s, count_tot=%s, '
                . 'rcpt_max=%s, rcpt_cur=%s, rcpt_tot=%s',
                $syslogPrefix,
                $username,
                $this->env['SUDO_UID'] ?? '',
                $this->env['SUDO_GID'] ?? '',
                $rcptCount,
                $status->value,
                $this->env['SUDO_COMMAND'] ?? '',
                $countMax,
                $countCur,
                $countTot,
                $rcptMax,
                $rcptCur,
                $rcptTot,
            );
            // Don't write to syslog for blocked user accounts - we still have all meta information in messages
            // table but don't want to fill up syslog.
            if ($status !== ThrottleStatus::Blocked) {
                ($this->log)(LOG_INFO, $syslogMsg);
            }

            // Report message limit reached to administrator, only once (not on OverQuota), and
            // never for blocked accounts.
            if ($status === ThrottleStatus::QuotaReached) {
                ($this->mail)(
                    $this->config->string('global.adminTo'),
                    $this->config->string('throttle.adminSubject'),
                    $syslogMsg,
                    'From: ' . $this->config->string('global.adminFrom'),
                );
            }

            // write all meta information to db messages log
            $this->logMessage($id, $username, $rcptCount, $status);

            return $status;
        } catch (PDOException $e) {
            ($this->log)(LOG_WARNING, sprintf('%s: PDOException: %s', $syslogPrefix, $e->getMessage()));

            return ThrottleStatus::Exception;
        }
    }

    /**
     * Insert meta information of the message into messages table, for logging purposes.
     */
    private function logMessage(int $throttleId, string $username, int $rcptCount, ThrottleStatus $status): void
    {
        $headers = $this->message->headers();

        $this->pdo->prepare(
            'INSERT INTO messages (throttle_id, username, uid, gid, rcpt_count, status, msgid, from_addr, to_addr,
                cc_addr, bcc_addr, subject, site, client, sender_host, script)
            VALUES (:throttleId, :username, :uid, :gid, :rcptCount, :status, :msgid, :fromAddr, :toAddr,
                :ccAddr, :bccAddr, :subject, :site, :client, :senderHost, :script)'
        )->execute([
            'throttleId' => $throttleId,
            'username' => $username,
            'uid' => $this->env['SUDO_UID'] ?? null,
            'gid' => $this->env['SUDO_GID'] ?? null,
            'rcptCount' => $rcptCount,
            'status' => $status->value,
            'msgid' => $headers['x-meta-msgid'] ?? null,
            'fromAddr' => mb_decode_mimeheader($headers['from'] ?? ''),
            'toAddr' => mb_decode_mimeheader($headers['to'] ?? ''),
            'ccAddr' => mb_decode_mimeheader($headers['cc'] ?? ''),
            'bccAddr' => mb_decode_mimeheader($headers['bcc'] ?? ''),
            'subject' => mb_decode_mimeheader($headers['subject'] ?? ''),
            'site' => $headers['x-meta-site'] ?? '',
            'client' => $headers['x-meta-client'] ?? null,
            'senderHost' => gethostname() ?: 'localhost',
            'script' => $headers['x-meta-script'] ?? null,
        ]);
    }
}
