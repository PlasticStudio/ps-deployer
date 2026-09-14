<?php

namespace Deployer;

set('sitehost_client_id', '969806');
set('php_config', []);

/**
 * Synchronise optional per-host PHP settings without rewriting php.ini when
 * the configured values already match.
 *
 * Example host config:
 *   ->set('php_config', ['post_max_size' => '100M'])
 */
desc('Synchronise optional PHP configuration');
task('sitehost:sync-config', function () {
    $phpConfig = get('php_config');

    if (empty($phpConfig)) {
        writeln('<comment>No php_config set - skipping PHP config sync</comment>');
        return;
    }

    if (!is_array($phpConfig)) {
        throw new \InvalidArgumentException('php_config must be an associative array.');
    }

    foreach ($phpConfig as $key => $value) {
        if (!is_string($key) || !preg_match('/^[A-Za-z0-9_.-]+$/', $key)) {
            throw new \InvalidArgumentException('php_config contains an invalid directive name.');
        }

        if (!is_scalar($value)) {
            throw new \InvalidArgumentException("php_config value for {$key} must be a scalar.");
        }

        $phpConfig[$key] = (string) $value;
    }

    $iniPath = '/container/config/php/php.ini';
    $encodedConfig = base64_encode(json_encode($phpConfig, JSON_THROW_ON_ERROR));
    $syncScript = <<<'PHP'
$path = $argv[1];
$settings = json_decode(base64_decode($argv[2]), true);
$contents = is_file($path) ? file_get_contents($path) : '';

if ($contents === false) {
    fwrite(STDERR, "Unable to read {$path}\n");
    exit(1);
}

$updated = $contents;
$newline = strpos($contents, "\r\n") !== false ? "\r\n" : "\n";

foreach ($settings as $key => $value) {
    $pattern = '/^[ \t]*' . preg_quote($key, '/') . '[ \t]*=[ \t]*(.*?)[ \t]*$/m';
    preg_match_all($pattern, $updated, $matches);
    $currentValue = empty($matches[1]) ? null : trim(end($matches[1]));

    if ($currentValue === $value) {
        continue;
    }

    $line = $key . '=' . $value;
    if ($currentValue !== null) {
        $replacement = $line . ($newline === "\r\n" ? "\r" : '');
        $updated = preg_replace_callback($pattern, function () use ($replacement) {
            return $replacement;
        }, $updated);
    } else {
        if ($updated !== '' && substr($updated, -1) !== "\n") {
            $updated .= $newline;
        }
        $updated .= $line . $newline;
    }
}

if ($updated === $contents) {
    echo 'unchanged';
    exit(0);
}

$directory = dirname($path);
if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
    fwrite(STDERR, "Unable to create {$directory}\n");
    exit(1);
}

if (file_put_contents($path, $updated, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write {$path}\n");
    exit(1);
}

echo 'changed';
PHP;

    $result = run(
        'php -r ' . escapeshellarg($syncScript)
        . ' ' . escapeshellarg($iniPath)
        . ' ' . escapeshellarg($encodedConfig)
    );

    if (trim($result) === 'changed') {
        writeln('<info>PHP config updated in ' . $iniPath . '</info>');
    } else {
        writeln('<info>PHP config already matches - skipping update</info>');
    }
});
