# ECGlassControl

Tönung eines elektrochromen Glases (EC-Glas) an einem LCN-Modul nach Vorlage M154: Ausgang O1 „EC manu“ schaltet zwischen Automatik und Handbetrieb, Ausgang O3 „EC-Glas“ hält die Tönung.

## Funktion

- Die Tönung hat 5 Stufen: 0 % (hell), 25, 50, 75 und 100 % (dunkel). Andere Werte werden auf die nächste Stufe gerundet.
- Eine Tönung setzt zuerst den Handbetrieb (O1 ein) und danach O3. Das Statuskommando von O1 sperrt in LCN die Sonnenautomatik, damit sie nicht dazwischenfährt.
- **Auto** schaltet O1 aus. LCN gibt die Sonnenautomatik frei und wiederholt ihre Summe, das Glas folgt also sofort wieder der Sonne.
- Taster, Sonnenautomatik und Stromausfall ändern die Ausgänge direkt in LCN. Die Instanz folgt beiden Ausgängen und zeigt jede Änderung.

**Kachel:**

- Aufbau wie TemperatureControl: in der Mitte gross die Tönung, darunter − und + und der Knopf **Auto**.
- Der Bogen geht von hell (klar) nach dunkelblau (getönt) und hat einen Punkt pro Stufe.
- Griff ziehen oder − und + drücken. Gesendet wird beim Loslassen oder 1,2 s nach dem letzten + oder −.
- **Auto** ist gefüllt, solange die Automatik aktiv ist. Im Handbetrieb ist es umrandet; ein Tipp schaltet zurück auf Automatik.

**Variablen** (zum Beispiel für die TreeView):

- **Tönung** (0–100 %, Schritt 25 %, Profil CCECG.Tint): Setzen schaltet auf Handbetrieb.
- **Automatik** (Schalter): ein = Automatik, aus = Handbetrieb mit der aktuellen Tönung.

## Konfiguration

| Einstellung | Bedeutung |
| --- | --- |
| Ausgang Handbetrieb (O1) | Helligkeitsvariable von Ausgang O1 „EC manu“ |
| Ausgang Tönung (O3) | Helligkeitsvariable von Ausgang O3 „EC-Glas“ |

## PHP-Funktionen

```php
CCECG_SetTint(int $InstanceID, int $Tint): bool  // 0..100 %, schaltet auf Handbetrieb
CCECG_SetAuto(int $InstanceID): bool             // zurück zur Sonnenautomatik
CCECG_SetManual(int $InstanceID): bool           // Handbetrieb, behält die aktuelle Tönung
```
