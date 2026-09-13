<?php
declare(strict_types=1);
namespace Micx\SecVcs;
final class Storage
{
    public function __construct(public readonly string $data, public readonly string $state, private string $template = '{repo}/{branch}')
    {
        foreach ([$data, $state] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new \RuntimeException('Cannot create storage');
            if (is_link($dir) || realpath($dir) !== $dir) throw new \RuntimeException('Storage roots must be canonical absolute paths');
        }
        if ($data === $state || str_starts_with($state, $data.'/') || str_starts_with($data, $state.'/')) throw new \RuntimeException('Data and private state must be disjoint');
        foreach (['locks', 'requests', 'workspaces'] as $name) if (!is_dir("$state/$name")) mkdir("$state/$name", 0700);
    }
    public static function relative(string $path, bool $empty = false): string
    {
        if (($path === '' && !$empty) || strlen($path)>512 || preg_match('/[\\\\\x00-\x1f\x7f]/', $path)) throw new Fault('INVALID_PATH', 'Invalid relative path');
        if ($path === '' && $empty) return $path;
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || str_starts_with($part, '-') || strcasecmp($part, '.git') === 0) throw new Fault('INVALID_PATH', 'Reserved path component');
        }
        return $path;
    }
    public static function branch(string $branch): string
    {
        if (strlen($branch)>200 || !preg_match('~^[A-Za-z0-9][A-Za-z0-9._/-]*$~D', $branch) || str_contains($branch, '..') || str_contains($branch, '//')) throw new Fault('INVALID_BRANCH', 'Invalid branch');
        foreach (explode('/', $branch) as $part) if ($part === '' || $part[0] === '.' || str_ends_with($part, '.') || str_ends_with($part, '.lock')) throw new Fault('INVALID_BRANCH', 'Invalid branch');
        return $branch;
    }
    public static function url(string $url): string
    {
        if (strlen($url)>512 || !preg_match('~^git@([A-Za-z0-9][A-Za-z0-9.-]*):([A-Za-z0-9_][A-Za-z0-9._/-]*)$~D', $url, $m)) throw new Fault('INVALID_REPOSITORY', 'Use git@host:owner/repository.git');
        self::relative($m[2]);
        if (str_contains($m[1], '..')) throw new Fault('INVALID_REPOSITORY', 'Invalid SSH hostname');
        return $url;
    }
    public function location(string $url, string $branch, string $id, ?string $directory, bool $temporary): string
    {
        $repo = substr(preg_replace('/[^A-Za-z0-9_-]/', '_', $url), 0, 100) . '_' . substr(hash('sha256', $url), 0, 16);
        $branchName = substr(str_replace('/', '_', $branch), 0, 100) . '_' . substr(hash('sha256', $branch), 0, 16);
        $relative = $directory ?? strtr($this->template, ['{repo}'=>$repo, '{branch}'=>$branchName, '{workspace}'=>$id]);
        if ($temporary) $relative = 'tmp/' . $id . '/' . $relative;
        self::relative($relative);
        return $this->data . '/' . $relative;
    }
    public function path(string $root, string $relative, bool $empty = false): string
    {
        self::relative($relative, $empty);
        if (!str_starts_with($root.'/', $this->data.'/')) throw new Fault('INVALID_PATH', 'Outside data root');
        $target = $relative === '' ? $root : "$root/$relative";
        $cursor = $this->data;
        foreach (explode('/', substr($target, strlen($this->data)+1)) as $part) {
            $cursor .= '/' . $part;
            clearstatcache(true, $cursor);
            if (is_link($cursor)) throw new Fault('INVALID_PATH', 'Symbolic links are not accessible');
            if (file_exists($cursor) && !is_file($cursor) && !is_dir($cursor)) throw new Fault('INVALID_PATH', 'Only regular files and directories are supported');
        }
        return $target;
    }
    public function lock(string $key, callable $fn): mixed
    {
        $handle = fopen($this->state . '/locks/' . hash('sha256', $key), 'c');
        if ($handle === false) throw new \RuntimeException('Cannot open lock');
        try {
            $until = microtime(true) + 5;
            while (!flock($handle, LOCK_EX | LOCK_NB)) {
                if (microtime(true)>$until) throw new Fault('BUSY', 'Workspace is busy');
                usleep(20000);
            }
            return $fn();
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }
    public function save(string $path, array $value): void
    {
        $temp = $path.'.'.bin2hex(random_bytes(8));
        $h = fopen($temp, 'x');
        if (!$h) throw new \RuntimeException('Cannot write journal');
        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR);
            if (fwrite($h, $json) !== strlen($json) || !fflush($h) || !fsync($h)) throw new \RuntimeException('Cannot persist journal');
        } finally { fclose($h); }
        if (!rename($temp, $path)) throw new \RuntimeException('Cannot publish journal');
    }
    public function load(string $path): array { return json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR); }
    public function metadata(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $id)) throw new Fault('NOT_FOUND', 'Unknown workspace');
        return $this->state.'/workspaces/'.$id.'.json';
    }
}
