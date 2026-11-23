# MICX Sec VCS

Docker-Worker für Git-Operationen über RabbitMQ. Repository-URL, Branch und
Checkout-Verzeichnis werden vom Client festgelegt; der SSH-Key bleibt im
Worker. Die PHP-Nutzung steht im
[SDK-Beispiel](https://github.com/micx-io/micx-sec-vcs-sdk/blob/main/examples/mixed-repositories.php)
und in der [SDK-README](https://github.com/micx-io/micx-sec-vcs-sdk).

## Docker Swarm

Das [Stack-Beispiel](stack.example.yml) startet RabbitMQ und den VCS-Worker
ohne veröffentlichte Ports. Es verwendet das veröffentlichte Image
`ghcr.io/micx-io/micx-sec-vcs:latest`.

Vor dem Deploy den SSH-Private-Key als Docker-Secret anlegen:

```sh
docker swarm init
docker secret create vcs_ssh_key /path/to/id_ed25519
docker stack deploy -c stack.example.yml micx
```

Die im Beispiel eingesetzten RabbitMQ-Zugangsdaten `micx/change-me`
sind nur Platzhalter und müssen vor dem Einsatz ersetzt werden.
Der Beispielstack setzt beide Services auf einen Manager-Node, weil die
lokalen Docker-Volumes nicht automatisch zwischen Swarm-Nodes geteilt
werden. Der Client benötigt dasselbe `vcs-data`-Volume, beispielsweise
unter `/vcs-share`; bei mehreren Nodes wird ein gemeinsames
Filesystem mit funktionierendem `flock` benötigt. Das Verzeichnis
`/data` enthält Checkouts einschließlich `.git` und Git-Locks.

### Environment des Workers

| Variable | Beispiel | Zweck |
|---|---|---|
| `CONF_AMQ_CONNECTION` | `rabbitmq://micx:change-me@rabbitmq:5672/` | Einzige RabbitMQ-Verbindungskonfiguration |
| `VCS_SSH_KEY_FILE` | `/run/secrets/vcs_ssh_key` | Pfad zum Docker-SSH-Secret |
| `VCS_DATA_DIR` | `/data` (Standard) | Gemeinsames Checkout-Volume |

Die Connection-URI akzeptiert `rabbitmq://` und `amqp://`. Der Pfad
steht für den **RabbitMQ-VHost** (Standard `/`), nicht für den
RPC-Exchange `micx.vcs.v1`. Sonderzeichen in Credentials und VHost
müssen URL-encoded sein. `amqps://` wird derzeit nicht unterstützt.

### Berechtigungen der Checkouts

Der Worker ändert weder Eigentümer noch Dateiberechtigungen im gemeinsamen
Volume. Vor Git-Operationen auf bestehenden Checkouts sowie nach dem ersten
Checkout und einem erfolgreichen `pull` prüft er, ob sein Prozess die
Verzeichnisse lesen, beschreiben und betreten sowie reguläre Dateien lesen und
beschreiben kann. Dateien unter `.git/objects/` müssen nur lesbar sein:
Git speichert unveränderliche Objekte üblicherweise schreibgeschützt, etwa
mit `0444`. Bei unzureichenden Rechten meldet der Worker einen `IO_ERROR`
mit dem betroffenen Dateipfad beziehungsweise Verzeichnispfad. Symlinks
werden nicht verfolgt.

Der Worker läuft standardmäßig als UID/GID `10001:10001` und verwendet
`umask 0000` für Git-Operationen. Neue Checkouts werden dadurch im
Normalfall für alle Benutzer lesbar und beschreibbar angelegt:
reguläre Dateien mit `0666`, Verzeichnisse mit `0777` und ausführbare
Dateien mit `0777`. Programme (einschließlich Git) können für einzelne
Dateien oder Metadaten ausdrücklich restriktivere Modi vorgeben; die
`umask` garantiert daher nicht für jeden Pfad diese Berechtigungen.

Andere Container und Prozesse, die in dieses Volume schreiben, müssen
ebenfalls auf world-readable und world-writable Berechtigungen achten.
Dafür sollte der jeweilige Schreibprozess `umask 0000` verwenden, damit
neu angelegte Dateien normalerweise `0666` und Verzeichnisse `0777`
erhalten. Eine abweichende `umask` (etwa `0022` oder `0007`) kann dazu
führen, dass der VCS-Worker neue oder geänderte Dateien nicht mehr
beschreiben kann. Die `umask` ändert bestehende Berechtigungen nicht.

Der private Lock-Ordner `.mix-vcs-locks` ist von der Checkout-Prüfung
ausgenommen. Die SSH-Schlüssel werden getrennt mit restriktiven Rechten
(`0600`) angelegt.

### Temporäre SSH-Host-Keys

Der Worker legt bei jedem Containerstart eine **leere** Datei
`/run/micx/known_hosts` im tmpfs an. Bei der ersten SSH-Verbindung
werden unbekannte Host-Keys mit `StrictHostKeyChecking=accept-new`
aufgenommen. Ein bereits bekannter, danach geänderter Host-Key wird
abgewiesen. Beim Neustart des Containers wird die temporäre Host-Liste
verworfen und neu angelegt. Es gibt weder ein persistent gespeichertes
`known_hosts`-Secret noch eine eigene Environment-Option dafür.

**Sicherheit:** Der erste Host-Key wird ohne vorherigen
Authentizitätsnachweis akzeptiert (Trust on First Use). Das eignet sich
nur für vertrauenswürdige Netzwerkumgebungen; ein Angreifer beim ersten
Verbindungsaufbau könnte einen falschen Key präsentieren.

## RPC und Fehlerbehandlung

Die Methoden `ping`, `pull`, `push`, `commit`, `status`, `log`
und `branches` entsprechen dem SDK. `commit` akzeptiert zusätzlich
`authorName` und `authorEmail` (zusammen mit `message`); der Worker
verwendet beide Angaben ausschließlich für den betreffenden Git-Commit
als Author und Committer. Ohne Angaben bleibt die Identität
`MICX <micx@localhost>`. Die Einstellung erfolgt über das SDK mit
`$repo->setCommitUser($name, $email)`. Jeder Aufruf enthält Repository-URL,
Branch und relativen Checkout-Pfad. Es gibt keinen State-Mount, keine
Workspace-Registry und kein Request-Journal.

Normale Git-Fehler liefern eine RPC-Fehlerantwort und werden nach
bestätigter Antwort mit RabbitMQ-`ack` abgeschlossen; fehlerhafte
Nachrichten werden nicht wieder in die Queue gestellt. Ein
Verbindungsabbruch vor `ack` kann jedoch zur erneuten Zustellung führen.

Weitere technische Details:
[RPC-Protokoll](docs/2026-09-12-secure-vcs-rpc.md).
