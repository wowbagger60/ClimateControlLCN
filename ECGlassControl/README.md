# ECGlassControl

Tint of an electrochromic glass (EC glass) on an LCN module built on the template of M154: output O1 "EC manu" switches between automatic and manual, output O3 "EC-Glas" holds the tint.

## Function

- The tint has 5 levels: 0 % (clear), 25, 50, 75 and 100 % (dark). Other values are rounded to the nearest level.
- Setting a tint first switches manual on (O1 on) and then sets O3. The status command of O1 locks the sun automation in LCN, so it cannot step in between.
- **Auto** switches O1 off. LCN releases the sun automation and repeats its sum, so the glass follows the sun again at once.
- Wall keys, the sun automation and a power failure change the outputs directly in LCN. The instance follows both outputs and shows each change.

**Tile:**

- Layout like TemperatureControl: the tint large in the middle, − and + below, and the **Auto** button.
- The arc runs from light (clear) to dark blue (tinted) and has one dot per level.
- Drag the handle or press − and +. The value is sent on release or 1.2 s after the last + or −.
- **Auto** is filled while the automation is active. In manual it is outlined; a tap switches back to automatic.

**Variables** (for example for the TreeView):

- **Tint** (0–100 %, step 25 %, profile CCECG.Tint): setting it switches to manual.
- **Automatic** (switch): on = automatic, off = manual with the current tint.

## Configuration

| Setting | Meaning |
| --- | --- |
| Output manual (O1) | Intensity variable of output O1 "EC manu" |
| Output tint (O3) | Intensity variable of output O3 "EC-Glas" |

## PHP functions

```php
CCECG_SetTint(int $InstanceID, int $Tint): bool  // 0..100 %, switches to manual
CCECG_SetAuto(int $InstanceID): bool             // back to the sun automation
CCECG_SetManual(int $InstanceID): bool           // manual, keeps the current tint
```
