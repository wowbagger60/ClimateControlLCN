<?php

declare(strict_types=1);

/**
 * Sets the heating and cooling setpoints of one or more rooms.
 *
 * The instance holds no values of its own: each pair points to two float variables (later the setpoint
 * variables of TemperatureControl). Several instances may show the same pair; every instance follows
 * the variables and refreshes its tile on each change.
 */
class TemperatureConfiguration extends IPSModuleStrict
{
    private const STATUS_NO_PAIRS = 201;
    private const STATUS_VARIABLES = 202;
    private const STATUS_LIMITS = 203;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Pairs', '[]');
        $this->RegisterPropertyFloat('HeatMin', 10.0);
        $this->RegisterPropertyFloat('HeatMax', 25.0);
        $this->RegisterPropertyFloat('CoolMin', 20.0);
        $this->RegisterPropertyFloat('CoolMax', 28.0);
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
            foreach ($this->pairs() as $pair) {
                foreach ([$pair['Heat'], $pair['Cool']] as $variable) {
                    $this->RegisterReference($variable);
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
                $this->SetPair((int) $data['pair'], (float) $data['heat'], (float) $data['cool']);
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
                    'name'     => 'Pairs',
                    'caption'  => 'Setpoint pairs',
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
                            'caption' => 'Heating setpoint',
                            'name'    => 'Heat',
                            'width'   => 'auto',
                            'add'     => 1,
                            'edit'    => ['type' => 'SelectVariable', 'validVariableTypes' => [VARIABLETYPE_FLOAT]]
                        ],
                        [
                            'caption' => 'Cooling setpoint',
                            'name'    => 'Cool',
                            'width'   => 'auto',
                            'add'     => 1,
                            'edit'    => ['type' => 'SelectVariable', 'validVariableTypes' => [VARIABLETYPE_FLOAT]]
                        ]
                    ]
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'Without a name, the tile shows the name of the object above the heating variable.'
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
                ['code' => self::STATUS_NO_PAIRS, 'icon' => 'error', 'caption' => 'Add at least one setpoint pair.'],
                ['code' => self::STATUS_VARIABLES, 'icon' => 'error', 'caption' => 'Each pair needs two float variables.'],
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

    /** Writes the setpoints of pair 0-N. Values are rounded to the step and must respect the limits and the gap. */
    public function SetPair(int $Pair, float $Heat, float $Cool): bool
    {
        if ($this->GetStatus() !== IS_ACTIVE) {
            $this->showMessage($this->Translate('The instance is not configured correctly.'));
            return false;
        }
        $pairs = $this->pairs();
        if (!isset($pairs[$Pair])) {
            $this->showMessage(sprintf($this->Translate('Pair %d does not exist.'), $Pair));
            return false;
        }
        $limits = $this->limits();
        $heat = $this->round($Heat, $limits['step']);
        $cool = $this->round($Cool, $limits['step']);
        if ($heat < $limits['heatMin'] || $heat > $limits['heatMax'] || $cool < $limits['coolMin'] || $cool > $limits['coolMax']
            || $cool < $heat + $limits['gap'] - 0.001) {
            $this->showMessage($this->Translate('The setpoints are outside the limits.'));
            $this->pushState();
            return false;
        }

        $ok = true;
        foreach (['Heat' => $heat, 'Cool' => $cool] as $key => $value) {
            $variable = $pairs[$Pair][$key];
            if (abs(GetValueFloat($variable) - $value) < 0.001) {
                continue;
            }
            $ok = $this->write($variable, $value) && $ok;
        }
        if (!$ok) {
            $this->showMessage($this->Translate('A setpoint could not be written.'));
        }
        $this->pushState();
        return $ok;
    }

    /** Returns the configured pairs with names and current values as JSON. */
    public function GetPairs(): string
    {
        return json_encode($this->tileState()['pairs']);
    }

    // Helpers -------------------------------------------------------------------------------------------

    private function pairs(): array
    {
        $pairs = [];
        foreach (json_decode($this->ReadPropertyString('Pairs'), true) ?: [] as $row) {
            $pairs[] = [
                'Caption' => trim((string) ($row['Caption'] ?? '')),
                'Heat'    => (int) ($row['Heat'] ?? 0),
                'Cool'    => (int) ($row['Cool'] ?? 0)
            ];
        }
        return $pairs;
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
        $pairs = $this->pairs();
        if (count($pairs) === 0) {
            return self::STATUS_NO_PAIRS;
        }
        foreach ($pairs as $pair) {
            foreach ([$pair['Heat'], $pair['Cool']] as $variable) {
                if (!IPS_VariableExists($variable) || IPS_GetVariable($variable)['VariableType'] !== VARIABLETYPE_FLOAT) {
                    return self::STATUS_VARIABLES;
                }
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

    // Variables with an action (for example the setpoint variables of TemperatureControl) go through the action,
    // so their owner writes the value to LCN. Plain variables are set directly.
    private function write(int $variable, float $value): bool
    {
        $info = IPS_GetVariable($variable);
        $this->SendDebug('Write', $variable . ' = ' . $value, 0);
        if ($info['VariableCustomAction'] > 1 || ($info['VariableCustomAction'] === 0 && $info['VariableAction'] > 1)) {
            return RequestAction($variable, $value);
        }
        return SetValueFloat($variable, $value);
    }

    private function pairName(array $pair): string
    {
        if ($pair['Caption'] !== '') {
            return $pair['Caption'];
        }
        return IPS_ObjectExists($pair['Heat']) ? IPS_GetName(IPS_GetParent($pair['Heat'])) : '';
    }

    private function tileState(): array
    {
        $pairs = [];
        if ($this->GetStatus() === IS_ACTIVE) {
            foreach ($this->pairs() as $pair) {
                $pairs[] = [
                    'name' => $this->pairName($pair),
                    'heat' => GetValueFloat($pair['Heat']),
                    'cool' => GetValueFloat($pair['Cool'])
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
