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
        'media'   => 'ActionsMedia',
        'other'   => 'ActionsOther',
        'script'  => 'ActionsScript'
    ];

    // Frühere gemeinsame Liste mit Gruppenfeld je Eintrag; wird per Migrate auf die Gruppen verteilt.
    private const LEGACY_PROPERTY = 'Actions';

    private const MEDIA_PROPERTY = 'ActionsMedia';

    private const STATUS_INVALID_ACTIONS = 201;
    private const STATUS_LEGACY_NOT_MIGRATED = 202;

    public function Create(): void
    {
        parent::Create();

        foreach (self::GROUP_PROPERTIES as $property) {
            $this->RegisterPropertyString($property, '[]');
        }
        $this->RegisterPropertyString(self::LEGACY_PROPERTY, '[]');

        // Letzter Start je Mediaplayer-Aktion: {"<Aktions-ID>": <Instanz-ID des Weckers, 0 = von Hand>}. Nur der
        // jüngste Start darf ausschalten ("neuerer Start gewinnt").
        $this->RegisterAttributeString('MediaStarts', '{}');
    }

    public function Migrate(string $JSONData): string
    {
        parent::Migrate($JSONData);

        // Als Objekte dekodieren, damit leere JSON-Objekte (z. B. "attributes": {}) nicht als Listen zurückgeschrieben werden.
        $data = json_decode($JSONData);
        if (!is_object($data) || !isset($data->configuration) || !is_object($data->configuration)) {
            return '';
        }
        $configuration = $data->configuration;

        $legacy = $this->DecodeList($configuration->{self::LEGACY_PROPERTY} ?? '[]');
        if ($legacy === null || count($legacy) === 0) {
            // Nicht lesbare Altdaten bleiben unverändert erhalten (Hinweis in ApplyChanges).
            return '';
        }
        foreach (self::GROUP_PROPERTIES as $property) {
            $current = $this->DecodeList($configuration->$property ?? '[]');
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
            $configuration->$property = $encoded;
        }
        $configuration->{self::LEGACY_PROPERTY} = '[]';

        $result = json_encode($data);
        return $result === false ? '' : $result;
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if ($this->DecodeList($this->ReadPropertyString(self::LEGACY_PROPERTY)) !== []) {
            $this->SendDebug('Configuration', 'Previous action list was not migrated', 0);
            $this->LogMessage($this->Translate('The previous central action list could not be migrated to the groups'), KL_ERROR);
            $this->SetStatus(self::STATUS_LEGACY_NOT_MIGRATED);
            return;
        }

        $ids = [];
        foreach (self::GROUP_PROPERTIES as $property) {
            $rows = $this->DecodeList($this->ReadPropertyString($property));
            if ($rows === null) {
                $this->SendDebug('Configuration', $property . ' cannot be read', 0);
                $this->LogMessage($this->Translate('The central action list cannot be read'), KL_ERROR);
                $this->SetStatus(self::STATUS_INVALID_ACTIONS);
                return;
            }
            foreach ($rows as $index => $row) {
                $id = is_array($row) ? ($row['ID'] ?? null) : null;
                if (!is_string($id) || $id === '' || isset($ids[$id])) {
                    // Ohne eindeutige ID kann kein Wecker die Aktion auswählen.
                    $this->SendDebug('Configuration', $property . ', entry ' . ($index + 1) . ': missing or duplicate ID', 0);
                    $this->LogMessage($this->Translate('The central action list contains entries without a unique ID'), KL_ERROR);
                    $this->SetStatus(self::STATUS_INVALID_ACTIONS);
                    return;
                }
                $ids[$id] = true;
            }
        }

        $this->SendDebug('Configuration', count($ids) . ' action(s)', 0);
        $this->SetStatus(IS_ACTIVE);
    }

    // Abstimmung der Wecker für Mediaplayer-Aktionen (aufgerufen per IPS_RequestAction aus WakeUpManager):
    // MediaStarted vermerkt den Start, MediaStop führt die Ausschalt-Aktion nur für den jüngsten Start aus.
    public function RequestAction(string $Ident, mixed $Value): void
    {
        $value = is_string($Value) ? json_decode($Value, true) : null;
        $id = is_array($value) ? ($value['ID'] ?? null) : null;
        $owner = is_array($value) ? ($value['Owner'] ?? null) : null;
        if (!in_array($Ident, ['MediaStarted', 'MediaStop'], true)) {
            throw new Exception('Invalid Ident');
        }
        if (!is_string($id) || $id === '' || !is_int($owner) || $owner < 0) {
            throw new Exception($this->Translate('Invalid value'));
        }

        $skippedFor = null;
        $semaphore = 'WUA_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 5000)) {
            $this->SendDebug('Media', 'Semaphore could not be entered', 0);
            throw new Exception($this->Translate('The media player coordination is busy, please try again'));
        }
        try {
            $starts = json_decode($this->ReadAttributeString('MediaStarts'), true);
            if (!is_array($starts)) {
                // Reine Verwaltungsdaten; ohne sie wird jedes Ausschalten ausgeführt (sicherer als ein weiterlaufendes Gerät).
                $this->SendDebug('Media', 'Start log cannot be read, rebuilding', 0);
                $starts = [];
            }
            if ($Ident === 'MediaStarted') {
                if (($starts[$id] ?? null) !== $owner) {
                    $starts[$id] = $owner;
                    $this->WriteAttributeString('MediaStarts', json_encode((object) $starts));
                }
                return;
            }
            if (isset($starts[$id]) && $starts[$id] !== $owner) {
                $this->SendDebug('Media', $id . ': switch-off by ' . $owner . ' skipped, started later by ' . $starts[$id], 0);
                $skippedFor = $starts[$id];
            }
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }

        // Meldungen und Ausschalt-Aktion außerhalb der Sperre
        if ($skippedFor !== null) {
            $this->LogMessage(sprintf($this->Translate('Switch-off of %s by %s skipped, started later by %s'), $this->MediaName($id), $this->OwnerName($owner), $this->OwnerName($skippedFor)), KL_MESSAGE);
            return;
        }
        $this->RunStopAction($id, $owner);
    }

    // Bezeichnung der Mediaplayer-Aktion für Meldungen, ersatzweise die ID
    private function MediaName(string $id): string
    {
        $row = $this->FindMediaRow($id);
        return is_string($row['Name'] ?? null) && $row['Name'] !== '' ? $row['Name'] : $id;
    }

    // Name des Weckers für Meldungen; 0 = Start von Hand
    private function OwnerName(int $owner): string
    {
        if ($owner === 0) {
            return $this->Translate('manual start');
        }
        return IPS_ObjectExists($owner) ? IPS_GetName($owner) . ' (#' . $owner . ')' : '#' . $owner;
    }

    private function FindMediaRow(string $id): ?array
    {
        foreach ($this->DecodeList($this->ReadPropertyString(self::MEDIA_PROPERTY)) ?? [] as $candidate) {
            if (is_array($candidate) && ($candidate['ID'] ?? null) === $id) {
                return $candidate;
            }
        }
        return null;
    }

    private function RunStopAction(string $id, int $owner): void
    {
        $row = $this->FindMediaRow($id);
        if ($row === null || ($row['SwitchOff'] ?? 1) === 0) {
            $this->SendDebug('Media', $id . ': no switch-off action', 0);
            return;
        }
        $name = is_string($row['Name'] ?? null) && $row['Name'] !== '' ? $row['Name'] : $id;
        $action = $row['StopAction'] ?? null;
        if (is_string($action)) {
            $action = json_decode($action, true);
        }
        if (!is_array($action) || !is_string($action['actionID'] ?? null) || $action['actionID'] === '' || !is_array($action['parameters'] ?? [])) {
            $this->SendDebug('Media', $name . ': no valid switch-off action', 0);
            return;
        }
        try {
            if (IPS_RunAction($action['actionID'], $action['parameters'] ?? [])) {
                $this->SendDebug('Media', $name . ': switch-off action executed', 0);
                $this->LogMessage(sprintf($this->Translate('Media player switched off: %s (alarm clock %s)'), $name, $this->OwnerName($owner)), KL_MESSAGE);
                return;
            }
            $this->SendDebug('Media', $name . ': switch-off action failed', 0);
        } catch (Throwable $e) {
            $this->SendDebug('Media', $name . ': switch-off action failed: ' . $e->getMessage(), 0);
        }
        $this->LogMessage(sprintf($this->Translate('Switch-off action failed: %s'), $name), KL_WARNING);
    }

    // Liefert die Liste oder null, wenn der Wert keine JSON-Liste ist.
    private function DecodeList(mixed $value): ?array
    {
        $list = is_string($value) ? json_decode($value, true) : $value;
        return is_array($list) && array_is_list($list) ? $list : null;
    }
}
