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
            '-c', 'core.sshCommand=ssh -F /dev/null -o BatchMode=yes -o StrictHostKeyChecking=yes -o IdentitiesOnly=yes -o ConnectTimeout=10 -o UserKnownHostsFile='.escapeshellarg($this->knownHosts).' -i '.escapeshellarg($this->key),
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
        $out = ''; $errSize = 0; $exit = -1; $end = microtime(true)+45;
        try {
            do {
                $out .= stream_get_contents($pipes[1]);
                $errSize += strlen(stream_get_contents($pipes[2]));
                if (strlen($out)>$maxBytes || $errSize>1048576) throw new Fault('TOO_LARGE', 'Git output limit exceeded');
                $status = proc_get_status($p);
                if (!$status['running']) { $exit = $status['exitcode']; break; }
                if (microtime(true)>$end) throw new Fault('OUTCOME_UNKNOWN', 'Git timed out; inspect workspace before another write');
                usleep(10000);
            } while (true);
            $out .= stream_get_contents($pipes[1]);
            if (strlen($out)>$maxBytes) throw new Fault('TOO_LARGE', 'Git output limit exceeded');
        } finally {
            $status = proc_get_status($p);
            if ($status['running']) { proc_terminate($p, 9); }
            fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
        }
        if ($exit !== 0) throw new Fault('GIT_FAILED', 'Git operation failed', ['exitCode'=>$exit]);
        return $out;
    }
    public function initialize(string $branch): void
    {
        $this->run(['init', '--bare', $this->gitDir]);
        $this->run(['config', 'core.bare', 'false']);
        $this->run(['config', 'core.worktree', $this->work]);
        $this->run(['remote', 'add', 'origin', $this->origin]);
        $this->run(['fetch', '--no-tags', 'origin', '+refs/heads/'.$branch.':refs/remotes/origin/'.$branch]);
        $this->run(['checkout', '-b', $branch, 'refs/remotes/origin/'.$branch]);
    }
    public function defaultBranch(): string
    {
        $out = $this->run(['ls-remote', '--symref', $this->origin, 'HEAD']);
        if (!preg_match('~^ref: refs/heads/(.+)\tHEAD$~m', $out, $m)) throw new Fault('EMPTY_REPOSITORY', 'Remote HEAD does not name a branch');
        return Storage::branch($m[1]);
    }
    public function pull()
    {
        $branch = trim($this->run(['symbolic-ref', '--short', 'HEAD']));
        $this->run(['fetch', '--no-tags', 'origin', '+refs/heads/'.$branch.':refs/remotes/origin/'.$branch]);
        $this->run(['merge', '--ff-only', 'refs/remotes/origin/'.$branch]);
    }
    public function push() { $this->run(['push', 'origin', 'HEAD:refs/heads/'.trim($this->run(['symbolic-ref', '--short', 'HEAD']))]); }
    public function commit(string $message)
    {
        $this->run(['add', '--all', '--', '.']);
        if ($this->run(['diff', '--cached', '--name-only']) !== '') $this->run(['commit', '--no-gpg-sign', '-m', $message]);
    }
    public function getChangedFiles(): array
    {
        $base = is_file($this->gitDir.'/micx_savepoint') ? trim(file_get_contents($this->gitDir.'/micx_savepoint')) : 'HEAD';
        $parts = explode("\0", rtrim($this->run(['diff','--no-renames','--name-status','-z',$base,'--']), "\0"));
        $changes=[];
        for ($i=0; $i+1<count($parts); $i+=2) $changes[]=[$parts[$i],$parts[$i+1]];
        return $changes;
    }
    public function saveSavepoint() { file_put_contents($this->gitDir.'/micx_savepoint',$this->getRev(),LOCK_EX); }
    public function getRev(): string { return trim($this->run(['rev-parse', '--verify', 'HEAD'])); }
    public function exists() { return is_file($this->gitDir.'/HEAD'); }
    public function getLocalRepoPath(): string { return $this->work; }
}
