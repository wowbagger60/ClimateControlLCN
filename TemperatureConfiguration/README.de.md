# TemperatureConfiguration

Stellt die Sollwerte für Heizen und Kühlen eines oder mehrerer Räume in einer Kachel ein.

## Funktion

- Jeder Raum zeigt auf eine TemperatureControl-Instanz und zeigt deren Standard-Sollwerte für Heizen und Kühlen. Die Instanz speichert selbst keine Werte.
- Bei mehreren Räumen zeigt die Kachel pro Raum eine Zeile mit einer Schiene und zwei Griffen: rot für Heizen, blau für Kühlen. Das Band dazwischen ist der Komfortbereich.
- Bei einem einzigen Raum zeigt die Kachel einen Bogen wie Symcons Thermostat mit beiden Griffen.
- Griff ziehen oder einen Wert antippen und mit − und + ändern. Gesendet wird beim Loslassen des Griffs oder 1,2 s nach dem letzten + oder −.
- Kühlen bleibt immer mindestens den eingestellten Abstand über Heizen. Die Griffe halten an, statt sich gegenseitig zu schieben.
- Mehrere Instanzen können denselben Raum zeigen, zum Beispiel eine Seite mit allen Räumen und eine Seite pro Raum. Jede Instanz folgt den Standard-Sollwerten und aktualisiert ihre Kachel bei jeder Änderung.
- Geändert wird über CCTEMP_SetDefaults: TemperatureControl setzt beide Werte und speichert die geänderten kurz danach in Registersatz 1.

## Konfiguration

| Einstellung | Bedeutung |
| --- | --- |
| Räume | Name (optional) und die TemperatureControl-Instanz des Raums. Ohne Namen zeigt die Kachel den Namen der Instanz. |
| Heizen von / bis | Bereich des Heizen-Griffs, Standard 10–25 °C |
| Kühlen von / bis | Bereich des Kühlen-Griffs, Standard 20–30 °C |
| Kühlen mindestens über Heizen | Mindestabstand, Standard 1 °C |
| Schritt | Rundung und Schritt von + und −, Standard 0,5 °C |

## PHP-Funktionen

```php
CCTC_SetRoom(int $InstanceID, int $Room, float $Heat, float $Cool): bool  // Raum 0..N
CCTC_GetRooms(int $InstanceID): string                                    // JSON: name, heat, cool
```
