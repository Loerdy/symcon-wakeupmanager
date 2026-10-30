<?php

declare(strict_types=1);

// Zentrale Aktionsliste. Die Wecker (WakeUpManager) lesen die Eigenschaft "Actions" über IPS_GetProperty.
class WakeUpActions extends IPSModuleStrict
{
    private const STATUS_INVALID_ACTIONS = 201;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Actions', '[]');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $rows = json_decode($this->ReadPropertyString('Actions'), true);
        if (!is_array($rows) || !array_is_list($rows)) {
            $this->SendDebug('Configuration', 'Aktionsliste ist nicht lesbar', 0);
            $this->LogMessage('Die zentrale Aktionsliste ist nicht lesbar', KL_ERROR);
            $this->SetStatus(self::STATUS_INVALID_ACTIONS);
            return;
        }

        $ids = [];
        foreach ($rows as $index => $row) {
            $id = is_array($row) ? ($row['ID'] ?? null) : null;
            if (!is_string($id) || $id === '' || isset($ids[$id])) {
                // Ohne eindeutige ID kann kein Wecker die Aktion auswählen.
                $this->SendDebug('Configuration', 'Aktion ' . ($index + 1) . ': fehlende oder doppelte ID', 0);
                $this->LogMessage('Die zentrale Aktionsliste enthält Einträge ohne eindeutige ID', KL_ERROR);
                $this->SetStatus(self::STATUS_INVALID_ACTIONS);
                return;
            }
            $ids[$id] = true;
        }

        $this->SendDebug('Configuration', count($ids) . ' Aktion(en)', 0);
        $this->SetStatus(IS_ACTIVE);
    }
}
