<?php
declare(strict_types=1);
namespace Micx\SecVcs;
final class Dispatcher
{
    public function __construct(private Storage $storage, private \Closure $execute) {}
    public function handle(array $request): array
    {
        $id = $request['id'] ?? null;
        try {
            if (($request['version'] ?? null)!==1 || !is_string($id) || !preg_match('/^[A-Za-z0-9_-]{8,128}$/D',$id)
                || !is_string($request['method'] ?? null) || !is_array($request['params'] ?? null)) throw new Fault('INVALID_REQUEST','Invalid version 1 request');
            return $this->storage->lock('request:'.$id, function () use ($request,$id) {
                $file=$this->storage->state.'/requests/'.hash('sha256',$id).'.json';
                $hash=hash('sha256',json_encode($request,JSON_THROW_ON_ERROR));
                if (is_file($file)) {
                    $record=$this->storage->load($file);
                    if ($record['hash']!==$hash) throw new Fault('ID_REUSED','Request ID used with a different payload');
                    if (!isset($record['response'])) throw new Fault('OUTCOME_UNKNOWN','Interrupted operation; inspect before submitting a new write');
                    return $record['response'];
                }
                $this->storage->save($file,['hash'=>$hash,'startedAt'=>gmdate('c')]);
                try {
                    $result=($this->execute)($request['method'],$request['params']);
                    $response=['version'=>1,'id'=>$id,'ok'=>true,'result'=>$result];
                } catch (Fault $e) { $response=$this->error($id,$e); }
                catch (\TypeError|\ValueError $e) { $response=$this->error($id,new Fault('INVALID_REQUEST','Invalid parameter type')); }
                catch (\Throwable $e) { $response=$this->error($id,new Fault('OUTCOME_UNKNOWN','Operation interrupted; inspect workspace')); }
                if (strlen(json_encode($response,JSON_THROW_ON_ERROR))>4194304) $response=$this->error($id,new Fault('TOO_LARGE','Response exceeds 4 MiB'));
                $this->storage->save($file,['hash'=>$hash,'response'=>$response,'completedAt'=>gmdate('c')]);
                return $response;
            });
        } catch (Fault $e) { return $this->error(is_string($id)?$id:null,$e); }
    }
    private function error(?string $id, Fault $error): array
    {
        return ['version'=>1,'id'=>$id,'ok'=>false,'error'=>['code'=>$error->kind,'message'=>$error->getMessage(),'details'=>$error->details]];
    }
}
