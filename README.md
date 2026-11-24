# MICX Sec VCS SDK

PHP SDK für den Zugriff auf Git-Repositories über einen isolierten VCS-Worker.
Die Anwendung benötigt keinen Git-SSH-Key: Er liegt als Secret ausschließlich
beim Worker. Zwischen Anwendung und Worker liegt RabbitMQ; die Dateien
stehen über ein gemeinsam gemountetes Volume bereit.

Das gleiche API funktioniert lokal ohne Worker. Mock-Verzeichnisse können
direkt im Anwendungsrepository liegen. Dadurch ist zwischen Entwicklung
und Produktion keine eigene Git-Implementierung oder aufwendige
Fallunterscheidung erforderlich.

## Verwendung

```php
use Micx\Vcs\MixSecVcsClient;

const DEVELOPMENT_MODE = true; // In Produktion false setzen.

$manager = new MixSecVcsClient(
    sharedDirectory: '/vcs-share',
    connector: 'rabbitmq://user:password@rabbitmq:5672/micx.vcs.v1',
);
$manager->addMockDir(
    'git@github.com:example/repo1.git',
    'main',
    __DIR__ . '/mock-repos/repo1'
);

// Mappings bleiben in beiden Umgebungen im Code; nur im Dev-Modus nutzen.
$manager->setAllowMockDir(DEVELOPMENT_MODE);

// Repo 1 verwendet im Development-Modus das registrierte Mock-Verzeichnis.
$repo = $manager->getRepository('git@github.com:example/repo1.git', 'main');

// Ohne Mock-Mapping bestimmt der dritte Parameter das Remote-Zielverzeichnis.
$remote = $manager->getRepository('git@github.com:example/repo2.git', 'main', 'sites/repo2');
$directory = $remote->getDirectory(); // /vcs-share/sites/repo2, auch vor dem Pull
```

Das Verzeichnis `/vcs-share` muss existieren und direkt als Root-Mount
im App-Container bereitstehen. Im Worker ist dasselbe Volume unter
`/data` eingebunden. Auch ein Mock-Verzeichnis muss bereits existieren.

Der RabbitMQ-URL-Pfad bezeichnet den Topic-Exchange. Benutzername und
Passwort dürfen im Connection-String stehen, wenn RabbitMQ ausschließlich
über ein abgeschottetes privates Docker-Netzwerk erreichbar ist. Zugangsdaten
dürfen dennoch nicht in Git eingecheckt oder protokolliert werden.

**Mock-Modus ist standardmäßig aus.** Registrierte Verzeichnisse bleiben
auch in Produktion im Code, werden aber nur bei
`setAllowMockDir(DEVELOPMENT_MODE)` mit `DEVELOPMENT_MODE = true` verwendet.
Andernfalls nimmt der Manager ausschließlich gültig formatierte
Git-Remote-URLs an; lokale Pfade und `file://` werden abgelehnt.
Ohne RabbitMQ kann lokal auch `new MixSecVcsClient()` verwendet werden.

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


## Docker-Worker

Der Docker-Worker ist unter `container/` mit eigener `composer.json` enthalten.
Die Root-`composer.json` ist das Client-Paket.

### Serverseitiger Mock Mode

`CONF_VCS_MOCK_MODE=true` auf dem Worker simuliert Git-Operationen
zu Testzwecken: Requests werden einschließlich URL, Branch und Pfad geprüft,
`pull` erstellt das Arbeitsverzeichnis im Shared Volume, doch kein Git
oder SSH wird gestartet. Es entstehen keine `.git`-Metadaten oder Commits.
Die RPC-Response meldet `mockMode: true`, auch bei Fehlern. Der Client
kann den Modus nicht ändern. Er unterscheidet sich von den lokalen
Client-Mocks mit `VcsMockRepository`.

| Worker-Variable | Standard | Beschreibung |
|---|---|---|
| `CONF_VCS_MOCK_MODE` | `false` | Bei `true` keine Git-Ausführung; nur Tests |
| `VCS_DATA_DIR` | `/data` | Shared Checkout-Volume |
| `CONF_AMQ_CONNECTION` | `rabbitmq://micx:micx@rabbitmq:5672/` | RabbitMQ-URI |

[Container-Dokumentation](container/README.md).
