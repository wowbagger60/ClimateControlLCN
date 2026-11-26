# TemperatureControl

Raumthermostat für ein LCN-Klimamodul nach Vorlage „Klima 1.3“: Regler 1 heizt, Regler 2 kühlt.

## Funktion

**Kachel (für alle):**

- Aufbau wie Symcons Thermostat: oben die Raumtemperatur, in der Mitte gross der Sollwert, darunter − und +.
- Der Bogen hat einen Farbverlauf von Blau (unten) nach Rot (oben) und einen Punkt pro Grad. Beim Heizen ist er vom unteren Ende bis zum Griff farbig, beim Kühlen vom oberen Ende bis zum Griff, der Rest ist grau. Eine kleine Marke aussen zeigt den Standard-Sollwert.
- Ein Tipp auf die Raumtemperatur öffnet das eingestellte Ziel, zum Beispiel die Temperatur-Historie.
- Griff ziehen oder − und + drücken. Gesendet wird beim Loslassen oder 1,2 s nach dem letzten + oder −.
- Eine Änderung wirkt wie T± am Taster. Der Raum geht auf manu (Neu laden gesperrt), und der Sollwert wird live gesetzt, nicht in ein Register.
- Im Handbetrieb erscheint der Knopf **Auto**. Er beendet den Handbetrieb, und der Raum lädt sein Register neu.
- Bei ABU B (belegt, Klima aus) oder U (unbelegt) lässt sich nichts einstellen. Der Bogen ist dann grau, ohne Griff und Knöpfe.
- Feuchte (mit Tropfen) und Luftqualität erscheinen unten, wenn Variablen dafür gewählt sind. Gezeigt wird ihr formatierter Wert.
- Ein ABU-Wechsel, ein Reset oder ein Stromausfall beenden den Handbetrieb (in LCN programmiert). Bei einem Gastwechsel ABU deshalb zuerst auf U und danach auf A oder B setzen.

**Standard-Sollwerte (Konfiguration):**

- Die Variablen **Standard Heizen** und **Standard Kühlen** gehören der Instanz. Sie stehen in Registersatz 1 der beiden Regler. Bearbeitet werden sie zum Beispiel mit TemperatureConfiguration.
- LCN speichert nur den aktuellen Sollwert. Die Instanz setzt ihn deshalb, liest ihn zurück und speichert ihn mit der Speichertaste (lang).
- Danach stellt sie den Raum wieder her:
  - **auto:** Die Speichertaste lädt das Register selbst neu. Bei ABU A gilt sofort der neue Standard, bei B und U wieder der Schutzwert.
  - **manu:** Die Instanz liest vorher die Sollwerte beider Regler, schaltet danach wieder auf manu und schreibt die Werte zurück. Der Benutzer behält seine Einstellung.
- Heizen und Kühlen, die kurz nacheinander geändert werden, gehen in einem Durchgang ins Modul.
- Schlägt das Speichern fehl, springt die Variable auf den alten Wert zurück, und die Kachel zeigt eine Meldung.
- Registersatz 2 (Schutz, 10 und 30 °C) wird nie geschrieben.

**Schwelle Sonnenschutz (optional):** Schwelle 1-1 folgt dem Sollwert Heizen plus Zuschlag, mindestens dem Mindestwert (Befehl aus dem bisherigen Sollwert-Skript).

## Konfiguration

| Einstellung | Bedeutung |
| --- | --- |
| Raumtemperatur | Float-Variable, Pflicht |
| Feuchte, Luftqualität | Integer- oder Float-Variablen, optional |
| Ziel der Raumtemperatur | Objekt, das ein Tipp auf die Raumtemperatur öffnet, optional |
| Sollwert Regler 1 / 2 | S1Target und S2Target der LCN-Value-Instanz. Die Befehle gehen an das LCN-Modul der Heizen-Variable |
| Klima manu | Status von Relais „K manu“ |
| Modus | Modusrelais, ein = Kühlen |
| ABU | Ausgang der Zentrale: 100 = aktiv, 50 = belegt, 0 = unbelegt |
| Tasten | Handbetrieb ein B1 kurz, Handbetrieb aus B2 kurz, Neu laden D5 kurz, Heizen speichern B1 lang, Kühlen speichern B2 lang. Bei „Klima 1.3 L“ ist Neu laden D1 |
| Grenzen | Heizen 10–25 °C, Kühlen 20–30 °C, Schritt 0,5 °C (wie die Reglergrenzen) |
| Schwelle Sonnenschutz | aus, Zuschlag 2 °C, mindestens 20 °C |

Der Knopf **Standard-Sollwerte in LCN speichern** schreibt beide Standardwerte erneut, zum Beispiel nach dem Tausch eines Moduls.

## PHP-Funktionen

```php
CCTEMP_SetSetpoint(int $InstanceID, float $Value): bool              // live, nur bei ABU A, schaltet auf manu
CCTEMP_SetAuto(int $InstanceID): bool                                // Handbetrieb aus, Register neu laden
CCTEMP_SetDefaults(int $InstanceID, float $Heat, float $Cool): bool  // Standard-Sollwerte speichern
CCTEMP_StoreDefaults(int $InstanceID): bool                          // beide Standardwerte erneut speichern
CCTEMP_GetState(int $InstanceID): string                             // JSON wie die Kachel
```
