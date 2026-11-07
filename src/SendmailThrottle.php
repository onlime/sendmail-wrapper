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
    /** SQLSTATE of deadlocks / serialization failures (e.g. MySQL error 1213) */
    private const SQLSTATE_DEADLOCK = '40001';

    private const DEADLOCK_RETRIES = 5;

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

            // retry on deadlocks (e.g. concurrent inserts), as recommended by MySQL
            for ($attempt = 1; ; $attempt++) {
                try {
                    [$id, $status, $counters] = $this->count($username, $rcptCount);
                    break;
                } catch (PDOException $e) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    if ($e->getCode() !== self::SQLSTATE_DEADLOCK || $attempt >= self::DEADLOCK_RETRIES) {
                        throw $e;
                    }
                    // random backoff, so concurrent processes don't collide again
                    usleep(random_int(10_000, 100_000));
                }
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
                ...array_values($counters),
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
     * Update the user's throttle counters in a transaction.
     *
     * @return array{int, ThrottleStatus, array<string, int>} throttle record id, status and counters
     */
    private function count(string $username, int $rcptCount): array
    {
        $now = $this->now->format('Y-m-d H:i:s');
        $status = ThrottleStatus::Ok;

        // Lock the user's throttle record until commit, so that concurrently sent messages
        // cannot read the same counters and lose each other's updates.
        $this->pdo->beginTransaction();
        $forUpdate = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $stmt = $this->pdo->prepare('SELECT * FROM throttle WHERE username = :username' . $forUpdate);
        $stmt->execute(['username' => $username]);
        $throttle = $stmt->fetchObject();

        if ($throttle) {
            // reset current counters and status on a new day (after midnight)
            $sameDay = str_starts_with((string) $throttle->updated_ts, $this->now->format('Y-m-d'));

            $counters = [
                'count_max' => (int) $throttle->count_max,
                'count_cur' => ($sameDay ? (int) $throttle->count_cur : 0) + 1,
                'count_tot' => (int) $throttle->count_tot + 1,
                'rcpt_max' => (int) $throttle->rcpt_max,
                'rcpt_cur' => ($sameDay ? (int) $throttle->rcpt_cur : 0) + $rcptCount,
                'rcpt_tot' => (int) $throttle->rcpt_tot + $rcptCount,
            ];
            $previousStatus = $sameDay ? (int) $throttle->status : ThrottleStatus::Ok->value;
        } else {
            $counters = [
                'count_max' => $this->config->int('throttle.countMax'),
                'count_cur' => 1,
                'count_tot' => 1,
                'rcpt_max' => $this->config->int('throttle.rcptMax'),
                'rcpt_cur' => $rcptCount,
                'rcpt_tot' => $rcptCount,
            ];
            $previousStatus = ThrottleStatus::Ok->value;
        }

        // check email or recipient count
        if ($counters['count_cur'] > $counters['count_max'] || $counters['rcpt_cur'] > $counters['rcpt_max']) {
            // only report reaching the limit once (if previous status was ok)
            $status = $previousStatus === ThrottleStatus::Ok->value
                ? ThrottleStatus::QuotaReached
                : ThrottleStatus::OverQuota;
        }

        if ($throttle) {
            $this->pdo->prepare(
                'UPDATE throttle SET updated_ts = :now, count_cur = :countCur, count_tot = :countTot,
                    rcpt_cur = :rcptCur, rcpt_tot = :rcptTot, status = :status
                WHERE username = :username'
            )->execute([
                'now' => $now,
                'countCur' => $counters['count_cur'],
                'countTot' => $counters['count_tot'],
                'rcptCur' => $counters['rcpt_cur'],
                'rcptTot' => $counters['rcpt_tot'],
                'status' => $status->value,
                'username' => $username,
            ]);
            $id = (int) $throttle->id;

            // if user is blocked, override previous status
            if ($throttle->blocked) {
                $status = ThrottleStatus::Blocked;
            }
        } else {
            $this->pdo->prepare(
                'INSERT INTO throttle (updated_ts, username, count_max, rcpt_max, rcpt_cur, rcpt_tot, status)
                VALUES (:now, :username, :countMax, :rcptMax, :rcptCur, :rcptTot, :status)'
            )->execute([
                'now' => $now,
                'username' => $username,
                'countMax' => $counters['count_max'],
                'rcptMax' => $counters['rcpt_max'],
                'rcptCur' => $counters['rcpt_cur'],
                'rcptTot' => $counters['rcpt_tot'],
                'status' => $status->value,
            ]);
            $id = (int) $this->pdo->lastInsertId();
        }

        // commit before notifying the admin, whose mail is throttled as well
        $this->pdo->commit();

        return [$id, $status, $counters];
    }

    /**
     * Insert meta information of the message into messages table, for logging purposes.
     *
     * Values are truncated to their column sizes. Failing to log a message must never prevent it
     * from being sent, so database errors are only reported to syslog.
     */
    private function logMessage(int $throttleId, string $username, int $rcptCount, ThrottleStatus $status): void
    {
        $headers = $this->message->headers();

        try {
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
                'msgid' => self::limit($headers['x-meta-msgid'] ?? null, 255),
                'fromAddr' => self::limit(self::decode($headers['from'] ?? ''), 255),
                'toAddr' => self::limitText(self::decode($headers['to'] ?? '')),
                'ccAddr' => self::limitText(self::decode($headers['cc'] ?? '')),
                'bccAddr' => self::limitText(self::decode($headers['bcc'] ?? '')),
                'subject' => self::limit(self::decode($headers['subject'] ?? ''), 255),
                'site' => self::limit($headers['x-meta-site'] ?? '', 255),
                'client' => self::limit($headers['x-meta-client'] ?? null, 40),
                'senderHost' => self::limit(gethostname() ?: 'localhost', 255),
                'script' => self::limit($headers['x-meta-script'] ?? null, 255),
            ]);
        } catch (PDOException $e) {
            ($this->log)(LOG_WARNING, sprintf(
                '%s: Could not log message to database: %s',
                $this->config->string('throttle.syslogPrefix'),
                $e->getMessage(),
            ));
        }
    }

    /**
     * Decode RFC 2047 encoded-words (e.g. "=?UTF-8?Q?M=C3=BCller?=") in a header value.
     *
     * Only (runs of adjacent) encoded-words are decoded, as mb_decode_mimeheader() replaces
     * raw (unencoded) UTF-8 characters by question marks.
     */
    private static function decode(string $value): string
    {
        return preg_replace_callback(
            '/=\?[^?\s]+\?[BbQq]\?[^?\s]*\?=(?:\s+=\?[^?\s]+\?[BbQq]\?[^?\s]*\?=)*/',
            fn (array $match) => mb_decode_mimeheader($match[0]),
            $value,
        ) ?? $value;
    }

    /**
     * Limit a value to the length of a VARCHAR column (in characters).
     */
    private static function limit(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }

    /**
     * Limit a value to the size of a TEXT column (64 KB), without breaking multibyte characters.
     */
    private static function limitText(string $value): string
    {
        return mb_strcut($value, 0, 65535);
    }
}
