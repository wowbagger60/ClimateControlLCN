# TemperatureControl

Room thermostat for an LCN climate module built on the "Klima 1.3" template: regulator 1 heats, regulator 2 cools.

## Function

**Tile (for everyone):**

- The arc shows the setpoint of the active regulator, red for heating and blue for cooling. A tick marks the standard setpoint, a dot the room temperature.
- Drag the handle or press − and +. The value is sent on release or 1.2 s after the last + or −.
- A change acts like T± on the wall key. The room switches to manual (reload locked) and the setpoint is set live, not into a register.
- In manual the **Auto** button appears. It ends manual and the room reloads its register.
- With occupancy B (occupied, climate off) or U (unoccupied) nothing can be set. The tile then shows only the room temperature and the state.
- Humidity and air quality appear at the bottom when variables are selected. The tile shows their formatted value.
- An occupancy change, a reset or a power failure ends manual (programmed in LCN). On a guest change, set the occupancy to U first and then to A or B.

**Standard setpoints (configuration):**

- The variables **Standard heating** and **Standard cooling** belong to the instance. They live in register set 1 of both regulators. Edit them, for example, with TemperatureConfiguration.
- LCN can only store the current setpoint. The instance therefore sets it, reads it back and stores it with the store key (lang).
- Afterwards it restores the room:
  - **auto:** the store key reloads the register itself. With occupancy A the new standard applies at once, with B and U the protection value again.
  - **manual:** the instance first reads the setpoints of both regulators, then switches to manual again and writes the values back. The user keeps their setting.
- Heating and cooling changed shortly after each other go to the module in one run.
- If storing fails, the variable returns to its old value and the tile shows a message.
- Register set 2 (protection, 10 and 30 °C) is never written.

**Sun protection threshold (optional):** threshold 1-1 follows the heating setpoint plus an offset, at least the minimum (command taken from the former setpoint script).

## Configuration

| Setting | Meaning |
| --- | --- |
| Room temperature | Float variable, required |
| Humidity, air quality | Integer or float variables, optional |
| Setpoint regulator 1 / 2 | S1Target and S2Target of the LCN Value instance. Commands go to the LCN module of the heating variable |
| Climate manual | Status of relay "K manu" |
| Mode | Mode relay, on = cooling |
| Occupancy | Output of the central module: 100 = active, 50 = occupied, 0 = unoccupied |
| Keys | Manual on B1 kurz, manual off B2 kurz, reload D5 kurz, store heating B1 lang, store cooling B2 lang. With "Klima 1.3 L" reload is D1 |
| Limits | Heating 10–25 °C, cooling 20–30 °C, step 0.5 °C (like the regulator limits) |
| Sun protection threshold | off, offset 2 °C, at least 20 °C |

The button **Store standard setpoints in LCN** writes both standard values again, for example after replacing a module.

## PHP functions

```php
CCTEMP_SetSetpoint(int $InstanceID, float $Value): bool              // live, only with occupancy A, switches to manual
CCTEMP_SetAuto(int $InstanceID): bool                                // end manual, reload the registers
CCTEMP_SetDefaults(int $InstanceID, float $Heat, float $Cool): bool  // store the standard setpoints
CCTEMP_StoreDefaults(int $InstanceID): bool                          // store both standard values again
CCTEMP_GetState(int $InstanceID): string                             // JSON as shown in the tile
```
