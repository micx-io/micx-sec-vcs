<?php
declare(strict_types=1);
namespace Micx\SecVcs;
final class Service
{
    public function __construct(private Storage $storage, private string $key, private string $knownHosts) {}
    private function git(array $meta): SecureGit
    {
        return new SecureGit($meta['url'], $meta['path'], $this->storage->state.'/workspaces/'.$meta['workspace'].'.git', $this->key, $this->knownHosts);
    }
    private function result(array $meta, SecureGit $git): array
    {
        return $meta + ['revision'=>$git->getRev()];
    }
    public function execute(string $method, array $params): array
    {
        if ($method === 'checkout' || $method === 'create') return $this->checkout($params, $method === 'create');
        $id = $params['workspace'] ?? '';
        if (!is_string($id)) throw new Fault('INVALID_REQUEST', 'workspace must be a string');
        $file = $this->storage->metadata($id);
        return $this->storage->lock('workspace:'.$id, function () use ($file, $params, $method) {
            if (!is_file($file)) throw new Fault('NOT_FOUND', 'Unknown workspace');
            $meta = $this->storage->load($file);
            if ($meta['released'] ?? false) throw new Fault('NOT_FOUND', 'Workspace was released');
            $this->storage->path($meta['path'], '', true);
            $git = $this->git($meta);
            if (in_array($method, ['pull','push','commit','branch','merge','update'], true)) {
                if (array_key_exists('expectedRevision', $params) && $params['expectedRevision'] !== $git->getRev()) throw new Fault('CONFLICT', 'Revision changed; inspect and retry', ['revision'=>$git->getRev()]);
                if (array_key_exists('expectedGeneration', $params) && $params['expectedGeneration'] !== $meta['generation']) throw new Fault('CONFLICT', 'Workspace changed', ['generation'=>$meta['generation']]);
                // Persist before Git/filesystem writes, including partially failed operations.
                ++$meta['generation']; $this->storage->save($file, $meta);
                $this->scan($meta['path']);
            }
            switch ($method) {
                case 'status': return $this->result($meta, $git) + ['status'=>$git->run(['status', '--porcelain=v1', '-z'])];
                case 'pull':
                    $this->clean($git); $git->pull(); break;
                case 'commit':
                    $message = $params['message'] ?? 'Update workspace';
                    if (!is_string($message) || trim($message)==='' || strlen($message)>8192 || str_contains($message, "\0")) throw new Fault('INVALID_REQUEST', 'Invalid commit message');
                    $git->commit($message);
                    if (($params['push'] ?? false) === true) {
                        try { $git->push(); }
                        catch (Fault $e) { throw new Fault('PUSH_FAILED', 'Local commit exists; inspect remote before retrying push', ['revision'=>$git->getRev(),'cause'=>['code'=>$e->kind,'message'=>$e->getMessage()]]); }
                    }
                    break;
                case 'push':
                    if (isset($params['branch'])) {
                        $branch = Storage::branch($params['branch']);
                        $git->run(['push','origin','refs/heads/'.$branch.':refs/heads/'.$branch]);
                    } else $git->push();
                    break;
                case 'branch':
                    $branch = Storage::branch($params['branch'] ?? '');
                    // Create a ref without changing this workspace's branch or path.
                    $git->run(['branch', $branch]); break;
                case 'merge':
                    $this->clean($git);
                    $source = Storage::branch($params['source'] ?? '');
                    $git->run(['fetch', '--no-tags', 'origin', '+refs/heads/'.$source.':refs/remotes/origin/'.$source]);
                    $git->mergeOurs('refs/remotes/origin/'.$source);
                    break;
                case 'list':
                    $path = $this->storage->path($meta['path'], $params['path'] ?? '', true);
                    if (!is_dir($path)) throw new Fault('NOT_FOUND', 'Directory not found');
                    $entries = [];
                    foreach (new \FilesystemIterator($path) as $entry) {
                        if ($entry->getFilename() === '.git') continue;
                        $entries[] = ['name'=>$entry->getFilename(), 'type'=>$entry->isLink() ? 'symlink' : ($entry->isDir() ? 'directory' : 'file')];
                        if (count($entries)>1000) throw new Fault('TOO_LARGE', 'Listing exceeds 1000 entries');
                    }
                    usort($entries, fn($a,$b)=>strcmp($a['name'],$b['name']));
                    return $this->result($meta, $git) + ['entries'=>$entries];
                case 'read': return $this->result($meta, $git) + ['files'=>$this->read($meta, $params['paths'] ?? [])];
                case 'update': $this->update($meta, $params['files'] ?? []); break;
                case 'snapshot':
                    $objects=[]; $bytes=0;
                    foreach (explode("\0",rtrim($git->run(['ls-tree','-r','-z','HEAD']),"\0")) as $line) {
                        if ($line==='') continue;
                        [$header,$name]=explode("\t",$line,2);
                        [$mode,$type,$oid]=explode(' ',$header);
                        if ($type!=='blob' || $mode==='120000') continue;
                        $content=$git->run(['cat-file','blob',$oid],false,2097152);
                        $bytes+=strlen($content);
                        if ($bytes>2097152 || count($objects)>=100) throw new Fault('TOO_LARGE','Snapshot exceeds 100 files or 2 MiB');
                        $objects[]=['path'=>$name,'mode'=>$mode,'encoding'=>'base64','content'=>base64_encode($content)];
                    }
                    return $this->result($meta,$git)+['files'=>$objects];
                case 'archive':
                    return $this->result($meta, $git) + ['encoding'=>'base64', 'mediaType'=>'application/zip',
                        'content'=>base64_encode($git->run(['archive', '--format=zip', 'HEAD'], false, 2097152))];
                case 'release':
                    if (!$meta['temporary']) throw new Fault('INVALID_REQUEST', 'Only temporary workspaces can be released');
                    // Tombstone first: a crash must never expose partially removed files.
                    $meta['released'] = true; $this->storage->save($file, $meta);
                    $this->remove($meta['path']);
                    $this->remove($this->storage->state.'/workspaces/'.$meta['workspace'].'.git');
                    return ['workspace'=>$meta['workspace'],'path'=>$meta['path'],'released'=>true];
                default: throw new Fault('UNKNOWN_METHOD', 'Unknown RPC method');
            }
            return $this->result($meta, $git);
        });
    }
    private function checkout(array $params, bool $create = false): array
    {
        $url = Storage::url($params['url'] ?? '');
        $branch = $params['branch'] ?? null;
        if ($branch !== null) Storage::branch($branch);
        $directory = $params['directory'] ?? null;
        if ($directory !== null) Storage::relative($directory);
        $temporary = $params['temporary'] ?? false;
        if (!is_bool($temporary)) throw new Fault('INVALID_REQUEST', 'temporary must be boolean');
        return $this->storage->lock('registry', function () use ($url, $branch, $directory, $temporary, $create) {
            // Remote HEAD lookup has no checkout and never exposes credentials.
            $probe = new SecureGit($url, $this->storage->data, $this->storage->state.'/probe.git', $this->key, $this->knownHosts);
            $default = $create ? ($branch ?? 'main') : $probe->defaultBranch();
            $branch ??= $default;
            $id = hash('sha256', json_encode([$url,$branch,$directory,$temporary ? bin2hex(random_bytes(16)) : null], JSON_THROW_ON_ERROR));
            $file = $this->storage->metadata($id);
            return $this->storage->lock('workspace:'.$id, function () use ($id,$file,$url,$branch,$directory,$temporary,$default,$create) {
                if (is_file($file)) {
                    $meta = $this->storage->load($file);
                    $this->storage->path($meta['path'], '', true);
                    $meta['defaultBranch'] = $default; $this->storage->save($file, $meta);
                    return $this->result($meta, $this->git($meta));
                }
                $path = $this->storage->location($url,$branch,$id,$directory,$temporary);
                $this->storage->path($path, '', true);
                // Never adopt or overwrite a directory supplied by another application.
                foreach (glob($this->storage->state.'/workspaces/*.json') as $existing) {
                    $other=$this->storage->load($existing)['path'];
                    if ($path===$other || str_starts_with($path,$other.'/') || str_starts_with($other,$path.'/')) throw new Fault('DIRECTORY_EXISTS','Destination overlaps an existing workspace');
                }
                if (file_exists($path)) throw new Fault('DIRECTORY_EXISTS', 'Checkout destination already exists');
                if (!mkdir($path, 0770, true)) throw new Fault('IO_ERROR', 'Cannot create working directory');
                $meta = ['workspace'=>$id,'url'=>$url,'branch'=>$branch,'defaultBranch'=>$default,'path'=>$path,'temporary'=>$temporary,'generation'=>0];
                $git = $this->git($meta);
                try { $git->initialize($branch, $create); }
                catch (\Throwable $e) { $this->remove($path); $this->remove($this->storage->state.'/workspaces/'.$id.'.git'); throw $e; }
                $this->storage->save($file, $meta);
                return $this->result($meta, $git);
            });
        });
    }
    private function clean(SecureGit $git): void
    {
        if ($git->run(['status','--porcelain=v1']) !== '') throw new Fault('DIRTY_WORKTREE', 'Commit changes before pull or merge');
    }
    private function scan(string $root): void
    {
        $count=0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            $relative = substr($entry->getPathname(), strlen($root)+1);
            $this->storage->path($root, $relative);
            if (++$count>100000) throw new Fault('TOO_LARGE', 'Workspace exceeds entry limit');
        }
    }
    private function read(array $meta, array $paths): array
    {
        if (count($paths)>100) throw new Fault('TOO_LARGE', 'At most 100 files');
        $result=[]; $total=0;
        foreach ($paths as $relative) {
            $path = $this->storage->path($meta['path'], $relative);
            if (!is_file($path)) throw new Fault('NOT_FOUND', 'File not found');
            $content = file_get_contents($path, false, null, 0, 2097153);
            if ($content === false) throw new Fault('IO_ERROR', 'Cannot read file');
            $total += strlen($content);
            if ($total>2097152) throw new Fault('TOO_LARGE', 'File payload exceeds 2 MiB');
            $result[]=['path'=>$relative,'encoding'=>'base64','content'=>base64_encode($content)];
        }
        return $result;
    }
    private function update(array $meta, array $files): void
    {
        if (count($files)>100) throw new Fault('TOO_LARGE', 'At most 100 files');
        $changes=[]; $total=0;
        foreach ($files as $file) {
            if (!is_array($file) || !isset($file['path']) || !array_key_exists('content',$file)) throw new Fault('INVALID_REQUEST', 'Each file needs path and content');
            $relative=$file['path']; $path=$this->storage->path($meta['path'],$relative);
            if (is_dir($path)) throw new Fault('INVALID_PATH', 'Cannot replace a directory');
            foreach (array_keys($changes) as $other) if ($path===$other || str_starts_with($path,$other.'/') || str_starts_with($other,$path.'/')) throw new Fault('INVALID_PATH', 'Duplicate or overlapping file paths');
            $content = $file['content'] === null ? null : base64_decode($file['content'], true);
            if ($content === false) throw new Fault('INVALID_REQUEST', 'Invalid base64');
            $total += strlen($content ?? '');
            if ($total>2097152) throw new Fault('TOO_LARGE', 'File payload exceeds 2 MiB');
            $changes[$path]=$content;
        }
        // Validate the whole batch first; each replacement is atomic, not the entire batch.
        foreach ($changes as $path=>$content) {
            if ($content === null) { if (is_file($path) && !unlink($path)) throw new Fault('IO_ERROR','Cannot delete file'); continue; }
            if (!is_dir(dirname($path)) && !mkdir(dirname($path),0770,true)) throw new Fault('IO_ERROR','Cannot create directory');
            $this->storage->path($meta['path'],substr($path,strlen($meta['path'])+1));
            $tmp=$path.'.micx-'.bin2hex(random_bytes(8));
            if (file_put_contents($tmp,$content,LOCK_EX)!==strlen($content)) throw new Fault('IO_ERROR','Cannot write file');
            chmod($tmp,0660);
            if (!rename($tmp,$path)) throw new Fault('IO_ERROR','Cannot replace file');
        }
    }
    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) { unlink($path); return; }
        if (!is_dir($path)) return;
        foreach (new \FilesystemIterator($path) as $entry) $this->remove($entry->getPathname());
        rmdir($path);
    }
}
