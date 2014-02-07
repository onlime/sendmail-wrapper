#!/usr/bin/php -n
<?php
/**
 * Sendmail Wrapper 3.0 (Throttle module)
 * by Onlime Webhosting
 *
 * @copyright  Copyright (c) 2007-2014 Onlime Webhosting (http://www.onlime.ch)
 */

/*****************************************************************************
 * CONFIGURATION
 *****************************************************************************/

// database configuration
define('DB_DSN',  'mysql:host=localhost;dbname=sendmailwrapper');
define('DB_USER', 'sendmailwrapper');
define('DB_PASS', 'xxxxxxxxxxxxxxx');

// throttle configuration
define('COUNT_MAX', 1000);
define('RCPT_MAX',  1000);

// syslog configuration
define('SYSLOG_PREFIX', 'sendmail-throttle-php');

// system administrator report
define('ADMIN_TO'     , 'info@example.com');
define('ADMIN_FROM'   , 'info@example.com');
define('ADMIN_SUBJECT', 'Sendmail limit exceeded');

/*****************************************************************************/


class SendmailThrottle
{
    /**
     * @var PDO
     */
    protected $_pdo;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->_connect();
    }
    
    /**
     * Destructor
     * close the PDO database connection
     */
    public function __destruct()
    {
        $this->_pdo = null;
    }

    /**
     * Create PDO database connection
     */
    protected function _connect()
    {
        try {
            $this->_pdo = new PDO(DB_DSN, DB_USER, DB_PASS);
        } catch (PDOException $e) {
            die('PDO-Error: ' . $e->getMessage());
        }
    }
    
    /**
     * throttling
     *
     * status code 0: limit not reached, ok
     * status code 1: limit reached, sending notification to admin
     * status code 2: limit succeeded, do not warn admin multiple times
     *
     * @param string $username
     * @param int $rcptCount number of recipients
     * @return int status code
     */
    public function throttle($username, $rcptCount)
    {
        try {
            $sql = 'SELECT * FROM throttle WHERE username = :username';
            $stmt = $this->_pdo->prepare($sql);
            $stmt->bindParam(':username', $username);
            $stmt->execute();
            $obj = $stmt->fetchObject();
            if ($obj) {
                $countMax = $obj->count_max;
                $countCur = ++$obj->count_cur; // raise by 1
                $countTot = ++$obj->count_tot; // raise by 1
                $rcptMax  = $obj->rcpt_max;
                $rcptCur  = $obj->rcpt_cur + $rcptCount; // raise by number of recipients
                $rcptTot  = $obj->rcpt_tot + $rcptCount; // raise by number of recipients
                
                // check email count
                if ($countCur < $obj->count_max) {
                    $status = 0;
                } else {
                    $status = ($countCur == $obj->count_max) ? 1 : 2;
                }
                // check recipient count
                if ($rcptCur < $obj->rcpt_max) {
                    $status = 0;
                } else {
                    $status = ($rcptCur == $obj->rcpt_max) ? 1 : 2;
                }
                
                // reset counters on new day (after midnight)
                $dateUpdated = new DateTime($obj->updated_ts);
                $dateCurrent = new DateTime();
                $sameDay = ($dateUpdated->format('Y-m-d') == $dateCurrent->format('Y-m-d'));
                if (!$sameDay) {
                    $countCur = 1;
                    $rcptCur  = 1;
                }
                
                $sql = 'UPDATE throttle SET updated_ts = NOW(), count_cur = :countCur, count_tot = :countTot, 
                        rcpt_cur = :rcptCur, rcpt_tot = :rcptTot, status = :status 
                        WHERE username = :username';
                $stmt = $this->_pdo->prepare($sql);
                $stmt->bindParam(':countCur', $countCur, PDO::PARAM_INT);
                $stmt->bindParam(':countTot', $countTot, PDO::PARAM_INT);
                $stmt->bindParam(':rcptCur' , $rcptCur , PDO::PARAM_INT);
                $stmt->bindParam(':rcptTot' , $rcptTot , PDO::PARAM_INT);
                $stmt->bindParam(':status'  , $status  , PDO::PARAM_INT);
                $stmt->bindParam(':username', $username);
                $stmt->execute();
            } else {
                $countMax = COUNT_MAX;
                $countCur = 1;
                $countTot = 1;
                $rcptMax  = RCPT_MAX;
                $rcptCur  = $rcptCount;
                $rcptTot  = $rcptCount;
                
                $sql = 'INSERT INTO throttle (updated_ts, username, count_max, rcpt_max, rcpt_cur, rcpt_tot) 
                        VALUES (NOW(), :username, :countMax, :rcptMax, :rcptCur, :rcptTot)';
                $stmt = $this->_pdo->prepare($sql);
                $stmt->bindParam(':username', $username);
                $stmt->bindParam(':countMax', $countMax, PDO::PARAM_INT);
                $stmt->bindParam(':rcptMax' , $rcptMax , PDO::PARAM_INT);
                $stmt->bindParam(':rcptCur' , $rcptCur , PDO::PARAM_INT);
                $stmt->bindParam(':rcptTot' , $rcptTot , PDO::PARAM_INT);
                $stmt->execute();
                $status = 0;
            }
            
            // syslogging
            $syslogMsg =  sprintf('%s: user=%s (%s:%s), rcpts=%s, status=%s, command=%s, ' . 
               'count_max=%s, count_cur=%s, count_tot=%s, ' .
               'rcpt_max=%s, rcpt_cur=%s, rcpt_tot=%s', 
                SYSLOG_PREFIX,
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
            syslog(LOG_INFO, $syslogMsg);
            
            // Report message limit succeeded to administrator
            if ($status == 1) {
                // Do not report on status code 2, as the admin only wants to get
                // notified once!
                mail(ADMIN_TO, ADMIN_SUBJECT, $syslogMsg, "From: " . ADMIN_FROM);
            }
            
            // return status code
            return $status;
            
        } catch (PDOException $e) {
            die('PDO-Error: ' . $e->getMessage());
        }
    }
}

// extract main parameters
$username  = $_SERVER['SUDO_USER'];
$rcptCount = (int) @$argv[1];

// do throttling
$sendmailThrottle = new SendmailThrottle();
$status = $sendmailThrottle->throttle($username, $rcptCount);
exit($status);
