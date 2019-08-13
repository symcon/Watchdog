<?
class Watchdog extends IPSModule
{

	public function Create() {
		//Never delete this line!
		parent::Create();
		
		//Properties
		$this->RegisterPropertyInteger("TimeBase", 0);
		$this->RegisterPropertyInteger("TimeValue", 60);
		$this->RegisterPropertyString("Targets", "[]");
		
		//Timer
		$this->RegisterTimer("CheckTargetsTimer", 0, 'WD_CheckTargets($_IPS[\'TARGET\']);');
		
		//Variables
		$this->RegisterVariableInteger("LastCheck", "Letzte Überprüfung", "~UnixTimestamp");
		$this->RegisterVariableString("AlertView", "Aktive Alarme", "~HTMLBox");
		$this->RegisterVariableBoolean("Alert", "Alarm", "~Alert");
		$this->RegisterVariableBoolean("Active", "Watchdog aktiv", "~Switch");
		$this->EnableAction("Active");

		//Attribute
		$this->RegisterAttributeInteger("TimerInterval", 10);
		
	}

	public function Destroy() {
		//Never delete this line!
		parent::Destroy();
		
	}

	public function ApplyChanges() {
		//Never delete this line!
		parent::ApplyChanges();
		
		//Links to list
		if ($this->ReadPropertyString("Targets") == "[]") {
            $TargetID = @$this->GetIDForIdent("Targets");

            if ($TargetID) {
                $Variables = [];
                foreach (IPS_GetChildrenIDs($TargetID) as $ChildrenID) {
                    $targetID = IPS_GetLink($ChildrenID)["TargetID"];
                    $line = [
                        "VariableID" => $targetID
                    ];
                    array_push($Variables, $line);
                    IPS_DeleteLink($ChildrenID);
                }

                IPS_DeleteCategory($TargetID);
                IPS_SetProperty($this->InstanceID, "Targets", json_encode($Variables));
                IPS_ApplyChanges($this->InstanceID);
                return;
            }
        }

		foreach (json_decode($this->ReadPropertyString("Targets"), true) as $target) {
			$this->RegisterMessage($target["VariableID"], VM_UPDATE);
		}


		if (GetValue($this->GetIDForIdent("Active"))) {
			$this->UpdateTimer();
		}
	}

	public function RequestAction($Ident, $Value) {
		
		switch($Ident) {
			case "Active":
				$this->SetActive($Value);
				break;
			default:
				throw new Exception("Invalid ident");
		}
	}

	public function SetActive(bool $SwitchOn){
		
		if ($SwitchOn){
			//When activating the simulation, fetch actual data for a day and activate timer for updating targets
			$this->CheckTargets();
			$this->UpdateTimer();
		} else {
			//When deactivating the simulation, kill data for simulation and deactivate timer for updating targets
			$this->SetTimerInterval("CheckTargetsTimer", 0);
			SetValue($this->GetIDForIdent("AlertView"), "Watchdog deaktiviert");
		}
		
		SetValue($this->GetIDForIdent("Active"), $SwitchOn);
		
	}

	public function CheckTargets() {
		
		$alertTargets = $this->GetAlertTargets();
		SetValue($this->GetIDForIdent("Alert"), sizeof($alertTargets) > 0);
		
		SetValue($this->GetIDForIdent("LastCheck"), time());
		
		$this->UpdateView($alertTargets);
		
	}

	public function GetAlertTargets() {
		
		$targets = $this->GetTargets();
		
		$watchTime = $this->GetWatchTime();
		$watchTimeBorder = time() - $watchTime;
		
		$alertTargets = [];
		
		foreach ($targets as $target){
									
			$v = IPS_GetVariable($target["VariableID"]);
			
			if($v['VariableUpdated'] < $watchTimeBorder){
				$alertTargets[] = array('Name' => $target["Name"], 'VariableID' => $target["VariableID"], 'LastUpdate' => $v['VariableUpdated']);
			}
		}
		return $alertTargets;
		
	}

	//Returns all variableID's and optional names of targets as array, which are listed in "Targets"
	private function GetTargets() {
		
		$targets = json_decode($this->ReadPropertyString("Targets"), true);
		
		$result = [];
		foreach($targets as $target) {
			if (IPS_VariableExists($target["VariableID"])) {
				$result[] = $target;
			}
		}
		return $result;
	}

	private function GetWatchTime() {
		
		$timeBase = $this->ReadPropertyInteger("TimeBase");
		$timeValue = $this->ReadPropertyInteger("TimeValue");
		
		switch($timeBase) {
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

	public function UpdateTimer() 
	{
		SetValue($this->GetIDForIdent("LastCheck"), time());
		$targets = $this->GetTargets();
		$updated = time();
		foreach ($targets as $target) {
			$targetUpdated = IPS_GetVariable($target["VariableID"])["VariableUpdated"];
			if ($targetUpdated < $updated) {
				$updated = $targetUpdated;
			}
		} 
		$updatedInterval = $this->ReadPropertyInteger("TimeValue") - time() + $updated;
        if ($updatedInterval > 0) {
            $this->SetTimerInterval("CheckTargetsTimer", $updatedInterval);
        } else {
            $this->SetTimerInterval("CheckTargetsTimer", 0);
			$this->CheckTargets();
		}
	}

	public function MessageSink ($TimeStamp, $SenderID, $MessageID, $Data)
	{
		$this->UpdateTimer();	
	}

	private function UpdateView($AlertTargets) {
		
		$html = "<table style='width: 100%; border-collapse: collapse;'>";
		$html .= "<tr>";
		$html .= "<td style='padding: 5px; font-weight: bold;'>Aktor</td>";
		$html .= "<td style='padding: 5px; font-weight: bold;'>Letzte Aktualisierung</td>";
		$html .= "<td style='padding: 5px; font-weight: bold;'>Überfällig seit</td>";
		$html .= "</tr>";
		
		foreach ($AlertTargets as $alertTarget) {
			
			if ($alertTarget["Name"] == "") {
				$name = IPS_GetLocation($alertTarget["VariableID"]);
			} else {
                $name = $alertTarget["Name"];
			}
			
			$timediff = time() - $alertTarget['LastUpdate'];
			$timestring = sprintf("%02d:%02d:%02d", (int)($timediff / 3600) , (int)($timediff / 60) % 60, ($timediff) % 60);
			
			$html .= "<tr style='border-top: 1px solid rgba(255,255,255,0.10);'>";
			$html .= "<td style='padding: 5px;'>".$name."</td>";
			$html .= "<td style='padding: 5px;'>".date("d.m.Y H:i:s", $alertTarget['LastUpdate'])."</td>";
			$html .= "<td style='padding: 5px;'>".$timestring." Stunden</td>";
			$html .= "</tr>";
		}
		$html .= "</table>";
		
		SetValue($this->GetIDForIdent("AlertView"), $html);
		
	}
}
?>
