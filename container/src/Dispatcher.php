<?php

declare(strict_types=1);

namespace Micx\SecVcs;

final class Dispatcher
{
    public function __construct(private \Closure $execute, private bool $mockMode = false)
    {
    }

    public function handle(array $request): array
    {
        $id = $request['id'] ?? null;

        try {
            if (
                ($request['version'] ?? null) !== 1
                || !is_string($id)
                || !preg_match('/^[A-Za-z0-9_-]{8,128}$/D', $id)
                || !is_string($request['method'] ?? null)
                || !is_array($request['params'] ?? null)
            ) {
                throw new Fault('INVALID_REQUEST', 'Invalid version 1 request');
            }

            // Jeder Request läuft unabhängig; normale Fehler sind endgültige Antworten.
            try {
                $result = ($this->execute)($request['method'], $request['params']);
                $response = ['version' => 1, 'id' => $id, 'ok' => true, 'result' => $result];
            } catch (Fault $e) {
                $response = $this->error($id, $e);
            } catch (\TypeError|\ValueError $e) {
                $response = $this->error($id, new Fault('INVALID_REQUEST', 'Invalid parameter type'));
            } catch (\Throwable $e) {
                $response = $this->error($id, new Fault('OUTCOME_UNKNOWN', 'Operation interrupted; inspect workspace'));
            }

            if (strlen(json_encode($response, JSON_THROW_ON_ERROR)) > 4194304) {
                return $this->annotate($this->error($id, new Fault('TOO_LARGE', 'Response exceeds 4 MiB')));
            }

            return $this->annotate($response);
        } catch (Fault $e) {
            return $this->annotate($this->error(is_string($id) ? $id : null, $e));
        } catch (\Throwable $e) {
            return $this->annotate($this->error(is_string($id) ? $id : null, new Fault('IO_ERROR', 'Cannot process request')));
        }
    }

    /** Include the server-controlled mock marker even on errors. */
    private function annotate(array $response): array
    {
        if ($this->mockMode) {
            $response['mockMode'] = true;
            if ($response['ok'] && is_array($response['result'])) {
                $response['result']['mockMode'] = true;
            }
        }
        return $response;
    }

    private function error(?string $id, Fault $error): array
    {
        return [
            'version' => 1,
            'id' => $id,
            'ok' => false,
            'error' => [
                'code' => $error->kind,
                'message' => $error->getMessage(),
                'details' => $error->details,
            ],
        ];
    }
}
