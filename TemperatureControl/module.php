<?php

declare(strict_types=1);

/**
 * Room thermostat for an LCN climate module ("Klima 1.3": regulator 1 heats, regulator 2 cools).
 *
 * Two kinds of setpoints:
 * - The live setpoint of the active regulator. Anyone can change it in the tile while the flat is active
 *   (occupancy A). It is never stored in a register: the room switches to manual like the T+/T- keys,
 *   and the next occupancy change, reset or power failure brings the standard back.
 * - The standard setpoints (variables DefaultHeat and DefaultCool). They live in register set 1 of both
 *   regulators. LCN can only store the current setpoint, so the module sets it, stores it and then
 *   restores the room: in auto the store key reloads the register itself, in manual the module writes
 *   the user's values back and switches to manual again.
 * Register set 2 (protection, 10 and 30 °C) is never touched.
 */
class TemperatureControl extends IPSModuleStrict
{
    private const STATUS_VARIABLES = 201;
    private const STATUS_KEYS = 202;
    private const STATUS_LIMITS = 203;

    private const LOCK_TIMEOUT = 30000;
    private const STORE_DELAY = 500;      // collect heating and cooling into one store run (ms)
    private const READ_WAIT = 1200;       // MW status answer (ms), as in the tests
    private const STORE_WAIT = 3000;      // store key, then reload via sum 2 (ms)

    public function Create(): void
    {
        parent::Create();

        // Inputs
        $this->RegisterPropertyInteger('Temperature', 1);
        $this->RegisterPropertyInteger('Humidity', 1);
        $this->RegisterPropertyInteger('AirQuality', 1);
        $this->RegisterPropertyInteger('Target', 1);            // opened by a tap on the room temperature
        $this->RegisterPropertyInteger('HeatTarget', 1);
        $this->RegisterPropertyInteger('CoolTarget', 1);
        $this->RegisterPropertyInteger('Cooling', 1);
        $this->RegisterPropertyInteger('Occupancy', 1);
        $this->RegisterPropertyInteger('Manual', 1);

        // Keys of the climate module ("Klima 1.3")
        $this->RegisterPropertyString('KeyManual', 'B1');     // kurz: manual on (locks the reload key)
        $this->RegisterPropertyString('KeyStoreHeat', 'B1');  // lang: regulator 1 into register 1
        $this->RegisterPropertyString('KeyStoreCool', 'B2');  // lang: regulator 2 into register 1
        $this->RegisterPropertyString('KeyAuto', 'B2');       // kurz: manual off (unlocks the reload key)
        $this->RegisterPropertyString('KeyReload', 'D5');     // kurz: reload the registers

        // Limits of the regulators
        $this->RegisterPropertyFloat('HeatMin', 10.0);
        $this->RegisterPropertyFloat('HeatMax', 25.0);
        $this->RegisterPropertyFloat('CoolMin', 20.0);
        $this->RegisterPropertyFloat('CoolMax', 30.0);
        $this->RegisterPropertyFloat('Step', 0.5);

        // Sun protection threshold (Schwelle 1-1) follows the heating setpoint
        $this->RegisterPropertyBoolean('SunThreshold', false);
        $this->RegisterPropertyFloat('SunOffset', 2.0);
        $this->RegisterPropertyFloat('SunMinimum', 20.0);

        $this->RegisterTimer('Store', 0, 'IPS_RequestAction($_IPS["TARGET"], "RunStore", "");');

        $this->SetVisualizationType(2);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Read only: the setpoint of the active regulator (S1 when heating, S2 when cooling).
        $this->RegisterVariableFloat('ActiveSetpoint', $this->Translate('Active setpoint'), '~Temperature', 0);
        $this->RegisterVariableFloat('DefaultHeat', $this->Translate('Standard heating'), '~Temperature', 1);
        $this->RegisterVariableFloat('DefaultCool', $this->Translate('Standard cooling'), '~Temperature', 2);
        $this->EnableAction('DefaultHeat');
        $this->EnableAction('DefaultCool');
        // New instance: the values written to the registers at commissioning.
        if ($this->GetValue('DefaultHeat') == 0.0 && $this->GetValue('DefaultCool') == 0.0) {
            $this->SetValue('DefaultHeat', 20.0);
            $this->SetValue('DefaultCool', 24.0);
        }

        foreach ($this->GetMessageList() as $sender => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($sender, $message);
            }
        }
        foreach ($this->GetReferenceList() as $reference) {
            $this->UnregisterReference($reference);
        }

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $status = $this->validate();
        $this->SetStatus($status);
        if ($status === IS_ACTIVE) {
            foreach (['Temperature', 'Humidity', 'AirQuality', 'HeatTarget', 'CoolTarget', 'Cooling', 'Occupancy', 'Manual'] as $property) {
                $variable = $this->ReadPropertyInteger($property);
                if ($this->exists($variable)) {
                    $this->RegisterReference($variable);
                    $this->RegisterMessage($variable, VM_UPDATE);
                }
            }
            foreach (['DefaultHeat', 'DefaultCool'] as $ident) {
                $this->RegisterMessage($this->GetIDForIdent($ident), VM_UPDATE);
            }
            $this->SetBuffer('Sun', '');
            $this->updateSunThreshold();
        }
        $this->pushState();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        if ($Message === VM_UPDATE) {
            if ($SenderID === $this->ReadPropertyInteger('HeatTarget')) {
                $this->updateSunThreshold();
            }
            $this->pushState();
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Setpoint':
                $this->SetSetpoint((float) $Value);
                return;
            case 'Auto':
                $this->SetAuto();
                return;
            case 'DefaultHeat':
                $this->SetDefaults((float) $Value, $this->GetValue('DefaultCool'));
                return;
            case 'DefaultCool':
                $this->SetDefaults($this->GetValue('DefaultHeat'), (float) $Value);
                return;
            case 'StoreDefaults':
                $this->StoreDefaults();
                return;
            case 'RunStore':
                $this->runStore();
                return;
        }
        throw new Exception('Invalid ident: ' . $Ident);
    }

    public function GetConfigurationForm(): string
    {
        $float = ['type' => 'SelectVariable', 'validVariableTypes' => [VARIABLETYPE_FLOAT]];
        $number = ['type' => 'SelectVariable', 'validVariableTypes' => [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT]];
        $bool = ['type' => 'SelectVariable', 'validVariableTypes' => [VARIABLETYPE_BOOLEAN]];
        $key = fn(string $name, string $caption) => [
            'type' => 'ValidationTextBox', 'name' => $name, 'caption' => $caption, 'width' => '110px', 'validate' => '^[A-Da-d][1-8]$'
        ];
        $spinner = fn(string $name, string $caption) => [
            'type' => 'NumberSpinner', 'name' => $name, 'caption' => $caption, 'digits' => 1, 'suffix' => ' °C'
        ];

        return json_encode([
            'elements' => [
                ['type' => 'ExpansionPanel', 'caption' => 'Room', 'expanded' => true, 'items' => [
                    ['name' => 'Temperature', 'caption' => 'Room temperature'] + $float,
                    ['name' => 'Humidity', 'caption' => 'Humidity (optional)'] + $number,
                    ['name' => 'AirQuality', 'caption' => 'Air quality (optional)'] + $number,
                    ['type' => 'SelectObject', 'name' => 'Target', 'caption' => 'Target of the room temperature (optional, e.g. its history)']
                ]],
                ['type' => 'ExpansionPanel', 'caption' => 'LCN', 'expanded' => true, 'items' => [
                    ['name' => 'HeatTarget', 'caption' => 'Setpoint regulator 1, heating (LCN Value S1Target)'] + $float,
                    ['name' => 'CoolTarget', 'caption' => 'Setpoint regulator 2, cooling (LCN Value S2Target)'] + $float,
                    ['name' => 'Manual', 'caption' => 'Climate manual (relay K manu)'] + $bool,
                    ['name' => 'Cooling', 'caption' => 'Mode, on = cooling (mode relay)'] + $bool,
                    ['name' => 'Occupancy', 'caption' => 'Occupancy ABU (100 = active, 50 = occupied, 0 = unoccupied)'] + $number,
                    ['type' => 'Label', 'caption' => 'Commands go to the LCN module of the heating setpoint.']
                ]],
                ['type' => 'ExpansionPanel', 'caption' => 'Keys', 'items' => [
                    ['type' => 'RowLayout', 'items' => [
                        $key('KeyManual', 'Manual on (kurz)'),
                        $key('KeyAuto', 'Manual off (kurz)'),
                        $key('KeyReload', 'Reload (kurz)')
                    ]],
                    ['type' => 'RowLayout', 'items' => [
                        $key('KeyStoreHeat', 'Store heating (lang)'),
                        $key('KeyStoreCool', 'Store cooling (lang)')
                    ]]
                ]],
                ['type' => 'ExpansionPanel', 'caption' => 'Limits', 'items' => [
                    ['type' => 'RowLayout', 'items' => [$spinner('HeatMin', 'Heating from'), $spinner('HeatMax', 'to')]],
                    ['type' => 'RowLayout', 'items' => [$spinner('CoolMin', 'Cooling from'), $spinner('CoolMax', 'to')]],
                    $spinner('Step', 'Step')
                ]],
                ['type' => 'ExpansionPanel', 'caption' => 'Sun protection threshold', 'items' => [
                    ['type' => 'CheckBox', 'name' => 'SunThreshold', 'caption' => 'Set threshold 1-1 to the heating setpoint plus offset'],
                    ['type' => 'RowLayout', 'items' => [$spinner('SunOffset', 'Offset'), $spinner('SunMinimum', 'At least')]]
                ]]
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Store standard setpoints in LCN', 'onClick' => 'IPS_RequestAction($id, "StoreDefaults", "");']
            ],
            'status' => [
                ['code' => self::STATUS_VARIABLES, 'icon' => 'error', 'caption' => 'Select the room temperature, both setpoints, manual, mode and occupancy.'],
                ['code' => self::STATUS_KEYS, 'icon' => 'error', 'caption' => 'Keys are written as A1 to D8.'],
                ['code' => self::STATUS_LIMITS, 'icon' => 'error', 'caption' => 'Check the limits: from below to and a step above 0.']
            ]
        ]);
    }

    public function GetVisualizationTile(): string
    {
        $state = json_encode($this->tileState(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return str_replace('/*__STATE__*/{}', $state, file_get_contents(__DIR__ . '/module.html'));
    }

    // Public API ---------------------------------------------------------------------------------------

    /** Changes the live setpoint of the active regulator (manual, not stored). Only while the flat is active. */
    public function SetSetpoint(float $Value): bool
    {
        if (!$this->ready()) {
            return false;
        }
        if ($this->occupancy() !== 'A') {
            $this->showMessage($this->Translate('Heating and cooling are off.'));
            return false;
        }
        $regulator = $this->cooling() ? 2 : 1;
        [$min, $max] = $this->range($regulator);
        $value = min($max, max($min, $this->round($Value)));
        if (!$this->lock()) {
            return false;
        }
        // As T+/T-: manual first, so the reload key is locked before the setpoint changes.
        $ok = $this->key($this->ReadPropertyString('KeyManual'), 'K')
            && $this->writeTarget($regulator, $value);
        $this->unlock();
        if (!$ok) {
            $this->showMessage($this->Translate('The setpoint could not be written.'));
        }
        $this->pushState();
        return $ok;
    }

    /** Ends manual: the room loads its register again (standard or protection, as LCN decides). */
    public function SetAuto(): bool
    {
        if (!$this->ready() || !$this->lock()) {
            return false;
        }
        $ok = $this->key($this->ReadPropertyString('KeyAuto'), 'K')
            && $this->key($this->ReadPropertyString('KeyReload'), 'K');
        $this->unlock();
        return $ok;
    }

    /** Sets the standard setpoints and stores the changed ones in register set 1. */
    public function SetDefaults(float $Heat, float $Cool): bool
    {
        if (!$this->ready()) {
            return false;
        }
        $heat = $this->round($Heat);
        $cool = $this->round($Cool);
        [$heatMin, $heatMax] = $this->range(1);
        [$coolMin, $coolMax] = $this->range(2);
        if ($heat < $heatMin || $heat > $heatMax || $cool < $coolMin || $cool > $coolMax) {
            $this->showMessage($this->Translate('The setpoints are outside the limits.'));
            return false;
        }
        $pending = $this->pending();
        foreach (['DefaultHeat' => $heat, 'DefaultCool' => $cool] as $ident => $value) {
            $old = $this->GetValue($ident);
            if (abs($old - $value) < 0.001) {
                continue;
            }
            // The variable changes at once (the tile stays where the user left it); a failed store sets it back.
            $pending[$ident] = ['value' => $value, 'old' => $pending[$ident]['old'] ?? $old];
            $this->SetValue($ident, $value);
        }
        $this->SetBuffer('Pending', json_encode($pending));
        if (count($pending) > 0) {
            $this->SetTimerInterval('Store', self::STORE_DELAY);
        }
        return true;
    }

    /** Writes both standard setpoints to LCN again, for example after commissioning or replacing a module. */
    public function StoreDefaults(): bool
    {
        if (!$this->ready()) {
            return false;
        }
        $pending = [];
        foreach (['DefaultHeat', 'DefaultCool'] as $ident) {
            $pending[$ident] = ['value' => $this->GetValue($ident), 'old' => $this->GetValue($ident)];
        }
        $this->SetBuffer('Pending', json_encode($pending));
        $this->SetTimerInterval('Store', self::STORE_DELAY);
        return true;
    }

    /** Returns the room state as JSON (the same data the tile shows). */
    public function GetState(): string
    {
        return json_encode($this->tileState());
    }

    // Store run ----------------------------------------------------------------------------------------

    private function runStore(): void
    {
        $this->SetTimerInterval('Store', 0);
        if (!$this->lock()) {
            $this->SetTimerInterval('Store', self::STORE_DELAY);
            return;
        }
        $pending = $this->pending();
        $this->SetBuffer('Pending', '');
        if (count($pending) === 0 || $this->GetStatus() !== IS_ACTIVE) {
            $this->unlock();
            return;
        }

        // In manual the store key's reload would replace the user's values: keep them.
        $manual = GetValueBoolean($this->ReadPropertyInteger('Manual'));
        $hand = $manual ? [1 => $this->readTarget(1), 2 => $this->readTarget(2)] : [];
        $this->SendDebug('Store', json_encode(['pending' => $pending, 'manual' => $manual, 'hand' => $hand]), 0);

        $failed = false;
        $jobs = [
            'DefaultHeat' => [1, $this->ReadPropertyString('KeyStoreHeat')],
            'DefaultCool' => [2, $this->ReadPropertyString('KeyStoreCool')]
        ];
        foreach ($jobs as $ident => [$regulator, $storeKey]) {
            if (!isset($pending[$ident])) {
                continue;
            }
            $value = $pending[$ident]['value'];
            if ($this->writeChecked($regulator, $value) && $this->key($storeKey, 'L')) {
                IPS_Sleep(self::STORE_WAIT);
                continue;
            }
            $failed = true;
            $this->LogMessage(sprintf('Regulator %d: standard %.1f °C not stored', $regulator, $value), KL_ERROR);
            $this->SetValue($ident, $pending[$ident]['old']);
        }

        if ($manual) {
            $this->key($this->ReadPropertyString('KeyManual'), 'K');
            foreach ($hand as $regulator => $value) {
                if ($value !== null) {
                    $this->writeTarget($regulator, $value);
                }
            }
        }
        $this->unlock();

        if ($failed) {
            $this->showMessage($this->Translate('A standard setpoint could not be stored.'));
        }
        $this->pushState();
    }

    // LCN ----------------------------------------------------------------------------------------------

    private function module(): int
    {
        return IPS_GetInstance(IPS_GetParent($this->ReadPropertyInteger('HeatTarget')))['ConnectionID'];
    }

    private function send(string $function, string $data): bool
    {
        $this->SendDebug('Send', $function . ' ' . $data, 0);
        try {
            return @LCN_SendCommand($this->module(), $function, $data) !== false;
        } catch (Throwable $error) {
            $this->SendDebug('Send', $error->getMessage(), 0);
            return false;
        }
    }

    /** Sends one key of the module: action K (kurz), L (lang) or O (los). */
    private function key(string $key, string $action): bool
    {
        $key = strtoupper($key);
        $tables = '----';
        $tables[ord($key[0]) - ord('A')] = $action;
        $bits = '00000000';
        $bits[(int) $key[1] - 1] = '1';
        return $this->send('TS', $tables . $bits);
    }

    private function target(int $regulator): int
    {
        return $this->ReadPropertyInteger($regulator === 1 ? 'HeatTarget' : 'CoolTarget');
    }

    private function writeTarget(int $regulator, float $value): bool
    {
        $this->SendDebug('Setpoint', sprintf('Regulator %d = %.1f', $regulator, $value), 0);
        try {
            return RequestAction($this->target($regulator), $value);
        } catch (Throwable $error) {
            $this->SendDebug('Setpoint', $error->getMessage(), 0);
            return false;
        }
    }

    private function readTarget(int $regulator): ?float
    {
        if (!$this->send('MW', 'S' . $regulator)) {
            return null;
        }
        IPS_Sleep(self::READ_WAIT);
        return GetValueFloat($this->target($regulator));
    }

    /** Writes a setpoint and reads it back before it may be stored (one retry, as in the tests). */
    private function writeChecked(int $regulator, float $value): bool
    {
        for ($try = 1; $try <= 2; $try++) {
            $this->writeTarget($regulator, $value);
            IPS_Sleep(500);
            $read = $this->readTarget($regulator);
            if ($read !== null && abs($read - $value) < 0.05) {
                return true;
            }
            $this->SendDebug('Setpoint', sprintf('Regulator %d reads %s instead of %.1f', $regulator, var_export($read, true), $value), 0);
        }
        return false;
    }

    // Threshold 1-1 = heating setpoint + offset, at least the minimum (command of the former setpoint script).
    private function updateSunThreshold(): void
    {
        if (!$this->ReadPropertyBoolean('SunThreshold') || $this->GetStatus() !== IS_ACTIVE) {
            return;
        }
        $value = max(GetValueFloat($this->ReadPropertyInteger('HeatTarget')) + $this->ReadPropertyFloat('SunOffset'),
            $this->ReadPropertyFloat('SunMinimum'));
        $raw = (int) round($value * 10) + 1000;
        if ($this->GetBuffer('Sun') === (string) $raw) {
            return;
        }
        if ($this->send('SS', 'E' . sprintf('%04d', $raw - 1150) . 'AR11')) {
            $this->SetBuffer('Sun', (string) $raw);
        }
    }

    // Helpers -------------------------------------------------------------------------------------------

    private function exists(int $variable): bool
    {
        return $variable > 1 && IPS_VariableExists($variable);
    }

    private function validate(): int
    {
        foreach (['Temperature', 'HeatTarget', 'CoolTarget', 'Manual', 'Cooling', 'Occupancy'] as $property) {
            if (!$this->exists($this->ReadPropertyInteger($property))) {
                return self::STATUS_VARIABLES;
            }
        }
        // Commands go to the module of the LCN Value instance that holds the heating setpoint.
        if (!IPS_InstanceExists(IPS_GetParent($this->ReadPropertyInteger('HeatTarget')))) {
            return self::STATUS_VARIABLES;
        }
        foreach (['KeyManual', 'KeyStoreHeat', 'KeyStoreCool', 'KeyAuto', 'KeyReload'] as $property) {
            if (!preg_match('/^[A-D][1-8]$/i', $this->ReadPropertyString($property))) {
                return self::STATUS_KEYS;
            }
        }
        if ($this->ReadPropertyFloat('HeatMin') >= $this->ReadPropertyFloat('HeatMax')
            || $this->ReadPropertyFloat('CoolMin') >= $this->ReadPropertyFloat('CoolMax')
            || $this->ReadPropertyFloat('Step') <= 0) {
            return self::STATUS_LIMITS;
        }
        return IS_ACTIVE;
    }

    private function ready(): bool
    {
        if ($this->GetStatus() === IS_ACTIVE) {
            return true;
        }
        $this->showMessage($this->Translate('The instance is not configured correctly.'));
        return false;
    }

    private function range(int $regulator): array
    {
        return $regulator === 1
            ? [$this->ReadPropertyFloat('HeatMin'), $this->ReadPropertyFloat('HeatMax')]
            : [$this->ReadPropertyFloat('CoolMin'), $this->ReadPropertyFloat('CoolMax')];
    }

    private function round(float $value): float
    {
        $step = $this->ReadPropertyFloat('Step');
        return round(round($value / $step) * $step, 2);
    }

    private function cooling(): bool
    {
        return GetValueBoolean($this->ReadPropertyInteger('Cooling'));
    }

    /** A = active (100 %), B = occupied without climate (50 %), U = unoccupied (0 %). */
    private function occupancy(): string
    {
        $value = (float) GetValue($this->ReadPropertyInteger('Occupancy'));
        return $value >= 99 ? 'A' : ($value >= 25 ? 'B' : 'U');
    }

    private function pending(): array
    {
        $pending = json_decode($this->GetBuffer('Pending'), true);
        return is_array($pending) ? $pending : [];
    }

    private function optional(string $property): ?string
    {
        $variable = $this->ReadPropertyInteger($property);
        return $this->exists($variable) ? GetValueFormatted($variable) : null;
    }

    private function tileState(): array
    {
        $state = [
            'valid' => $this->GetStatus() === IS_ACTIVE,
            'texts' => [
                'auto'    => $this->Translate('Auto'),
                'invalid' => $this->Translate('Not configured')
            ],
            'message'   => $this->GetBuffer('Message'),
            'messageId' => (int) $this->GetBuffer('MessageId')
        ];
        if (!$state['valid']) {
            return $state;
        }
        $cooling = $this->cooling();
        $regulator = $cooling ? 2 : 1;
        [$min, $max] = $this->range($regulator);
        return $state + [
            'mode'        => $cooling ? 'cool' : 'heat',
            'occupancy'   => $this->occupancy(),
            'manual'      => GetValueBoolean($this->ReadPropertyInteger('Manual')),
            'temperature' => GetValueFloat($this->ReadPropertyInteger('Temperature')),
            'target'      => IPS_ObjectExists($this->ReadPropertyInteger('Target')) ? $this->ReadPropertyInteger('Target') : 1,
            'setpoint'    => GetValueFloat($this->target($regulator)),
            'standard'    => $this->GetValue($cooling ? 'DefaultCool' : 'DefaultHeat'),
            'min'         => $min,
            'max'         => $max,
            'step'        => $this->ReadPropertyFloat('Step'),
            'humidity'    => $this->optional('Humidity'),
            'airQuality'  => $this->optional('AirQuality')
        ];
    }

    private function pushState(): void
    {
        $state = $this->tileState();
        if ($state['valid'] && abs($this->GetValue('ActiveSetpoint') - $state['setpoint']) >= 0.001) {
            $this->SetValue('ActiveSetpoint', $state['setpoint']);
        }
        $this->UpdateVisualizationValue(json_encode($state));
    }

    private function showMessage(string $text): void
    {
        $this->SendDebug('Message', $text, 0);
        $this->SetBuffer('Message', $text);
        $this->SetBuffer('MessageId', (string) ((int) $this->GetBuffer('MessageId') + 1));
        $this->pushState();
    }

    private function lock(): bool
    {
        if (IPS_SemaphoreEnter('CCTEMP_' . $this->InstanceID, self::LOCK_TIMEOUT)) {
            return true;
        }
        $this->SendDebug('Lock', 'Timeout while waiting for the lock', 0);
        return false;
    }

    private function unlock(): void
    {
        IPS_SemaphoreLeave('CCTEMP_' . $this->InstanceID);
    }
}
