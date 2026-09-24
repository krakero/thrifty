<?php

namespace App\Agent\Exceptions;

/**
 * No OpenAI API key has been saved in Settings.
 */
class MissingApiKey extends AnalysisFailed
{
    public function __construct()
    {
        parent::__construct('Add your OpenAI API key in Settings to start scanning.');
    }
}
