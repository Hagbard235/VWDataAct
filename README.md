# VW EU Data Act Telemetry - IP-Symcon Modul (PHP SDK)

Vollständiges IP-Symcon Modul (ab IPS 7.0+) zur Verarbeitung, Diagnose und Visualisierung von Telemetrie-Exports aus dem **VW EU Data Act Portal** (für Volkswagen, Cupra, SEAT, Audi, Skoda).

---

## Features

- **Memory ZIP Ingestion**: Entpackt automatisiert ZIP-Exporte im Arbeitsspeicher (unterstützt Dateipfad zu `.zip` oder Ordnerpfad mit autom. Erkennung des neuesten Telemetrie-Exports).
- **Variablen- & Profil-Management**: Legt automatisch alle benötigten Variablenprofile (`kW`, `kWh`, `km`, `bar`, `V`, `A`, `mV`) und Variablen in IP-Symcon an.
- **6 Datenbereiche / Cluster**:
  1. **Hochvolt-Akku & PV-Laden**: SoC, Ladeleistung, Laderate, Restladezeit, geladene Energie, Netzströme L1-L3, Stecker & Klappe.
  2. **12V-Bordnetz & Akku-Diagnose**: 12V-Spannung, 12V-Strom, BEM Level, HV-Max/Min-Zelldrift ($\Delta V$ in mV), Akkutemperaturen.
  3. **Sicherheit & Status**: Schlösser & Offen-Status (4 Türen, Kofferraum, Motorhaube), Fensteröffnungsgrad, Licht.
  4. **Reifendruck**: Ist- & Soll-Druck für alle 4 Räder.
  5. **Klima & Komfort**: Außen- & Innenraumtemperatur, Scheibenheizungen, Standklima Restzeit & Verbraucherleistung.
  6. **Laufleistung & Wartung**: Kilometerstand, Reichweite, Inspektions-Countdown, Systemfehlercodes.
- **Moderne Kachel-Visualisierung (HTMLBox / HTMLSDK)**: Ansprechendes Dark-Mode Dashboard für WebFront & Tile Visu mit radialer SoC-Anzeige, Fahrzeugsilhouette, Reifendruck-Karte und Diagnose-Widgets.

---

## Installation in IP-Symcon

### Automatisch über den IP-Symcon Module Store / Git:
1. Öffne die IP-Symcon Verwaltungskonsole.
2. Navigiere zu **Kern-Instanzen** -> **Module**.
3. Klicke auf **Hinzufügen** und gib folgende Repository-URL ein:
   `https://github.com/Hagbard235/VWDataAct`
4. Erstelle eine neue Instanz des Moduls **"VW EU Data Act Telemetry"**.

---

## Konfiguration

In der Modulinstanz folgende Einstellungen festlegen:
- **ZIP-Dateipfad oder Ordnerpfad**: Pfad zur heruntergeladenen `.zip` Datei oder Ordner, in den Exporte abgelegt werden.
- **Import Intervall**: Ingestion-Intervall in Minuten (Standard: `15 min`).
- **Feature-Toggles**: Datenbereiche je nach Wunsch aktivieren/deaktivieren.
- **Buttons**:
  - `Jetzt Importieren & Aktualisieren`: Manuelle Aktualisierung der Daten.
  - `Visualisierung Neu Rendern`: Erneuert das Kachel-Dashboard.

---

## License

MIT License. Developed for IP-Symcon 7.0+.
