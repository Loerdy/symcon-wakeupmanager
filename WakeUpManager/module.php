<?php

declare(strict_types=1);

class WakeUpManager extends IPSModuleStrict
{
    // Verpasste Zeitpunkte werden nur innerhalb dieser Toleranz (Sekunden) nachgeholt.
    private const GRACE_PERIOD = 300;

    // Maximaler Zeitversatz einer Aktion in Minuten (vorher bzw. nachher).
    private const MAX_OFFSET = 1440;

    private const TIMER = 'WakeUpTimer';

    private const ACTIONS_MODULE_ID = '{4E963960-1F66-A246-3BC9-BA42E525A6F7}';

    // Schlüssel entspricht dem ISO-8601-Wochentag (DateTime::format('N')).
    private const WEEKDAYS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday'
    ];

    // Darstellungen der Weckzeit in der kleinen Kachel; Schlüssel werden gespeichert und nicht übersetzt.
    private const DISPLAY_STYLES = ['standard', 'digital', 'flip'];

    // Tage mit eigener Weckzeit, wenn das Wochenende getrennt eingestellt ist.
    private const WEEKEND = [6, 7];

    // Eigenschaft je Gruppe in WakeUpActions und gleichnamige Liste im Formular hier; die Gruppenschlüssel
    // werden nicht übersetzt. Nur Aktionen der Gruppe "heating" haben einen Zeitversatz.
    private const GROUP_PROPERTIES = [
        'light'   => 'ActionsLight',
        'shutter' => 'ActionsShutter',
        'heating' => 'ActionsHeating',
        'other'   => 'ActionsOther',
        'script'  => 'ActionsScript'
    ];
    private const OFFSET_GROUP = 'heating';

    // Frühere gemeinsame Auswahlliste (vor den Gruppen); wird per Migrate übernommen.
    private const LEGACY_PROPERTY = 'Actions';

    // Inhalt einer Vorlage: Attribut => Typ. Gespeichert werden die unveränderten Rohdaten, damit nichts verloren geht.
    private const TEMPLATE_ATTRIBUTES = [
        'WakeUpTime'        => 'string',
        'SeparateWeekend'   => 'bool',
        'WakeUpTimeWeekend' => 'string',
        'Weekdays'          => 'string',
        'Selection'         => 'string'
    ];
    private const MAX_TEMPLATES = 20;
    private const MAX_TEMPLATE_NAME = 40;

    private const STATUS_INVALID_TIME = 201;
    private const STATUS_NO_WEEKDAY = 202;
    private const STATUS_INVALID_ACTIONS = 203;
    private const STATUS_CATALOG_UNAVAILABLE = 204;
    private const STATUS_INVALID_WEEKDAYS = 205;
    public function Create(): void
    {
        parent::Create();

        // Einrichtung über die Konsole ("Übernehmen")
        $this->RegisterPropertyInteger('ActionInstance', 0);
        $this->RegisterPropertyString('Icon', 'alarm-clock');

        // Einstellungen für die tägliche Bedienung (Kachel und Konsole) werden vom Modul nach Prüfung sofort gespeichert.
        $this->RegisterAttributeBoolean('Active', false);
        $this->RegisterAttributeString('WakeUpTime', '{"hour":6,"minute":30,"second":0}');
        // Eigene Weckzeit für Sa/So; leer = noch nie eingestellt (beim ersten Einschalten wird WakeUpTime übernommen).
        $this->RegisterAttributeBoolean('SeparateWeekend', false);
        $this->RegisterAttributeString('WakeUpTimeWeekend', '');
        // Aktive Wochentage als ISO-8601-Nummern (1 = Montag)
        $this->RegisterAttributeString('Weekdays', '[1,2,3,4,5]');
        // Auswahl aus den zentralen Aktionen: [{"ID": "<ID der zentralen Aktion>", "Active": bool, "Offset": Minuten}, ...]
        $this->RegisterAttributeString('Selection', '[]');
        // Vom Benutzer in der Kachel gewählte Darstellung der Weckzeit
        $this->RegisterAttributeString('DisplayStyle', 'standard');
        // Vorlagen (z. B. Früh-/Spätschicht): [{"Name": "...", "Settings": {"<Attribut>": <Rohwert>, ...}}, ...]
        $this->RegisterAttributeString('Templates', '[]');

        // Ende des zuletzt abgearbeiteten Zeitfensters (Unix-Zeitstempel); -1 = Wecker war deaktiviert, nichts nachholen.
        $this->RegisterAttributeInteger('LastProcessed', -1);
        // Bereits ausgeführte Aktionen je Weckvorgang: {"<Wecktag Y-m-d>": ["<Aktions-ID>", ...]}
        $this->RegisterAttributeString('Executed', '{}');

        $this->RegisterTimer(self::TIMER, 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'ProcessTimer\', 0);');

        // HTML-Kachel auch im maximierten Zustand verwenden.
        $this->SetVisualizationType(INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN);
    }

    // Übernimmt die Einstellungen aus den früheren Eigenschaften (Active, WakeUpTime, Monday..Sunday, Auswahllisten je
    // Gruppe bzw. gemeinsame Altliste) einmalig in die Attribute. Bereits vorhandene Attribute werden nie überschrieben.
    public function Migrate(string $JSONData): string
    {
        parent::Migrate($JSONData);

        // Als Objekte dekodieren, damit leere JSON-Objekte nicht als Listen zurückgeschrieben werden.
        $data = json_decode($JSONData);
        if (!is_object($data) || !isset($data->configuration) || !is_object($data->configuration)) {
            return '';
        }
        $configuration = $data->configuration;
        if (!isset($data->attributes) || !is_object($data->attributes)) {
            $data->attributes = new stdClass();
        }
        $attributes = $data->attributes;
        $changed = false;

        if (!property_exists($attributes, 'Active') && is_bool($configuration->Active ?? null)) {
            $attributes->Active = $configuration->Active;
            $changed = true;
        }
        if (!property_exists($attributes, 'WakeUpTime') && is_string($configuration->WakeUpTime ?? null)) {
            $attributes->WakeUpTime = $configuration->WakeUpTime;
            $changed = true;
        }
        if (!property_exists($attributes, 'Weekdays')) {
            $found = false;
            $days = [];
            foreach (self::WEEKDAYS as $day => $name) {
                if (property_exists($configuration, $name)) {
                    $found = true;
                    if ($configuration->$name === true) {
                        $days[] = $day;
                    }
                } elseif ($day <= 5) {
                    // Fehlender Wochentag: bisheriger Standardwert (Mo–Fr aktiv)
                    $days[] = $day;
                }
            }
            if ($found) {
                $attributes->Weekdays = json_encode($days);
                $changed = true;
            }
        }
        if (!property_exists($attributes, 'Selection')) {
            $selection = $this->MigrateSelection($configuration);
            if ($selection !== null) {
                $attributes->Selection = $selection;
                $changed = true;
            }
        }

        if (!$changed) {
            return '';
        }
        $result = json_encode($data);
        return $result === false ? '' : $result;
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        foreach ($this->GetReferenceList() as $reference) {
            $this->UnregisterReference($reference);
        }
        $actionInstance = $this->ReadPropertyInteger('ActionInstance');
        if ($actionInstance > 0) {
            $this->RegisterReference($actionInstance);
        }

        // Andere Instanzen erst ansprechen, wenn der Kernel bereit ist; sonst übernimmt das IPS_KERNELSTARTED.
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->RegisterCatalogMessages();
        $this->Process();

        // Die Aktionsliste hängt von der gewählten zentralen Instanz ab.
        $this->ReloadForm();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->RegisterCatalogMessages();
            $this->Process();
            return;
        }
        if (($Message === IM_CHANGESTATUS || $Message === IM_CHANGESETTINGS)
            && $SenderID === $this->ReadPropertyInteger('ActionInstance')
            && IPS_GetKernelRunlevel() === KR_READY) {
            $this->Process();
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        try {
            $this->HandleRequest($Ident, $Value);
        } catch (Exception $e) {
            // Abgelehnte oder nicht gespeicherte Änderung: Kachel auf den gespeicherten Stand zurücksetzen.
            $this->UpdateTile();
            throw $e;
        }
    }

    public function GetConfigurationForm(): string
    {
        $content = file_get_contents(__DIR__ . '/form.json');
        $form = is_string($content) ? json_decode($content, true) : null;
        if (!is_array($form) || !isset($form['actions']) || !is_array($form['actions'])) {
            $this->SendDebug('Form', 'form.json cannot be read', 0);
            return is_string($content) ? $content : '{}';
        }

        $actionInstance = $this->ReadPropertyInteger('ActionInstance');
        $context = [
            'actionInstance' => $actionInstance,
            'catalog'        => $actionInstance > 0 ? $this->LoadCatalog($actionInstance) : null,
            'selection'      => $this->LoadSelection($invalid),
            'invalid'        => $invalid,
            'time'           => $this->ParseTime($this->ReadAttributeString('WakeUpTime')),
            'weekendTime'    => $this->GetWeekendTime(),
            'weekdays'       => $this->LoadWeekdays(),
            'templates'      => $this->TemplateNames()
        ];
        $this->FillFormElements($form['actions'], $context);

        return (string) json_encode($form, JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function GetVisualizationTile(): string
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        if ($html === false) {
            $this->SendDebug('Tile', 'module.html cannot be read', 0);
            return '';
        }
        // Vollständiger Initialzustand; handleMessage ist erst nach module.html definiert. json_encode maskiert
        // "/", ein "</script>" in Aktionsnamen kann den Skriptblock deshalb nicht beenden.
        return $html . '<script>handleMessage(' . json_encode($this->GetTileState()) . ');</script>';
    }

    // Führt die früheren Auswahllisten (je Gruppe und gemeinsame Altliste) zu einer Liste zusammen; der erste Eintrag
    // je ID gilt. Ist eine Liste nicht lesbar, wird deren Originalinhalt unverändert übernommen (Status 203 statt
    // stillem Verlust). Einträge ohne ID (Format vor den zentralen Aktionen) sind nicht zuordenbar und entfallen.
    private function MigrateSelection(object $configuration): ?string
    {
        $present = false;
        $rows = [];
        $seen = [];
        foreach ([...array_values(self::GROUP_PROPERTIES), self::LEGACY_PROPERTY] as $property) {
            if (!property_exists($configuration, $property)) {
                continue;
            }
            $present = true;
            $list = $this->DecodeList($configuration->$property);
            if ($list === null) {
                return is_string($configuration->$property) ? $configuration->$property : (string) json_encode($configuration->$property);
            }
            foreach ($list as $row) {
                $id = is_array($row) ? ($row['ID'] ?? null) : null;
                if (is_string($id) && $id !== '' && !isset($seen[$id])) {
                    $seen[$id] = true;
                    $rows[] = $row;
                }
            }
        }
        if (!$present) {
            return null;
        }
        $encoded = json_encode($rows);
        return $encoded === false ? null : $encoded;
    }

    // Nach Neuladen (z. B. Modul-Update) oder Änderung der zentralen Aktionen neu berechnen.
    private function RegisterCatalogMessages(): void
    {
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                if ($message === IM_CHANGESTATUS || $message === IM_CHANGESETTINGS) {
                    $this->UnregisterMessage($senderID, $message);
                }
            }
        }
        $actionInstance = $this->ReadPropertyInteger('ActionInstance');
        if ($actionInstance > 0 && IPS_InstanceExists($actionInstance)) {
            $this->RegisterMessage($actionInstance, IM_CHANGESTATUS);
            $this->RegisterMessage($actionInstance, IM_CHANGESETTINGS);
        }
    }

    private function HandleRequest(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'ProcessTimer':
                $this->Process();
                break;
            case 'SetActive':
                // Die Kachel sendet den Wert JSON-kodiert, die Konsole als bool.
                $active = is_string($Value) ? json_decode($Value) : $Value;
                if (!is_bool($active)) {
                    $this->RejectValue($Ident);
                }
                $this->UpdateSettings(fn () => $this->WriteAttributeBoolean('Active', $active));
                break;
            case 'SetWakeUpTime':
                $time = $this->ParseTime($Value);
                if ($time === null) {
                    $this->RejectValue($Ident);
                }
                $this->UpdateSettings(fn () => $this->WriteAttributeString('WakeUpTime', json_encode($time)));
                break;
            case 'SetSeparateWeekend':
                $separate = is_string($Value) ? json_decode($Value) : $Value;
                if (!is_bool($separate)) {
                    $this->RejectValue($Ident);
                }
                $this->UpdateSettings(function () use ($separate): void
                {
                    // Beim ersten Einschalten die bisherige Weckzeit für das Wochenende übernehmen.
                    if ($separate && $this->ReadAttributeString('WakeUpTimeWeekend') === '') {
                        $time = $this->ParseTime($this->ReadAttributeString('WakeUpTime'));
                        if ($time !== null) {
                            $this->WriteAttributeString('WakeUpTimeWeekend', json_encode($time));
                        }
                    }
                    $this->WriteAttributeBoolean('SeparateWeekend', $separate);
                });
                break;
            case 'SetDisplayStyle':
                if (!in_array($Value, self::DISPLAY_STYLES, true)) {
                    $this->RejectValue($Ident);
                }
                // Reine Darstellung: keine Neuberechnung des Weckers nötig.
                if ($this->ReadAttributeString('DisplayStyle') !== $Value) {
                    $this->WriteAttributeString('DisplayStyle', $Value);
                }
                $this->UpdateTile();
                break;
            case 'SetWakeUpTimeWeekend':
                $time = $this->ParseTime($Value);
                if ($time === null) {
                    $this->RejectValue($Ident);
                }
                $this->UpdateSettings(fn () => $this->WriteAttributeString('WakeUpTimeWeekend', json_encode($time)));
                break;
            case 'SetWeekday':
                $this->SetWeekday($this->DecodeObject($Value));
                break;
            case 'SetAction':
                $this->SetAction($this->DecodeObject($Value));
                break;
            case 'DeleteAction':
                if (!is_string($Value) || $Value === '') {
                    $this->RejectValue($Ident);
                }
                $this->UpdateSelection($Value, null);
                break;
            case 'RunActions':
                ['error' => $error, 'message' => $message] = $this->RunSelectedActions();
                $this->Respond(is_string($Value) ? $Value : 'script', $message, $error);
                break;
            case 'SaveTemplate':
            case 'LoadTemplate':
            case 'DeleteTemplate':
                // Kachel und Konsole senden {"Name", "Source"}, Skripte nur den Namen.
                $request = is_string($Value) ? json_decode($Value, true) : null;
                $name = is_array($request) ? ($request['Name'] ?? null) : $Value;
                $source = is_array($request) && is_string($request['Source'] ?? null) ? $request['Source'] : 'script';
                try {
                    $message = match ($Ident) {
                        'SaveTemplate'   => $this->SaveTemplate($name),
                        'LoadTemplate'   => $this->LoadTemplate($name),
                        'DeleteTemplate' => $this->DeleteTemplate($name)
                    };
                } catch (Exception $e) {
                    $this->Respond($source, $e->getMessage(), true);
                    break;
                }
                // Geänderte Vorlagenliste an offene Kacheln senden (Laden aktualisiert die Kachel bereits über Process()).
                if ($Ident !== 'LoadTemplate') {
                    $this->UpdateTile();
                }
                if ($source === 'console') {
                    // Erfolg zeigt das neu geladene Formular (Auswahlliste, geladene Einstellungen); ein Popup ginge dabei verloren.
                    $this->ReloadForm();
                } else {
                    $this->Respond($source, $message, false);
                }
                break;
            default:
                throw new Exception('Invalid Ident');
        }
    }

    // Rückmeldung je Aufrufer: Kachel per Hinweis, Konsole per Popup, Skripte per Exception im Fehlerfall.
    private function Respond(string $source, string $message, bool $error): void
    {
        if ($source === 'tile') {
            $this->UpdateVisualizationValue((string) json_encode(['notice' => $message, 'error' => $error]));
        } elseif ($source === 'console') {
            $this->UpdateFormField('RunResultText', 'caption', $message);
            $this->UpdateFormField('RunResult', 'visible', true);
        } elseif ($error) {
            throw new Exception($message);
        }
    }

    // Vorlagen als Liste oder null, wenn die gespeicherten Daten nicht lesbar sind.
    private function LoadTemplates(): ?array
    {
        $templates = $this->DecodeList($this->ReadAttributeString('Templates'));
        if ($templates === null) {
            $this->SendDebug('Templates', 'Templates cannot be read', 0);
        }
        return $templates;
    }

    // Namen der lesbaren Vorlagen in gespeicherter Reihenfolge (für Kachel und Konsole).
    private function TemplateNames(): array
    {
        $names = [];
        foreach ($this->LoadTemplates() ?? [] as $template) {
            if (is_array($template) && is_string($template['Name'] ?? null)) {
                $names[] = $template['Name'];
            }
        }
        return $names;
    }

    // Index der Vorlage mit diesem Namen (ohne Rücksicht auf Groß-/Kleinschreibung) oder null.
    private function FindTemplate(array $templates, string $name): ?int
    {
        foreach ($templates as $index => $template) {
            if (is_array($template) && is_string($template['Name'] ?? null) && mb_strtolower($template['Name']) === mb_strtolower($name)) {
                return $index;
            }
        }
        return null;
    }

    private function TemplateName(mixed $name): string
    {
        $name = is_string($name) ? trim($name) : '';
        if ($name === '' || mb_strlen($name) > self::MAX_TEMPLATE_NAME) {
            throw new Exception($this->Translate('Please enter a name with 1 to 40 characters'));
        }
        return $name;
    }

    // Speichert die aktuellen Einstellungen unter dem Namen; eine gleichnamige Vorlage wird überschrieben.
    private function SaveTemplate(mixed $name): string
    {
        $name = $this->TemplateName($name);
        $this->Locked(function () use ($name): void
        {
            $templates = $this->LoadTemplates();
            if ($templates === null) {
                throw new Exception($this->Translate('The saved templates cannot be read'));
            }
            $settings = [];
            foreach (self::TEMPLATE_ATTRIBUTES as $attribute => $type) {
                $settings[$attribute] = $type === 'bool' ? $this->ReadAttributeBoolean($attribute) : $this->ReadAttributeString($attribute);
            }
            $index = $this->FindTemplate($templates, $name);
            if ($index === null && count($templates) >= self::MAX_TEMPLATES) {
                throw new Exception(sprintf($this->Translate('No more than %d templates can be saved'), self::MAX_TEMPLATES));
            }
            $templates[$index ?? count($templates)] = ['Name' => $name, 'Settings' => $settings];
            $this->WriteTemplates($templates);
        });
        return sprintf($this->Translate('Template "%s" saved'), $name);
    }

    // Übernimmt die Einstellungen der Vorlage wie jede andere Einstellungsänderung (kein Nachholen, keine
    // erneute Ausführung am selben Tag). Aktiv-Schalter und Darstellung bleiben unverändert.
    private function LoadTemplate(mixed $name): string
    {
        $name = $this->TemplateName($name);
        $templates = $this->LoadTemplates();
        if ($templates === null) {
            throw new Exception($this->Translate('The saved templates cannot be read'));
        }
        $index = $this->FindTemplate($templates, $name);
        if ($index === null) {
            throw new Exception($this->Translate('The template does not exist'));
        }
        $settings = $templates[$index]['Settings'] ?? null;
        foreach (self::TEMPLATE_ATTRIBUTES as $attribute => $type) {
            $value = is_array($settings) ? ($settings[$attribute] ?? null) : null;
            if (($type === 'bool' && !is_bool($value)) || ($type === 'string' && !is_string($value))) {
                throw new Exception($this->Translate('The template cannot be read'));
            }
        }

        $this->UpdateSettings(function () use ($settings): void
        {
            foreach (self::TEMPLATE_ATTRIBUTES as $attribute => $type) {
                if ($type === 'bool') {
                    $this->WriteAttributeBoolean($attribute, $settings[$attribute]);
                } else {
                    $this->WriteAttributeString($attribute, $settings[$attribute]);
                }
            }
        });
        return sprintf($this->Translate('Template "%s" loaded'), $templates[$index]['Name']);
    }

    private function DeleteTemplate(mixed $name): string
    {
        $name = $this->TemplateName($name);
        $this->Locked(function () use ($name): void
        {
            $templates = $this->LoadTemplates();
            if ($templates === null) {
                throw new Exception($this->Translate('The saved templates cannot be read'));
            }
            $index = $this->FindTemplate($templates, $name);
            if ($index === null) {
                throw new Exception($this->Translate('The template does not exist'));
            }
            unset($templates[$index]);
            $this->WriteTemplates(array_values($templates));
        });
        return sprintf($this->Translate('Template "%s" deleted'), $name);
    }

    private function WriteTemplates(array $templates): void
    {
        $encoded = json_encode($templates);
        if ($encoded === false) {
            throw new Exception($this->Translate('The setting could not be saved, please try again'));
        }
        $this->WriteAttributeString('Templates', $encoded);
    }

    // Führt $call unter der Instanzsperre aus (wie Einstellungen und Weckroutine).
    private function Locked(callable $call): void
    {
        $semaphore = 'WUM_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 5000)) {
            $this->SendDebug('Settings', 'Semaphore could not be entered', 0);
            throw new Exception($this->Translate('The setting could not be saved, please try again'));
        }
        try {
            $call();
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }
    }

    // Startet die ausgewählten Aktionen sofort (z. B. zum Testen oder wenn man vor dem Wecker wach ist), unabhängig von Aktiv-Schalter, Weckzeit und Zeitversatz.
    // Protokoll, Zeitfenster und Timer der Weckroutine bleiben unverändert, das reguläre Wecken läuft normal.
    // Bedingungen der Aktionen gelten auch hier. Liefert eine übersetzte Meldung (Anzahl gestartet/übersprungen/
    // fehlgeschlagen bzw. der Grund, warum nichts gestartet wurde) und ob es sich um einen Fehler handelt.
    private function RunSelectedActions(): array
    {
        $selection = $this->LoadSelection();
        if ($selection === null) {
            return ['error' => true, 'message' => $this->Translate('The action selection cannot be read')];
        }
        $actionInstance = $this->ReadPropertyInteger('ActionInstance');
        $catalog = $actionInstance > 0 ? $this->LoadCatalog($actionInstance) : null;
        if ($catalog === null) {
            return ['error' => true, 'message' => $this->Translate('The central actions are not available')];
        }

        // Reihenfolge wie in der Konsole: nach Gruppen und zentraler Liste
        $results = ['started' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($catalog as $id => $definition) {
            if (!($selection[$id]['Active'] ?? false)) {
                continue;
            }
            $this->SendDebug('Run', 'Starting ' . $definition['name'], 0);
            $results[$this->RunAction($definition['name'], $definition)]++;
        }
        if (array_sum($results) === 0) {
            return ['error' => true, 'message' => $this->Translate('No actions are selected')];
        }

        $parts = [sprintf($this->Translate('%d action(s) started'), $results['started'])];
        if ($results['skipped'] > 0) {
            $parts[] = sprintf($this->Translate('%d skipped (condition not met)'), $results['skipped']);
        }
        if ($results['failed'] > 0) {
            $parts[] = sprintf($this->Translate('%d failed'), $results['failed']);
        }
        return ['error' => $results['failed'] > 0, 'message' => implode(', ', $parts)];
    }

    private function SetWeekday(?array $value): void
    {
        $day = $value['Day'] ?? null;
        $active = $value['Active'] ?? null;
        if (!is_int($day) || !isset(self::WEEKDAYS[$day]) || !is_bool($active)) {
            $this->RejectValue('SetWeekday');
        }
        $this->UpdateSettings(function () use ($day, $active): void
        {
            $weekdays = $this->LoadWeekdays();
            if ($weekdays === null) {
                // Nicht lesbare gespeicherte Wochentage nicht überschreiben.
                throw new Exception($this->Translate('The weekdays cannot be read'));
            }
            $weekdays[$day] = $active;
            ksort($weekdays);
            $this->WriteAttributeString('Weekdays', json_encode(array_keys(array_filter($weekdays))));
        });
    }

    private function SetAction(?array $value): void
    {
        $id = $value['ID'] ?? null;
        if (!is_string($id) || $id === '') {
            $this->RejectValue('SetAction');
        }
        $active = $value['Active'] ?? null;
        if ($active !== null && !is_bool($active)) {
            $this->RejectValue('SetAction');
        }
        $offset = null;
        if (isset($value['Offset'])) {
            $offset = $this->NormalizeOffset($value['Offset']);
            if ($offset === null) {
                $this->RejectValue('SetAction');
            }
        }
        // Nur Aktionen der gewählten zentralen Instanz dürfen ausgewählt werden.
        $actionInstance = $this->ReadPropertyInteger('ActionInstance');
        $catalog = $actionInstance > 0 ? $this->LoadCatalog($actionInstance) : null;
        if ($catalog === null || !isset($catalog[$id])) {
            $this->RejectValue('SetAction');
        }

        $this->UpdateSelection($id, ['Active' => $active, 'Offset' => $offset]);
    }

    // Ändert unter der Sperre nur die Zeile(n) der Aktion $id in den gespeicherten Rohdaten; alle anderen Zeilen,
    // auch beschädigte oder unbekannte, bleiben unverändert. $change: neue Werte (null = beibehalten) oder null = löschen.
    // Eine nicht lesbare Auswahl wird nicht überschrieben; geschrieben wird nur bei einer tatsächlichen Änderung.
    private function UpdateSelection(string $id, ?array $change): void
    {
        $this->UpdateSettings(function () use ($id, $change): void
        {
            // Als Objekte dekodieren, damit leere JSON-Objekte nicht als Listen zurückgeschrieben werden.
            $rows = json_decode($this->ReadAttributeString('Selection'));
            if (!is_array($rows)) {
                $this->SendDebug('Settings', 'Action selection cannot be read, not changed', 0);
                throw new Exception($this->Translate('The action selection cannot be read'));
            }

            $changed = false;
            $found = false;
            foreach ($rows as $index => $row) {
                if (!is_object($row) || ($row->ID ?? null) !== $id) {
                    continue;
                }
                if ($change === null) {
                    unset($rows[$index]);
                    $changed = true;
                } elseif (!$found) {
                    // Doppelte IDs: wie beim Auswerten gilt die erste Zeile, weitere bleiben unverändert.
                    $found = true;
                    $changed = $this->ApplySelectionChange($row, $change) || $changed;
                }
            }
            if ($change !== null && !$found) {
                $row = (object) ['ID' => $id];
                $this->ApplySelectionChange($row, $change);
                $rows[] = $row;
                $changed = true;
            }
            if (!$changed) {
                return;
            }

            $encoded = json_encode(array_values($rows));
            if ($encoded === false) {
                throw new Exception($this->Translate('The setting could not be saved, please try again'));
            }
            $this->WriteAttributeString('Selection', $encoded);
        });
    }

    // Setzt Active und Offset einer Auswahlzeile; ungültige gespeicherte Werte werden dabei durch gültige ersetzt.
    // Liefert, ob sich die Zeile geändert hat. Weitere Felder der Zeile bleiben erhalten.
    private function ApplySelectionChange(object $row, array $change): bool
    {
        $active = $change['Active'] ?? (is_bool($row->Active ?? null) ? $row->Active : false);
        $offset = $change['Offset'] ?? $this->NormalizeOffset($row->Offset ?? 0) ?? 0;
        if (($row->Active ?? null) === $active && ($row->Offset ?? null) === $offset) {
            return false;
        }
        $row->Active = $active;
        $row->Offset = $offset;
        return true;
    }

    // Zeitversatz in Minuten als int oder null, wenn ungültig (JSON kann ganze Zahlen auch als Float liefern).
    private function NormalizeOffset(mixed $offset): ?int
    {
        if (is_float($offset) && floor($offset) === $offset) {
            $offset = (int) $offset;
        }
        return is_int($offset) && abs($offset) <= self::MAX_OFFSET ? $offset : null;
    }

    // Schreibt Einstellungen unter derselben Sperre wie die Weckroutine und berechnet danach den nächsten Zeitpunkt neu.
    private function UpdateSettings(callable $write): void
    {
        $due = [];
        $error = null;
        $this->Locked(function () use ($write, &$due, &$error): void
        {
            // Was nach der bisherigen Einstellung bis jetzt fällig war, wird noch ausgeführt. Danach beginnt das
            // Zeitfenster jetzt: Zeitpunkte, die erst durch die Änderung in der Vergangenheit liegen, werden nicht nachgeholt.
            $due = $this->TakeCurrentDueActions();
            try {
                $write();
            } catch (Exception $e) {
                // Bereits als ausgeführt gespeicherte Aktionen trotzdem starten, erst danach den Fehler melden.
                $error = $e;
            }
        });
        $this->Process($due);
        if ($error !== null) {
            throw $error;
        }
    }

    // Fällige Aktionen nach der gespeicherten Einstellung; nur unter der Sperre aufrufen.
    private function TakeCurrentDueActions(): array
    {
        if (!$this->ReadAttributeBoolean('Active')) {
            return [];
        }
        $now = time();
        $config = $this->LoadConfiguration();
        $due = $config === null ? [] : $this->TakeDueActions($config, $now);
        // Auch bei bisher ungültiger Einstellung (z. B. kein Wochentag) ab jetzt rechnen.
        if ($this->ReadAttributeInteger('LastProcessed') < $now) {
            $this->WriteAttributeInteger('LastProcessed', $now);
        }
        return $due;
    }

    private function RejectValue(string $ident): never
    {
        $this->SendDebug('Settings', 'Invalid value for ' . $ident, 0);
        throw new Exception($this->Translate('Invalid value'));
    }

    // Werte aus Konsole bzw. Kachel kommen JSON-kodiert oder bereits als Array.
    private function DecodeObject(mixed $value): ?array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        return is_array($decoded) ? $decoded : null;
    }

    // Akzeptiert {"hour", "minute", "second"} (SelectTime, JSON-kodiert oder als Array) oder "HH:MM[:SS]".
    private function ParseTime(mixed $value): ?array
    {
        if (is_string($value) && preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $match) === 1) {
            $time = ['hour' => (int) $match[1], 'minute' => (int) $match[2], 'second' => (int) ($match[3] ?? 0)];
        } else {
            $time = $this->DecodeObject($value);
            if ($time === null) {
                return null;
            }
            $time += ['second' => 0];
        }
        foreach (['hour' => 23, 'minute' => 59, 'second' => 59] as $key => $maximum) {
            if (!$this->IsValidTimePart($time, $key, $maximum)) {
                return null;
            }
        }
        return ['hour' => $time['hour'], 'minute' => $time['minute'], 'second' => $time['second']];
    }

    // Setzt die gespeicherten Einstellungen in die Elemente des Bereichs "actions" (inkl. verschachtelter Elemente).
    // Captions übersetzt die Konsole anhand der locale.json; Translate() nur für Werte und Logmeldungen.
    private function FillFormElements(array &$elements, array $context): void
    {
        foreach ($elements as &$element) {
            $name = $element['name'] ?? '';
            if ($name === 'Active') {
                $element['value'] = $this->ReadAttributeBoolean('Active');
            } elseif ($name === 'WakeUpTime') {
                if ($context['time'] !== null) {
                    $element['value'] = json_encode($context['time']);
                }
            } elseif ($name === 'SeparateWeekend') {
                $element['value'] = $this->ReadAttributeBoolean('SeparateWeekend');
            } elseif ($name === 'WakeUpTimeWeekend') {
                if ($context['weekendTime'] !== null) {
                    $element['value'] = json_encode($context['weekendTime']);
                }
            } elseif (($day = array_search($name, self::WEEKDAYS, true)) !== false) {
                if ($context['weekdays'] === null) {
                    // Nicht lesbare gespeicherte Wochentage nicht durch Bedienung überschreiben lassen.
                    $element['enabled'] = false;
                } else {
                    $element['value'] = isset($context['weekdays'][$day]);
                }
            } elseif ($name === 'TemplateSelect') {
                $element['options'] = array_map(static fn (string $template): array => ['caption' => $template, 'value' => $template], $context['templates']);
                if (count($element['options']) === 0) {
                    // Die Konsole braucht einen Eintrag zum aktuellen Wert, sonst meldet sie "Wert nicht verfügbar".
                    $element['options'][] = ['caption' => $this->Translate('No templates saved'), 'value' => ''];
                }
                $element['value'] = $context['templates'][0] ?? '';
                $element['enabled'] = count($context['templates']) > 0;
            } elseif (in_array($name, ['TemplateLoad', 'TemplateOverwrite', 'TemplateDelete'], true)) {
                $element['enabled'] = count($context['templates']) > 0;
            } elseif ($name === 'ActionsHint') {
                if ($context['actionInstance'] <= 0) {
                    $element['caption'] = 'Select the central actions and apply; the actions will then be listed here.';
                    $element['visible'] = true;
                } elseif ($context['catalog'] === null) {
                    $element['caption'] = 'The central actions are not available. The current selection is kept.';
                    $element['visible'] = true;
                } elseif ($context['selection'] === null) {
                    $element['caption'] = 'The saved action selection cannot be read and will not be overwritten.';
                    $element['visible'] = true;
                } elseif ($context['invalid'] > 0) {
                    // Mit Anzahl, deshalb hier übersetzt statt durch die Konsole.
                    $element['caption'] = sprintf($this->Translate('%d entries of the stored action selection are invalid and will be ignored. They are kept unchanged.'), $context['invalid']);
                    $element['visible'] = true;
                }
            } elseif (($group = array_search($name, self::GROUP_PROPERTIES, true)) !== false) {
                if ($context['catalog'] === null || $context['selection'] === null) {
                    $element['visible'] = false;
                } else {
                    $element['values'] = $this->BuildSelectionRows($group, $context['catalog'], $context['selection']);
                    $element['visible'] = count($element['values']) > 0;
                }
            }
            if (isset($element['items']) && is_array($element['items'])) {
                $this->FillFormElements($element['items'], $context);
            }
        }
        unset($element);
    }

    // Weckzeit für Sa/So zur Anzeige; solange sie nie eingestellt wurde, die Weckzeit, die beim Einschalten übernommen wird.
    private function GetWeekendTime(): ?array
    {
        $value = $this->ReadAttributeString('WakeUpTimeWeekend');
        return $this->ParseTime($value === '' ? $this->ReadAttributeString('WakeUpTime') : $value);
    }

    // Weckzeit je ISO-Wochentag oder null, wenn eine benötigte Weckzeit nicht lesbar ist.
    private function LoadTimes(): ?array
    {
        $time = $this->ParseTime($this->ReadAttributeString('WakeUpTime'));
        $weekendTime = $this->ReadAttributeBoolean('SeparateWeekend') ? $this->ParseTime($this->ReadAttributeString('WakeUpTimeWeekend')) : $time;
        if ($time === null || $weekendTime === null) {
            $this->SendDebug('Configuration', 'Invalid wake-up time', 0);
            return null;
        }
        $times = [];
        foreach (array_keys(self::WEEKDAYS) as $day) {
            $times[$day] = in_array($day, self::WEEKEND, true) ? $weekendTime : $time;
        }
        return $times;
    }

    // Liefert die aktiven Wochentage als [ISO-Nummer => true] oder null, wenn die gespeicherten Daten nicht lesbar sind.
    private function LoadWeekdays(): ?array
    {
        $list = $this->DecodeList($this->ReadAttributeString('Weekdays'));
        if ($list === null) {
            $this->SendDebug('Configuration', 'Weekdays cannot be read', 0);
            return null;
        }
        $weekdays = [];
        foreach ($list as $day) {
            if (!is_int($day) || !isset(self::WEEKDAYS[$day])) {
                $this->SendDebug('Configuration', 'Weekdays cannot be read', 0);
                return null;
            }
            $weekdays[$day] = true;
        }
        ksort($weekdays);
        return $weekdays;
    }

    private function UpdateTile(): void
    {
        $this->UpdateVisualizationValue($this->GetTileState());
    }

    // Zustand für die Kachel. Liest nur gespeicherte Daten, ohne Status oder Meldungen zu setzen.
    private function GetTileState(): string
    {
        $active = $this->ReadAttributeBoolean('Active');
        $status = $this->GetStatus();
        $time = $this->ParseTime($this->ReadAttributeString('WakeUpTime'));
        $weekendTime = $this->GetWeekendTime();
        $times = $this->LoadTimes();
        $weekdays = $this->LoadWeekdays();

        // Tage und Uhrzeiten in der Zeitzone von IP-Symcon, nicht in der des Browsers.
        $timezone = new DateTimeZone(date_default_timezone_get());
        $next = null;
        // Bezugspunkt für die Startzeiten der Heizung: nächster Weckzeitpunkt, sonst die Weckzeit am heutigen Tag.
        $reference = null;
        if ($times !== null) {
            $today = new DateTimeImmutable('now', $timezone);
            $todayTime = $times[(int) $today->format('N')];
            $reference = $today->setTime($todayTime['hour'], $todayTime['minute'], $todayTime['second'])->getTimestamp();
        }
        if ($active && $times !== null && $weekdays !== null && in_array($status, [IS_ACTIVE, self::STATUS_CATALOG_UNAVAILABLE], true)) {
            $now = time();
            $occurrences = $this->GetOccurrences(['times' => $times, 'weekdays' => $weekdays], $now + 1, $now + 8 * 86400);
            if (count($occurrences) > 0) {
                $reference = min($occurrences);
                $date = (new DateTimeImmutable('@' . $reference))->setTimezone($timezone);
                $today = (new DateTimeImmutable('now', $timezone))->setTime(0, 0);
                $next = [
                    'time'    => $date->format('H:i'),
                    'weekday' => (int) $date->format('N'),
                    'days'    => $today->diff($date->setTime(0, 0))->days
                ];
            }
        }

        $hint = '';
        $groups = [];
        $actionInstance = $this->ReadPropertyInteger('ActionInstance');
        $catalog = $actionInstance > 0 ? $this->LoadCatalog($actionInstance) : null;
        $selection = $this->LoadSelection();
        if ($actionInstance <= 0) {
            $hint = 'noInstance';
        } elseif ($catalog === null) {
            $hint = 'catalog';
        } elseif ($selection === null) {
            $hint = 'selection';
        } else {
            // Auswahlen ohne zentrale Aktion werden nur in der Konsole angezeigt und dort gelöscht.
            foreach (array_keys(self::GROUP_PROPERTIES) as $group) {
                $actions = [];
                foreach ($catalog as $id => $action) {
                    if ($action['group'] !== $group) {
                        continue;
                    }
                    $row = ['id' => (string) $id, 'name' => $action['name'], 'active' => $selection[$id]['Active'] ?? false, 'condition' => $action['condition'] !== null];
                    if ($group === self::OFFSET_GROUP) {
                        $row['offset'] = $selection[$id]['Offset'] ?? 0;
                        if ($reference !== null) {
                            // Der Zeitversatz ist eine echte Zeitspanne (wie bei der Ausführung); days = Tagesversatz zur Weckzeit.
                            $start = (new DateTimeImmutable('@' . ($reference + $row['offset'] * 60)))->setTimezone($timezone);
                            $wakeDay = (new DateTimeImmutable('@' . $reference))->setTimezone($timezone)->setTime(0, 0);
                            $interval = $wakeDay->diff($start->setTime(0, 0));
                            $row['start'] = ['time' => $start->format('H:i'), 'days' => $interval->invert === 1 ? -$interval->days : $interval->days];
                        }
                    }
                    $actions[] = $row;
                }
                if (count($actions) > 0) {
                    $groups[] = ['key' => $group, 'actions' => $actions];
                }
            }
        }

        return (string) json_encode([
            'active'    => $active,
            'icon'      => $this->ReadPropertyString('Icon'),
            'style'     => $this->ReadAttributeString('DisplayStyle'),
            'status'    => $status,
            'time'      => $time === null ? null : sprintf('%02d:%02d', $time['hour'], $time['minute']),
            'separate'  => $this->ReadAttributeBoolean('SeparateWeekend'),
            'weekend'   => $weekendTime === null ? null : sprintf('%02d:%02d', $weekendTime['hour'], $weekendTime['minute']),
            'weekdays'  => $weekdays === null ? null : array_keys($weekdays),
            'next'      => $next,
            'hint'      => $hint,
            'groups'    => $groups,
            'templates' => $this->TemplateNames(),
            'maxOffset' => self::MAX_OFFSET
        ], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    // Eine Zeile je zentraler Aktion der Gruppe in zentraler Reihenfolge. Auswahlen, deren zentrale Aktion nicht
    // mehr existiert, bleiben löschbar am Ende der Gruppe "Sonstiges" erhalten.
    private function BuildSelectionRows(string $group, array $catalog, array $selection): array
    {
        $rows = [];
        foreach ($catalog as $id => $action) {
            if ($action['group'] !== $group) {
                continue;
            }
            $row = [
                'ID'        => (string) $id,
                'Name'      => $action['name'],
                'Active'    => $selection[$id]['Active'] ?? false,
                'Condition' => $action['condition'] !== null ? $this->Translate('yes') : '',
                'deletable' => false
            ];
            if ($group === self::OFFSET_GROUP) {
                $row['Offset'] = $selection[$id]['Offset'] ?? 0;
            }
            $rows[] = $row;
        }
        if ($group === 'other') {
            foreach ($selection as $id => $entry) {
                if (!isset($catalog[$id])) {
                    $rows[] = [
                        'ID'        => (string) $id,
                        'Name'      => $this->Translate('No longer available'),
                        'Active'    => $entry['Active'],
                        'Condition' => '',
                        'rowColor'  => '#FFC0C0',
                        'deletable' => true
                    ];
                }
            }
        }
        return $rows;
    }

    // Liefert die Liste oder null, wenn der Wert keine JSON-Liste ist.
    private function DecodeList(mixed $value): ?array
    {
        $list = is_string($value) ? json_decode($value, true) : $value;
        return is_array($list) && array_is_list($list) ? $list : null;
    }

    // $pending: bereits als ausgeführt gespeicherte Aktionen, die noch auszuführen sind.
    private function Process(array $pending = []): void
    {
        $due = [...$pending, ...$this->ProcessSchedule()];
        // Nach jeder Neuberechnung (Einstellung, Timer, Start, zentrale Aktionen) die Kachel aktualisieren.
        $this->UpdateTile();

        // Aktionen außerhalb der Sperre starten (Sperre so kurz wie möglich, Kachel zuerst aktuell). Sie sind
        // bereits als ausgeführt gespeichert, ein paralleler Aufruf startet sie deshalb nicht erneut.
        foreach ($due as $action) {
            $this->RunAction($action['label'], $action['definition']);
        }
    }

    // Plant den nächsten Zeitpunkt und liefert die fälligen Aktionen, die der Aufrufer ausführen muss.
    private function ProcessSchedule(): array
    {
        $semaphore = 'WUM_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 5000)) {
            $this->SendDebug('Process', 'Semaphore could not be entered', 0);
            $this->LogMessage($this->Translate('Wake-up routine could not be processed (semaphore busy)'), KL_WARNING);
            // Der Timer würde sonst erst nach seinem bisherigen, ggf. stundenlangen Intervall erneut auslösen.
            $this->SetTimerInterval(self::TIMER, 1000);
            return [];
        }

        try {
            $now = time();

            if (!$this->ReadAttributeBoolean('Active')) {
                $this->ResetState();
                $this->SetTimerInterval(self::TIMER, 0);
                $this->SetStatus(IS_INACTIVE);
                return [];
            }

            $config = $this->LoadConfiguration();
            if ($config === null) {
                $this->SetTimerInterval(self::TIMER, 0);
                return [];
            }

            $due = $this->TakeDueActions($config, $now);
            $this->ScheduleNext($config, $now);
            return $due;
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }
    }

    // Meldet einen Fehler nur beim Wechsel in diesen Status im Meldungsfenster, nicht bei jeder Neuberechnung.
    private function SetErrorStatus(int $status, string $message, int $type): void
    {
        if ($this->GetStatus() !== $status) {
            $this->LogMessage($this->Translate($message), $type);
        }
        $this->SetStatus($status);
    }

    private function LoadConfiguration(): ?array
    {
        $times = $this->LoadTimes();
        if ($times === null) {
            $this->SetStatus(self::STATUS_INVALID_TIME);
            return null;
        }

        $weekdays = $this->LoadWeekdays();
        if ($weekdays === null) {
            $this->SetErrorStatus(self::STATUS_INVALID_WEEKDAYS, 'The weekdays of the alarm clock cannot be read', KL_ERROR);
            return null;
        }
        if (count($weekdays) === 0) {
            $this->SendDebug('Configuration', 'No weekday enabled', 0);
            $this->SetStatus(self::STATUS_NO_WEEKDAY);
            return null;
        }

        $selection = $this->LoadSelection();
        if ($selection === null) {
            $this->SetErrorStatus(self::STATUS_INVALID_ACTIONS, 'The action selection of the alarm clock cannot be read', KL_ERROR);
            return null;
        }

        // Ohne zentrale Aktionen wird weiter geplant, damit ein vorübergehender Fehler den Wecker nicht dauerhaft anhält.
        $actionInstance = $this->ReadPropertyInteger('ActionInstance');
        $catalog = $actionInstance > 0 ? $this->LoadCatalog($actionInstance) : null;
        if ($actionInstance > 0 && $catalog === null) {
            $this->SetErrorStatus(self::STATUS_CATALOG_UNAVAILABLE, 'The central actions of the alarm clock are not available', KL_WARNING);
        } else {
            $this->SetStatus(IS_ACTIVE);
        }

        $actions = [];
        foreach ($selection as $id => $entry) {
            if (!$entry['Active']) {
                continue;
            }
            if ($catalog !== null && !isset($catalog[$id])) {
                $this->SendDebug('Configuration', 'Selected action ' . $id . ' no longer exists', 0);
                continue;
            }
            // Nur Heizungsaktionen haben einen Zeitversatz. Ohne zentrale Aktionen ist die Gruppe unbekannt; die
            // Aktion ist dann ohnehin nicht ausführbar, geplant wird mit dem gespeicherten Versatz.
            $group = $catalog[$id]['group'] ?? null;
            $offset = ($group === null || $group === self::OFFSET_GROUP) ? $entry['Offset'] : 0;
            $actions[] = [
                'key'    => (string) $id,
                'label'  => $catalog[$id]['name'] ?? (string) $id,
                'offset' => $offset * 60
            ];
        }

        return [
            'times'    => $times,
            'weekdays' => $weekdays,
            'catalog'  => $catalog,
            'actions'  => $actions
        ];
    }

    private function IsValidTimePart(array $time, string $key, int $maximum): bool
    {
        return isset($time[$key]) && is_int($time[$key]) && $time[$key] >= 0 && $time[$key] <= $maximum;
    }

    // Liefert die eigene Auswahl je Aktions-ID oder null, wenn die gespeicherten Daten nicht lesbar sind.
    // Fehlerhafte Einträge werden einzeln übersprungen (bleiben gespeichert); $invalid liefert deren Anzahl.
    private function LoadSelection(?int &$invalid = null): ?array
    {
        $invalid = 0;
        $rows = $this->DecodeList($this->ReadAttributeString('Selection'));
        if ($rows === null) {
            $this->SendDebug('Configuration', 'Action selection cannot be read', 0);
            return null;
        }

        $selection = [];
        foreach ($rows as $index => $row) {
            $id = is_array($row) ? ($row['ID'] ?? null) : null;
            if (!is_string($id) || $id === '') {
                $this->SendDebug('Configuration', 'Selection ' . ($index + 1) . ': missing ID', 0);
                $invalid++;
                continue;
            }
            if (isset($selection[$id])) {
                // Doppelte IDs entstehen durch das Modul nicht; falls doch, gilt der erste Eintrag.
                $invalid++;
                continue;
            }
            $offset = $this->NormalizeOffset($row['Offset'] ?? 0);
            if ($offset === null) {
                $this->SendDebug('Configuration', 'Selection ' . ($index + 1) . ': invalid offset', 0);
                $invalid++;
                continue;
            }
            $selection[$id] = [
                'Active' => ($row['Active'] ?? false) === true,
                'Offset' => $offset
            ];
        }
        return $selection;
    }

    // Liefert die zentralen Aktionen je ID oder null, wenn die Instanz ungültig oder ihre Liste nicht lesbar ist.
    private function LoadCatalog(int $instanceID): ?array
    {
        if ($this->CallCatalog(static fn () => IPS_InstanceExists($instanceID)) !== true) {
            $this->SendDebug('Catalog', 'Instance ' . $instanceID . ' does not exist', 0);
            return null;
        }
        $instance = $this->CallCatalog(static fn () => IPS_GetInstance($instanceID));
        if (!is_array($instance) || ($instance['ModuleInfo']['ModuleID'] ?? '') !== self::ACTIONS_MODULE_ID) {
            $this->SendDebug('Catalog', 'Instance ' . $instanceID . ' is not a WakeUpActions instance', 0);
            return null;
        }
        $rows = [];
        foreach (self::GROUP_PROPERTIES as $group => $property) {
            $raw = $this->CallCatalog(static fn () => IPS_GetProperty($instanceID, $property));
            $list = is_string($raw) ? $this->DecodeList($raw) : null;
            if ($list === null) {
                $this->SendDebug('Catalog', 'Central action list ' . $property . ' cannot be read', 0);
                return null;
            }
            foreach ($list as $row) {
                $rows[] = [$group, $row];
            }
        }

        $catalog = [];
        foreach ($rows as $index => [$group, $row]) {
            $label = sprintf($this->Translate('Central action %d'), $index + 1);
            $id = is_array($row) ? ($row['ID'] ?? null) : null;
            if (!is_string($id) || $id === '' || isset($catalog[$id])) {
                $this->SendDebug('Catalog', $label . ': missing or duplicate ID', 0);
                continue;
            }
            $name = is_string($row['Name'] ?? null) && $row['Name'] !== '' ? $row['Name'] : $label;

            // Der Wert von SelectAction ist JSON-kodiert; je nach Speicherung kann er bereits als Array vorliegen.
            $action = $row['Action'] ?? null;
            if (is_string($action)) {
                $action = json_decode($action, true);
            }
            $valid = is_array($action) && is_string($action['actionID'] ?? null) && $action['actionID'] !== '' && is_array($action['parameters'] ?? []);
            if (!$valid) {
                $this->SendDebug('Catalog', $name . ': no valid action selected', 0);
            }
            $catalog[$id] = [
                'name'       => $name,
                'group'      => $group,
                'actionID'   => $valid ? $action['actionID'] : null,
                'parameters' => $valid ? ($action['parameters'] ?? []) : [],
                'condition'  => $this->NormalizeCondition($row['Condition'] ?? null)
            ];
        }
        return $catalog;
    }

    // Zugriff auf die zentrale Instanz. Während diese neu geladen wird (z. B. Modul-Update), melden die
    // IPS-Funktionen Warnungen; diese würden sonst z. B. das Konfigurationsformular unbrauchbar machen.
    private function CallCatalog(callable $call): mixed
    {
        $error = null;
        set_error_handler(static function (int $number, string $message) use (&$error): bool
        {
            $error = $message;
            return true;
        });
        try {
            $result = $call();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        } finally {
            restore_error_handler();
        }

        if ($error !== null) {
            $this->SendDebug('Catalog', 'Central instance not reachable: ' . $error, 0);
            return null;
        }
        return $result;
    }

    // Ermittelt die fälligen Aktionen und speichert sie als ausgeführt; ausgeführt werden sie vom Aufrufer.
    private function TakeDueActions(array $config, int $now): array
    {
        $lastProcessed = $this->ReadAttributeInteger('LastProcessed');
        $windowStart = $lastProcessed < 0 ? $now : max($lastProcessed, $now - self::GRACE_PERIOD);

        if ($lastProcessed > 0 && $lastProcessed < $windowStart) {
            // Nicht in die Vergangenheit unbegrenzt suchen; nur zur Protokollierung übersprungener Aktionen.
            $skippedFrom = max($lastProcessed, $now - 8 * 86400);
            $skipped = $this->GetActionEvents($config, $skippedFrom, $windowStart);
            if (count($skipped) > 0) {
                foreach ($skipped as $event) {
                    $this->SendDebug('Skipped', $event['action']['label'] . ' was due at ' . $this->FormatTime($event['time']), 0);
                }
                $this->LogMessage(sprintf($this->Translate('%d missed action(s) were not executed'), count($skipped)), KL_WARNING);
            }
        }

        $events = $this->GetActionEvents($config, $windowStart, $now);

        $executed = $this->ReadExecuted();
        $originalExecuted = $executed;
        $due = [];
        foreach ($events as $event) {
            // Ein Weckvorgang je Wecktag: Wird die Weckzeit verschoben, laufen bereits ausgeführte Aktionen
            // (z. B. die vorgeheizte Heizung) an diesem Tag nicht erneut.
            $day = $this->WakeDay($event['occurrence']);
            $key = $event['action']['key'];
            if (in_array($key, $executed[$day] ?? [], true)) {
                $this->SendDebug('Execute', $event['action']['label'] . ' was already executed for this wake-up', 0);
                continue;
            }
            $executed[$day][] = $key;
            $due[] = $event;
        }

        // Einträge entfernen, deren Weckvorgang keine fälligen Aktionen mehr haben kann.
        $oldestDay = $this->WakeDay($now - self::MAX_OFFSET * 60);
        foreach (array_keys($executed) as $day) {
            if ($day < $oldestDay) {
                unset($executed[$day]);
            }
        }

        // Zustand vor dem Ausführen speichern, damit jede Aktion höchstens einmal ausgeführt wird.
        if ($executed !== $originalExecuted) {
            $this->WriteAttributeString('Executed', json_encode((object) $executed));
        }
        if ($now > $lastProcessed) {
            $this->WriteAttributeInteger('LastProcessed', $now);
        }

        $actions = [];
        foreach ($due as $event) {
            $actions[] = [
                'label'      => $event['action']['label'],
                'definition' => $config['catalog'][$event['action']['key']] ?? null
            ];
        }
        return $actions;
    }

    // Startet eine Aktion, sofern ihre optionale Bedingung erfüllt ist. Liefert 'started', 'skipped' oder 'failed'.
    private function RunAction(string $label, ?array $definition): string
    {
        if ($definition === null || $definition['actionID'] === null) {
            $this->SendDebug('Execute', $label . ' not executable (central action not available)', 0);
            $this->LogMessage(sprintf($this->Translate('Wake-up action not executable: %s'), $label), KL_WARNING);
            return 'failed';
        }

        if ($definition['condition'] !== null) {
            $passing = $this->EvaluateCondition($definition['condition']);
            if ($passing === null) {
                // Nicht auswertbar (z. B. Variable gelöscht): lieber nicht schalten, aber sichtbar machen.
                $this->LogMessage(sprintf($this->Translate('The condition of a wake-up action cannot be evaluated, action skipped: %s'), $label), KL_WARNING);
                return 'skipped';
            }
            if (!$passing) {
                $this->SendDebug('Execute', $label . ' skipped (condition not met)', 0);
                return 'skipped';
            }
        }

        try {
            if (IPS_RunAction($definition['actionID'], $definition['parameters'])) {
                $this->SendDebug('Execute', $label . ' executed', 0);
                return 'started';
            }
            $this->SendDebug('Execute', $label . ' failed', 0);
        } catch (Throwable $e) {
            $this->SendDebug('Execute', $label . ' failed: ' . $e->getMessage(), 0);
        }
        $this->LogMessage(sprintf($this->Translate('Wake-up action failed: %s'), $label), KL_WARNING);
        return 'failed';
    }

    // Prüft eine Bedingung im Format von SelectCondition; null, wenn sie nicht ausgewertet werden kann.
    private function EvaluateCondition(string $condition): ?bool
    {
        $error = null;
        set_error_handler(static function (int $number, string $message) use (&$error): bool
        {
            $error = $message;
            return true;
        });
        try {
            $passing = IPS_IsConditionPassing($condition);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        } finally {
            restore_error_handler();
        }

        if ($error !== null || !is_bool($passing ?? null)) {
            $this->SendDebug('Condition', 'Cannot be evaluated: ' . ($error ?? 'no result'), 0);
            return null;
        }
        return $passing;
    }

    // Bedingung aus der zentralen Liste als JSON-String oder null, wenn keine gesetzt ist. Der Wert von
    // SelectCondition ist JSON-kodiert; je nach Speicherung kann er bereits als Array vorliegen.
    private function NormalizeCondition(mixed $condition): ?string
    {
        if (is_array($condition)) {
            return count($condition) === 0 ? null : (string) json_encode($condition);
        }
        if (!is_string($condition) || trim($condition) === '' || json_decode($condition, true) === []) {
            return null;
        }
        // Nicht lesbare Werte bleiben erhalten; die Auswertung scheitert dann kontrolliert (Aktion wird übersprungen).
        return $condition;
    }

    private function ScheduleNext(array $config, int $now): void
    {
        ['next' => $next, 'wakeUp' => $nextWakeUp] = $this->GetNextPoints($config, $now);

        if ($next === null) {
            $this->SendDebug('Schedule', 'No next point in time found', 0);
            $this->SetTimerInterval(self::TIMER, 0);
            return;
        }

        $interval = max(100, (int) ceil(($next - microtime(true)) * 1000));
        $this->SetTimerInterval(self::TIMER, $interval);
        $this->SendDebug('Schedule', 'Next point in time: ' . $this->FormatTime($next) . ', next wake-up: ' . ($nextWakeUp === null ? '-' : $this->FormatTime($nextWakeUp)), 0);
    }

    // Nächster fälliger Zeitpunkt für den Timer und nächster Weckzeitpunkt nach $now (jeweils null, wenn keiner ansteht).
    private function GetNextPoints(array $config, int $now): array
    {
        $range = self::MAX_OFFSET * 60;
        $next = null;
        $nextWakeUp = null;
        foreach ($this->GetOccurrences($config, $now - $range, $now + 8 * 86400 + $range) as $occurrence) {
            if ($occurrence > $now && ($nextWakeUp === null || $occurrence < $nextWakeUp)) {
                $nextWakeUp = $occurrence;
            }
            $times = [$occurrence];
            foreach ($config['actions'] as $action) {
                $times[] = $occurrence + $action['offset'];
            }
            foreach ($times as $time) {
                if ($time > $now && ($next === null || $time < $next)) {
                    $next = $time;
                }
            }
        }

        // Die Kachel zeigt den Tag relativ zu heute ("Heute", "Morgen", Wochentag). Die Anzeige ändert sich zu Beginn
        // des Vortags ("Morgen") und des Wecktags ("Heute"); zum ersten noch ausstehenden dieser Zeitpunkte neu berechnen.
        if ($nextWakeUp !== null) {
            $wakeDay = (new DateTimeImmutable('@' . $nextWakeUp))->setTimezone(new DateTimeZone(date_default_timezone_get()))->setTime(0, 0);
            foreach ([$wakeDay->modify('-1 day')->getTimestamp(), $wakeDay->getTimestamp()] as $change) {
                if ($change > $now) {
                    $next = min($next, $change);
                    break;
                }
            }
        }
        return ['next' => $next, 'wakeUp' => $nextWakeUp];
    }

    // Liefert alle Aktionszeitpunkte im Intervall ($from, $to], sortiert nach Zeit und Listenreihenfolge.
    private function GetActionEvents(array $config, int $from, int $to): array
    {
        if ($to <= $from || count($config['actions']) === 0) {
            return [];
        }

        $range = self::MAX_OFFSET * 60;
        $events = [];
        foreach ($this->GetOccurrences($config, $from - $range, $to + $range) as $occurrence) {
            foreach ($config['actions'] as $order => $action) {
                $time = $occurrence + $action['offset'];
                if ($time > $from && $time <= $to) {
                    $events[] = ['time' => $time, 'order' => $order, 'occurrence' => $occurrence, 'action' => $action];
                }
            }
        }
        usort($events, static function (array $a, array $b): int
        {
            return [$a['time'], $a['order']] <=> [$b['time'], $b['order']];
        });
        return $events;
    }

    // Liefert alle Weckzeitpunkte im Intervall [$from, $to] in der lokalen Zeitzone.
    private function GetOccurrences(array $config, int $from, int $to): array
    {
        $timezone = new DateTimeZone(date_default_timezone_get());
        $day = (new DateTimeImmutable('@' . $from))->setTimezone($timezone)->setTime(0, 0)->modify('-1 day');
        $end = (new DateTimeImmutable('@' . $to))->setTimezone($timezone)->setTime(0, 0)->modify('+1 day');

        $occurrences = [];
        while ($day <= $end) {
            $weekday = (int) $day->format('N');
            if (isset($config['weekdays'][$weekday])) {
                // Nicht existierende Uhrzeiten (Zeitumstellung) verschiebt PHP auf die nächste gültige Uhrzeit.
                $time = $config['times'][$weekday];
                $occurrence = $day->setTime($time['hour'], $time['minute'], $time['second'])->getTimestamp();
                if ($occurrence >= $from && $occurrence <= $to) {
                    $occurrences[] = $occurrence;
                }
            }
            $day = $day->modify('+1 day')->setTime(0, 0);
        }
        return $occurrences;
    }

    private function ReadExecuted(): array
    {
        $executed = json_decode($this->ReadAttributeString('Executed'), true);
        if (!is_array($executed)) {
            // Reine Verwaltungsdaten ohne Benutzerinhalt; ein Neuaufbau ist sicher, da LastProcessed
            // eine erneute Ausführung bereits abgearbeiteter Zeitpunkte verhindert.
            $this->SendDebug('State', 'Execution log cannot be read, rebuilding', 0);
            return [];
        }
        // Schlüssel ist der Wecktag ("Y-m-d"). Frühere Versionen nutzten den Weckzeitpunkt (Unix-Zeitstempel);
        // diese Einträge werden dem Wecktag zugeordnet, damit ein Update keinen Weckvorgang doppelt ausführt.
        $result = [];
        foreach ($executed as $key => $actions) {
            if (!is_array($actions)) {
                continue;
            }
            $day = ctype_digit((string) $key) ? $this->WakeDay((int) $key) : (string) $key;
            $result[$day] = array_values(array_unique([...$result[$day] ?? [], ...array_filter($actions, 'is_string')]));
        }
        return $result;
    }

    // Lokales Datum eines Weckzeitpunkts; kennzeichnet den Weckvorgang im Ausführungsprotokoll.
    private function WakeDay(int $timestamp): string
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d');
    }

    // Beim Deaktivieren wird das Zeitfenster verworfen, damit beim Reaktivieren nichts nachgeholt wird.
    private function ResetState(): void
    {
        if ($this->ReadAttributeInteger('LastProcessed') !== -1) {
            $this->WriteAttributeInteger('LastProcessed', -1);
        }
        if ($this->ReadAttributeString('Executed') !== '{}') {
            $this->WriteAttributeString('Executed', '{}');
        }
    }

    private function FormatTime(int $timestamp): string
    {
        return date('d.m.Y H:i:s', $timestamp);
    }
}
