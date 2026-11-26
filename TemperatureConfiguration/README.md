# TemperatureConfiguration

Sets the heating and cooling setpoints of one or more rooms in a tile.

## Function

- Each pair points to two float variables: the heating and the cooling setpoint of a room. The instance stores no values of its own.
- With several pairs, the tile shows one row per room with a track and two handles: red for heating, blue for cooling. The band between them is the comfort range.
- With a single pair, the tile shows an arc like Symcon's thermostat with both handles.
- Drag a handle, or tap a value and use − and +. Values are sent when the handle is released, or 1.2 s after the last + or −.
- Cooling always stays at least the configured gap above heating. The handles stop instead of pushing each other.
- Several instances may show the same pair, for example one page with all rooms and one page per room. Every instance follows the variables and updates its tile on each change.
- A variable with an action (for example a setpoint variable of TemperatureControl) is written through its action, so its owner can pass the value on. Other variables are set directly.

## Configuration

| Setting | Meaning |
| --- | --- |
| Setpoint pairs | Name (optional), heating variable, cooling variable. For the standard setpoints of a room, select "Standard heating" and "Standard cooling" of its TemperatureControl instance. Without a name, the tile uses the name of the object above the heating variable. |
| Heating from / to | Range of the heating handle, default 10–25 °C |
| Cooling from / to | Range of the cooling handle, default 20–30 °C |
| Cooling at least above heating | Minimum gap, default 1 °C |
| Step | Rounding and step of + and −, default 0.5 °C |

## PHP functions

```php
CCTC_SetPair(int $InstanceID, int $Pair, float $Heat, float $Cool): bool  // pair 0..N
CCTC_GetPairs(int $InstanceID): string                                    // JSON: name, heat, cool
```
