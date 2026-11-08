<?php

namespace b10k\componentmap\services;

/**
 * A report could not be made for a reason the caller should be told about —
 * not a bug: no files named, not a git checkout, an unknown branch.
 */
class ReportException extends \RuntimeException
{
}
