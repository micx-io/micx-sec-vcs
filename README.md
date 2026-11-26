# MICX Sec VCS

MICX Sec VCS kapselt Git-Operationen und den privaten SSH-Key in einem
isolierten Docker-Worker. Die Anwendung spricht den Worker über RabbitMQ an;
der SSH-Key liegt ausschließlich beim Worker und wird nicht an den Client
weitergegeben. Checkouts und Git-Metadaten liegen in einem gemeinsam
gemounteten Verzeichnis, sodass der Client die Dateien direkt bearbeiten
kann. Der Worker stellt dafür unter anderem `pull`, `commit`, `push`,
`status` und `log` bereit.

Das Repository enthält **den Container-Worker** unter `container/` und das
**PHP-Client-Paket** `micx/sec-vcs-client` im Repository-Root. Für die lokale
Entwicklung gibt es zusätzlich Client-Mocks ohne RabbitMQ oder Git-Zugriff.

## Container starten

Ein Docker-Compose-Beispiel mit RabbitMQ und einem extern vorbereiteten
Checkout-Verzeichnis (alle `CONF_*`-Variablen konfigurieren den **Worker**):

```yaml
services:
  rabbitmq:
    image: rabbitmq:4-alpine
    environment:
      RABBITMQ_DEFAULT_USER: micx
      RABBITMQ_DEFAULT_PASS: change-me
    networks: [rpc]

  vcs:
    image: ghcr.io/micx-io/micx-sec-vcs:latest
    environment:
      CONF_AMQ_CONNECTION: rabbitmq://micx:change-me@rabbitmq:5672/
      CONF_DATA_DIR: /data
      CONF_SSH_KEY_FILE: /run/secrets/vcs_ssh_key
      CONF_MOCK_MODE: "false"
    secrets: [vcs_ssh_key]
    volumes:
      - /srv/micx-vcs:/data
    networks: [rpc]
    depends_on: [rabbitmq]

secrets:
  vcs_ssh_key:
    file: ./secrets/id_ed25519

networks:
  rpc:
    internal: true
```

Vor dem Start `/srv/micx-vcs` auf dem Docker-Host **explizit anlegen**
und für die Container-UID/GID `10001:10001` lesbar, beschreibbar und
betretbar machen. Außerdem die SSH-Private-Key-Datei als Secret
bereitstellen und die Beispiel-Zugangsdaten ersetzen. Der Worker legt
`/data` nicht selbst an: Ist `CONF_DATA_DIR` nicht gemountet oder fehlen
Rechte, bricht der Container mit einer diagnostischen Fehlermeldung ab.
`CONF_MOCK_MODE=true` ist ausschließlich für Development/Tests gedacht;
dann werden **keine** Git- oder SSH-Kommandos ausgeführt.

```sh
docker compose up -d
```

Der PHP-Client muss dasselbe Verzeichnis (im Beispiel `/srv/micx-vcs`)
in seinem eigenen Container unter einem gewünschten Pfad, z. B.
`/vcs-share`, gemountet haben. Nur dann können Client und Worker dieselben
Checkouts sehen. Für Production die RabbitMQ-Verbindung und Secrets
absichern.

### Worker-Konfiguration

| Environment-Variable | Wert / Vorgabe | Zweck |
|---|---|---|
| `CONF_AMQ_CONNECTION` | z. B. `rabbitmq://micx:change-me@rabbitmq:5672/` | RabbitMQ-Verbindung und VHost |
| `CONF_DATA_DIR` | Pflicht, z. B. `/data` | Bereits vorhandenes extern gemountetes Datenverzeichnis |
| `CONF_SSH_KEY_FILE` | empfohlen, z. B. `/run/secrets/vcs_ssh_key` | Pfad zur privaten SSH-Key-Datei als Secret |
| `CONF_SSH_KEY` | optional, alternativ zu `CONF_SSH_KEY_FILE` | Privater SSH-Key direkt im Environment (nicht empfohlen) |
| `CONF_MOCK_MODE` | `false` (Standard) | `true` simuliert Git-Operationen ohne Git/SSH |

`CONF_SSH_KEY_FILE` hat Vorrang vor `CONF_SSH_KEY`. SSH-Credentials
dürfen nicht in Git eingecheckt oder protokolliert werden.

## PHP-Client verwenden

Das Composer-Paket wird direkt aus diesem Repository bereitgestellt:

```sh
composer require micx/sec-vcs-client
```

```php
use Micx\Vcs\MixSecVcsClient;

$client = new MixSecVcsClient(
    sharedDirectory: '/vcs-share',
    connector: 'rabbitmq://user:password@rabbitmq:5672/micx.vcs.v1',
);

$repo = $client->getRepository(
    'git@github.com:example/repo.git',
    'main',
    'sites/repo',
);

$directory = $repo->getDirectory(); // /vcs-share/sites/repo
$repo->pull();                       // stellt Checkout bereit, committet Dirty Files, pullt; kein Push
// Dateien im gemeinsamen Verzeichnis bearbeiten ...
$repo->commit('Update content');
$repo->push();
```

Das Shared Directory muss im Client existieren. Der Client hält **keinen**
SSH-Key und sendet Git-Operationen über RabbitMQ an den Worker. Die
Repository- und Branch-Zuordnung sowie das relative Checkout-Verzeichnis
legt der Client fest.

Für lokale Entwicklung können bestehende Mock-Verzeichnisse registriert
werden, ohne den Worker aufzurufen:

```php
$client->addMockDir(
    'git@github.com:example/repo.git',
    'main',
    __DIR__ . '/mock-repos/repo'
);
$client->setAllowMockDir(true); // nur Development
```

Die lokalen Client-Mocks unterscheiden sich vom **serverseitigen**
`CONF_MOCK_MODE`: Im serverseitigen Mock Mode muss jedes Zielverzeichnis
schon existieren, auch bei `pull`. Ein fehlender Checkout liefert
`NOT_FOUND`. In Production bleibt der Mock Mode deaktiviert.

`getChangedFiles()` liefert sowohl im lokalen Client-Mock als auch im
serverseitigen Mock Mode immer drei simulierte Änderungen:

| Dateiname | `ChangeSet::change` |
| --- | --- |
| `mock_created.txt` | `created` |
| `mock_modified.txt` | `changed` |
| `mock_deleted.txt` | `deleted` |

Diese Einträge sind unabhängig vom tatsächlichen Datei- oder Push-Stand und
von den übergebenen Revisionen. Die Dateien werden dadurch nicht angelegt,
verändert oder gelöscht. Der Worker liefert hierfür die Listen `added`,
`modified` und `deleted`; der Client übersetzt sie in `ChangeSet`-Objekte.

Siehe das [vollständige PHP-Beispiel](examples/mixed-repositories.php)
und die [Container-Dokumentation mit Docker-Swarm, Volumes,
Berechtigungen und Startup-Logs](container/README.md).

## Repository-API

`getRepository($url, $branch, $directory)` liefert einen passiven
Repository-Handle. Das optionale `$directory` liegt relativ unter dem
gemeinsamen `sharedDirectory`. Ohne Angabe gilt das Template
`{repo}/{branch}`, das sich mit `storageTemplate` anpassen lässt.
`getDirectory()` liefert den absoluten Pfad ohne RPC oder Checkout.

### Methodenübersicht

| Methode | Verhalten |
|---|---|
| `getDirectory()` | Gibt den Pfad zum Checkout zurück, auch wenn er noch nicht existiert. |
| **`pull($message = 'Save local changes before pull')`** | **Stellt sicher, dass der Checkout initial vorhanden und nutzbar ist:** Beim ersten Aufruf wird geklont. Existiert der Checkout und enthält Dirty Files, werden diese **zuerst lokal mit der angegebenen Commit-Message committed**, danach werden Remote-Änderungen geholt. **Kein Push.** |
| `commit($message = 'Update workspace')` | Committet lokale Änderungen, ohne Push; ohne Änderungen kein neuer Commit. |
| `push()` | Veröffentlicht lokale Commits im Remote. |
| `sync()` | Führt `pull()` und danach `push()` aus; **auch die durch Pull automatisch erstellten Commits werden gepusht**. |
| `hasUnpushedChanges()` | Erkennt Dirty Files und lokale, noch nicht gepushte Commits. |
| `getFile($path, $revision = 'HEAD')` | Liest den committeten Dateiinhalt als String (auch binär), ohne Checkout oder Index zu ändern. Fehlende Tags werden gezielt von `origin` geholt. |
| `getChangedFiles($revision = null, $targetRevision = null)` | Gibt eine Liste von `ChangeSet`-Objekten mit `filename` (relativer Pfad) und `change` (`created`, `changed`, `deleted`) zurück. Standard: letzter lokal bekannter Stand von `origin/<branch>`; alternativ `HEAD`, Tag oder vollständiger lokaler Commit-Hash. Ohne Zielrevision wird gegen den aktuellen Working Tree verglichen, einschließlich lokaler Commits, staged/unstaged Änderungen und nicht ignorierter neuer Dateien. Mit Zielrevision werden zwei Commit-Stände verglichen. |
| `getHistory()` | Gibt die Commit-Historie zurück. |
| `getBranches()` | Gibt die Branches zurück. |
| `ping($timeout = 5.0)` | Prüft die Erreichbarkeit des Backends. |
| `setCommitUser($name, $email)` | Setzt Author und Committer für spätere Commits, einschließlich automatischer Commits durch `pull()`, auf diesem Repository-Handle. |

**Verzeichnis initial bereitstellen:**

```php
$repo = $client->getRepository('git@github.com:example/repo.git', 'main', 'sites/repo');
$repo->pull(); // Erstes Checkout; bei späteren Aufrufen Dirty Files committen und pullen.
$directory = $repo->getDirectory(); // Jetzt im Production-Modus nutzbar.
```

Bei Bedarf kann die automatische Commit-Message angepasst werden:
`$repo->pull('Save local workspace before updating from origin');`.
Der automatische Commit bleibt lokal, bis `push()` oder `sync()`
ausgeführt wird. Der Worker führt Commit und Pull unter derselben
Repository-Sperre aus. Konflikte bzw. SSH-Fehler können weiterhin zu
einer Fehlermeldung führen; `pull()` verwirft keine Änderungen und
erzwingt keinen Remote-Push. Bei `CONF_MOCK_MODE=true` (serverseitiger
Testbetrieb) wird kein echtes Git ausgeführt; das Verzeichnis muss
dann bereits existieren.

`setCommitUser()` ändert die Identität nicht dauerhaft in der
Git-Konfiguration. Ohne Angabe verwendet der Worker
`MICX <micx@localhost>`. Siehe [das vollständige Beispiel](examples/mixed-repositories.php).

