# MICX Sec VCS – RPC-Protokoll

> Diese Fassung beschreibt den auf die öffentliche SDK-API reduzierten Worker.
> Frühere Workspace-IDs, serverseitige Templates und Request-Journale entfallen.

## § 1 Ziel

Ein Worker führt Git-Befehle im Auftrag des SDK über RabbitMQ aus. Der
Client bestimmt Repository-URL, Branch und relativen Checkout-Pfad vollständig.
Jeder RPC enthält diese drei Parameter, damit weder eine Workspace-Registry
noch ein vorbereitender Checkout-/Status-Ping nötig ist.

## § 2 Volumes und Git-Metadaten

Der Worker besitzt nur den persistenten Daten-Root `VCS_DATA_DIR`
(Standard: `/data`). Im SDK wird dasselbe Volume unter
`sharedDirectory` (beispielsweise `/vcs-share`) eingebunden.
Der Client wählt `directory` relativ zum Volume. Das Standard-Template
`{repo}/{branch}` wird ausschließlich vom SDK ausgewertet.

Beispiel: `directory=site/main` bezeichnet im Worker
`/data/site/main` und in der Anwendung `/vcs-share/site/main`.
Der Pfad ist ohne RPC bekannt; erst `pull` legt den Checkout an.

Git-Daten liegen in `site/main/.git`. Verzeichnissperren liegen im
gemeinsamen Volume unter `/data/.mix-vcs-locks`; ein privates
State-Volume, die Workspace-JSON-Registry, Request-Journale und
`VCS_PATH_TEMPLATE` werden nicht mehr verwendet. Ein vorhandenes
Checkout wird anhand der URL in `origin` und des aktiven Branches
geprüft. Pfad-Traversal und Symlinks im Verzeichnis werden abgewiesen.

Das Shared Volume darf nur vertrauenswürdigen Anwendungen bereitgestellt
werden, da ihnen jetzt auch die lokalen Git-Metadaten zugänglich sind.
Der SSH-Key bleibt beim Worker unter `/run/micx`; `known_hosts` wird dort
als leere Datei im tmpfs bei jedem Containerstart neu angelegt.
SSH akzeptiert unbekannte Host-Keys beim ersten Kontakt und prüft sie
für die Dauer des Container-Lebenszyklus gegen diese temporäre Liste.

## § 3 RPC-Form

RabbitMQ verwendet Exchange `micx.vcs.v1`, Routing-Key
`rpc.request`, die Queue `micx.vcs.v1.requests` und eine
korrelierte Reply-Queue.

```json
{
  "version": 1,
  "id": "request-12345678",
  "method": "status",
  "params": {
    "url": "git@github.com:example/site.git",
    "branch": "main",
    "directory": "site/main"
  }
}
```

Antwort bei Erfolg:

```json
{
  "version": 1,
  "id": "request-12345678",
  "ok": true,
  "result": {"hasUnpushedChanges": false}
}
```

Fehler enthalten stattdessen `ok: false` und ein `error`-Objekt mit
`code`, `message` und `details`. Git-stderr und SSH-Secrets werden
nicht an Clients durchgereicht.

## § 4 Unterstützte Funktionen

| Methode | Zusätzliche Parameter | Ergebnis |
|---|---|---|
| `ping` | — | `pong`, `ready`, `errors` |
| `pull` | — | `revision`; erstellt Checkout bei Bedarf |
| `push` | — | `revision` |
| `commit` | `message?`, `authorName?`, `authorEmail?` | `revision`; erzeugt keinen Leer-Commit |
| `status` | — | `revision`, `hasUnpushedChanges` |
| `log` | — | `entries` |
| `branches` | — | `branches` |

`authorName` und `authorEmail` müssen gemeinsam angegeben werden.
Sie setzen nur für diesen `commit` den Git-Author und Committer; die
Identität wird nicht in der Git-Konfiguration gespeichert. Ohne beide
Felder verwendet der Worker `MICX <micx@localhost>`.

`sync()` ist eine SDK-Operation aus `pull()` und `push()` und kein
zusätzlicher Worker-RPC. Es gibt keine RPC-Methoden für allgemeine
Dateizugriffe, Archivexport, Branch-Erstellung, Merge oder Release.

`pull` erwartet bei bestehendem Checkout einen sauberen Git-Worktree.
Bei Merge-Konflikten wird die lokale Seite bevorzugt; andere
Merge-Fehler werden gemeldet. Push erfolgt ohne Force.

## § 5 Fehlgeschlagene Aufträge und Wiederholungen

Normale Validierungs- und Git-Fehler werden als RPC-Fehlerantwort gesendet.
Sobald deren Publikation bestätigt ist, bestätigt der Worker die
eingegangene Nachricht per `ack` – **auch bei einem Git-Fehler**.
Ungültige Queue-Nachrichten werden mit `reject(requeue=false)` verworfen.
Fehlgeschlagene Git-Operationen bleiben deshalb nicht in einer
automatischen Wiederholungsschleife und blockieren die Queue nicht.

Es gibt **kein persistentes Request-Journal und keine Deduplizierung
über Request-IDs**. Bricht die Brokerverbindung oder der Worker vor
dem Request-`ack` ab, ist erneute Zustellung möglich. Die Operation
kann dann erneut ausgeführt werden. Das SDK wartet standardmäßig
60 Sekunden, Git-Prozesse höchstens 45 Sekunden; ein Timeout belegt
nicht, ob Git tatsächlich abgeschlossen wurde.

Bei unklarem Ergebnis vor einem erneuten Commit/Pull/Push den Git-Zustand
abgleichen. Die Sperren unter `/data/.mix-vcs-locks` serialisieren
gleichzeitige Operationen auf demselben Zielverzeichnis zwischen
Worker-Instanzen mit gemeinsamem Volume und verlässlichem `flock`.

## § 6 Sicherheit und Betrieb

Nur validierte Git-SCP-URLs `git@host:owner/repository.git` sind
derzeit für den Worker erlaubt. Der Worker erzeugt keinen beliebigen
Shell-Befehl. SSH nutzt `StrictHostKeyChecking=accept-new` und die
konfigurierten Secrets; Git-Hooks und Submodule werden deaktiviert.

Der Container läuft unter UID/GID 10001, mit read-only
Root-Dateisystem, ohne veröffentlichte Ports und ohne Linux-Capabilities.
Ein Supervisor startet einen abgebrochenen Worker erneut.
Container- und Broker-Replikate benötigen einen gemeinsamen
Daten-Mount mit verlässlichen Locks.

## § 7 Prüfungen

Die CI prüft Syntax, PHPUnit einschließlich lokaler Git-Operationen,
RabbitMQ-Integration mit zwei Workern und Docker-Build.
Ein echter Git-SSH-Remote muss für eine produktive Freigabe separat
validiert werden.

## § 8 Referenzen

- [SDK](https://github.com/micx-io/micx-sec-vcs-sdk)
- [RabbitMQ Consumer Acknowledgements](https://www.rabbitmq.com/docs/confirms)
- [Git-Konfiguration](https://git-scm.com/docs/git-config)
