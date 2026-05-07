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

function getChatModelMode(): int
{
    $mode = getProjectEnvValue('CHAT_MODEL_MODE');
    if (is_string($mode) && preg_match('/^\d+$/', $mode) === 1) {
        return (int) $mode;
    }
    if (is_int($mode)) {
        return $mode;
    }
    return 2;
}

function getChatModelNameFromMode($mode): string
{
    $m = $mode;
    if (is_string($m) && preg_match('/^\d+$/', $m) === 1) {
        $m = (int) $m;
    }
    if ($m === 1) {
        return 'gpt-5.2';
    }
    if ($m === 2) {
        return 'gpt-5-mini';
    }
    if ($m === 3) {
        return 'gpt-4.1-mini';
    }
    return 'gpt-4.1-mini';
}

function getChatModelName(): string
{
    return getChatModelNameFromMode(getChatModelMode());
}
