<?php

declare(strict_types=1);

// Zentrale Aktionsliste, eine Eigenschaft je Gruppe. Die Wecker (WakeUpManager) lesen diese über IPS_GetProperty.
class WakeUpActions extends IPSModuleStrict
{
    // Eigenschaft je Gruppe; die Gruppenschlüssel werden gespeichert und nicht übersetzt.
    private const GROUP_PROPERTIES = [
        'light'   => 'ActionsLight',
        'shutter' => 'ActionsShutter',
        'heating' => 'ActionsHeating',
        'other'   => 'ActionsOther',
        'script'  => 'ActionsScript'
    ];

    // Frühere gemeinsame Liste mit Gruppenfeld je Eintrag; wird per Migrate auf die Gruppen verteilt.
    private const LEGACY_PROPERTY = 'Actions';

    private const STATUS_INVALID_ACTIONS = 201;
    private const STATUS_LEGACY_NOT_MIGRATED = 202;

    public function Create(): void
    {
        parent::Create();

        foreach (self::GROUP_PROPERTIES as $property) {
            $this->RegisterPropertyString($property, '[]');
        }
        $this->RegisterPropertyString(self::LEGACY_PROPERTY, '[]');
    }

    public function Migrate(string $JSONData): string
    {
        parent::Migrate($JSONData);

        $data = json_decode($JSONData, true);
        if (!is_array($data) || !isset($data['configuration']) || !is_array($data['configuration'])) {
            return '';
        }
        $configuration = $data['configuration'];

        $legacy = $this->DecodeList($configuration[self::LEGACY_PROPERTY] ?? '[]');
        if ($legacy === null || count($legacy) === 0) {
            // Nicht lesbare Altdaten bleiben unverändert erhalten (Hinweis in ApplyChanges).
            return '';
        }
        foreach (self::GROUP_PROPERTIES as $property) {
            $current = $this->DecodeList($configuration[$property] ?? '[]');
            if ($current === null || count($current) > 0) {
                // Neue Listen sind bereits befüllt; nichts überschreiben.
                return '';
            }
        }

        $groups = array_fill_keys(array_keys(self::GROUP_PROPERTIES), []);
        foreach ($legacy as $row) {
            $group = is_array($row) ? ($row['Group'] ?? 'other') : 'other';
            if (!is_string($group) || !isset(self::GROUP_PROPERTIES[$group])) {
                $group = 'other';
            }
            $groups[$group][] = $row;
        }

        // Zielformat vor dem Ersetzen prüfen: alle Einträge müssen verlustfrei übernommen sein.
        if (array_sum(array_map('count', $groups)) !== count($legacy)) {
            return '';
        }
        foreach (self::GROUP_PROPERTIES as $group => $property) {
            $encoded = json_encode($groups[$group]);
            if ($encoded === false || json_decode($encoded, true) !== $groups[$group]) {
                return '';
            }
            $data['configuration'][$property] = $encoded;
        }
        $data['configuration'][self::LEGACY_PROPERTY] = '[]';

        $result = json_encode($data);
        return $result === false ? '' : $result;
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if ($this->DecodeList($this->ReadPropertyString(self::LEGACY_PROPERTY)) !== []) {
            $this->SendDebug('Configuration', 'Bisherige Aktionsliste wurde nicht übernommen', 0);
            $this->LogMessage('Die bisherige zentrale Aktionsliste konnte nicht in die Gruppen übernommen werden', KL_ERROR);
            $this->SetStatus(self::STATUS_LEGACY_NOT_MIGRATED);
            return;
        }

        $ids = [];
        foreach (self::GROUP_PROPERTIES as $property) {
            $rows = $this->DecodeList($this->ReadPropertyString($property));
            if ($rows === null) {
                $this->SendDebug('Configuration', $property . ' ist nicht lesbar', 0);
                $this->LogMessage('Die zentrale Aktionsliste ist nicht lesbar', KL_ERROR);
                $this->SetStatus(self::STATUS_INVALID_ACTIONS);
                return;
            }
            foreach ($rows as $index => $row) {
                $id = is_array($row) ? ($row['ID'] ?? null) : null;
                if (!is_string($id) || $id === '' || isset($ids[$id])) {
                    // Ohne eindeutige ID kann kein Wecker die Aktion auswählen.
                    $this->SendDebug('Configuration', $property . ', Eintrag ' . ($index + 1) . ': fehlende oder doppelte ID', 0);
                    $this->LogMessage('Die zentrale Aktionsliste enthält Einträge ohne eindeutige ID', KL_ERROR);
                    $this->SetStatus(self::STATUS_INVALID_ACTIONS);
                    return;
                }
                $ids[$id] = true;
            }
        }

        $this->SendDebug('Configuration', count($ids) . ' Aktion(en)', 0);
        $this->SetStatus(IS_ACTIVE);
    }

    // Liefert die Liste oder null, wenn der Wert keine JSON-Liste ist.
    private function DecodeList(mixed $value): ?array
    {
        $list = is_string($value) ? json_decode($value, true) : $value;
        return is_array($list) && array_is_list($list) ? $list : null;
    }
}
