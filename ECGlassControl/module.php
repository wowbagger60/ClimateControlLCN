<?php

declare(strict_types=1);

/**
 * Tint of an electrochromic glass on an LCN module (template M154).
 *
 * - Output "manu" (O1): on = manual, off = automatic. Its status command locks or releases the sun automation
 *   in LCN; switching it off repeats the automation sum, so the glass follows the sun again at once.
 * - Output "tint" (O3): 0 % = clear, 100 % = dark, in 5 levels of 25 %.
 *
 * Setting a tint first switches manual on and then the tint output, so the automation cannot step in between.
 */
class ECGlassControl extends IPSModuleStrict
{
    private const STATUS_VARIABLES = 201;
    private const LEVEL = 25;
    private const LOCK_TIMEOUT = 5000;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('Manual', 1);
        $this->RegisterPropertyInteger('Tint', 1);

        $this->SetVisualizationType(2);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if (!IPS_VariableProfileExists('CCECG.Tint')) {
            IPS_CreateVariableProfile('CCECG.Tint', VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileValues('CCECG.Tint', 0, 100, self::LEVEL);
        IPS_SetVariableProfileText('CCECG.Tint', '', ' %');
        IPS_SetVariableProfileIcon('CCECG.Tint', 'Sun');

        $this->RegisterVariableInteger('Tint', $this->Translate('Tint'), 'CCECG.Tint', 0);
        $this->RegisterVariableBoolean('Automatic', $this->Translate('Automatic'), '~Switch', 1);
        $this->EnableAction('Tint');
        $this->EnableAction('Automatic');

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
            foreach (['Manual', 'Tint'] as $property) {
                $variable = $this->ReadPropertyInteger($property);
                $this->RegisterReference($variable);
                $this->RegisterMessage($variable, VM_UPDATE);
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
        switch ($Ident) {
            case 'Tint':
                $this->SetTint((int) round((float) $Value));
                return;
            case 'Automatic':
                $Value ? $this->SetAuto() : $this->SetManual();
                return;
            case 'Auto':
                $this->SetAuto();
                return;
        }
        throw new Exception('Invalid ident: ' . $Ident);
    }

    public function GetConfigurationForm(): string
    {
        return json_encode([
            'elements' => [
                ['type' => 'SelectVariable', 'name' => 'Manual', 'caption' => 'Output manual (O1)', 'width' => '400px'],
                ['type' => 'SelectVariable', 'name' => 'Tint', 'caption' => 'Output tint (O3)', 'width' => '400px'],
                ['type' => 'Label', 'caption' => 'Select the intensity variables of the two LCN outputs.']
            ],
            'status' => [
                ['code' => self::STATUS_VARIABLES, 'icon' => 'error', 'caption' => 'Select both output variables.']
            ]
        ]);
    }

    public function GetVisualizationTile(): string
    {
        $state = json_encode($this->tileState(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return str_replace('/*__STATE__*/{}', $state, file_get_contents(__DIR__ . '/module.html'));
    }

    // Public API ---------------------------------------------------------------------------------------

    /** Switches to manual and sets the tint 0-100 % (rounded to 25 %). */
    public function SetTint(int $Tint): bool
    {
        if (!$this->ready()) {
            return false;
        }
        $tint = max(0, min(100, (int) (round($Tint / self::LEVEL) * self::LEVEL)));
        $ok = ($this->manual() || $this->write('Manual', 100)) && $this->write('Tint', $tint);
        $this->unlock();
        if (!$ok) {
            $this->showMessage($this->Translate('The tint could not be set.'));
        }
        $this->pushState();
        return $ok;
    }

    /** Ends manual: LCN follows the sun automation again. */
    public function SetAuto(): bool
    {
        if (!$this->ready()) {
            return false;
        }
        $ok = $this->write('Manual', 0);
        $this->unlock();
        if (!$ok) {
            $this->showMessage($this->Translate('Automatic could not be switched on.'));
        }
        $this->pushState();
        return $ok;
    }

    /** Switches to manual and keeps the current tint. */
    public function SetManual(): bool
    {
        if (!$this->ready()) {
            return false;
        }
        $ok = $this->write('Manual', 100);
        $this->unlock();
        if (!$ok) {
            $this->showMessage($this->Translate('Manual could not be switched on.'));
        }
        $this->pushState();
        return $ok;
    }

    // Helpers -------------------------------------------------------------------------------------------

    private function validate(): int
    {
        foreach (['Manual', 'Tint'] as $property) {
            $variable = $this->ReadPropertyInteger($property);
            if (!IPS_VariableExists($variable) || IPS_GetVariable($variable)['VariableType'] === VARIABLETYPE_STRING) {
                return self::STATUS_VARIABLES;
            }
        }
        return IS_ACTIVE;
    }

    private function ready(): bool
    {
        if ($this->GetStatus() !== IS_ACTIVE) {
            $this->showMessage($this->Translate('The instance is not configured correctly.'));
            return false;
        }
        if (!IPS_SemaphoreEnter('CCECG_' . $this->InstanceID, self::LOCK_TIMEOUT)) {
            $this->showMessage($this->Translate('The instance is busy.'));
            return false;
        }
        return true;
    }

    private function unlock(): void
    {
        IPS_SemaphoreLeave('CCECG_' . $this->InstanceID);
    }

    private function read(string $property): float
    {
        return (float) GetValue($this->ReadPropertyInteger($property));
    }

    private function manual(): bool
    {
        return $this->read('Manual') > 0;
    }

    private function tint(): int
    {
        return max(0, min(100, (int) (round($this->read('Tint') / self::LEVEL) * self::LEVEL)));
    }

    /** Writes an output through the action of its LCN variable, in the type of that variable. */
    private function write(string $property, int $value): bool
    {
        $variable = $this->ReadPropertyInteger($property);
        $this->SendDebug('Write', $property . ' = ' . $value, 0);
        $type = IPS_GetVariable($variable)['VariableType'];
        $data = match ($type) {
            VARIABLETYPE_BOOLEAN => $value > 0,
            VARIABLETYPE_FLOAT   => (float) $value,
            default              => $value
        };
        try {
            return RequestAction($variable, $data);
        } catch (Throwable $error) {
            $this->SendDebug('Write', $error->getMessage(), 0);
            return false;
        }
    }

    private function tileState(): array
    {
        $state = [
            'valid'     => $this->GetStatus() === IS_ACTIVE,
            'texts'     => [
                'auto'    => $this->Translate('Auto'),
                'invalid' => $this->Translate('Not configured')
            ],
            'message'   => $this->GetBuffer('Message'),
            'messageId' => (int) $this->GetBuffer('MessageId')
        ];
        if (!$state['valid']) {
            return $state;
        }
        return $state + [
            'tint'   => $this->tint(),
            'manual' => $this->manual(),
            'step'   => self::LEVEL
        ];
    }

    private function pushState(): void
    {
        $state = $this->tileState();
        if ($state['valid']) {
            if ($this->GetValue('Tint') !== $state['tint']) {
                $this->SetValue('Tint', $state['tint']);
            }
            if ($this->GetValue('Automatic') !== !$state['manual']) {
                $this->SetValue('Automatic', !$state['manual']);
            }
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
}
