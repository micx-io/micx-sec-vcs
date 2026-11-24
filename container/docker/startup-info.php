<?php

declare(strict_types=1);

// Entrypoint invokes this once per container start, not on worker retries.
// Log only approved configuration metadata; never secrets or file contents.
$dataDir = getenv('CONF_DATA_DIR');
if (!is_string($dataDir) || !is_dir($dataDir) || is_link($dataDir)) {
    fwrite(STDERR, "CONF_DATA_DIR_NOT_FOUND: configured data directory is unavailable\n");
    exit(1);
}

$setting = strtolower(trim(getenv('CONF_VCS_MOCK_MODE') ?: 'false'));
if (!in_array($setting, ['true', 'false', '1', '0', 'yes', 'no', 'on', 'off'], true)) {
    fwrite(STDERR, "CONF_VCS_MOCK_MODE_INVALID: expected boolean value\n");
    exit(1);
}
$mockMode = in_array($setting, ['true', '1', 'yes', 'on'], true);

$encode = static fn (mixed $value): string => json_encode(
    $value,
    JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES
);
$owner = fileowner($dataDir);
$group = filegroup($dataDir);
$mode = fileperms($dataDir);
$uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
$gid = function_exists('posix_getegid') ? posix_getegid() : null;
$groups = function_exists('posix_getgroups') ? posix_getgroups() : [];
$listing = scandir($dataDir);
if ($listing === false) {
    fwrite(STDERR, "CONF_DATA_DIR_LIST_FAILED: cannot list immediate directory entries\n");
    exit(1);
}

$directories = [];
foreach ($listing as $name) {
    if ($name === '.' || $name === '..') {
        continue;
    }
    $path = $dataDir . '/' . $name;
    if (!is_link($path) && is_dir($path)) {
        $directories[] = $name;
    }
}
sort($directories, SORT_STRING);

$lines = [
    'VCS STARTUP CONFIGURATION (one time per container start)',
    'CONF_DATA_DIR=' . $encode($dataDir),
    'CONF_VCS_MOCK_MODE=' . ($mockMode ? 'true' : 'false'),
    'worker_uid=' . $encode($uid) . ' worker_gid=' . $encode($gid)
        . ' supplementary_gids=' . $encode($groups),
    'data_owner_uid=' . $encode($owner) . ' data_owner_gid=' . $encode($group)
        . ' data_mode=' . ($mode === false ? 'unavailable' : sprintf('%04o', $mode & 07777)),
    'worker_access=read:' . (is_readable($dataDir) ? 'yes' : 'no')
        . ',write:' . (is_writable($dataDir) ? 'yes' : 'no')
        . ',traverse:' . (is_executable($dataDir) ? 'yes' : 'no'),
    'immediate_subdirectories=' . $encode($directories),
    'Only direct directories are listed; files and nested entries are omitted.',
];
fwrite(STDERR, implode("\n", $lines) . "\n");

if ($mockMode) {
    $banner = str_repeat('=', 76);
    fwrite(STDERR, "\n" . $banner . "\n"
        . '!!! WARNING: VCS WORKER RUNNING IN MOCK MODE !!!' . "\n"
        . 'CONF_VCS_MOCK_MODE=true - DEVELOPMENT / TEST ONLY' . "\n"
        . 'NO GIT OR SSH COMMANDS WILL RUN. PULL, PUSH AND COMMIT ARE SIMULATED.' . "\n"
        . 'Existing checkout directories are required and are never created by mock pull.' . "\n"
        . 'For PRODUCTION: set CONF_VCS_MOCK_MODE=false in the worker environment.' . "\n"
        . $banner . "\n\n");
}
