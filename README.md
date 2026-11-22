# MICX Sec VCS

Isolierter PHP-Worker für die Git-Operationen des
[MICX Sec VCS SDK](https://github.com/micx-io/micx-sec-vcs-sdk).
Die Anwendung bestimmt Repository-URL, Branch und Checkout-Verzeichnis
clientseitig und übergibt alle drei Werte bei **jedem RPC**. Der Worker
führt Git über RabbitMQ aus; der SSH-Key bleibt ausschließlich im Container.

## Checkout und Mount

Der Worker nutzt nur noch **ein persistentes Datenvolume** unter `/data`,
konfigurierbar mit `VCS_DATA_DIR`. Der SDK-Manager kann dasselbe Volume
beispielsweise unter `/vcs-share` mounten:

```php
$manager = new MixVcsManager(
    sharedDirectory: '/vcs-share',
    connector: 'rabbitmq://micx:micx@rabbitmq:5672/micx.vcs.v1',
    storageTemplate: '{repo}/{branch}',
);
$repo = $manager->getRepository(
    'git@github.com:example/site.git',
    'main',
    'sites/site-main',
);
$directory = $repo->getDirectory(); // /vcs-share/sites/site-main; kein RPC
$repo->pull(); // Erst hier wird Git initialisiert/aktualisiert.
```

Der dritte Parameter ist relativ zum gemeinsamen Volume. Ohne ihn nutzt
der SDK-Manager sein Storage-Template (Standard `{repo}/{branch}`).
Der Worker berechnet **keinen** Verzeichnisnamen und verwaltet
**keine** Workspace-IDs, Workspace-JSON-Registry oder Request-Journale.
Die Git-Metadaten liegen als `.git` im jeweiligen Checkout-Verzeichnis,
Git-Locks unter `/data/.mix-vcs-locks`. Ein eigener `/state`-Mount,
`VCS_STATE_DIR` und `VCS_PATH_TEMPLATE` entfallen.

**Sicherheit:** Im Shared Volume sind mit `.git` jetzt auch die lokalen
Git-Metadaten für die Anwendung sichtbar. Daher darf das Volume nur an
vertrauenswürdige Services gemountet werden; idealerweise wird es
read-only geteilt und Dateiänderungen erfolgen über einen kontrollierten
Schreibzugang. Worker-SSH-Secrets werden niemals in diesem Volume abgelegt.

## RabbitMQ-RPC

Der Worker deklariert den Topic-Exchange `micx.vcs.v1`, bindet
`micx.vcs.v1.requests` an `rpc.request` und antwortet auf
die jeweilige `reply_to`-Queue unter der übermittelten `correlation_id`.
Jede Repository-Nachricht enthält `url`, `branch` und das vom SDK
gewählte relative `directory`:

```json
{
  "version": 1,
  "id": "request-12345678",
  "method": "commit",
  "params": {
    "url": "git@github.com:example/site.git",
    "branch": "main",
    "directory": "sites/site-main",
    "message": "Update content"
  }
}
```

**Unterstützte Methoden:** `ping`, `pull`, `push`, `commit`,
`status` (`hasUnpushedChanges`), `log` (`entries`) und
`branches` (`branches`). Nur diese Methoden werden vom
öffentlichen SDK-`VcsRepository` verwendet. `pull` legt das Checkout
bei Bedarf an; alle übrigen Git-Methoden erwarten ein vorhandenes
Checkout. Auch ein `ping` erhält alle drei Repository-Parameter.

Die alten RPCs `checkout`, `create`, `update`, `read`, `list`,
`merge`, `branch`, `snapshot`, `archive` und `release` werden
nicht mehr angeboten.

## Fehlgeschlagene Aufträge und Wiederholungen

Der Worker antwortet für normale Git- und Validierungsfehler mit
`ok: false` und bestätigt den Request anschließend **auch bei Fehlern**
mit `ack`, sobald RabbitMQ die Antwortpublikation bestätigt.
Ungültige RabbitMQ-Nachrichten werden mit `reject(requeue: false)`
verworfen. Ein fachlich fehlerhafter Git-Auftrag wird deshalb nicht
automatisch wiederholt und blockiert die Queue nicht.

**Ausnahme:** Bricht der Worker oder die Brokerverbindung vor dem
Acknowledgement ab, kann RabbitMQ eine Nachricht erneut zustellen.
Ohne Request-Journal wird derselbe Auftrag dann nochmals ausgeführt.
Bei Timeouts und Verbindungsabbrüchen vor einer erneuten Schreiboperation
den Zustand des Repositories prüfen. Ein `commit` ohne Änderungen erzeugt
keinen weiteren Commit; bei `pull` und `push` können sich zwischenzeitlich
Remote-Änderungen ausgewirkt haben. Gleiche Repository-Verzeichnisse
werden über `flock` gegen gleichzeitige Worker-Operationen gesperrt.

## Konfiguration

| Einstellung | Standard | Zweck |
|---|---|---|
| `VCS_DATA_DIR` | `/data` | Persistentes gemeinsames Checkout-Volume |
| `AMQP_HOST` / `AMQP_PORT` | `rabbitmq` / `5672` | RabbitMQ |
| `AMQP_USER` / `AMQP_PASSWORD` | `micx` / `micx` | Broker-Anmeldung |
| `AMQP_VHOST` | `/` | Broker-VHost |
| `VCS_SSH_KEY_FILE` | über Secrets | SSH-Privatschlüssel |
| `VCS_KNOWN_HOSTS_FILE` | `/run/secrets/known_hosts` | Geprüfte SSH-Hostschlüssel |

Der Container läuft als UID/GID 10001, mit read-only Root-Dateisystem,
ohne veröffentlichte Ports und ohne Linux-Capabilities. Er benötigt
ausgehenden SSH-/DNS-Zugriff zum Git-Host. Git-Kommandos haben ein
serviceinternes Limit von 45 Sekunden; die SDK-RPC-Wartezeit ist
standardmäßig 60 Sekunden. Pro RPC sind maximal 4 MiB JSON erlaubt.
Der Supervisor startet einen ausgefallenen Worker nach einer
Wiederanlaufpause von 30 Sekunden neu.

```sh
composer install
composer test
docker compose build
```

Weitere Details: [RPC-Protokoll](docs/2026-09-12-secure-vcs-rpc.md).
