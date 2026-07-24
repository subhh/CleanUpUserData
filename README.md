# CleanUpUserData
Dieses Modul stellt einen Konsolenbefehl bereit, der bei veraltete Benutzerdaten die persönlichen Angaben der Nutzenden in der Datenbank bereinigt.

## Beschreibung
Das CleanUpUserData-Modul erweitert VuFind um einen Wartungsbefehl zur Datenbankbereinigung. Die Hauptfunktionen umfassen:

- Bereinigen von Benutzerdatensätzen, deren letzter Login älter als eine definierte Stundenanzahl ist
- Konfigurierbare Zeitgrenze über den Parameter `--hours` (Standardwert: 24 Stunden)
- Automatisches Abmelden betroffener Nutzer (Invalidieren einer eventuell noch aktiven Session), bevor ihre Daten bereinigt werden
- Bereitstellung eines Bash-Skripts für den einfachen Einsatz als Cronjob

## Installation
### Voraussetzungen
- VuFind 9.0 oder höher
- PHP 7.0 oder höher

### Installation
1. Integrieren Sie das Modul im `module`-Verzeichnis von VuFind.
2. Aktivieren Sie das Modul, indem Sie `CleanUpUserData` zur Liste der aktiven Module hinzufügen (z. B. in der Umgebungsvariable `VUFIND_LOCAL_MODULES`).

### Konfiguration
Das Modul bietet eine Standardkonfiguration, die in das `local`-Verzeichnis von VuFind kopiert werden kann (`config/vufind/CleanUpUserData.ini`). Darin kann der Standwert für die Zeitgrenze in Stunden angepasst, sowie die zu bereinigenden Felder konfiguriert werden.

```
[Global]
default_hours = 24

[Logout]
enabled = true

[CleanUp]
firstname     = ''
lastname      = ''
cat_pass_enc  = ''
created       = '2000-01-01 00:00:00'
last_language = ''
email         = ''
```

### Abmelden betroffener Nutzer (`[Logout]`)
Standardmäßig (`enabled = true`) wird für jeden Nutzer, dessen Daten bereinigt werden, versucht, eine eventuell noch aktive Session zu invalidieren, damit er nicht mitten in seiner Sitzung mit bereinigten Profildaten weiterarbeitet. Da keines der VuFind-Session-Backends Sessions anhand einer Nutzer-ID nachschlagen kann, wird dazu je nach konfiguriertem `[Session] type` in `config.ini` vorgegangen:

- **File** und **Database**: Sessions werden durchsucht (Verzeichnis-Scan bzw. Tabellen-Scan) und bei Treffer gezielt zerstört.
- **Memcache** oder wenn `[Session] secure = true` gesetzt ist: eine gezielte Zuordnung ist technisch nicht zuverlässig möglich (keine Schlüsselauflistung bzw. verschlüsselte Session-Daten) — es wird lediglich eine Warnung ausgegeben, die betroffene Session bleibt unangetastet, die übrige Datenbereinigung läuft normal weiter.

Über `enabled = false` kann das automatische Abmelden deaktiviert werden.

## Verwendung
### Konsolenbefehl
Der Befehl kann direkt über die VuFind-Konsole aufgerufen werden, sofern die Umgebungsvariablen `VUFIND_HOME` und `VUFIND_LOCAL_DIR` gesetzt sind.

```bash
php public/index.php util/cleanup_user_data
```

Mit optionalem `--hours`-Parameter:

```bash
php public/index.php util/cleanup_user_data --hours 48
```

### Parameter
| Parameter | Beschreibung                                                                                 | Standardwert |
|-----------|----------------------------------------------------------------------------------------------|--------------|
| `--hours` | Zeitraum in Stunden. Benutzerdaten, deren letzter Login länger zurückliegt, werden bereinigt | 24 |

### Bash-Skript
Im Verzeichnis `scripts/` befindet sich das Skript `clean_up_user_data.sh`, das die notwendigen Umgebungsvariablen setzt und den Befehl ausführt. Innerhalb des Scripts müssen dafür zunächst die Umgebungsvariablen `VUFIND_HOME` und `VUFIND_LOCAL_DIR` an die lokale Umgebung angepasst werden.

```bash
# Ohne Parameter (Standard: 24 Stunden)
./scripts/clean_up_user_data.sh

# Mit benutzerdefinierter Stundenanzahl
./scripts/clean_up_user_data.sh 48
```

Das Skript setzt folgende Umgebungsvariablen:
- `VUFIND_HOME`: Pfad zur VuFind-Installation
- `VUFIND_LOCAL_DIR`: Pfad zur lokalen Konfiguration
- `VUFIND_LOCAL_MODULES`: Aktivierte lokale Module

Das Skript eignet sich für den Einsatz als Cronjob, um die Datenbankbereinigung regelmäßig und automatisiert durchzuführen.

## Technische Details
### Modulstruktur
```
CleanUpUserData/
├── config/
│   ├── vufind
│   │   └── CleanUpUserData.ini                        # Beispielkonfiguration
│   └── module.config.php                              # Modulkonfiguration
├── scripts/
│   └── clean_up_user_data.sh                          # Bash-Skript für den Aufruf
├── src/CleanUpUserData/
│   ├── Command/Util/
│   │   ├── CleanUpUserDataCommand.php                 # Konsolenbefehl
│   │   └── CleanUpUserDataCommandFactory.php          # Factory für den Konsolenbefehl
│   └── Session/
│       ├── SessionInvalidator.php                     # Abmelden betroffener Nutzer
│       └── SessionInvalidatorFactory.php              # Factory für den SessionInvalidator
├── tests/unit-tests/src/CleanUpUserData/
│   ├── Command/Util/
│   │   └── CleanUpUserDataCommandTest.php              # Test für den Konsolenbefehl
│   └── Session/
│       └── SessionInvalidatorTest.php                  # Test für den SessionInvalidator
├── .gitignore
├── Module.php                                         # Modulklasse
└── README.md
```

### Befehlsname
- `util/cleanup_user_data`
