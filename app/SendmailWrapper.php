<?php
require_once 'StdinMailParser.php';
require_once 'ConfigLoader.php';
require_once 'SendmailThrottle.php';

/**
 * Sendmail Wrapper by Onlime GmbH webhosting services
 * https://github.com/onlime/sendmail-wrapper
 *
 * @copyright Copyright (c) Onlime GmbH (https://www.onlime.ch)
 */
class SendmailWrapper extends StdinMailParser
{
    /**
     * @var StdClass
     */
    protected $_conf;

    /**
     * Constructor
     */
    public function __construct()
    {
        // load configuration
        $configLoader = new ConfigLoader();
        $this->_conf  = $configLoader->getConfig();

        parent::__construct();
    }

    /**
     * Run sendmail wrapper
     *
     * @return int exit status code (0 = success)
     */
    public function run()
    {
        $status = SendmailThrottle::STATUS_OK;

        // get config variables
        $sendmailCmd      = $this->_conf->wrapper->sendmailCmd;
        $throttleCmd      = $this->_conf->wrapper->throttleCmd;
        $throttleOn       = (bool) $this->_conf->wrapper->throttleOn;
        $xHeaderPrefix    = $this->_conf->wrapper->xHeaderPrefix;
        $defaultHost      = $this->_conf->wrapper->defaultHost;
        $ignoreExceptions = (bool) $this->_conf->throttle->ignoreExceptions;

        // generate an RFC-compliant Message-ID
        // RFC 2822 (http://www.faqs.org/rfcs/rfc2822.html)
        $msgId = sprintf(
            '%s.%s@%s',
            date("YmdHis"),
            base_convert(mt_rand(), 10, 36),
            $defaultHost
        );

        // set additional header
        $this->setHeader($xHeaderPrefix . 'MsgID', sprintf('<%s>', $msgId));

        // parse original headers
        $headerArr = $this->getParsedHeaderArr();

        // count total number of recipients
        $rcptCount   = 0;
        $rcptHeaders = ['to', 'cc', 'bcc'];
        foreach ($rcptHeaders as $rcptHeader) {
            if (isset($headerArr[$rcptHeader])) {
                // parse recipient headers according to RFC2822 (http://www.faqs.org/rfcs/rfc2822.html)
                $rcptCount += count(imap_rfc822_parse_adrlist($headerArr[$rcptHeader], $defaultHost));
            }
        }

        $messageInfo   = [
            'uid'     => trim(shell_exec('whoami')),
            'msgid'   => $msgId,
            'from'    => @$headerArr['from'],
            'to'      => @$headerArr['to'],
            'cc'      => @$headerArr['cc'],
            'bcc'     => @$headerArr['bcc'],
            'subject' => @$headerArr['subject'],
            'site'    => @$_SERVER["HTTP_HOST"],
            'client'  => @$_SERVER["REMOTE_ADDR"],
            'script'  => getenv('SCRIPT_FILENAME')
        ];

        // throttling
        if ($throttleOn) {
            // add recipient count to throttle command
            $throttleCmd .= ' ' . $rcptCount;

            // simple command execution
            //system($throttleCmd, $status);

            // redirect STDIN data to throttle command
            // We're going to use the whole email message including some
            // extra headers.
            $throttleMsg = $this->buildMessage([
                'X-Meta-MsgID'  => $messageInfo['msgid'],
                'X-Meta-Site'   => $messageInfo['site'],
                'X-Meta-Client' => $messageInfo['client'],
                'X-Meta-Script' => $messageInfo['script'],
            ]);
            $descriptorSpec = [
                0 => ['pipe', 'r']
            ];
            $proc = proc_open($throttleCmd, $descriptorSpec, $pipes);
            if (is_resource($proc)) {
                fwrite($pipes[0], $throttleMsg);
                fclose($pipes[0]);
                // It is important that you close any pipes before calling
                // proc_close in order to avoid a deadlock
                $status = proc_close($proc);
            }
        }

        // message logging to syslog
        $syslogMsg = sprintf(
            '%s: uid=%s, msgid=%s, from=%s, to="%s", cc="%s", bcc="%s", subject="%s", site=%s, client=%s, script=%s, throttleStatus=%s',
            $this->_conf->wrapper->syslogPrefix,
            $messageInfo['uid'],
            $messageInfo['msgid'],
            $messageInfo['from'],
            $this->stripStrLength($messageInfo['to']),
            $this->stripStrLength($messageInfo['cc']),
            $this->stripStrLength($messageInfo['bcc']),
            $messageInfo['subject'],
            $messageInfo['site'],
            $messageInfo['client'],
            $messageInfo['script'],
            $status
        );
        // Don't write to syslog for blocked useraccounts - we still have all meta information in messages
        // table but don't want to fill up syslog.
        if ($status != SendmailThrottle::STATUS_BLOCKED) {
            syslog(LOG_INFO, $syslogMsg);
        }

        // terminate if message limit exceeded
        if ($throttleOn && $status > SendmailThrottle::STATUS_OK && !($ignoreExceptions && $status == SendmailThrottle::STATUS_EXCEPTION)) {
            // return exit status
            return $status;
        }

        // get arguments
        $argv = $_SERVER['argv'];
        array_shift($argv);
        $allArgs = implode(' ', $argv);

        // Force adding envelope sender address (sendmail -r/-f parameters)
        // For security reasons, we check if the Return-Path or From email addresses
        // are valid, prior to passing them to -r.
        if (preg_match('/^\-r/', $allArgs) || false !== strstr($allArgs, ' -r')) {
            // -r parameter was found, no changes
        } elseif (preg_match('/^\-f/', $allArgs) || false !== strstr($allArgs, ' -f')) {
            // -f parameter was found, no changes
        } else {
            // use Return-Path as -r parameter
            if (isset($headerArr['return-path']) && filter_var($headerArr['return-path'], FILTER_VALIDATE_EMAIL)) {
                $sendmailCmd .= ' -r ' . $headerArr['return-path'];
                $this->setHeader($xHeaderPrefix . 'EnvSender', 'Return-Path');
                // use From as -r parameter
            } elseif (isset($headerArr['from']) && filter_var($headerArr['from'], FILTER_VALIDATE_EMAIL)) {
                $sendmailCmd .= ' -r ' . $headerArr['from'];
                $this->setHeader($xHeaderPrefix . 'EnvSender', 'From');
            }
        }

        // append all passed arguments
        if (count($argv)) {
            $sendmailCmd .= ' ' . $allArgs;
            //$addHeaders['X-Debug-Argv'] = $allArgs;
        }

        // reassemble the message
        $data = $this->buildMessage();

        // pass email to the original sendmail binary
        $h = popen($sendmailCmd, "w");
        fwrite($h, $data);
        pclose($h);

        // success
        return $status;
    }

    /**
     * Limit a string to a specific length.
     *
     * @param string $value
     * @param int $limit
     * @param string $suffix
     * @return string
     */
    public function stripStrLength($value, $limit = null, $suffix = null)
    {
        if ($limit === null) {
            // load limit from configuration
            $limit  = $this->_conf->syslog->stringLengthLimit;
        }
        if ($suffix === null) {
            // load suffix from configuration
            $suffix = $this->_conf->syslog->stringCutSuffix;
        }

        $strLen = mb_strlen($value);

        if ($strLen > $limit) {
            $suffixLen = mb_strlen($suffix);
            $value = mb_substr($value, 0, $limit - $suffixLen) . $suffix;
        }

        return $value;
    }
}
