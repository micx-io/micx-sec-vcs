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

Nach jedem erfolgreichen ersten Checkout und jedem späteren `pull` setzt der
Worker Verzeichnisse einschließlich `.git` auf `0777` und reguläre Dateien
auf `0666`. Bereits ausführbare Dateien bleiben ausführbar (`0777`).
Auch die übergeordneten Verzeichnisse bis einschließlich `VCS_DATA_DIR`
werden auf `0777` gesetzt, damit andere Container-Benutzer den Checkout
erreichen können. Symlinks werden dabei nicht verfolgt.

**Sicherheit:** Das gemeinsame Daten-Volume darf nur vertrauenswürdigen
Containern zugänglich sein: Andere Benutzer können sowohl Arbeitsdateien
als auch Git-Metadaten verändern. Neu angelegte oder von externen
Prozessen geänderte Dateien werden erst beim nächsten erfolgreichen
`pull` erneut angepasst. Der private Lock-Ordner `.mix-vcs-locks` bleibt
von dieser Anpassung ausgenommen.

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
