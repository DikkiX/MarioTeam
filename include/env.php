<?php

function getProjectEnvValue($key)
{
    static $envValues = null;

    if ($envValues === null) {
        $envValues = [];
        $envPath = dirname(__DIR__) . '/.env';

        if (is_file($envPath)) {
            $parsedEnv = parse_ini_file($envPath, false, INI_SCANNER_RAW);

            if (is_array($parsedEnv)) {
                $envValues = $parsedEnv;
            }
        }
    }

    if (array_key_exists($key, $envValues)) {
        return trim((string) $envValues[$key], "\"'");
    }

    return null;
}
