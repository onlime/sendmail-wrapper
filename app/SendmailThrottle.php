<?php

require_once 'StdinMailParser.php';

/**
 * Sendmail Wrapper by Onlime GmbH webhosting services
 * https://github.com/onlime/sendmail-wrapper
 *
 * @copyright Copyright (c) Onlime GmbH (https://www.onlime.ch)
 */
class SendmailThrottle extends StdinMailParser
{
    const STATUS_OK = 0;
    const STATUS_QUOTA_REACHED = 1;
    const STATUS_OVERQUOTA = 2;
    const STATUS_BLOCKED = 3;
    const STATUS_EXCEPTION = 4;

    protected ?PDO $pdo;

    /**
     * Destructor
     * close the PDO database connection
     */
    public function __destruct()
    {
        $this->pdo = null;
    }

    /**
     * Create PDO database connection
     *
     * @throws PDOException
     */
    protected function connect()
    {
        $this->pdo = new PDO(
            $this->conf->db->dsn,
            $this->conf->db->user,
            $this->conf->db->pass
        );
    }

    /**
     * throttling
     *
     * status code 0: limit not reached, ok
     * status code 1: limit reached, sending notification to admin
     * status code 2: over limit, do not warn admin multiple times
     *
     * @param string $username
     * @param int $rcptCount number of recipients
     * @return int exit status code (0 = success)
     * @throws Exception
     */
    public function run(string $username, int $rcptCount): int
    {
        try {
            // connect to DB
            $this->connect();

            // default status code: success
            $status = self::STATUS_OK;

            $sql = 'SELECT * FROM throttle WHERE username = :username';
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':username', $username);
            $stmt->execute();
            $throttle = $stmt->fetchObject();
            if ($throttle) {
                // reset counters on new day (after midnight)
                $dateUpdated = new DateTime($throttle->updated_ts);
                $dateCurrent = new DateTime();
                $sameDay = ($dateUpdated->format('Y-m-d') == $dateCurrent->format('Y-m-d'));
                if (! $sameDay) {
                    $countCur = 1;
                    $rcptCur  = 1;
                } else {
                    $countCur = ++$throttle->count_cur;           // raise by 1
                    $rcptCur  = $throttle->rcpt_cur + $rcptCount; // raise by number of recipients
                }

                $countMax = $throttle->count_max;
                $countTot = ++$throttle->count_tot; // raise by 1
                $rcptMax  = $throttle->rcpt_max;
                $rcptTot  = $throttle->rcpt_tot + $rcptCount; // raise by number of recipients

                // check email or recipient count
                if ($countCur > $countMax || $rcptCur > $rcptMax) {
                    // return 1 if previous status was 0 (ok), otherwise 2
                    $status = ($throttle->status == self::STATUS_OK) ? self::STATUS_QUOTA_REACHED : self::STATUS_OVERQUOTA;
                }

                $sql = 'UPDATE throttle SET updated_ts = NOW(), count_cur = :countCur, count_tot = :countTot,
                        rcpt_cur = :rcptCur, rcpt_tot = :rcptTot, status = :status
                        WHERE username = :username';
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindParam(':countCur', $countCur, PDO::PARAM_INT);
                $stmt->bindParam(':countTot', $countTot, PDO::PARAM_INT);
                $stmt->bindParam(':rcptCur', $rcptCur, PDO::PARAM_INT);
                $stmt->bindParam(':rcptTot', $rcptTot, PDO::PARAM_INT);
                $stmt->bindParam(':status', $status, PDO::PARAM_INT);
                $stmt->bindParam(':username', $username);
                $stmt->execute();
                $id = $throttle->id;

                // if user is blocked, override previous status by return code 3
                if ($throttle->blocked) {
                    $status = self::STATUS_BLOCKED;
                }
            } else {
                $countMax = $this->conf->throttle->countMax;
                $countCur = 1;
                $countTot = 1;
                $rcptMax  = $this->conf->throttle->rcptMax;
                $rcptCur  = $rcptCount;
                $rcptTot  = $rcptCount;

                $sql = 'INSERT INTO throttle (updated_ts, username, count_max, rcpt_max, rcpt_cur, rcpt_tot)
                        VALUES (NOW(), :username, :countMax, :rcptMax, :rcptCur, :rcptTot)';
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindParam(':username', $username);
                $stmt->bindParam(':countMax', $countMax, PDO::PARAM_INT);
                $stmt->bindParam(':rcptMax', $rcptMax, PDO::PARAM_INT);
                $stmt->bindParam(':rcptCur', $rcptCur, PDO::PARAM_INT);
                $stmt->bindParam(':rcptTot', $rcptTot, PDO::PARAM_INT);
                $stmt->execute();
                $id = $this->pdo->lastInsertId();
            }

            // syslogging
            $syslogMsg =  sprintf('%s: user=%s (%s:%s), rcpts=%s, status=%s, command=%s, ' .
                'count_max=%s, count_cur=%s, count_tot=%s, ' .
                'rcpt_max=%s, rcpt_cur=%s, rcpt_tot=%s',
                $this->conf->throttle->syslogPrefix,
                $username,
                $_SERVER['SUDO_UID'],
                $_SERVER['SUDO_GID'],
                $rcptCount,
                $status,
                $_SERVER['SUDO_COMMAND'],
                $countMax,
                $countCur,
                $countTot,
                $rcptMax,
                $rcptCur,
                $rcptTot
            );
            // Don't write to syslog for blocked useraccounts - we still have all meta information in messages
            // table but don't want to fill up syslog.
            if ($status != self::STATUS_BLOCKED) {
                syslog(LOG_INFO, $syslogMsg);
            }

            // Report message limit reached to administrator
            if ($status == self::STATUS_QUOTA_REACHED) {
                // Do not report on status code 2, as the admin only wants to get notified once!
                // Also, he is never interested in blocked accounts (status code 3).
                mail(
                    $this->conf->global->adminTo,
                    $this->conf->throttle->adminSubject,
                    $syslogMsg,
                    'From: ' . $this->conf->global->adminFrom
                );
            }

            // write all meta information to db messages log
            $this->logMessage($id, $username, $rcptCount, $status);

            return $status;
        } catch (PDOException $e) {
            syslog(LOG_WARNING, sprintf('%s: PDOException: %s', $this->conf->throttle->syslogPrefix, $e->getMessage()));
            return self::STATUS_EXCEPTION;
        }
    }

    /**
     * Insert metadata of each message into messages table,
     * for logging purposes.
     *
     * @param int $throttleId
     * @param string $username
     * @param int $rcptCount
     * @param int $status
     */
    protected function logMessage(int $throttleId, string $username, int $rcptCount, int $status)
    {
        $headerArr = $this->getParsedHeaderArr();
        $from    = mb_decode_mimeheader($headerArr['from'] ?? null);
        $to      = mb_decode_mimeheader($headerArr['to'] ?? null);
        $cc      = mb_decode_mimeheader($headerArr['cc'] ?? null);
        $bcc     = mb_decode_mimeheader($headerArr['bcc'] ?? null);
        $subject = mb_decode_mimeheader($headerArr['subject'] ?? null);

        $sql = "INSERT INTO messages (throttle_id, username, uid, gid, rcpt_count, status, msgid, from_addr, to_addr,
                  cc_addr, bcc_addr, subject, site, client, sender_host, script)
                VALUES (:throttleId, :username, :uid, :gid, :rcptCount, :status, :msgid, :fromAddr, :toAddr,
                  :ccAddr, :bccAddr, :subject, :site, :client, SUBSTRING_INDEX(USER(), '@', -1), :script)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':throttleId', $throttleId);
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':uid', $_SERVER['SUDO_UID']);
        $stmt->bindParam(':gid', $_SERVER['SUDO_GID']);
        $stmt->bindParam(':rcptCount', $rcptCount);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':msgid', $headerArr['x-meta-msgid']);
        $stmt->bindParam(':fromAddr', $from);
        $stmt->bindParam(':toAddr', $to);
        $stmt->bindParam(':ccAddr', $cc);
        $stmt->bindParam(':bccAddr', $bcc);
        $stmt->bindParam(':subject', $subject);
        $stmt->bindParam(':site', $headerArr['x-meta-site']);
        $stmt->bindParam(':client', $headerArr['x-meta-client']);
        $stmt->bindParam(':script', $headerArr['x-meta-script']);
        $stmt->execute();
    }
}
