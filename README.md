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
$repo->pull();                       // initialisiert bzw. aktualisiert Checkout
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

Siehe das [vollständige PHP-Beispiel](examples/mixed-repositories.php)
und die [Container-Dokumentation mit Docker-Swarm, Volumes,
Berechtigungen und Startup-Logs](container/README.md).

## Repository-API

`getRepository($url, $branch, $directory)` gibt sofort einen passiven Handle
zurück; `$directory` ist ein relativer Pfad unter `sharedDirectory` und kann
weggelassen werden. Standardmäßig bestimmt das Manager-Template
`{repo}/{branch}` den Pfad; es lässt sich über
`storageTemplate: 'sites/{repo}/{branch}'` ändern. `{repo}` ist der
Repository-Basename ohne `.git`. `getDirectory()` liefert immer den
absoluten Pfad, auch vor dem ersten Checkout, ohne RPC oder Dateisystemzugriff.
Erst `pull()` legt das Repository an bzw. aktualisiert es. Bei jedem RPC
sendet das SDK URL, Branch und das relative Zielverzeichnis. Es gibt keine
Workspace-Registry, kein Server-Template und keinen vorbereitenden Ping.
`sync()` führt Pull und Push aus, ohne automatisch zu committen.

Weitere Methoden: `ping($timeout = 5.0)` (Mock: sofort `true`),
`setCommitUser($name, $email)`, `commit()`, `push()`,
`hasUnpushedChanges()`, `getHistory()`, `getBranches()`. Alle Backends implementieren
`Micx\Vcs\Repository\VcsRepository`.

`setCommitUser()` setzt die Git-Identität für spätere `commit()`-Aufrufe
am aktuellen Repository-Handle. Dabei erfolgt kein RPC. Erst `commit()`
überträgt `authorName` und `authorEmail` mit der Commit-Nachricht.
Beide Werte gelten sowohl für den Git-Author als auch den Committer;
ohne explizite Einstellung verwendet der Worker `MICX <micx@localhost>`.

Siehe [das einzige vollständige Beispiel](examples/mixed-repositories.php).


