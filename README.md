# Synology Kachel

Kachel (HTML-SDK) für IP-Symcon 8 zu einer Synology-NAS. Sie baut auf dem Store-Modul **Synology** (timo-u) auf.

## Inhalt

- Status, Modell, DSM-Version, CPU, Arbeitsspeicher, Temperatur, Netzwerk, Laufzeit, DSM-Update, Systemabsturz
- Speicher: Volumes mit Belegung, Speicherpools, Datenträger mit Temperatur und SMART-Status
- Surveillance Station: Home Mode schalten, Kamerastatus, Aufnahme je Kamera starten/stoppen
- USV: Ladestand, Restlaufzeit, Verbindung, DSM-Abschaltregel
- Pakete mit Status (aufklappbar)
- Wartung: Aktualisieren, neu anmelden, Link zum DSM, Neustart und Herunterfahren (nur mit Passwort)

## Passwort für Neustart und Herunterfahren

Neustart und Herunterfahren gehen nur nach Eingabe eines Passworts. Es wird in der Instanz-Konfiguration unter „Passwort setzen“ festgelegt;
gespeichert wird nur der Hash. Ohne Passwort sind beide Funktionen gesperrt. Nach 5 Fehlversuchen ist die Eingabe 5 Minuten gesperrt.

## USV-Alarm

Geht die USV in den Batteriebetrieb, meldet die Kachel einen Stromausfall an die eingestellte Kachel-Visualisierung.
Unterschreiten Ladestand oder Restlaufzeit die Schwellen (oder meldet die USV „Batterie schwach“), folgt ein Alarm.
Die Kachel zeigt dann oben einen Alarmbereich mit der Option, die NAS herunterzufahren. Ist der Strom zurück, kommt eine Info.

## Einrichtung

1. Modul über die Modulverwaltung hinzufügen: `https://github.com/cippo-69/IPSymconSynologyKachel`
2. Instanz „Synology Kachel“ anlegen und mit der vorhandenen **Synology IO** verbinden.
3. Instanzen System, Storage, Surveillance Station und UPS auswählen.

Die Kachel braucht keine eigenen Zugangsdaten: Zusatzdaten fragt sie über die Synology IO ab.

## Farben

Die Kachel hat keine eigenen Farbeinstellungen. Die Farben kommen aus dem Modul [Visu Design](https://github.com/cippo-69/IPSymconVisuDesign) (CSS-Variablen `--w5-*`, heller Modus und Dark Mode). Ohne Design-Instanz gilt das Farbkonzept W5x. Ein Theme-Wechsel kommt ohne Neuladen an: Die Kachel abonniert die Einstellungen der Design-Instanz und schickt das Design-CSS mit jedem Zustand mit (auch an zwischengespeicherte Kacheln).

## Lizenz

MIT
