<?php

namespace silverorange\PackageRelease;

use Psr\Log;

/**
 * @package   PackageRelease
 * @author    Michael Gauthier <mike@silverorange.com>
 * @copyright 2017 silverorange
 * @license   http://www.opensource.org/licenses/mit-license.html MIT License
 */
class Prompt
{
    /**
     * @var Log\LoggerInterface $logger
     */
    protected $logger = null;

    public function __construct(Log\LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Asks a yes or no question, waits for a response and returns a boolean
     *
     * @param string $prompt optional. The prompt text to use.
     *
     * @return boolean true if the user entered yes, otherwise false.
     */
    public function ask($line1 = 'Yes or no? ', $line2 = '')
    {
        $answered = false;

        $prompt = ($line2 == '') ? $line1 : $line2;
        $this->logger->notice('');

        while (!$answered) {
            if ($line2 != '') {
                $this->logger->notice($line1);
            }
            $response = readline($prompt);
            if (preg_match('/^y|yes$/i', $response) === 1) {
                $response = true;
                $answered = true;
            } elseif (preg_match('/^n|no$/i', $response) === 1) {
                $response = false;
                $answered = true;
            }
            $this->logger->notice('');
        }

        return $response;
    }
}