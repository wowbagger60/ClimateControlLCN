# TemperatureConfiguration

Stellt die Sollwerte für Heizen und Kühlen eines oder mehrerer Räume in einer Kachel ein.

## Funktion

- Jedes Paar zeigt auf zwei Float-Variablen: den Sollwert Heizen und den Sollwert Kühlen eines Raums. Die Instanz speichert selbst keine Werte.
- Bei mehreren Paaren zeigt die Kachel pro Raum eine Zeile mit einer Schiene und zwei Griffen: rot für Heizen, blau für Kühlen. Das Band dazwischen ist der Komfortbereich.
- Bei einem einzigen Paar zeigt die Kachel einen Bogen wie Symcons Thermostat mit beiden Griffen.
- Griff ziehen oder einen Wert antippen und mit − und + ändern. Gesendet wird beim Loslassen des Griffs oder 1,2 s nach dem letzten + oder −.
- Kühlen bleibt immer mindestens den eingestellten Abstand über Heizen. Die Griffe halten an, statt sich gegenseitig zu schieben.
- Mehrere Instanzen können dasselbe Paar zeigen, zum Beispiel eine Seite mit allen Räumen und eine Seite pro Raum. Jede Instanz folgt den Variablen und aktualisiert ihre Kachel bei jeder Änderung.
- Eine Variable mit Aktion (zum Beispiel ein Sollwert von TemperatureControl) wird über ihre Aktion geschrieben, damit ihr Besitzer den Wert weitergeben kann. Andere Variablen werden direkt gesetzt.

## Konfiguration

| Einstellung | Bedeutung |
| --- | --- |
| Sollwert-Paare | Name (optional), Variable Heizen, Variable Kühlen. Ohne Namen zeigt die Kachel den Namen des Objekts über der Heizen-Variable. |
| Heizen von / bis | Bereich des Heizen-Griffs, Standard 10–25 °C |
| Kühlen von / bis | Bereich des Kühlen-Griffs, Standard 20–28 °C |
| Kühlen mindestens über Heizen | Mindestabstand, Standard 1 °C |
| Schritt | Rundung und Schritt von + und −, Standard 0,5 °C |

## PHP-Funktionen

```php
CCTC_SetPair(int $InstanceID, int $Pair, float $Heat, float $Cool): bool  // Paar 0..N
CCTC_GetPairs(int $InstanceID): string                                    // JSON: name, heat, cool
```
