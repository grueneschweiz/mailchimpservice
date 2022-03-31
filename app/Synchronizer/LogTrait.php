<?php

namespace App\Synchronizer;

use Illuminate\Support\Facades\Log;

trait LogTrait
{
    private function log(string $method, string $message, string $more = ""): void
    {
        if (!in_array($method, ['write', 'log', 'debug', 'info', 'warning', 'notice', 'error', 'critical', 'emergency'])) {
            throw new \InvalidArgumentException("Method $method not supported.");
        }
        
        $more = trim($more);
        $more = $more !== '' ? " $more " : " ";
        Log::$method("config=\"{$this->configName}\"{$more}msg=\"{$message}\"");
    }
}