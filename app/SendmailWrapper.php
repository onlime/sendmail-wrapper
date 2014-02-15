<?php
require_once 'ConfigLoader.php';

/**
 * Sendmail Wrapper by Onlime Webhosting
 * https://github.com/onlime/sendmail-wrapper
 *
 * @copyright  Copyright (c) 2007-2014 Onlime Webhosting (http://www.onlime.ch)
 */
class SendmailWrapper
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
    }

    /**
     * Run sendmail wrapper
     *
     * @return int exit status code (0 = success)
     */
    public function run()
    {
        // get config variables
        $sendmailCmd   = $this->_conf->wrapper->sendmailCmd;
        $throttleCmd   = $this->_conf->wrapper->throttleCmd;
        $throttleOn    = (bool) $this->_conf->wrapper->throttleOn;
        $xHeaderPrefix = $this->_conf->wrapper->xHeaderPrefix;
        $defaultHost   = $this->_conf->wrapper->defaultHost;

        // generate an RFC-compliant Message-ID
        // RFC 2822 (http://www.faqs.org/rfcs/rfc2822.html)
        $msgId = sprintf(
            '%s.%s@%s',
            date("YmdHis"),
            base_convert(mt_rand(), 10, 36),
            $defaultHost
        );

        // setup additional headers
        $addHeaders                           = array();
        $addHeaders[$xHeaderPrefix . 'MsgID'] = sprintf('<%s>', $msgId);

        // read STDIN (complete mail message)
        $data = '';
        while (!feof(STDIN)) {
            $data .= fread(STDIN, 1024);
        }

        // normalize line breaks (get rid of Windows newlines on a Unix platform)
        $data = str_replace("\r\n", PHP_EOL, $data);

        // split out headers
        list($headers, $message) = explode(PHP_EOL . PHP_EOL, $data, 2);

        // parse headers
        $headerLines = explode(PHP_EOL, $headers);
        $headerArr   = array();
        foreach ($headerLines as $line) {
            list($headerKey, $headerValue) = explode(":", $line);
            $headerArr[strtolower(trim($headerKey))] = trim($headerValue);
        }

        // count total number of recipients
        $rcptCount   = 0;
        $rcptHeaders = array('to', 'cc', 'bcc');
        foreach ($rcptHeaders as $rcptHeader) {
            if (isset($headerArr[$rcptHeader])) {
                // parse recipient headers according to RFC2822 (http://www.faqs.org/rfcs/rfc2822.html)
                $rcptCount += count(imap_rfc822_parse_adrlist($headerArr[$rcptHeader], $defaultHost));
            }
        }

        // throttling
        if ($throttleOn) {
            $throttleCmd .= ' ' . $rcptCount;
            system($throttleCmd, $throttleStatus);
        }

        // message logging to syslog
        $logData   = array(
            'uid'     => `whoami`,
            'msgid'   => $msgId,
            'from'    => @$headerArr['from'],
            'to'      => @$headerArr['to'],
            'subject' => @$headerArr['subject'],
            'site'    => @$_SERVER["HTTP_HOST"],
            'client'  => @$_SERVER["REMOTE_ADDR"],
            'file'    => getenv('SCRIPT_FILENAME'),
            'status'  => $throttleStatus
        );
        $syslogMsg = sprintf('%s: uid=%s, msgid=%s, from=%s, to=%s, subject="%s", site=%s, client=%s, file=%s, throttleStatus=%s',
            $this->_conf->wrapper->syslogPrefix,
            $logData['uid'],
            $logData['msgid'],
            $logData['from'],
            $logData['to'],
            $logData['subject'],
            $logData['site'],
            $logData['client'],
            $logData['file'],
            $logData['status']
        );
        syslog(LOG_INFO, $syslogMsg);

        // terminate if message limit exceeded
        if ($throttleOn && $throttleStatus > 0) {
            // return exit status
            return $throttleStatus;
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
                $addHeaders[$xHeaderPrefix . 'EnvSender'] = 'Return-Path';
                // use From as -r parameter
            } elseif (isset($headerArr['from']) && filter_var($headerArr['from'], FILTER_VALIDATE_EMAIL)) {
                $sendmailCmd .= ' -r ' . $headerArr['from'];
                $addHeaders[$xHeaderPrefix . 'EnvSender'] = 'From';
            }
        }

        // append all passed arguments
        if (count($argv)) {
            $sendmailCmd .= ' ' . $allArgs;
            //$addHeaders['X-Debug-Argv'] = $allArgs;
        }

        // add additional headers
        if ($addHeaders) {
            foreach ($addHeaders as $field => $contents) {
                $headers .= PHP_EOL . $field . ": " . $contents;
            }
        }

        // reassemble the message
        $data = $headers . PHP_EOL . PHP_EOL . $message;

        // pass email to the original sendmail binary
        $h = popen($sendmailCmd, "w");
        fwrite($h, $data);
        pclose($h);

        // success
        return 0;
    }
}
