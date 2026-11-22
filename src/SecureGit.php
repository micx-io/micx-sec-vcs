<?php
declare(strict_types=1);
namespace Micx\SecVcs;
use Phore\VCS\Git\GitRepository;
/** phore/vcs adapter: replaces shell execution and insecure SSH defaults. */
final class SecureGit extends GitRepository
{
    public function __construct(string $url, private string $work, private string $gitDir, private string $key, private string $knownHosts)
    {
        parent::__construct($url, $work, 'MICX', 'micx@localhost');
    }
    public function run(array $args, bool $remote = false, int $maxBytes = 3145728): string
    {
        $base = ['git', '--git-dir='.$this->gitDir, '--work-tree='.$this->work,
            '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '-c', 'core.attributesFile=/dev/null',
            '-c', 'core.sshCommand=ssh -F /dev/null -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o IdentitiesOnly=yes -o ConnectTimeout=10 -o UserKnownHostsFile='.escapeshellarg($this->knownHosts).' -i '.escapeshellarg($this->key),
            '-c', 'protocol.allow=never', '-c', 'protocol.ssh.allow=always', '-c', 'protocol.file.allow=never',
            '-c', 'submodule.recurse=false', '-c', 'commit.gpgSign=false', '-c', 'tag.gpgSign=false',
            '-c', 'user.name=MICX', '-c', 'user.email=micx@localhost'];
        if (($args[0] ?? '') === 'init') { array_splice($base, 1, 2); }
        $env = ['PATH'=>'/usr/local/bin:/usr/bin:/bin', 'HOME'=>dirname($this->key), 'LANG'=>'C.UTF-8',
            'GIT_CONFIG_NOSYSTEM'=>'1', 'GIT_CONFIG_GLOBAL'=>'/dev/null', 'GIT_TERMINAL_PROMPT'=>'0',
            'GIT_ATTR_NOSYSTEM'=>'1', 'GIT_NO_REPLACE_OBJECTS'=>'1'];
        $p = proc_open(array_merge($base, $args), [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $this->work, $env);
        if (!is_resource($p)) throw new Fault('GIT_FAILED', 'Cannot start Git');
        fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $out = ''; $err = ''; $errSize = 0; $exit = -1; $end = microtime(true)+45;
        try {
            do {
                $out .= stream_get_contents($pipes[1]);
                $chunk = stream_get_contents($pipes[2]);
                $errSize += strlen($chunk);
                $err .= substr($chunk, 0, max(0, 65536-strlen($err)));
                if (strlen($out)>$maxBytes || $errSize>1048576) throw new Fault('TOO_LARGE', 'Git output limit exceeded');
                $status = proc_get_status($p);
                if (!$status['running']) { $exit = $status['exitcode']; break; }
                if (microtime(true)>$end) throw new Fault('TIMEOUT', 'Git timed out; inspect workspace and remote before another write');
                usleep(10000);
            } while (true);
            $out .= stream_get_contents($pipes[1]);
            $err .= substr(stream_get_contents($pipes[2]), 0, max(0, 65536-strlen($err)));
            if (strlen($out)>$maxBytes) throw new Fault('TOO_LARGE', 'Git output limit exceeded');
        } finally {
            $status = proc_get_status($p);
            if ($status['running']) { proc_terminate($p, 9); }
            fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
        }
        if ($exit !== 0) {
            // SECURITY: classify diagnostics locally; never return raw stderr, keys or command lines.
            $code = 'GIT_FAILED'; $message = 'Git operation failed; inspect server configuration and workspace status';
            $patterns = [
                'SSH_KEY_INVALID'=>['~invalid format|error in libcrypto|bad permissions|unprotected private key|incorrect passphrase~i', 'SSH private key is invalid, encrypted or has unsafe permissions; check the service secret'],
                'SSH_HOST_KEY_FAILED'=>['~host key verification failed|remote host identification has changed~i', 'SSH host key verification failed; verify the server against known_hosts'],
                'SSH_AUTH_FAILED'=>['~permission denied \(publickey|no supported authentication methods~i', 'SSH authentication failed; check the service key and repository permissions'],
                'REPOSITORY_UNAVAILABLE'=>['~repository .*not found|repository not found|does not appear to be a git repository|access denied~i', 'Repository does not exist or is not accessible to the service key'],
                'REMOTE_UNREACHABLE'=>['~could not resolve hostname|connection timed out|connection refused|no route to host|network is unreachable|connection reset|connection closed~i', 'Git remote cannot be reached; check DNS, network and SSH service'],
                'BRANCH_NOT_FOUND'=>['~couldn.t find remote ref|remote branch .* not found~i', 'Requested branch does not exist on the remote'],
                'PUSH_REJECTED'=>['~\[rejected\]|\[remote rejected\]|pre-receive hook declined~i', 'Remote rejected the push; check newer commits, branch protection and server hooks'],
                'IO_ERROR'=>['~no space left on device|read-only file system|disk quota exceeded|permission denied~i', 'Git cannot access storage; check free space, quota and filesystem permissions'],
            ];
            foreach ($patterns as $kind=>[$pattern,$description]) {
                if (preg_match($pattern,$err)) { $code=$kind; $message=$description; break; }
            }
            throw new Fault($code, $message, ['exitCode'=>$exit]);
        }
        return $out;
    }
    public function initialize(string $branch, bool $empty = false): void
    {
        $this->run(['init', '--bare', $this->gitDir]);
        $this->run(['config', 'core.bare', 'false']);
        $this->run(['config', 'core.worktree', $this->work]);
        $this->run(['remote', 'add', 'origin', $this->origin]);
        if ($empty) { $this->run(['symbolic-ref', 'HEAD', 'refs/heads/'.$branch]); return; }
        $this->run(['fetch', '--no-tags', 'origin', '+refs/heads/'.$branch.':refs/remotes/origin/'.$branch]);
        $this->run(['checkout', '-b', $branch, 'refs/remotes/origin/'.$branch]);
    }
    public function pull()
    {
        $branch = trim($this->run(['symbolic-ref', '--short', 'HEAD']));
        $this->run(['fetch', '--no-tags', 'origin', '+refs/heads/'.$branch.':refs/remotes/origin/'.$branch]);
        $this->mergeOurs('refs/remotes/origin/'.$branch);
    }
    /** Merge remote changes, preferring this workspace at conflicting paths. */
    public function mergeOurs(string $source): void
    {
        try {
            try { $this->run(['merge', '--no-edit', '--no-gpg-sign', '-X', 'ours', $source]); }
            catch (Fault $e) {
                if ($e->kind !== 'GIT_FAILED') throw $e;
                $unmerged = $this->run(['ls-files', '--unmerged', '-z']);
                if ($unmerged === '') throw $e;
                $paths = [];
                foreach (explode("\0", rtrim($unmerged, "\0")) as $entry) {
                    [$header, $path] = explode("\t", $entry, 2);
                    Storage::relative($path);
                    $paths[$path] = ($paths[$path] ?? false) || str_ends_with($header, ' 2');
                }
                // -X ours handles content/binary conflicts. For delete/modify conflicts,
                // stage 2 is our side; absence there means our deletion wins.
                foreach ($paths as $path => $oursExists) {
                    if ($oursExists) {
                        $this->run(['checkout', '--ours', '--', $path]);
                        $this->run(['add', '--', $path]);
                    } else $this->run(['rm', '-f', '--', $path]);
                }
                $this->run(['commit', '--no-edit', '--no-gpg-sign']);
            }
        } catch (Fault $e) {
            try { $this->run(['merge', '--abort']); } catch (Fault) {}
            throw new Fault('MERGE_FAILED', 'Merge could not be completed; inspect workspace status', ['cause'=>['code'=>$e->kind,'message'=>$e->getMessage()]]);
        }
    }
    public function push() { $this->run(['push', 'origin', 'HEAD:refs/heads/'.trim($this->run(['symbolic-ref', '--short', 'HEAD']))]); }
    public function commit(string $message)
    {
        $this->run(['add', '--all', '--', '.']);
        if ($this->run(['diff', '--cached', '--name-only']) !== '') $this->run(['commit', '--no-gpg-sign', '-m', $message]);
    }
    /**
     * Status für die öffentliche Repository-API: uncommittete Dateien oder
     * lokale Commits, die noch nicht zum Remote-Branch gepusht wurden.
     *
     * @return bool true bei lokalen Änderungen oder unveröffentlichten Commits
     * @see self::commit()
     */
    public function hasUnpushedChanges(): bool
    {
        if ($this->run(['status', '--porcelain=v1', '-z']) !== '') {
            return true;
        }

        // Unborn Branches enthalten noch keine lokalen Commits.
        if ($this->getRev() === '') {
            return false;
        }

        $branch = trim($this->run(['symbolic-ref', '--short', 'HEAD']));
        $remoteRef = 'refs/remotes/origin/' . $branch;
        $remoteExists = $this->run(['for-each-ref', '--format=%(objectname)', $remoteRef]) !== '';
        $args = ['rev-list', '--count', 'HEAD'];
        if ($remoteExists) {
            $args[] = '--not';
            $args[] = $remoteRef;
        }

        return (int) trim($this->run($args)) > 0;
    }

    /**
     * Liest höchstens 50 Commits als SDK-kompatible, strukturierte Metadaten.
     *
     * @return list<array{id:string,message:string,timestamp:string,branch:string}>
     * @see self::getBranches()
     */
    public function getHistory(string $branch, ?string $ref = null): array
    {
        $ref ??= 'HEAD';
        if ($this->getRev() === '') {
            return [];
        }

        $raw = $this->run(['log', '-z', '-n', '50', '--format=%H%x00%s%x00%cI', $ref]);
        $fields = explode("\0", rtrim($raw, "\0"));
        $entries = [];
        for ($i = 0; $i + 2 < count($fields); $i += 3) {
            $entries[] = [
                'id' => $fields[$i],
                'message' => $fields[$i + 1],
                'timestamp' => $fields[$i + 2],
                'branch' => $branch,
            ];
        }

        return $entries;
    }

    /**
     * Gibt lokale Branches mit ihrem letzten Commit zurück.
     *
     * @return list<array{name:string,latestCommit:array|null}>
     * @see self::getHistory()
     */
    public function getBranches(): array
    {
        $names = trim($this->run(['for-each-ref', '--format=%(refname:short)', 'refs/heads']));
        if ($names === '') {
            return [];
        }

        $branches = [];
        foreach (explode("\n", $names) as $name) {
            $branch = Storage::branch($name);
            $history = $this->getHistory($branch, 'refs/heads/' . $branch);
            $branches[] = ['name' => $branch, 'latestCommit' => $history[0] ?? null];
        }

        return $branches;
    }

    /**
     * Required by the phore/vcs repository interface.
     * Returns the current Git working-tree changes without a savepoint file.
     *
     * @return list<array{0:string,1:string}>
     * @see \Phore\VCS\VcsRepository::getChangedFiles()
     */
    public function getChangedFiles(): array
    {
        $output = $this->run(['status', '--porcelain=v1', '-z', '--no-renames']);
        if ($output === '') {
            return [];
        }

        $changes = [];
        foreach (explode("\0", rtrim($output, "\0")) as $entry) {
            $changes[] = [trim(substr($entry, 0, 2)), substr($entry, 3)];
        }

        return $changes;
    }

    public function getRev(): string
    {
        // A newly created repository has an unborn branch and no commit yet.
        $branch = trim($this->run(['symbolic-ref', '--short', 'HEAD']));
        return trim($this->run(['for-each-ref', '--format=%(objectname)', 'refs/heads/'.$branch]));
    }
    public function getLocalRepoPath(): string { return $this->work; }
}
