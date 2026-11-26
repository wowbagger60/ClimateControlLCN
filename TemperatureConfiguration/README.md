# TemperatureConfiguration

Sets the heating and cooling setpoints of one or more rooms in a tile.

## Function

- Each room points to a TemperatureControl instance and shows its standard setpoints for heating and cooling. The instance stores no values of its own.
- With several rooms, the tile shows one row per room with a track and two handles: red for heating, blue for cooling. The band between them is the comfort range.
- With a single room, the tile shows an arc like Symcon's thermostat with both handles.
- Drag a handle, or tap a value and use − and +. Values are sent when the handle is released, or 1.2 s after the last + or −.
- Cooling always stays at least the configured gap above heating. The handles stop instead of pushing each other.
- Several instances may show the same room, for example one page with all rooms and one page per room. Every instance follows the standard setpoints and updates its tile on each change.
- Changes go through CCTEMP_SetDefaults: TemperatureControl sets both values and stores the changed ones in register set 1 shortly after.

## Configuration

| Setting | Meaning |
| --- | --- |
| Rooms | Name (optional) and the TemperatureControl instance of the room, chosen in the object tree. After applying, rows that point to another object are marked in red and the instance reports an error. Without a name, the tile uses the name of the instance. |
| Heating from / to | Range of the heating handle, default 10–25 °C |
| Cooling from / to | Range of the cooling handle, default 20–30 °C |
| Cooling at least above heating | Minimum gap, default 1 °C |
| Step | Rounding and step of + and −, default 0.5 °C |

## PHP functions

```php
CCTC_SetRoom(int $InstanceID, int $Room, float $Heat, float $Cool): bool  // room 0..N
CCTC_GetRooms(int $InstanceID): string                                    // JSON: name, heat, cool
```
