<?php

/**
 * Sendmail Wrapper by Onlime GmbH webhosting services
 * https://github.com/onlime/sendmail-wrapper
 *
 * @copyright Copyright (c) Onlime GmbH (https://www.onlime.ch)
 */
abstract class StdinMailParser
{
    /**
     * @var string
     */
    protected $_data = '';

    /**
     * @var string
     */
    protected $_header;

    /**
     * @var array
     */
    protected $_additionalHeaders = [];

    /**
     * @var string
     */
    protected $_body;

    /**
     * Headers that can appear more then once, according to RFC5322
     * http://tools.ietf.org/html/rfc5322#section-3.6
     *
     * @var array
     */
    protected $_rfc5322MultiHeaders = [
        'trace',
        'resent-date',
        'resent-from',
        'resent-sender',
        'resent-to',
        'resent-cc',
        'resent-bcc',
        'resent-msg-id',
        'comments',
        'keywords',
        'optional-field'
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->init();
    }

    /**
     * Run sendmail wrapper
     */
    public function init()
    {
        // read STDIN (complete mail message)
        $data = '';
        while (!feof(STDIN)) {
            $data .= fread(STDIN, 1024);
        }

        // normalize line breaks (get rid of Windows newlines on a Unix platform)
        $data = str_replace("\r\n", PHP_EOL, $data);

        // split out headers
        list($this->_header, $this->_body) = explode(PHP_EOL . PHP_EOL, $data, 2);

        // store original STDIN data for later retrieval
        $this->_data = $data;
    }

    /**
     * Get STDIN data.
     *
     * @return string
     */
    public function getData()
    {
        return $this->_data;
    }

    /**
     * Set an additional header.
     *
     * @param string $name
     * @param string $value
     */
    public function setHeader($name, $value)
    {
        $this->_additionalHeaders[$name] = $value;
    }

    /**
     * Parse original mail headers into an array.
     *
     * @return array
     */
    public function getParsedHeaderArr()
    {
        $headerLines = explode(PHP_EOL, $this->_header);
        $headerArr   = [];
        foreach ($headerLines as $line) {
            list($key, $value) = @explode(":", $line);
            if (is_null($value)) {
                // avoid 'PHP Notice:  Undefined offset: 1' on header line without colon
                syslog(LOG_WARNING, sprintf('%s: Could not parse mail header line: %s', __METHOD__, $line));
            }
            $key   = strtolower(trim($key));
            $value = trim($value);
            if (isset($headerArr[$key]) && !in_array($key, $this->_rfc5322MultiHeaders)) {
                // workaround for duplicate headers that should not appear
                // more than once: simply merge them, comma separated.
                // This prevents spammers to tamper with our recipient counting.
                $headerArr[$key] .= ', ' . $value;
            } else {
                $headerArr[$key] = $value;
            }
        }

        return $headerArr;
    }

    /**
     * Re-assemble the email message from its header and body parts.
     *
     * @param array $extraHeaders add extra headers only for this method call
     * @return string
     */
    public function buildMessage(array $extraHeaders = null)
    {
        $header = $this->_header;

        // add all additional headers after the original ones
        if ($this->_additionalHeaders) {
            foreach ($this->_additionalHeaders as $name => $value) {
                $header .= PHP_EOL . $name . ": " . $value;
            }
        }

        // these are "on-the-fly" headers that are only used temporarily
        if ($extraHeaders) {
            foreach ($extraHeaders as $name => $value) {
                $header .= PHP_EOL . $name . ": " . $value;
            }
        }

        // reassemble the message
        return $header . PHP_EOL . PHP_EOL . $this->_body;
    }
}
