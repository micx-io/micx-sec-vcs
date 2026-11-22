# MICX Sec VCS

Leichter PHP-CLI-Container für Git-RPC über RabbitMQ. SSH-Key und Git-Metadaten bleiben privat; Anwendungen erhalten Arbeitsverzeichnisse, Dateien oder ZIP-Revisionen. Erste Implementierung, noch keine produktive Betriebsfreigabe.

## Ping ohne SSH-Key oder Repository

Der Standard-Entrypoint startet den Worker auch ohne SSH-Konfiguration. Der
erste Test ist ein echter RabbitMQ-RPC-Rundlauf mit dem verbundenen SDK-Client:

```php
$status = $vcs->ping();
echo json_encode($status, JSON_PRETTY_PRINT);
```

Ohne Credentials enthält das Ergebnis `pong: true`, `ready: false` und
`errors` mit `SSH_KEY_MISSING` sowie gegebenenfalls `KNOWN_HOSTS_MISSING`.
Das bestätigt die Verbindung über Broker und Worker; es wird kein Git-Prozess
gestartet und kein Repository benötigt. `ready: true` bestätigt nur vorhandene,
lesbare und nicht leere SSH-Konfigurationsdateien, keine erfolgreiche Anmeldung
bei einem Git-Server. Für einen neuen Konfigurationsstand einen neuen Ping senden.

Vor Remote-Aktionen prüfen `checkout`, `pull`, `push`, `merge` und
`commit(push: true)` dieselben Voraussetzungen und liefern bei fehlenden Dateien
einen ausdrücklichen Fehler. Lokale Workspace-Aktionen bleiben nutzbar;
fehlende Credentials führen niemals zu einem simulierten erfolgreichen Push.

## Anwendung: Repository, Workspace, Commit und Push

Das [SDK](https://github.com/micx-io/micx-sec-vcs-sdk/pull/1) zeigt den vollständigen Ablauf mit Verbindung und nummerierten Beispielen. Der normale Aufruf benötigt nur den Workspace, keine Revision, Generation oder selbst erzeugte Request-ID:

```php
$repository = $vcs->checkout('git@github.com:example/project.git', 'main');
$workspace = $repository['workspace'];
echo $repository['path']; // Service-Pfad unter /data.
$vcs->update($workspace, [
    ['path'=>'hello.txt', 'content'=>base64_encode("Hello\n")],
]);
$vcs->commit($workspace);
$vcs->push($workspace);
```

Dieser Ausschnitt setzt den verbundenen SDK-Client `$vcs` voraus. `commit` erfasst alle Änderungen; eine Nachricht ist optional. `create(url, directory: 'new-project')` ersetzt `checkout` für ein leeres lokales Repository auf `main`. Es legt kein Projekt beim Git-Anbieter an: Das Remote muss spätestens vor `push` existieren. Vor dem ersten Commit ist `revision` leer. `$vcs->path($workspace)` liefert den Pfad später erneut; zum direkten Dateizugriff muss die Anwendung dasselbe Volume ebenfalls unter `/data` mounten.

`pull($workspace)` integriert Änderungen des aktuellen Remote-Branches. `merge($workspace, 'feature/content')` integriert einen anderen Remote-Branch. Zuerst lokale Änderungen committen. Bei Konflikten gewinnt die eigene Workspace-Seite, einschließlich binärer Dateien und Lösch-/Änderungskonflikten; konfliktfreie Remote-Änderungen bleiben erhalten. Technische Mergeprobleme ergeben weiterhin `MERGE_FAILED`. Push erfolgt ohne Force und übergeht weder Branchschutz noch Server-Hooks.

Der SDK-Konstruktor akzeptiert `timeout` in Sekunden (Standard 60, > 0 bis 300). Nach Ablauf kommt `OperationTimeoutException` mit vollständigem Request für eine bewusste Wiederaufnahme. Das beendet die Wartezeit, bestätigt aber keinen Remote-Abbruch. Einzelne Git-Prozesse haben zusätzlich ein serviceinternes Laufzeitlimit von 45 Sekunden.

## Start

Voraussetzung für einen Ping ist nur ein erreichbarer RabbitMQ-Broker. Der
Standard-Entrypoint bleibt aktiv, auch wenn keine SSH-Dateien angegeben sind.
Für echte Git-Remote-Aktionen zusätzlich einen passenden SSH-Key und unabhängig
geprüfte Hostschlüssel konfigurieren. Das folgende Compose-Beispiel bindet
diese Dateien ein und setzt sie deshalb außerhalb des Repositories voraus:

```sh
mkdir -p secrets
cp /sicherer/pfad/id_ed25519 secrets/id_ed25519
cp /sicherer/pfad/known_hosts secrets/known_hosts
# Lokales Compose muss die Secret-Dateien für Container-UID 10001 lesbar mounten.
# Berechtigungen/Ownership passend setzen; Key niemals allgemein lesbar machen.
docker compose up -d --build
# Mehrere Worker auf denselben Volumes:
docker compose up -d --scale vcs=3
```

Kein Port wird veröffentlicht. Broker: `rabbitmq:5672`, User/Passwort `micx`/`micx`, VHost `/`, ohne TLS im isolierten internen Netz. Anwendungen verwenden das SDK `micx-io/micx-sec-vcs-sdk` und verbinden sich mit demselben `rpc`-Netz. Ein Schreibzugriff auf `/data` setzt koordinierte, vertrauenswürdige Anwendungen voraus; vorzugsweise read-only mounten oder Dateien per RPC übertragen.

| Konfiguration | Standard |
|---|---|
| `VCS_SSH_KEY_FILE` | Secret-Dateipfad, in Compose gesetzt |
| `VCS_SSH_KEY` | Alternative Klartext-Environment-Variable, Secret-Datei bevorzugt |
| `VCS_KNOWN_HOSTS_FILE` | `/run/secrets/known_hosts` |
| `VCS_DATA_DIR` | `/data` |
| `VCS_STATE_DIR` | `/state`, niemals an Apps mounten |
| `VCS_PATH_TEMPLATE` | `{repo}/{branch}`, jeweils normalisiert und mit Hash |
| `AMQP_HOST`, `AMQP_PORT` | `rabbitmq`, `5672` |
| `AMQP_USER`, `AMQP_PASSWORD`, `AMQP_VHOST` | `micx`, `micx`, `/` |

Der Container läuft mit UID/GID 10001, read-only Root-Dateisystem und ohne Linux-Capabilities. Die Default-Branch wird remote erkannt. Branches haben getrennte Arbeitsverzeichnisse. Temporäre Checkouts müssen ausdrücklich mit `release` freigegeben werden.

## API, Sicherheit und Grenzen

[Entwurf und RPC-Protokoll](docs/2026-09-12-secure-vcs-rpc.md) beschreibt alle Methoden, Beispiele, Konfliktprüfung, Retry-Verhalten und Parallelbetrieb. Keine Raw-Git-/Shell-API, kein SSH-Server, keine HTTP-Schnittstelle. `phore/vcs` wird über einen gehärteten Git-Adapter verwendet.

4 MiB JSON pro Nachricht, 2 MiB Datei-/ZIP-Payload, maximal 100 Dateien pro Transfer. ZIP/Snapshot exportieren committed HEAD. RPC-Aufträge werden ohne persistentes Request-Journal verarbeitet. Eine erneute Zustellung nach einem Broker- oder Worker-Ausfall kann eine Git-Operation erneut ausführen; nach Timeouts deshalb den Repository-Zustand prüfen. Mehrere Worker benötigen dieselben `/data`- und `/state`-Volumes mit verlässlichem `flock`.

## Entwicklung

```sh
composer install
composer test
```

PHP 8.3+, YAML-Erweiterung, Git. Die CI prüft PHP-Syntax, PHPUnit und Docker-Build. Keine Schlüssel für Unit- und lokale Git-Tests erforderlich. Secrets sind in `.gitignore` ausgeschlossen und werden nicht in den Build-Kontext aufgenommen.

## Fehler, Parallelität und Broker-Ausfall

Jeder gültige RPC-Request erhält entweder ein Ergebnis oder `error.code`, `error.message` und `error.details`. Das SDK wirft daraus `RpcException` (bei `TIMEOUT` die Unterklasse `OperationTimeoutException`); die Meldung ist über `getMessage()` verfügbar. Git-Diagnosen werden lokal klassifiziert: Rohes stderr, Befehlszeilen und Secrets werden nicht an Clients weitergegeben. Nicht erkannte Git-Fehler bleiben `GIT_FAILED`.

| Fehlercode | Bedeutung / nächste Aktion |
|---|---|
| `SSH_KEY_MISSING`, `KNOWN_HOSTS_MISSING` | SSH-Konfigurationsdatei fehlt, ist nicht lesbar oder leer; Ping bleibt verfügbar |
| `SSH_KEY_INVALID` | Key beschädigt, verschlüsselt oder unsichere Dateirechte; Service-Secret prüfen |
| `SSH_AUTH_FAILED` | SSH-Anmeldung abgelehnt; Key und Repository-Berechtigungen prüfen |
| `SSH_HOST_KEY_FAILED` | Hostschlüssel fehlt oder stimmt nicht; `known_hosts` unabhängig prüfen |
| `REPOSITORY_UNAVAILABLE` | Repository fehlt oder Zugriff verweigert; Server unterscheiden das oft absichtlich nicht |
| `REMOTE_UNREACHABLE` | DNS-, Netzwerk- oder SSH-Verbindungsfehler |
| `BRANCH_NOT_FOUND`, `EMPTY_REPOSITORY` | Branch fehlt bzw. Remote-HEAD bezeichnet keinen Branch |
| `PUSH_REJECTED` | Neuere Remote-Commits, Branchschutz oder Server-Hook verhindern Push |
| `PUSH_FAILED` | Kombinierter Commit/Push fehlgeschlagen; lokale Revision und `details.cause` prüfen |
| `CONFLICT`, `DIRTY_WORKTREE`, `MERGE_FAILED` | Veralteter Zustand, uncommittete Änderungen oder Mergeproblem; Status abgleichen |
| `BUSY` | Sperre nach fünf Sekunden nicht verfügbar; Operation wurde nicht ausgeführt |
| `IO_ERROR` | Dateisystem, Rechte, voller Datenträger oder anderes Storageproblem; bei Schreiboperationen sind Teilergebnisse möglich |
| `TIMEOUT` | Warte- oder Git-Laufzeitlimit erreicht; Teilergebnisse sind möglich, Workspace und Remote prüfen |
| `OUTCOME_UNKNOWN` | Unerwarteter Abbruch während einer Operation; Zustand vor einer Wiederholung prüfen |
| `INVALID_REQUEST`, `UNKNOWN_METHOD`, `INVALID_REPOSITORY`, `INVALID_BRANCH`, `INVALID_PATH` | Aufruf oder Parameter korrigieren |
| `NOT_FOUND`, `DIRECTORY_EXISTS`, `TOO_LARGE` | Workspace/Datei fehlt, Ziel belegt oder Limit überschritten |

Ein Worker verarbeitet durch synchronen Callback und Prefetch 1 genau einen Request gleichzeitig. N Worker können bis zu N Requests bearbeiten. Gleiche Workspaces werden über `flock` serialisiert; verschiedene Workspaces können parallel laufen. Checkouts halten zusätzlich eine globale Registry-Sperre. Sperren warten höchstens fünf Sekunden und garantieren keine FIFO-Reihenfolge. Auch ein Leser wartet auf einen Schreiber. Direkte Dateizugriffe anderer Anwendungen nehmen nicht automatisch an diesen Sperren teil.

Es gibt keine persistente Request-ID-Deduplizierung mehr. Jeder erneut zugestellte Request wird erneut bearbeitet. Fachliche Git-Fehler werden als Fehlerantwort zurückgegeben; nach bestätigter Reply-Veröffentlichung bestätigt der Worker die Queue-Nachricht auch bei Fehlern mit `ack`. Fehlerhafte JSON-Nachrichten werden mit `reject(requeue=false)` verworfen. Bei Verbindungsabbruch vor dem Acknowledgement kann RabbitMQ den Auftrag erneut zustellen. Für mehrere Worker sind dieselben verlässlich lockfähigen `/state`- und `/data`-Volumes erforderlich.

**Queue fällt aus:** Ein kleiner Supervisor bleibt als PID 1 aktiv und startet genau einen Worker-Unterprozess. Bei fehlender oder verlorener Broker-Verbindung meldet der Worker eine sichere Fehlerkategorie; der Supervisor protokolliert Exit-Status und nächsten Versuch. Wiederanlaufpause: **immer 30 Sekunden**, auch nach dem ersten Fehler und ohne maximale Versuchszahl. Das ist die Wartezeit *zwischen* Versuchen; Verbindungsaufbau und Fehlererkennung dauern zusätzlich. AMQP-Heartbeats (60 Sekunden, Signal-Sender auch während Git-Arbeit) erkennen ausgefallene Verbindungen; ein stiller Netzwerkausfall wird nicht zwingend sofort erkannt. Beispiele:

```text
worker: AMQP_UNAVAILABLE: connection failed or was lost; check broker, DNS and network; supervisor will restart
supervisor: worker stopped (exit=0); retry in 30s
supervisor: starting worker
MICX VCS worker ready
```

`docker compose logs -f vcs` zeigt die Diagnose. `AMQP_CONNECTION` verweist auf Anmeldung/VHost, `AMQP_CHANNEL` auf Queue-Konfiguration/Rechte, `WORKER_ERROR` auf Service-/Storagekonfiguration. Es werden keine rohen AMQP-Exceptions oder Zugangsdaten ausgegeben. Auch bei dauerhaft falscher Konfiguration bleibt der Supervisor aktiv; „Container läuft“ bedeutet daher nicht „RPC bereit“. Nach Korrektur ist ggf. ein Container-Neustart zur Übernahme neuer Environment-Variablen nötig.

Ein bereits laufender Git-Befehl kann vor Erkennung des Brokerfehlers fertig werden. Der Worker veröffentlicht die Antwort und bestätigt den Request anschließend mit `ack`. Nicht bestätigte Requests können nach Wiederherstellung erneut zugestellt und nochmals ausgeführt werden. Normale fehlgeschlagene Operationen bleiben nicht durch wiederholtes Requeueing in der Queue; die Git- und Workspace-Daten müssen dennoch persistent bleiben. Fehlende SSH-Dateien werden beim Start sicher diagnostiziert; der Worker bleibt für Ping erreichbar. Erst Remote-Git-Aktionen werden mit einem Konfigurationsfehler abgewiesen. Compose `restart: unless-stopped` bleibt als zusätzliche Absicherung bei Container-/Hostausfällen bestehen.

SIGTERM wird vom Supervisor an den Worker weitergereicht; dieser nimmt keine weitere Arbeit an, beendet den laufenden Request und schließt die Verbindung. Während der Wiederanlaufpause unterbricht SIGTERM das Warten sofort. Überschreitet die Arbeit die Compose-Stopfrist von 60 Sekunden, kann Docker sie hart abbrechen; ein noch nicht bestätigter Request kann erneut zugestellt werden.

Ein verlorenes Reply bzw. ein Client-Timeout bricht Git nicht ab. Das SDK verbindet sich nicht selbst neu: Die Anwendung prüft beziehungsweise erneuert die AMQP-Verbindung und gleicht vor einem erneuten Schreibauftrag den Git-Zustand ab. Die ursprünglichen Parameter stehen bei `OperationTimeoutException` in `$e->request`; das Wiederholen derselben Request-ID führt ohne Journal zu einer neuen Ausführung. Kanalaufbaufehler melden `UNAVAILABLE`; ein Verbindungsabbruch ab Publish-Beginn meldet konservativ `OUTCOME_UNKNOWN`. Verbindungsaufbau außerhalb des SDK kann die ursprüngliche AMQP-Exception werfen. Eine vorhandene Queue ohne aktive Worker führt zum Timeout. Broker-Downtime kann daher länger dauern als der einzelne RPC-Timeout.

Die Tests decken Fehlerklassifikation, parallele Prozesse mit Workspace-Sperren und wiederholte RPC-Requests ohne Journal ab. Die CI prüft zusätzlich Start ohne Broker, Broker-Unterbrechung und Wiederaufnahme mit zwei Workern. Ein echter Broker-Neustart mitten in einem Remote-Push und Storage-Ausfälle auf dem vorgesehenen Produktionsvolume sind noch nicht end-to-end validiert.

Separater [Vorschlag zur Worker-Skalierung](docs/2026-09-12-worker-scaling.md): ein aktiver Worker pro Container; der Supervisor verwaltet dessen Lebenszyklus, keinen internen Worker-Pool.
