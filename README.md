# MICX Sec VCS

Leichter PHP-CLI-Container für Git-RPC über RabbitMQ. SSH-Key und Git-Metadaten bleiben privat; Anwendungen erhalten Arbeitsverzeichnisse, Dateien oder ZIP-Revisionen. Erste Implementierung, noch keine produktive Betriebsfreigabe.

## Start

Voraussetzungen: Docker Compose, ein passender SSH-Key und unabhängig geprüfte SSH-Hostschlüssel. Beide Dateien werden außerhalb des Repositories vorbereitet:

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

4 MiB JSON pro Nachricht, 2 MiB Datei-/ZIP-Payload, maximal 100 Dateien pro Transfer. ZIP/Snapshot exportieren committed HEAD. Es gibt keine Exactly-once-Garantie für Remote-Pushes; unterbrochene Operationen werden ausdrücklich als unklar gemeldet. Mehrere Worker benötigen dieselben `/data`- und `/state`-Volumes mit verlässlichem `flock`.

## Entwicklung

```sh
composer install
composer test
```

PHP 8.3+, YAML-Erweiterung, Git. Die CI prüft PHP-Syntax, PHPUnit und Docker-Build. Keine Schlüssel für Unit- und lokale Git-Tests erforderlich. Secrets sind in `.gitignore` ausgeschlossen und werden nicht in den Build-Kontext aufgenommen.
