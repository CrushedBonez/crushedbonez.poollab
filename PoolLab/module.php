<?php

class PoolLab extends IPSModule
{
    public function Create() {
        parent::Create();

        $this->RegisterPropertyString('CloudEndpoint', 'https://labcom.cloud/graphql');
        $this->RegisterPropertyString('ApiKey', '');
        $this->RegisterPropertyInteger('StartTime', 0);
        $this->RegisterPropertyBoolean('DebugEnabled', false);
        $this->RegisterPropertyBoolean('ArchiveControlEnabled', false);
        IPS_LogMessage(IPS_GetName($this->InstanceID), "Executed function Create()");
        // Import data every 60 minutes
        $this->RegisterTimer('ImportData', 60 * 60 * 1000, 'PoolLab_ImportData(' . $this->InstanceID . ');');
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
    }

    private function QueryLabcomCloud(string $QueryString) {
        $ApiKey = IPS_GetProperty($this->InstanceID, "ApiKey");
        $CloudEndpoint = IPS_GetProperty($this->InstanceID, "CloudEndpoint");
        $AuthHeader = array("Content-Type: application/json","Authorization: $ApiKey");
        $Query = array("query"=>"$QueryString");

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $CloudEndpoint);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $AuthHeader);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($Query));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $Response = curl_exec($ch);
        $HttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return array($HttpCode, $Response);
    }

    private function GetCloudAccount() {
        $Query = "{CloudAccount{id,email,Accounts{id,forename,surname,street,zipcode,city,phone1,phone2,fax,email,country,canton,notes,volume,pooltext,gps}}}";
        $ResponseAccounts = $this->QueryLabcomCloud($Query);
        if ($ResponseAccounts[0] == 200) {
            $CloudAccount = json_decode($ResponseAccounts[1])->{'data'}->{'CloudAccount'};
            return array(true, $CloudAccount);
        } else {
            return array(false, NULL);
        }
    }

    private function GetMeasurements(string $AccountID, bool $DebugEnabled) {
        $StartTime = IPS_GetProperty($this->InstanceID, "StartTime") + 1;
        //Temporary fix for first import until proper Re-Aggregation handling
        if ($StartTime == 1) {
            if ($DebugEnabled) {
                IPS_LogMessage(IPS_GetName($this->InstanceID), "Increasing max_execution_time to 600 seconds for initial import.");
            }
            //ini_set('max_execution_time', 600);
        }
        $Query = "{Accounts(id: $AccountID){Measurements(from: $StartTime){id,scenario,parameter,unit,comment,value,ideal_low,ideal_high,timestamp}}}";
        $ResponseMeasurements = $this->QueryLabcomCloud($Query);
        if ($ResponseMeasurements[0] == 200) {
            $Measurements = json_decode($ResponseMeasurements[1])->{'data'}->{'Accounts'}[0]->{'Measurements'};
            return array(true, $Measurements);
        } else {
            return array(false, NULL);
        }
    }

    private function ParseCloudAccount(object  $CloudAccount, bool $DebugEnabled) {
        $CloudAccountId = $CloudAccount->{'id'};
        $CloudAccountEmail = $CloudAccount->{'email'};
        $Accounts = $CloudAccount->{'Accounts'};
        if ($DebugEnabled) {
            IPS_LogMessage(IPS_GetName($this->InstanceID), "Found Cloud-Account $CloudAccountId $CloudAccountEmail");
        }
        return $Accounts;
    }

    private function ParseAccount(object $Account, bool $DebugEnabled) {
        $AccountID = $Account->{'id'};
        $AccountForename = $Account->{'forename'};
        $AccountSurname = $Account->{'surname'};
        $AccountStreet = $Account->{'street'};
        $AccountZipcode = $Account->{'zipcode'};
        $AccountCity = $Account->{'city'};
        $AccountPoolVolume = $Account->{'volume'};
        $AccountPoolText = $Account->{'pooltext'};
        if ($DebugEnabled) {
            IPS_LogMessage(IPS_GetName($this->InstanceID), "Account ID:\t$AccountID\n$AccountForename $AccountSurname\n$AccountStreet\n$AccountZipcode $AccountCity\n");
        }
        $AccountIdent = "Account" . $AccountID;
        $ParentID = $this->InstanceID;

        $VariableID = @IPS_GetObjectIDByIdent($AccountIdent, $ParentID);
        if ($VariableID == false) {
            $VariableID = IPS_CreateInstance("{391A27E6-B2E9-2B8B-0331-82BDE3540FDA}");
            IPS_SetName($VariableID, "$AccountForename $AccountSurname");
            IPS_SetParent($VariableID, $ParentID);
            IPS_SetIdent($VariableID, $AccountIdent);
            IPS_SetPosition($VariableID, $AccountID + 100);
            PoolLabAccount_SetArchiveControl($VariableID, IPS_GetProperty($this->InstanceID, 'ArchiveControlEnabled'));
        }
        PoolLabAccount_SetAccountDetails($VariableID, $AccountID, $AccountForename, $AccountSurname, $AccountStreet, $AccountZipcode, $AccountCity, $AccountPoolVolume, $AccountPoolText);
        return $AccountID;
    }

    private function ParseMeasurement(int $AccountID, object $Measurement, bool $DebugEnabled) {
        $MeasurementID = $Measurement->{'id'};
        $MeasurementScenario = $Measurement->{'scenario'};
        $MeasurementParameter = $Measurement->{'parameter'};
        $MeasurementUnit = $Measurement->{'unit'};
        if ($Measurement->{'comment'}) {
            $MeasurementComment = $Measurement->{'comment'};
        } else {
            $MeasurementComment = "";
        }
        $MeasurementValue = (float) $Measurement->{'value'};
        $MeasurementIdealLow = $Measurement->{'ideal_low'};
        $MeasurementIdealHigh = $Measurement->{'ideal_high'};
        $MeasurementTimestamp = $Measurement->{'timestamp'};
        $MeasurementDate = date(DATE_RFC822, $MeasurementTimestamp);

        if ($DebugEnabled) {
            IPS_LogMessage(IPS_GetName($this->InstanceID), "Found Measurement $MeasurementDate $MeasurementScenario $MeasurementValue $MeasurementUnit");
        }
        
        PoolLabAccount_InsertMeasurement($AccountID, $MeasurementScenario, $MeasurementValue, $MeasurementComment, $MeasurementTimestamp);

        $StartTime = IPS_GetProperty($this->InstanceID, "StartTime");
        if ($MeasurementTimestamp > $StartTime) {
            IPS_SetProperty($this->InstanceID, "StartTime", $MeasurementTimestamp);
            IPS_ApplyChanges($this->InstanceID);
        }

        return;
    }

    public function ImportData() {
        $DebugEnabled = IPS_GetProperty($this->InstanceID, "DebugEnabled");
        if (empty(IPS_GetProperty($this->InstanceID, 'ApiKey'))) {
            if ($DebugEnabled) {
                IPS_LogMessage(IPS_GetName($this->InstanceID), "No API-Key set, skipping import...");
            }
            return;
        } else {
            $CloudAccount = $this->GetCloudAccount();
            if ($CloudAccount[0]) {
                $Accounts = $this->ParseCloudAccount($CloudAccount[1], $DebugEnabled);
                foreach ($Accounts as $Account) {
                    $AccountID = $this->ParseAccount($Account, $DebugEnabled);
                    $Measurements = $this->GetMeasurements($AccountID, $DebugEnabled);
                    $AccountVariableID = IPS_GetObjectIDByIdent("Account" . $Account->{'id'} , $this->InstanceID);
                    if ($DebugEnabled) {
                        IPS_LogMessage(IPS_GetName($this->InstanceID), "Working on Account " . IPS_GetName($AccountVariableID));
                    }
                if ($Measurements[0]) {
                    foreach ($Measurements[1] as $Measurement) {
                            $this->ParseMeasurement($AccountVariableID, $Measurement, $DebugEnabled);
                        }
                    }
                }
            }
        }
    }
}
