# Worker-Anzahl, Supervisor und Repository-Sperren

| Datum | Benutzername | Kurzbeschreibung |
|---|---|---|
| 2026-09-12 | dermatthes | §§ 1–5: Separater Vorschlag zu Worker-Anzahl, Queue-Zustellung und Sperrgrenzen |

## § 1 Empfehlung

Ein aktiver PHP-Worker pro Container; darüber ein kleiner Supervisor als PID 1, der Verbindungsabbrüche und Worker-Enden mit begrenztem Backoff auffängt. Mehr Kapazität über `docker compose up -d --scale vcs=3`. Ein Container enthält damit zwei Verwaltungs-/Arbeitsprozesse sowie vorübergehend Git/SSH-Unterprozesse, aber nur einen aktiven Request-Verarbeiter. Die Empfehlung „ein Prozess pro Container“ ist hier als ein Dienst mit einem Worker zu verstehen, nicht als Verbot eines kleinen Supervisors. [neu]

Der Supervisor und seine Wiederanlaufpausen 1, 2, 4, 8, 16, 30 Sekunden sind Bestandteil der aktuellen Fehlerbehebung. Ein konfigurierbarer interner Worker-Pool ist ausdrücklich nur eine spätere Option dieses Vorschlags und wird hier nicht implementiert. [neu]

## § 2 Anzahl und Zustellung

Alle Worker verwenden genau dieselbe durable Queue `micx.vcs.v1.requests`, keinen eigenen Queue-Namen pro Container. RabbitMQ verteilt Nachrichten unter konkurrierenden Consumern; Prefetch 1 und der synchrone Callback begrenzen jeden Worker auf einen laufenden Request. Drei Container können somit bis zu drei Requests gleichzeitig ausführen. Es gibt keine feste Bindung Worker → Repository; ein Worker kann nach Abschluss ein anderes Repository bearbeiten. [neu]

Ein Request wird bei gewöhnlicher Zustellung einem Consumer zugewiesen. Bei Abbruch vor Ack ist Wiederzustellung möglich, gegebenenfalls während der ursprüngliche Worker noch einen Git-Prozess beendet. Deshalb schützt zusätzlich ein gemeinsames Lock `request:<id>` samt persistentem Ergebnisjournal. Bei vorhandenem Ergebnis wird dieses zurückgegeben; bei begonnenem Request ohne Ergebnis folgt `OUTCOME_UNKNOWN`. Ein anderer Payload mit derselben ID wird mit `ID_REUSED` abgewiesen. Dies ist keine Exactly-once-Garantie für einen externen Git-Server. [neu]

## § 3 Was genau wird gesperrt?

| Bereich | Koordination |
|---|---|
| Derselbe Workspace / Checkout | Alle RPC-Zugriffe über `workspace:<id>`; maximal eine laufende Operation |
| Verschiedene Workspaces | Parallel möglich, auch für dasselbe Remote-Repository |
| Checkout-Anlage / Zielverzeichnisse | Zusätzlich globale Registry-Sperre verhindert überlappende Verzeichnisse |
| Gleichzeitige Wiederholung derselben Request-ID | Request-Lock und Journal verhindern doppelte Ausführung |
| Push aus verschiedenen Workspaces auf denselben Remote-Branch | Git-Server prüft Ref-Update; konkurrierender Push kann abgelehnt werden |
| Direkte Dateizugriffe einer Anwendung auf `/data` | Nehmen nicht automatisch an RPC-Sperren teil; separate Koordination erforderlich |

Revision und Generation werden innerhalb der Workspace-Sperre geprüft: Ein wartender Schreiber mit altem Zustand erhält `CONFLICT`. Nach fünf Sekunden erfolglosem Lock-Versuch folgt `BUSY`; es gibt keine FIFO-Zusage. Lange Arbeit an einem Workspace kann mehrere Worker durch Warten kurzzeitig binden, bevor `BUSY` zurückgegeben wird. [neu]

Eine zusätzliche globale Sperre pro Remote-URL würde auch unabhängige Branches unnötig blockieren. Sie wäre nur bei der ausdrücklich gewünschten fachlichen Regel „jede Aktion am selben Remote vollständig serialisieren“ sinnvoll und müsste alle Workspaces und Push-Ziele einheitlich kanonisieren. Für sichere lokale Arbeitsdateien genügt die Workspace-Sperre; konkurrierende Remote-Pushes bleiben ein sichtbarer Git-Konflikt. [neu]

## § 4 Alternative: Worker-Anzahl im Container

| Variante | Vorteil | Aufwand / Grenze |
|---|---|---|
| Ein Worker je Container, Compose-Skalierung | Klare Ressourcenlimits, Logs, Neustarts und Ausfallisolierung je Worker; passt zum bisherigen Deployment | Jeder Container hat PHP/Git und private Secret-Kopie; identische gemeinsame Volumes nötig |
| `VCS_WORKERS=N` innerhalb eines Containers | Ein Deployment-Objekt, gemeinsame Laufzeitumgebung | Poolverwaltung, Prozess-Reaping, Signalweitergabe, einzelne Worker-Backoffs und gemeinsame Ressourcenlimits; alle Worker vom selben Container-Ausfall betroffen |

Ein interner Pool löst weder Sperren noch RPC-Korrelation besser. Er wäre eine spätere Betriebsoption für Umgebungen ohne Container-Skalierung. Dafür sollten zuerst konkrete Lastdaten und Deployment-Anforderungen vorliegen. Der jetzige Supervisor startet bewusst nie einen zweiten Worker, bevor der vorherige beendet und aufgesammelt wurde. [neu]

## § 5 Betriebsgrenzen und Validierung

Alle Worker benötigen denselben lockfähigen `/state`- und `/data`-Storage. Die aktuelle Zielumgebung ist ein Docker-Host mit gemeinsamem Volume. Mehrere Hosts mit ungeprüftem NFS, unabhängigen Volumes oder verlorenem Journal haben diese Garantien nicht; hierfür wäre eine separate verteilte Koordination zu entwerfen. [neu]

Prüfungen: zwei parallele Dispatcher-Prozesse mit identischer ID, Workspace-Sperren zwischen Prozessen, zwei echte RabbitMQ-Worker mit gleichzeitig eingehenden Requests, absichtlich umgekehrte Antwortreihenfolge zwischen zwei SDK-Clients sowie Supervisor-Backoff und Broker-Wiederherstellung. Ausfälle mitten in einem echten SSH-Push und auf dem vorgesehenen Produktionsstorage bleiben vor produktiver Freigabe separat zu validieren. [neu]
