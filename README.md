# VW EU Data Act Telemetry - IP-Symcon Modul (PHP SDK)

Vollständiges IP-Symcon Modul (ab IPS 7.0+) zur manuellen und **vollautomatisierten** Verarbeitung, Diagnose und Visualisierung von Telemetrie-Exports aus dem **VW EU Data Act Portal** (`eu-data-act.drivesomethinggreater.com`) für VAG-Fahrzeuge (Volkswagen, Cupra, SEAT, Audi, Skoda).

---

## Features

- **Automatischer Portal-Download (OIDC Auth)**: Authentifiziert sich automatisch beim VW Data Act Portal mit deinem Markenkonto (VW ID, myAudi, Cupra, SEAT ID), lädt den neuesten Telemetrie-Export (`.zip`) herunter und aktualisiert alle IP-Symcon Variablen.
- **Memory ZIP Ingestion**: Entpackt automatisiert ZIP-Exporte im Arbeitsspeicher (unterstützt auch lokale Ordnerpfade oder `.zip` Dateien).
- **Variablen- & Profil-Management**: Legt automatisch alle benötigten Variablenprofile (`kW`, `kWh`, `km`, `bar`, `V`, `A`, `mV`) und Variablen in IP-Symcon an.
- **100% Datenabdeckung über 6 Cluster**:
  1. **Hochvolt-Akku & PV-Laden**: SoC, Ladeleistung, Laderate, Restladezeit, geladene Energie, Netzströme L1-L3, Stecker & Klappe.
  2. **12V-Bordnetz & Akku-Diagnose**: 12V-Spannung, 12V-Strom, BEM Level, HV-Max/Min-Zelldrift ($\Delta V$ in mV), Akkutemperaturen.
  3. **Sicherheit & Status**: Schlösser & Offen-Status (4 Türen, Kofferraum, Motorhaube), Fensteröffnungsgrad, Licht.
  4. **Reifendruck**: Ist- & Soll-Druck für alle 4 Räder.
  5. **Klima & Komfort**: Außen- & Innenraumtemperatur, Scheibenheizungen, Standklima Restzeit & Verbraucherleistung.
  6. **Laufleistung & Wartung**: Kilometerstand, Reichweite, Inspektions-Countdown, Systemfehlercodes.
- **Moderne Kachel-Visualisierung (HTMLBox / HTMLSDK)**: Ansprechendes Dark-Mode Dashboard für WebFront & Tile Visu.

---

## Installation in IP-Symcon

1. Öffne die IP-Symcon Verwaltungskonsole.
2. Navigiere zu **Kern-Instanzen** -> **Module**.
3. Klicke auf **Hinzufügen** und gib folgende Repository-URL ein:
   `https://github.com/Hagbard235/VWDataAct`
4. Erstelle eine neue Instanz des Moduls **"VW EU Data Act Telemetry"**.

---

## Konfiguration

In der Modulinstanz kannst du zwischen zwei Betriebsmodi wählen:

### 1. Automatischer Portal-Download (Empfohlen ⭐)
- **Datenquelle**: `Automatischer Download vom Portal (drivesomethinggreater.com)`
- **Marke**: Volkswagen, Audi, Škoda, SEAT oder CUPRA – bestimmt, mit welchem Markenkonto sich das Modul anmeldet.
- **E-Mail & Passwort**: Deine VW ID / myAudi / Cupra / SEAT ID Zugangsdaten.
- **Fahrzeug-ID (VIN)**: Deine Fahrzeug-Identifizierungsnummer (leer lassen, wenn nur ein Fahrzeug im Portal verknüpft ist).
- **Import Intervall**: z. B. `15 min` (Das Modul prüft periodisch, ob das Portal neue Datensätze bereitgestellt hat).

**Einmalige Voraussetzung im Browser:** im Portal anmelden, die Einwilligung bestätigen, das Fahrzeug verknüpfen und eine fortlaufende Datenanfrage aktivieren.

**So arbeitet der Abruf:** Das Portal legt nur dann einen Datensatz ab, wenn am Fahrzeug etwas passiert; längere Pausen sind normal. Die Datensätze enthalten jeweils nur einen Teil der Felder. Das Modul importiert deshalb bei jedem Lauf alle neuen Datensätze der Reihe nach (beim ersten Lauf die neuesten 8) und lässt Variablen, die in einem Datensatz fehlen, unverändert. `Letztes Update` zeigt den Zeitpunkt, zu dem das Portal den neuesten importierten Datensatz bereitgestellt hat. Die geladenen ZIPs liegen in `user/vwdataact_exports`. Der Button „Portal-Datensätze neu einlesen“ importiert die neuesten 8 Datensätze erneut.

Der Anmelde- und Abrufablauf folgt der Umsetzung in [evcc](https://github.com/evcc-io/evcc) (`vehicle/vw/eudataact`).

### 2. Ordner / Lokale ZIP-Datei
- **Datenquelle**: `Ordner / Lokale ZIP-Datei`
- **Dateipfad**: Pfad zur `.zip` Datei oder Ordnerpfad (z. B. `user/` im Docker-Container).

---

## License

MIT License. Developed for IP-Symcon 7.0+.
