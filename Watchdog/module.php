<?php

declare(strict_types=1);
class Watchdog extends IPSModule
{
    public function Create()
    {
        //Never delete this line!
        parent::Create();

        //Properties
        $this->RegisterPropertyInteger('TimeBase', 0);
        $this->RegisterPropertyInteger('TimeValue', 60);
        $this->RegisterPropertyString('Targets', '[]');
        $this->RegisterPropertyBoolean('BlockAlarm', false);
        $this->RegisterPropertyBoolean('CheckChange', false);

        //Timer
        $this->RegisterTimer('CheckTargetsTimer', 0, 'WD_UpdateTimer($_IPS[\'TARGET\'], true);');

        //Variables
        $this->RegisterVariableInteger('LastCheck', $this->Translate('Last Check'), '~UnixTimestamp');
        $this->RegisterVariableString('AlertView', $this->Translate('Active Alerts'), '~HTMLBox');
        $this->RegisterVariableBoolean('Alert', $this->Translate('Alert'), '~Alert');
        $this->RegisterVariableBoolean('Active', $this->Translate('Watchdog Active'), '~Switch');
        $this->EnableAction('Active');

        //Attribute
        $this->RegisterAttributeInteger('TimerInterval', 10);
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        //Links to list
        if ($this->ReadPropertyString('Targets') == '[]') {
            $targetID = @$this->GetIDForIdent('Targets');

            if ($targetID) {
                $variables = [];
                foreach (IPS_GetChildrenIDs($targetID) as $childID) {
                    $targetID = IPS_GetLink($childID)['TargetID'];
                    $line = [
                        'VariableID' => $targetID,
                        'Name'       => IPS_GetName($childID)
                    ];
                    array_push($variables, $line);
                    IPS_DeleteLink($childID);
                }

                IPS_DeleteCategory($targetID);
                IPS_SetProperty($this->InstanceID, 'Targets', json_encode($variables));
                IPS_ApplyChanges($this->InstanceID);
                return;
            }
        }

        //Delete all registrations in order to readd them
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        foreach (json_decode($this->ReadPropertyString('Targets'), true) as $target) {
            $this->RegisterMessage($target['VariableID'], VM_UPDATE);
        }
        //Additional condition: "No alarm on start" must be active
        if ((IPS_GetKernelRunlevel() == KR_CREATE) && $this->ReadPropertyBoolean('BlockAlarm')) {
            $this->SetBuffer('Ready', 'false');
            $this->RegisterMessage(0, KR_READY);
        } elseif (GetValue($this->GetIDForIdent('Active'))) {
            $this->UpdateTimer(false);
            $this->CheckTargets();
        }

        //Add references
        foreach ($this->GetReferenceList() as $referenceID) {
            $this->UnregisterReference($referenceID);
        }
        $targets = json_decode($this->ReadPropertyString('Targets'));
        foreach ($targets as $target) {
            $this->RegisterReference($target->VariableID);
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Active':
                $this->SetActive($Value);
                break;
            default:
                throw new Exception('Invalid ident');
        }
    }

    public function SetActive(bool $SwitchOn)
    {
        if ($SwitchOn) {
            //When activating the simulation, fetch actual data for a day and activate timer for updating targets
            $this->UpdateTimer(true);
            $this->CheckTargets();
            $this->SendDebug('ModuleActive', 'working', 0);
            $this->SendDebug('ModuleActive', 'TimerUpdated', 0);
        } else {
            //When deactivating the simulation, kill data for simulation and deactivate timer for updating targets
            $this->SetTimerInterval('CheckTargetsTimer', 0);
            SetValue($this->GetIDForIdent('AlertView'), $this->Translate('Watchdog disabled'));
        }

        SetValue($this->GetIDForIdent('Active'), $SwitchOn);
    }

    public function GetAlertTargets()
    {
        $targets = $this->GetTargets();

        $watchTime = $this->GetWatchTime();
        $watchTimeBorder = time() - $watchTime;
        $alertTargets = [];

        foreach ($targets as $target) {
            $v = IPS_GetVariable($target['VariableID']);
            $variableChange = 0;
            if ($this->ReadPropertyBoolean('CheckChange')) {
                $variableChange = $v['VariableChanged'];
            } else {
                $variableChange = $v['VariableUpdated'];
            }

            if ($variableChange < $watchTimeBorder) {
                //The isset check is required for legacy purposes. Initially we made an error while importing and forgot to import 'Name's which left the field uninitialized.
                $alertTargets[] = ['Name' => isset($target['Name']) ? $target['Name'] : '', 'VariableID' => $target['VariableID'], 'LastUpdate' => $variableChange];
            }
        }
        return $alertTargets;
    }

    public function UpdateTimer(bool $Force)
    {
        if ($Force) {
            $this->SetBuffer('Ready', 'true');
        }

        //Immediately return if off flag is set or instance is inactive
        $this->SendDebug('UpdateTimer', 'Force: ' . $Force, 0);
        if (($this->GetBuffer('Ready') == 'false') || !(GetValue($this->GetIDForIdent('Active')))) {
            $this->SendDebug('UpdateTimer', 'NotReady or NotActive', 0);
            return;
        }
        SetValue($this->GetIDForIdent('LastCheck'), time());
        $targets = $this->GetTargets();
        $updated = time();
        foreach ($targets as $target) {
            if ($this->ReadPropertyBoolean('CheckChange')) {
                $targetUpdated = IPS_GetVariable($target['VariableID'])['VariableChanged'];
            } else {
                $targetUpdated = IPS_GetVariable($target['VariableID'])['VariableUpdated'];
            }
            if ($targetUpdated < $updated) {
                $this->SendDebug('UpdateTimer', 'Last update (' . IPS_GetName(IPS_GetVariable($target['VariableID'])['VariableID']) . '): ' . date('H:i:s', $targetUpdated), 0);
                $this->SendDebug('UpdateTimer', 'Time: ' . date('H:i:s', $updated), 0);
                $updated = $targetUpdated;
            }
        }
        $updatedInterval = $this->GetWatchTime() - time() + $updated;
        if ($updatedInterval > 0) {
            $this->SetTimerInterval('CheckTargetsTimer', $updatedInterval * 1000);
        } else {
            $this->SetTimerInterval('CheckTargetsTimer', 60 * 1000);
            $this->CheckTargets();
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $MessageID, $Data)
    {
        switch ($MessageID) {
            case VM_UPDATE:
                //If in alarm state not only timer update
                if (GetValue($this->GetIDForIdent('Alert'))) {
                    $this->CheckTargets();
                    $this->UpdateTimer(false);
                } else {
                    $this->UpdateTimer(false);
                }
                break;

            case KR_READY:
                $this->SetTimerInterval('CheckTargetsInterval', $this->GetWatchTime());
                break;
        }
    }

    private function CheckTargets()
    {
        $this->SetBuffer('Ready', 'true');
        $alertTargets = $this->GetAlertTargets();
        SetValue($this->GetIDForIdent('Alert'), count($alertTargets) > 0);

        SetValue($this->GetIDForIdent('LastCheck'), time());

        $this->UpdateView($alertTargets);
        $this->SendDebug('CheckTargets', 'TargetChecked', 0);
    }

    //Returns all variableID's and optional names of targets as array, which are listed in "Targets"
    private function GetTargets()
    {
        $targets = json_decode($this->ReadPropertyString('Targets'), true);

        $result = [];
        foreach ($targets as $target) {
            if (IPS_VariableExists($target['VariableID'])) {
                $result[] = $target;
            }
        }
        return $result;
    }

    private function GetWatchTime()
    {
        $timeBase = $this->ReadPropertyInteger('TimeBase');
        $timeValue = $this->ReadPropertyInteger('TimeValue');

        switch ($timeBase) {
            case 0:
                return $timeValue;

            case 1:
                return $timeValue * 60;

            case 2:
                return $timeValue * 3600;

            case 3:
                return $timeValue * 86400;
        }
    }

    private function FormatTime($value)
    {
        $template = '';
        $number = 0;
        if ($value < 60) {
            return $this->Translate('Just now');
        } elseif (($value > 60) && ($value < (60 * 60))) {
            $template = '%d Minute';
            $number = floor($value / 60);
            if ($value >= (2 * 60)) {
                $template .= 's';
            }
        } elseif (($value > (60 * 60)) && ($value < (24 * 60 * 60))) {
            $template = '%d Hour';
            $number = floor($value / (60 * 60));
            if ($value >= (2 * 60 * 60)) {
                $template .= 's';
            }
        } elseif ($value > (24 * 60 * 60)) {
            $template = '%d Day';
            $number = floor($value / (24 * 60 * 60));
            if ($value >= (2 * 24 * 60 * 60)) {
                $template .= 's';
            }
        }

        return sprintf($this->Translate($template), $number);
    }

    private function UpdateView($alertTargets)
    {
        $last = '';

        if ($this->ReadPropertyBoolean('CheckChange')) {
            $last = $this->Translate('Last change');
        } else {
            $last = $this->Translate('Last update');
        }

        $html = "<table style='width: 100%; border-collapse: collapse;'>";
        $html .= '<tr>';
        $html .= "<td style='padding: 5px; font-weight: bold;'>" . $this->Translate('Actor') . '</td>';
        $html .= "<td style='padding: 5px; font-weight: bold;'>" . $last . '</td>';
        $html .= "<td style='padding: 5px; font-weight: bold;'>" . $this->Translate('Overdue since') . '</td>';
        $html .= '</tr>';

        foreach ($alertTargets as $alertTarget) {
            if ($alertTarget['Name'] == '') {
                $name = IPS_GetLocation($alertTarget['VariableID']);
            } else {
                $name = $alertTarget['Name'];
            }

            $timediff = time() - $alertTarget['LastUpdate'];
            $timestring = $this->FormatTime($timediff);

            $html .= "<tr style='border-top: 1px solid rgba(255,255,255,0.10);'>";
            $html .= "<td style='padding: 5px;'>" . $name . '</td>';
            $html .= "<td style='padding: 5px;'>" . date('d.m.Y H:i:s', $alertTarget['LastUpdate']) . '</td>';
            $html .= "<td style='padding: 5px;'>" . $timestring . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';

        SetValue($this->GetIDForIdent('AlertView'), $html);
    }
}
