<?php

declare(strict_types=1);

/**
 * Sets the heating and cooling setpoints of one or more rooms.
 *
 * The instance holds no values of its own: each room points to a TemperatureControl instance and shows its
 * standard setpoints (variables DefaultHeat and DefaultCool). Changes go through CCTEMP_SetDefaults, which
 * stores them in register set 1. Several instances may show the same room; every instance follows the
 * variables and refreshes its tile on each change.
 */
class TemperatureConfiguration extends IPSModuleStrict
{
    private const STATUS_NO_ROOMS = 201;
    private const STATUS_CONTROLS = 202;
    private const CONTROL_MODULE = '{2A8D82A8-9ED2-4717-A15A-72A8D7037635}';
    private const STATUS_LIMITS = 203;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Rooms', '[]');
        $this->RegisterPropertyFloat('HeatMin', 10.0);
        $this->RegisterPropertyFloat('HeatMax', 25.0);
        $this->RegisterPropertyFloat('CoolMin', 20.0);
        $this->RegisterPropertyFloat('CoolMax', 30.0);
        $this->RegisterPropertyFloat('Gap', 1.0);
        $this->RegisterPropertyFloat('Step', 0.5);

        // HTML tile with fullscreen support (Symcon 9.1).
        $this->SetVisualizationType(2);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

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
            foreach ($this->rooms() as $room) {
                $this->RegisterReference($room['Control']);
                foreach ([$room['Heat'], $room['Cool']] as $variable) {
                    $this->RegisterMessage($variable, VM_UPDATE);
                }
            }
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
            $this->pushState();
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'Set') {
            $data = json_decode((string) $Value, true);
            if (is_array($data) && isset($data['pair'], $data['heat'], $data['cool'])) {
                $this->SetRoom((int) $data['pair'], (float) $data['heat'], (float) $data['cool']);
                return;
            }
        }
        throw new Exception('Invalid ident: ' . $Ident);
    }

    public function GetConfigurationForm(): string
    {
        return json_encode([
            'elements' => [
                [
                    'type'     => 'List',
                    'name'     => 'Rooms',
                    'caption'  => 'Rooms',
                    'add'      => true,
                    'delete'   => true,
                    'changeOrder' => true,
                    'rowCount' => 8,
                    'columns'  => [
                        [
                            'caption' => 'Name',
                            'name'    => 'Caption',
                            'width'   => '200px',
                            'add'     => '',
                            'edit'    => ['type' => 'ValidationTextBox']
                        ],
                        [
                            'caption' => 'TemperatureControl',
                            'name'    => 'Control',
                            'width'   => 'auto',
                            'add'     => 1,
                            'edit'    => ['type' => 'SelectModule', 'moduleID' => self::CONTROL_MODULE]
                        ]
                    ]
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'Without a name, the tile shows the name of the TemperatureControl instance.'
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Limits',
                    'items'   => [
                        ['type' => 'RowLayout', 'items' => [
                            ['type' => 'NumberSpinner', 'name' => 'HeatMin', 'caption' => 'Heating from', 'digits' => 1, 'suffix' => ' °C'],
                            ['type' => 'NumberSpinner', 'name' => 'HeatMax', 'caption' => 'to', 'digits' => 1, 'suffix' => ' °C']
                        ]],
                        ['type' => 'RowLayout', 'items' => [
                            ['type' => 'NumberSpinner', 'name' => 'CoolMin', 'caption' => 'Cooling from', 'digits' => 1, 'suffix' => ' °C'],
                            ['type' => 'NumberSpinner', 'name' => 'CoolMax', 'caption' => 'to', 'digits' => 1, 'suffix' => ' °C']
                        ]],
                        ['type' => 'RowLayout', 'items' => [
                            ['type' => 'NumberSpinner', 'name' => 'Gap', 'caption' => 'Cooling at least above heating', 'digits' => 1, 'suffix' => ' °C'],
                            ['type' => 'NumberSpinner', 'name' => 'Step', 'caption' => 'Step', 'digits' => 1, 'suffix' => ' °C']
                        ]]
                    ]
                ]
            ],
            'status' => [
                ['code' => self::STATUS_NO_ROOMS, 'icon' => 'error', 'caption' => 'Add at least one room.'],
                ['code' => self::STATUS_CONTROLS, 'icon' => 'error', 'caption' => 'Each room needs a TemperatureControl instance.'],
                ['code' => self::STATUS_LIMITS, 'icon' => 'error', 'caption' => 'Check the limits: from below to, a step above 0 and a gap of at least 0.']
            ]
        ]);
    }

    public function GetVisualizationTile(): string
    {
        $state = json_encode($this->tileState(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return str_replace('/*__STATE__*/{}', $state, file_get_contents(__DIR__ . '/module.html'));
    }

    // Public API ---------------------------------------------------------------------------------------

    /** Sets the standard setpoints of room 0-N. Values are rounded to the step and must respect the limits and the gap. */
    public function SetRoom(int $Room, float $Heat, float $Cool): bool
    {
        if ($this->GetStatus() !== IS_ACTIVE) {
            $this->showMessage($this->Translate('The instance is not configured correctly.'));
            return false;
        }
        $rooms = $this->rooms();
        if (!isset($rooms[$Room])) {
            $this->showMessage(sprintf($this->Translate('Room %d does not exist.'), $Room));
            return false;
        }
        $limits = $this->limits();
        $heat = $this->round($Heat, $limits['step']);
        $cool = $this->round($Cool, $limits['step']);
        if ($heat < $limits['heatMin'] || $heat > $limits['heatMax'] || $cool < $limits['coolMin'] || $cool > $limits['coolMax']
            || $cool < $heat + $limits['gap'] - 0.001) {
            $this->showMessage($this->Translate('The setpoints are outside the limits.'));
            return false;
        }

        // TemperatureControl sets both variables at once and stores the changed ones in LCN a moment later.
        $this->SendDebug('Set', $rooms[$Room]['Control'] . ': ' . $heat . ' / ' . $cool, 0);
        $ok = CCTEMP_SetDefaults($rooms[$Room]['Control'], $heat, $cool);
        if (!$ok) {
            $this->showMessage($this->Translate('The setpoints could not be set.'));
        }
        $this->pushState();
        return $ok;
    }

    /** Returns the configured rooms with names and current values as JSON. */
    public function GetRooms(): string
    {
        return json_encode($this->tileState()['pairs']);
    }

    // Helpers -------------------------------------------------------------------------------------------

    // Rooms with the variable IDs of their TemperatureControl instance (0 if missing).
    private function rooms(): array
    {
        $rooms = [];
        foreach (json_decode($this->ReadPropertyString('Rooms'), true) ?: [] as $row) {
            $control = (int) ($row['Control'] ?? 0);
            $valid = IPS_InstanceExists($control) && IPS_GetInstance($control)['ModuleInfo']['ModuleID'] === self::CONTROL_MODULE;
            $rooms[] = [
                'Caption' => trim((string) ($row['Caption'] ?? '')),
                'Control' => $control,
                'Heat'    => $valid ? (int) @IPS_GetObjectIDByIdent('DefaultHeat', $control) : 0,
                'Cool'    => $valid ? (int) @IPS_GetObjectIDByIdent('DefaultCool', $control) : 0
            ];
        }
        return $rooms;
    }

    private function limits(): array
    {
        return [
            'heatMin' => $this->ReadPropertyFloat('HeatMin'),
            'heatMax' => $this->ReadPropertyFloat('HeatMax'),
            'coolMin' => $this->ReadPropertyFloat('CoolMin'),
            'coolMax' => $this->ReadPropertyFloat('CoolMax'),
            'gap'     => $this->ReadPropertyFloat('Gap'),
            'step'    => $this->ReadPropertyFloat('Step')
        ];
    }

    private function validate(): int
    {
        $rooms = $this->rooms();
        if (count($rooms) === 0) {
            return self::STATUS_NO_ROOMS;
        }
        foreach ($rooms as $room) {
            if ($room['Heat'] === 0 || $room['Cool'] === 0) {
                return self::STATUS_CONTROLS;
            }
        }
        $l = $this->limits();
        if ($l['heatMin'] >= $l['heatMax'] || $l['coolMin'] >= $l['coolMax'] || $l['step'] <= 0 || $l['gap'] < 0) {
            return self::STATUS_LIMITS;
        }
        return IS_ACTIVE;
    }

    private function round(float $value, float $step): float
    {
        return round(round($value / $step) * $step, 2);
    }

    private function roomName(array $room): string
    {
        return $room['Caption'] !== '' ? $room['Caption'] : IPS_GetName($room['Control']);
    }

    private function tileState(): array
    {
        $pairs = [];
        if ($this->GetStatus() === IS_ACTIVE) {
            foreach ($this->rooms() as $room) {
                $pairs[] = [
                    'name' => $this->roomName($room),
                    'heat' => GetValueFloat($room['Heat']),
                    'cool' => GetValueFloat($room['Cool'])
                ];
            }
        }
        return [
            'pairs'     => $pairs,
            'limits'    => $this->limits(),
            'message'   => $this->GetBuffer('Message'),
            'messageId' => (int) $this->GetBuffer('MessageId'),
            'texts'     => [
                'heat'    => $this->Translate('Heating'),
                'cool'    => $this->Translate('Cooling'),
                'invalid' => $this->Translate('Not configured')
            ]
        ];
    }

    private function pushState(): void
    {
        $this->UpdateVisualizationValue(json_encode($this->tileState()));
    }

    private function showMessage(string $text): void
    {
        $this->SendDebug('Message', $text, 0);
        $this->SetBuffer('Message', $text);
        $this->SetBuffer('MessageId', (string) ((int) $this->GetBuffer('MessageId') + 1));
        $this->pushState();
    }
}
