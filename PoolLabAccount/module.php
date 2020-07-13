<?php

class PoolLabAccount extends IPSModule
{
    private $MeasurementNames = [
        'Measurement421'    => "gesamtes Chlor (cCl2)",
        'Measurement428'    => "freies Chlor (fCl2)",
        'Measurement429'    => "pH Wert (pH)",
        'Measurement430'    => "Alkalinität (CaCO3)",
        'Measurement431'    => "Cyanursäure (CYA)"
    ];

    public function Create() {
        parent::Create();

        $this->RegisterPropertyString('AccountID', '');
        $this->RegisterPropertyString('AccountForename', '');
        $this->RegisterPropertyString('AccountSurname', '');
        $this->RegisterPropertyString('AccountStreet', '');
        $this->RegisterPropertyString('AccountZipcode', '');
        $this->RegisterPropertyString('AccountCity', '');
        $this->RegisterPropertyString('AccountPoolVolume', '');
        $this->RegisterPropertyString('AccountPoolText', '');
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
    }

    public function SetAccountDetails(string $AccountID, string $AccountForename, string $AccountSurname, string $AccountStreet, string $AccountZipcode, string $AccountCity, string $AccountPoolVolume, string $AccountPoolText) {
        $AccountFields = [
            'AccountID' => $AccountID,
            'AccountForename'  => $AccountForename,
            'AccountSurname' => $AccountSurname,
            'AccountStreet' => $AccountStreet,
            'AccountZipcode' => $AccountZipcode,
            'AccountCity' => $AccountCity,
            'AccountPoolVolume' => $AccountPoolVolume,
            'AccountPoolText' => $AccountPoolText
        ];

        foreach($AccountFields as $Name => $Value) {
            if (strcmp(IPS_GetProperty($this->InstanceID, $Name), $Value) != 0) {
                IPS_SetProperty($this->InstanceID, $Name, "$Value");
            }
        }
        IPS_ApplyChanges($this->InstanceID);
    }

    public function InsertMeasurement(string $MeasurementScenario, float $MeasurementValue, int $MeasurementTimestamp) {
        $ACID = IPS_GetInstanceListByModuleID("{43192F0B-135B-4CE7-A0A7-1475603F3060}")[0];
        $ParentID = $this->InstanceID;
        $MeasurementNumber = substr($MeasurementScenario, 0, 3);
        $MeasurementIdent = "Measurement" . $MeasurementNumber;

        $VariableID = @IPS_GetObjectIDByIdent($MeasurementIdent, $ParentID);
        if ($VariableID == false) {
            $VariableID = IPS_CreateVariable(2);
            $VariableName = $this->MeasurementNames[$MeasurementIdent];
            if (strlen($VariableName) == 0) {
                $VariableName = $MeasurementIdent;
            }
            IPS_SetParent($VariableID, $ParentID);
            IPS_SetName($VariableID, $VariableName);
            IPS_SetIdent($VariableID, $MeasurementIdent);
            IPS_SetPosition($VariableID, $MeasurementNumber);
            AC_SetLoggingStatus($ACID, $VariableID, true);
            IPS_ApplyChanges($ACID);
        }
        $IsLogged = sizeof(AC_GetLoggedValues($ACID, $VariableID, $MeasurementTimestamp, $MeasurementTimestamp, 1), 0);
        if ($IsLogged == 0) {
            AC_AddLoggedValues($ACID, $VariableID, [['TimeStamp' => $MeasurementTimestamp, 'Value' => $MeasurementValue]]);
            AC_ReAggregateVariable($ACID, $VariableID);
            sleep(1);
        }
        if (time() - IPS_GetVariable($VariableID)['VariableUpdated'] > 60) {
            SetValueFloat($VariableID, $MeasurementValue);
        }
    }

    public function GetConfigurationForm() {
        $elements = array();
        $actions = array();
        $status = array();

        $Address = IPS_GetProperty($this->InstanceID, 'AccountForename') . " " . IPS_GetProperty($this->InstanceID, 'AccountSurname') . "\n" . IPS_GetProperty($this->InstanceID, 'AccountStreet') . "\n" . IPS_GetProperty($this->InstanceID, 'AccountZipcode') . " " . IPS_GetProperty($this->InstanceID, 'AccountCity');

        array_push($elements, ["type" => "Label", "caption" => "Account ID " . IPS_GetProperty($this->InstanceID, 'AccountID')]);
        array_push($elements, ["type" => "Label", "caption" => $Address]);
        array_push($elements, ["type" => "Label", "caption" => "Poolinhalt: " . IPS_GetProperty($this->InstanceID, 'AccountPoolVolume') . " m3"]);

        $form = (object) [
            'elements' => $elements,
            'actions' => $actions,
            'status' => $status
        ];

        return json_encode($form);
    }
}
