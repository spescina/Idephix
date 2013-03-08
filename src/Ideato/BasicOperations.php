<?php

namespace Ideato;

/**
 * Provides interaction functionalities with a remote peer
 * and local command execution.
 * Constuctor accepts a generic object representing
 * a remote server on which operations are executed.
 */
class BasicOperations
{
    private $target;

    public function __construct($target = null)
    {
        $this->target = $target;
    }

    public function remote($cmd, $dryRun = false)
    {
        if (!$dryRun) {

            return $this->target->exec($cmd);
        }
        echo("Dry run: remote " . $cmd);
    }

    public function local($cmd, $dryRun = false)
    {
        if (!$dryRun) {
            exec($cmd . ' 2>&1', $output, $return);
            if ($return != 0) {
                throw new \Exception("local: returned non-0 value: ".$return."\n".implode("\n", $output));
            }

            return $output;
        }
        echo("Dry run: local " . $cmd);
    }

    public function sudo($cmd, $dryRun = false)
    {
        if (!$dryRun) {

            return $this->target->sudo($cmd);
        }
        echo("Dry run: remote sudo ".$cmd);
    }
}