# MICX Secure VCS: RPC und Container, Entwurf v1

| Datum | Benutzername | Kurzbeschreibung |
|---|---|---|
| 2026-09-12 | dermatthes | §§ 1–9: Erstentwurf mit Service, SDK, Sicherheitsmodell und Tests |
| 2026-09-12 | dermatthes | § 5: Supervisor, begrenzter Backoff und aktive Heartbeats ergänzt |

## § 1 Ziel und Komponenten

`micx-io/micx-sec-vcs` ist ein PHP-CLI-Worker mit Git und OpenSSH-Client, ohne HTTP-Server oder SSH-Server. Er konsumiert RabbitMQ-Nachrichten und beantwortet Requests. `micx-io/micx-sec-vcs-sdk` stellt `Micx\Vcs\MixVcs` und das austauschbare `RpcTransport`-Interface bereit. Das SDK besitzt keinen SSH-Key. Der konkrete Git-Adapter erweitert `Phore\VCS\Git\GitRepository` aus Composer-Paket `phore/vcs`.

`phore/message-queue` enthält zum Prüfzeitpunkt lediglich ein Projektgerüst. Deshalb wird keine dort noch nicht vorhandene Connector-API vorausgesetzt. Ein späterer Adapter implementiert `RpcTransport::request(array $request, float $timeout): array`; Fach-API und JSON-Envelope bleiben erhalten.

## § 2 Verzeichnisse und Identitäten

| Pfad | Inhalt | Anwendungen dürfen mounten |
|---|---|---|
| `/data` | Arbeitsdateien mehrerer Repositories und Branches | Ja, vorzugsweise read-only |
| `/state` | Private Git-Verzeichnisse, Workspace-Metadaten, Locks, Request-Journal | Nein |
| `/run/micx` | SSH-Key und geprüfte known_hosts, nur im tmpfs | Nein |

Standardlayout: `/data/{repo}/{branch}`. Die URL wird mit Unterstrichen normalisiert; URL und Branch erhalten jeweils einen kurzen SHA-256-Suffix gegen Namenskollisionen. Ein Branch `feature/a` und `feature_a` haben verschiedene Pfade. Der vollständige Workspace-Identifier ist SHA-256 über URL, Branch und optionales Zielverzeichnis. Ein explizites `directory` ist immer relativ zu `/data`; absolute Pfade, `..`, `.git` und überlappende Workspaces werden abgelehnt. `VCS_PATH_TEMPLATE` darf `{repo}`, `{branch}` und `{workspace}` verwenden. Ein einmal registrierter Workspace behält seinen Pfad.

Temporäre Checkouts bekommen eine zufällige Identität und liegen unter `/data/tmp/{workspace}/...`. `release` entfernt nur temporäre Workspaces; ein Tombstone bleibt zur Absicherung alter Requests erhalten. V1 hat keine automatische TTL-Bereinigung. Das Journal bleibt ebenfalls erhalten: Speicherbedarf überwachen und Datenvolume begrenzen. Ein späteres GC benötigt ein ausdrücklich vereinbartes Retry-Zeitfenster.

Die Default-Branch wird mit `ls-remote --symref HEAD` ermittelt und als `defaultBranch` gespeichert; sie wird beim nächsten Checkout erneut geprüft. Ohne Branch-Angabe wird sie ausgecheckt. Leere Repositories ohne Remote-HEAD werden in v1 ausdrücklich abgelehnt.

## § 3 RPC-Envelope und Transport

Exchange `micx.vcs.v1`, Typ `topic`, durable; Queue `micx.vcs.v1.requests`, durable; Binding/Routing-Key `rpc.request`. Prefetch ist 1. Requests sind persistent, der Client setzt `correlation_id=id` sowie `reply_to=micx.vcs.reply.<32 Hexzeichen>`. Eine exklusive Reply-Queue wird pro synchronem Aufruf angelegt und danach geschlossen. Der Worker akzeptiert ausschließlich dieses Reply-Präfix und veröffentlicht Antworten über den Default-Exchange. Kein beliebiger Reply-Exchange aus Nutzereingaben.

```json
{
  "version": 1,
  "id": "request-00000001",
  "method": "checkout",
  "params": {"url": "git@github.com:example/project.git", "branch": "main"}
}
```

```json
{
  "version": 1,
  "id": "request-00000001",
  "ok": true,
  "result": {
    "workspace": "<64 Hexzeichen>",
    "url": "git@github.com:example/project.git",
    "branch": "main",
    "defaultBranch": "main",
    "path": "/data/<URL-Slug_Hash>/<Branch-Slug_Hash>",
    "temporary": false,
    "revision": "<Commit-SHA>",
    "generation": 0
  }
}
```

Fehler: `{"version":1,"id":"request-00000001","ok":false,"error":{"code":"CONFLICT","message":"Workspace changed","details":{"generation":2}}}`. Keine SSH-Keys oder rohe Git-/SSH-Fehlerausgaben werden zurückgeliefert oder protokolliert. Häufige Codes: `INVALID_REQUEST`, `INVALID_PATH`, `INVALID_REPOSITORY`, `CONFLICT`, `BUSY`, `GIT_FAILED`, `PUSH_FAILED`, `MERGE_FAILED`, `TOO_LARGE`, `ID_REUSED`, `OUTCOME_UNKNOWN`.

## § 4 Fach-API

Alle Workspace-Antworten enthalten `path`; normale Antworten außerdem `revision` und `generation`. Schreiboperationen benötigen `expectedRevision` und `expectedGeneration`. Der Zähler wird vor Schreibversuchen persistiert und kann auch nach einem Fehler steigen: danach `status` neu abrufen. Er erkennt RPC-Änderungen am noch uncommitteten Arbeitsbaum; externe Dateischreiber müssen sich selbst koordinieren.

| Methode | Parameter zusätzlich zu workspace | Wirkung |
|---|---|---|
| `checkout` | url, branch?, directory?, temporary? | Workspace anlegen oder bestehenden unverändert zurückgeben; kein implizites Pull |
| `status` | — | Revision, Zähler, Git-Porcelain-Status (NUL-separiert) |
| `pull` | expectedRevision, expectedGeneration | Fetch des aktuellen Branches, ausschließlich Fast-forward, sauberer Arbeitsbaum nötig |
| `commit` | message, push?, expectedRevision, expectedGeneration | Alle Arbeitsänderungen stagen, bei Änderungen committen, optional pushen |
| `push` | branch?, expectedRevision, expectedGeneration | Aktuellen Branch oder benannten lokalen Branch pushen, ohne Force |
| `branch` | branch, expectedRevision, expectedGeneration | Lokalen Branch bei aktuellem HEAD anlegen; Workspace-Branch bleibt bestehen |
| `merge` | source, expectedRevision, expectedGeneration | Remote-Branch fetchen und mergen; Konflikt führt zu Fehler und Abort-Versuch |
| `list` | path? | Eine Verzeichnisebene, maximal 1.000 Einträge |
| `read` | paths[] | Arbeitsdateien als `{path, encoding: "base64", content}` |
| `update` | files[], expectedRevision, expectedGeneration | Mehrere Arbeitsdateien setzen; `content:null` löscht eine Datei |
| `archive` | — | Aktuelles committed HEAD als Base64-ZIP |
| `snapshot` | — | Reguläre Dateien des committed HEAD als JSON-Objekte, inklusive Git-Dateimodus |
| `release` | — | Temporären Workspace löschen; keine dauerhaften Checkouts |

Branch-Workflow: `branch`, anschließend `push(..., branch: 'feature/demo')`, danach `checkout(url, 'feature/demo')` für einen getrennten Arbeitsbaum. Ein Merge schreibt nie stillschweigend Konflikte mit einer „theirs“-Strategie weg. `commit(push:true)` ist keine atomare Transaktion mit dem Remote: `PUSH_FAILED` liefert die vorhandene lokale Revision; nach Prüfung kann `push` separat folgen.

Dateiinhalte werden immer Base64-kodiert, auch bei Text, damit binäre Dateien funktionieren. Eine Update-Liste wird vollständig validiert; einzelne Dateiersetzungen erfolgen atomar per Rename. Die gesamte Liste ist bei I/O-Fehler oder Absturz nicht transaktional. Nach solchen Fehlern `status`/`read` prüfen. Das ist ausdrücklich keine Garantie, dass jede Datei gemeinsam übernommen wurde.

Maximal 4 MiB JSON pro RPC, maximal 2 MiB Rohdaten bei Datei-/ZIP-Export und maximal 100 Dateien pro Read/Update/Snapshot. Größere Übertragungen benötigen in einer Folgeversion Chunking; heute werden sie mit `TOO_LARGE` abgelehnt. ZIP enthält committed HEAD, keine uncommitteten Änderungen. Snapshot überspringt Symlinks und Submodule; ZIP kann Symlinks enthalten und darf clientseitig nur mit einem sicheren ZIP-Extractor entpackt werden.

Es gibt keine Shell- oder Raw-Git-RPC-Methode. Weitere Git-Funktionen werden als typisierte Operationen ergänzt. Das ist die Sicherheitsgrenze für konfigurationsverändernde Git-Befehle, Hook-Ausführung und Remote-Helper.

## § 5 Parallelität, Wiederholungen und Abstürze

Alle Worker konsumieren dieselbe Queue und teilen **beide** Volumes `/data` und `/state`. Ein Workspace wird durch `flock` serialisiert, verschiedene Workspaces können parallel arbeiten. Checkout-Registrierung ist global gesperrt. Das Dateisystem muss für alle beteiligten Worker verlässliche POSIX-Locks und atomare Renames bieten. Unterstützter erster Betrieb: mehrere Container auf demselben Docker-Host. Getrennte Datenvolumes oder ungeprüfte NFS-Lock-Semantik sind kein unterstützter Clusterbetrieb; dafür sind ein verteiltes Lock-/Journal-Backend und Routing nach Storage-Pool nötig.

Vor einer Operation wird unter dem Request-Lock ein begonnenes Journal geschrieben. Ein vollständig gespeichertes Ergebnis wird bei identischer ID und identischer JSON-Payload zurückgegeben. Dieselbe ID mit anderer Payload ergibt `ID_REUSED`. Fehlt nach einem Prozessabsturz das Ergebnis, lautet die Antwort `OUTCOME_UNKNOWN`; eine möglicherweise bereits ausgeführte Schreiboperation wird nicht blind wiederholt. Dies ist keine Exactly-once-Garantie über Git-Remote und Broker hinweg. Ein Totalausfall des Dateisystems bleibt außerhalb dieser Prozessabsturzabsicherung.

Der Worker bestätigt den Request erst nach dem gespeicherten Ergebnis und bestätigter Antwortpublikation. Ist die exklusive Reply-Queue inzwischen verschwunden, kann der Client über dieselbe ID das gespeicherte Ergebnis abholen. Ein Client-Timeout bricht eine bereits laufende Git-Operation nicht ab. Das SDK führt deshalb keine automatischen Retries mit neuer ID durch. Für Retries `MixVcs::call` mit derselben gespeicherten ID und denselben Parametern verwenden; `commit` hat zusätzlich ein requestId-Argument.

Einzelne Git-Prozesse haben 45 Sekunden Laufzeitlimit; SDK-Wartezeit standardmäßig 60 Sekunden, konfigurierbar bis 300 Sekunden. Mehrschrittige Operationen können länger als 60 Sekunden brauchen. Der Worker verwendet AMQP-Heartbeats mit 60 Sekunden und einem PCNTL-Signal-Sender auch während blockierender Git-Arbeit. Ein Supervisor bleibt bei Brokerfehlern im Container aktiv und startet genau einen Worker nach 1, 2, 4, 8, 16 und maximal 30 Sekunden Pause neu; nach mindestens 60 Sekunden Prozesslaufzeit wird der Backoff zurückgesetzt. Sichere Fehlerkategorien und nächste Versuche stehen im Container-Log. Weitere Worker werden über Container-Replikate skaliert; siehe [separater Skalierungsvorschlag](2026-09-12-worker-scaling.md). [geändert]

## § 6 Sicherheit und Betriebsgrenzen

Docker läuft als UID/GID 10001, mit read-only Root-Dateisystem, entfernten Linux-Capabilities, `no-new-privileges`, begrenzten Prozessen und Speicher. Es werden keine Ports veröffentlicht. Nur RabbitMQ-RPC ist eingehend erreichbar; der Worker benötigt ausgehend DNS/SSH zu den Git-Hosts. Der Broker liegt im internen Docker-Netz. Anwendungen, die dort Zugang erhalten, dürfen mit den Berechtigungen des hinterlegten Keys Git-Operationen auslösen; v1 bietet keine Mandantentrennung oder Repository-ACL innerhalb dieses Vertrauensbereichs.

Secret-Datei bevorzugt: Der Key wird zur Laufzeit in `/run/micx/id_key` mit 0600 kopiert. Alternativ `VCS_SSH_KEY`; Environment-Secrets bleiben über Container-Verwaltung/Inspect potenziell sichtbar, auch wenn die Variable vor PHP-Start entfernt wird. Keys nie in Image-Layer, Git, Queue oder `/data` speichern. Ein zusätzliches `known_hosts` ist für authentifizierte SSH-Gegenstellen nötig: Host-Fingerprints außerhalb der Verbindung prüfen. Der alte Phore-SSH-Adapter mit `StrictHostKeyChecking=no` wird bewusst nicht verwendet.

Nur validierte SCP-SSH-URLs `git@host:owner/repository.git` werden akzeptiert. HTTPS, file://, freie SSH-Optionen, Submodule und benutzerdefinierte Git-Remote-Helper sind deaktiviert. Git-Aufrufe nutzen Argumentlisten statt Shell-Interpolation. Git-Hooks, fsmonitor, globale/System-Konfiguration und interaktive Passworteingaben sind deaktiviert; alle Git-Metadaten liegen privat unter `/state`.

Shared-Volume-Modus ist für **vertrauenswürdige** Dateischreiber. RPC-Locks sperren keine externen Dateizugriffe, und Pfadprüfungen bieten gegenüber einem bösartigen gleichzeitigen Schreiber keine vollständige TOCTOU-Abwehr. Für gegeneinander isolierte Anwendungen `/data` nicht schreibbar teilen und ausschließlich RPC-Dateioperationen benutzen. Repositories werden niemals als PHP oder Composer-Projekt ausgeführt. Ressourcenlimits ersetzen keine Größenquote für Fetch-Objekte und Arbeitsdateien; passende Volume-/Host-Quoten sind Teil des Betriebs.

RabbitMQ verwendet intern Standardwerte `micx`/`micx`, Port 5672, VHost `/`, ohne TLS. Es gibt keine zusätzliche RPC-Authentifizierung. RabbitMQ selbst benötigt Zugangsdaten; diese Defaults vermeiden individuelle App-Konfiguration im isolierten Compose-Netz. Außerhalb dieses Netzes separate Broker-Zugangsdaten und einen gesicherten Transportadapter verwenden.

## § 7 Start und SDK-Beispiel

Siehe [README](../README.md) für Compose. Andere Services hängen am `rpc`-Netz und mounten bei Bedarf dasselbe `data`-Volume unter `/data`. `/state` und Secrets werden nicht weitergereicht.

```php
$connection = new AMQPStreamConnection('rabbitmq', 5672, 'micx', 'micx');
$vcs = new MixVcs(new RabbitMqTransport($connection), timeout: 120);
$w = $vcs->checkout('git@github.com:example/project.git', 'main');
$w = $vcs->update($w['workspace'], [
    ['path' => 'README.md', 'content' => base64_encode("Hello\n")],
], $w['revision'], $w['generation']);
$w = $vcs->commit($w['workspace'], 'Update README', $w['revision'], $w['generation'], push: true);
```

Vollständiges Beispiel mit Imports im SDK unter `examples/workflow.php`. Ein Transportwechsel ändert nur die Konstruktion des `RpcTransport`.

## § 8 Implementierung und Validierung

`Storage` validiert Namen, trennt Pfade und verwaltet Locks/JSON-Dateien. `SecureGit` implementiert die sichere Phore-Git-Ausführung. `Service` bildet Methoden auf Git- und Dateioperationen ab. `Dispatcher` verwaltet Protokoll und Wiederholungen. `worker.php` bindet RabbitMQ an. Im SDK bleiben `MixVcs`, `RpcException`, `RpcTransport` und `RabbitMqTransport` unabhängig vom Service-Quellcode.

Tests prüfen Pfad-/URL-Angriffe, Slug-Kollisionen, Symlinks, Wiederholungen und unterbrochene Requests, SDK-Korrelation, reale lokale Git-Commits und ZIP-Ausgabe. CI führt Composer, PHP-Lint, PHPUnit und den Container-Build aus. Der erste Entwurf ist vor produktiver Freigabe zusätzlich mit einem eigenen SSH-Test-Remote, Broker-Ausfällen und dem konkreten Shared-Storage zu prüfen. Ein erfolgreicher Unit-Test ist keine Bestätigung dieser noch notwendigen Betriebsprüfung.

## § 9 Referenzen

- [RabbitMQ PHP RPC](https://www.rabbitmq.com/tutorials/tutorial-six-php): correlation_id, Reply-Queues, doppelte Zustellungen.
- [RabbitMQ Confirms](https://www.rabbitmq.com/docs/confirms): Publisher-Confirms und Consumer-Acknowledgements.
- [Git-Konfiguration](https://git-scm.com/docs/git-config): Hooks, Protokolle und SSH-Konfiguration.
- [Phore VCS](https://github.com/phore/phore-vcs): tatsächlich verwendete Abstraktion; gehärteter Adapter im Service.
