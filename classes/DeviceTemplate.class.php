<?php
/*
	openDCIM

	This is the main class library for the openDCIM application, which
	is a PHP/Web based data center infrastructure management system.

	This application was originally written by Scott A. Milliken while
	employed at Vanderbilt University in Nashville, TN, as the
	Data Center Manager, and released under the GNU GPL.

	Copyright (C) 2011 Scott A. Milliken

	This program is free software:  you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published
	by the Free Software Foundation, version 3.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	For further details on the license, see http://www.gnu.org/licenses
*/
class DeviceTemplate {
	var $TemplateID;
	var $ManufacturerID;
	var $Model;
	var $EndofLife;
	var $Height;
	var $Weight;
	var $Wattage;
	var $DeviceType;
	var $PSCount;
	var $NumPorts;
	var $Notes;
	var $FrontPictureFile;
	var $RearPictureFile;
	var $ChassisSlots;
	var $RearChassisSlots;
	var $SNMPVersion;
	var $CustomValues;
	var $GlobalID;
    
	public function __construct($dtid=false){
		if($dtid){
			$this->TemplateID=intval($dtid);
		}
		return $this;
	}

	function MakeSafe(){
		$validDeviceTypes=array('Server','Appliance','Storage Array','Switch','Chassis','Patch Panel','Physical Infrastructure','CDU','Sensor');
		$validSNMPVersions=array(1,'2c',3);

		// Instead of defaulting to v2c for snmp we'll default to whatever the system default is
		global $config;

		$this->TemplateID=intval($this->TemplateID);
		$this->ManufacturerID=intval($this->ManufacturerID);
		$this->Model=sanitize($this->Model);
		$this->EndofLife=sanitize($this->EndofLife);
		$this->Height=intval($this->Height);
		$this->Weight=intval($this->Weight);
		$this->Wattage=intval($this->Wattage);
		$this->DeviceType=(in_array($this->DeviceType, $validDeviceTypes))?$this->DeviceType:'Server';
		$this->PSCount=intval($this->PSCount);
		$this->NumPorts=intval($this->NumPorts);
        $this->Notes=sanitize($this->Notes,false);
        $this->FrontPictureFile=sanitize($this->FrontPictureFile);
	    $this->RearPictureFile=sanitize($this->RearPictureFile);
		$this->ChassisSlots=intval($this->ChassisSlots);
		$this->RearChassisSlots=intval($this->RearChassisSlots);
		$this->SNMPVersion=(in_array($this->SNMPVersion, $validSNMPVersions))?$this->SNMPVersion:$config->ParameterArray["SNMPVersion"];
		$this->GlobalID=intval($this->GlobalID);
	}

	function MakeDisplay(){
		$this->Model=stripslashes($this->Model);
		$this->EndofLife=stripslashes($this->EndofLife);
        $this->Notes=stripslashes($this->Notes);
        $this->FrontPictureFile=stripslashes($this->FrontPictureFile);
	    $this->RearPictureFile=stripslashes($this->RearPictureFile);
	}

	static function RowToObject($row,$extendmodel=true){
		$Template=new DeviceTemplate();
		$Template->TemplateID=$row["TemplateID"];
		$Template->ManufacturerID=$row["ManufacturerID"];
		$Template->Model=$row["Model"];
		$Template->EndofLife=$row["EndofLife"];
		$Template->Height=$row["Height"];
		$Template->Weight=$row["Weight"];
		$Template->Wattage=$row["Wattage"];
		$Template->DeviceType=$row["DeviceType"];
		$Template->PSCount=$row["PSCount"];
		$Template->NumPorts=$row["NumPorts"];
        $Template->Notes=$row["Notes"];
        $Template->FrontPictureFile=html_entity_decode($row["FrontPictureFile"],ENT_QUOTES);
        $Template->RearPictureFile=html_entity_decode($row["RearPictureFile"],ENT_QUOTES);
		$Template->ChassisSlots=$row["ChassisSlots"];
		$Template->RearChassisSlots=$row["RearChassisSlots"];
		$Template->SNMPVersion=$row["SNMPVersion"];
		$Template->GlobalID = $row["GlobalID"];
        $Template->MakeDisplay();
		$Template->GetCustomValues();

		if($extendmodel){
			// Extend our device model
			if($Template->DeviceType=="CDU"){
				$cdut=new CDUTemplate();
				$cdut->TemplateID=$Template->TemplateID;
				$cdut->GetTemplate();
				foreach($cdut as $prop => $val){
					$Template->$prop=$val;
				}
			}
			if($Template->DeviceType=="Sensor"){
				$st=new SensorTemplate();
				$st->TemplateID=$Template->TemplateID;
				$st->GetTemplate();
				foreach($st as $prop => $val){
					$Template->$prop=$val;
				}
			}
		}
		return $Template;
	}
  
	function query($sql){
		global $dbh;
		return $dbh->query($sql);
	}
	
	function exec($sql){
		global $dbh;
		return $dbh->exec($sql);
	}
	
	function prepare( $sql ) {
		global $dbh;
		return $dbh->prepare( $sql );
	}
		
	function CreateTemplate(){
		global $dbh;
		
		$this->MakeSafe();

		$sql="INSERT INTO fac_DeviceTemplate SET ManufacturerID=$this->ManufacturerID, 
			Model=\"$this->Model\",
			EndofLife=\"".date("Y-m-d",strtotime($this->EndofLife))."\",
			Height=$this->Height, Weight=$this->Weight,
			Wattage=$this->Wattage, DeviceType=\"$this->DeviceType\", 
			PSCount=$this->PSCount, NumPorts=$this->NumPorts, Notes=\"$this->Notes\", 
			FrontPictureFile=\"$this->FrontPictureFile\", RearPictureFile=\"$this->RearPictureFile\",
			ChassisSlots=$this->ChassisSlots, RearChassisSlots=$this->RearChassisSlots, SNMPVersion=\"$this->SNMPVersion\",
			GlobalID=$this->GlobalID;";

		if(!$dbh->exec($sql)){
			error_log( "SQL Error: " . $sql );
			return false;
		}else{
			$this->TemplateID=$dbh->lastInsertId();
			if($this->DeviceType=="CDU"){
				// If this is a cdu make the corresponding other hidden template
				$cdut=new CDUTemplate();
				foreach($cdut as $prop => $val){
					if(isset($this->$prop)){
						$cdut->$prop=$this->$prop;
					}
				}
				$cdut->CreateTemplate($this->TemplateID);
			}

			if($this->DeviceType=="Sensor"){
				// If this is a sensor make the corresponding other hidden template
				$st=new SensorTemplate();
				foreach($st as $prop => $val){
					if(isset($this->$prop)){
						$st->$prop=$this->$prop;
					}
				}
				$st->CreateTemplate($this->TemplateID);
			}

			(class_exists('LogActions'))?LogActions::LogThis($this):'';
			$this->MakeDisplay();
			return true;
		}
	}
  
	function UpdateTemplate(){
		$this->MakeSafe();
        $sql="UPDATE fac_DeviceTemplate SET ManufacturerID=$this->ManufacturerID,
			Model=\"$this->Model\",
			EndofLife=\"".date("Y-m-d",strtotime($this->EndofLife))."\",
			Height=$this->Height, Weight=$this->Weight,
			Wattage=$this->Wattage, DeviceType=\"$this->DeviceType\", 
			PSCount=$this->PSCount, NumPorts=$this->NumPorts, Notes=\"$this->Notes\", 
			FrontPictureFile=\"$this->FrontPictureFile\", RearPictureFile=\"$this->RearPictureFile\",
			ChassisSlots=$this->ChassisSlots, RearChassisSlots=$this->RearChassisSlots, SNMPVersion=\"$this->SNMPVersion\",
			GlobalID=$this->GlobalID
			WHERE TemplateID=$this->TemplateID;";

		$old=new DeviceTemplate();
		$old->TemplateID=$this->TemplateID;
		$old->GetTemplateByID();

		if($old->DeviceType=="CDU" && $this->DeviceType!=$old->DeviceType){
			// Template changed from CDU to something else, clean up the mess
			$cdut=new CDUTemplate();
			$cdut->TemplateID=$this->TemplateID;
			$cdut->DeleteTemplate();
		}elseif($this->DeviceType=="CDU" && $this->DeviceType!=$old->DeviceType){
			// Template changed to CDU from something else, make the extra stuff
			$cdut=new CDUTemplate();
			$cdut->Model=$this->Model;
			$cdut->EndofLife=$this->EndofLife;
			$cdut->ManufacturerID=$this->ManufacturerID;
			$cdut->CreateTemplate($this->TemplateID);
		}

		if($old->DeviceType=="Sensor" && $this->DeviceType!=$old->DeviceType){
			// Template changed from Sensor to something else, clean up the mess
			$st=new SensorTemplate();
			$st->TemplateID=$this->TemplateID;
			$st->DeleteTemplate();
		}elseif($this->DeviceType=="Sensor" && $this->DeviceType!=$old->DeviceType){
			// Template changed to Sensor from something else, make the extra stuff
			$st=new SensorTemplate();
			$st->Model=$this->Model;
			$st->EndofLife=$this->EndofLife;
			$st->ManufacturerID=$this->ManufacturerID;
			$st->CreateTemplate($this->TemplateID);
		}

		if(!$this->query($sql)){
			return false;
		}else{
			// If we update the picture then update all device pictures so they get the 
			// new value none of the other values getting updated directly apply to the 
			// devices so we shouldn't have to call this that often.
			if($this->FrontPictureFile!=$old->FrontPictureFile || $this->RearPictureFile!=$old->RearPictureFile){
				$dev=new Device();
				$dev->TemplateID=$this->TemplateID;
				foreach($dev->search() as $i => $d){
					$d->UpdateDeviceCache();
				}
			}

			(class_exists('LogActions'))?LogActions::LogThis($this,$old):'';
			$this->MakeDisplay();
			return true;
		}
	}
  
	function DeleteTemplate(){
		$this->MakeSafe();

		// If we're removing the template clean up the children
		$this->DeleteSlots();
		$this->DeletePorts();

		$sql="DELETE FROM fac_DeviceTemplate WHERE TemplateID=$this->TemplateID;";
		(class_exists('LogActions'))?LogActions::LogThis($this):'';
		return $this->exec($sql);
	}

	function Search($indexedbyid=false,$loose=false){
		$o=new stdClass();
		// Store any values that have been added before we make them safe 
		foreach($this as $prop => $val){
			if(isset($val)){
				$o->$prop=$val;
			}
		}

		// Make everything safe for us to search with
		$this->MakeSafe();

		// This will store all our extended sql
		$sqlextend="WHERE a.ManufacturerID=b.ManufacturerID";

		foreach($o as $prop => $val){
			extendsql('a.'.$prop,$this->$prop,$sqlextend,$loose);
		}

		// The join is purely to sort the templates by the manufacturer's name
		$sql="SELECT a.* FROM fac_DeviceTemplate a, fac_Manufacturer b 
			$sqlextend ORDER BY Name ASC, Model ASC;";

		$templateList=array();

		foreach($this->query($sql) as $row){
			if($indexedbyid){
				$templateList[$row["TemplateID"]]=DeviceTemplate::RowToObject($row);
			}else{
				$templateList[]=DeviceTemplate::RowToObject($row);
			}
		}

		return $templateList;
	}

	// Make a simple reference to a loose search
	function LooseSearch($indexedbyid=false){
		return $this->Search($indexedbyid,true);
	}

	// Add a wrapper so we get templates like devices and other stuff, generic
	function GetTemplate(){
		return $this->GetTemplateByID();
	}

	function GetTemplateByID(){
		$this->MakeSafe();

		$sql="SELECT * FROM fac_DeviceTemplate WHERE TemplateID=$this->TemplateID;";

		//JMGA Reset object in case of a lookup failure
		$this->ManufacturerID=0;
		$this->Model="";
		$this->EndofLife="1970-01-01";
		$this->Height=0;
		$this->Weight=0;
		$this->Wattage=0;
		$this->DeviceType='Server';
		$this->PSCount=0;
		$this->NumPorts=0;
        $this->Notes="";
        $this->FrontPictureFile="";
	    $this->RearPictureFile="";
		$this->ChassisSlots=0;
		$this->RearChassisSlots=0;
		$this->SNMPVersion="";
		$this->GlobalID=0;
		// Reset object in case of a lookup failure
		//foreach($this as $prop => $value){
		//	$value=($prop!='TemplateID')?null:$value;
		//}
		
		if($row=$this->query($sql)->fetch()){
			foreach(DeviceTemplate::RowToObject($row) as $prop => $value){
				$this->$prop=$value;
			}
			return true;
		}else{
			return false;
		}
	}
  
	static function GetTemplateList( $indexed=false ){
		global $dbh;

		$sql="SELECT * FROM fac_DeviceTemplate a, fac_Manufacturer b WHERE 
			a.ManufacturerID=b.ManufacturerID ORDER BY Name ASC, Model ASC;";

		$templateList=array();
		foreach($dbh->query($sql) as $row){
			if ( $indexed ) {
				$templateList[$row["TemplateID"]]=DeviceTemplate::RowToObject($row);
			} else {
				$templateList[]=DeviceTemplate::RowToObject($row);
			}
		}

		return $templateList;
	}
	
	function GetTemplateListByManufacturer(){
		$this->MakeSafe();

		$sql="SELECT * FROM fac_DeviceTemplate a, fac_Manufacturer b WHERE 
			a.ManufacturerID=b.ManufacturerID AND a.ManufacturerID=$this->ManufacturerID 
			ORDER BY Name ASC, Model ASC;";

		$templateList=array();
		foreach($this->query($sql) as $row){
			$templateList[]=DeviceTemplate::RowToObject($row);
		}

		return $templateList;
	}

    /**
     * Return a list of the templates indexed by the TemplateID
     *
     * @return multitype:DeviceTemplate
     */
    public static function getTemplateListIndexedbyID() {
        global $dbh;
        $templateList = array();
        $stmt = $dbh->prepare('select * from fac_DeviceTemplate');
        $stmt->execute();
        while ($row = $stmt->fetch()) {
            $devTempl = DeviceTemplate::RowToObject($row);
            $templateList[$devTempl->TemplateID] = $devTempl;
        }
        return $templateList;
    }

	function GetMissingMfgDates(){
		$this->MakeSafe();

		$sql="SELECT a.* FROM fac_Device a, fac_DeviceTemplate b WHERE
			a.TemplateID=b.TemplateID AND b.ManufacturerID=$this->ManufacturerID AND 
			a.MfgDate<'1970-01-01'";

		$devList=array();
		foreach($this->query($sql) as $row){
			$devList[]=Device::RowToObject($row);
		}

		$this->MakeDisplay();
		return $devList;
	}

	function UpdateDevices(){
		/* This will cause every device with a TemplateID matching this one to display
		   the updated values.  We are not touching DeviceType or NumPorts at this time
		   because those have alternate side effects that i'm not sure we really need
		   to address here
		*/
		$this->MakeSafe();

		$sql="UPDATE fac_Device SET Height=$this->Height, NominalWatts=$this->Wattage, 
			PowerSupplyCount=$this->PSCount, ChassisSlots=$this->ChassisSlots, Weight=$this->Weight,
			RearChassisSlots=$this->RearChassisSlots, SNMPVersion=\"$this->SNMPVersion\" WHERE TemplateID=$this->TemplateID;";

		return $this->query($sql);
	}
	
	function DeleteSlots(){
		$this->MakeSafe();
		
		$sql="DELETE FROM fac_Slots WHERE TemplateID=$this->TemplateID";
		if(!$this->query($sql)){
			return false;
		}
		return true;
	}
	
	function DeletePorts(){
		$this->MakeSafe();
		
		$sql="DELETE FROM fac_TemplatePorts WHERE TemplateID=$this->TemplateID";
		if(!$this->query($sql)){
			return false;
		}
		return true;
	}

	function DeletePowerPorts(){
		$this->MakeSafe();
		
		$sql="DELETE FROM fac_TemplatePowerPorts WHERE TemplateID=$this->TemplateID";
		if(!$this->query($sql)){
			return false;
		}
		return true;
	}

	// This was a double of the DeletePorts function need to come back later
	// and see if this thing is even being used.	
	function removePorts(){
		return $this->DeletePorts();
	}
	
	function ExportTemplate(){
		global $config;
		if($this->GetTemplate()){
			//Get manufacturer name
			$manufacturer=new Manufacturer($this->ManufacturerID);
			$manufacturer->GetManufacturer();
			$this->ManufacturerName=$manufacturer->Name;

			$dp=new TemplatePorts($this->TemplateID);
			$dpp=new TemplatePowerPorts($this->TemplateID);
			$this->ports=$dp->getPorts();
			$this->powerports=$dpp->getPorts();
			$this->slots=Slot::getSlots($this->TemplateID);

			$colorcodes=ColorCoding::GetCodeList();
			$mediatypes=MediaTypes::GetMediaTypeList();
			foreach($this->ports as $i => $p){
				if($p->MediaID>0){
					$p->MediaType=$mediatypes[$p->MediaID]->MediaType;
				}
				if($p->ColorID>0){
					$p->Name=$colorcodes[$p->ColorID]->Name;
				}
			}

			foreach(array('FrontPictureFile','RearPictureFile') as $pic){
				$path=$config->ParameterArray['picturepath'];
				$file=$path.DIRECTORY_SEPARATOR.$this->$pic;
				if(is_file($file)){
					$type = pathinfo($file, PATHINFO_EXTENSION);
					$data = file_get_contents($file);
					$base64 = 'data:image/' . $type . ';base64,' . base64_encode($data);
					$this->$pic=$base64;
				}
			}

			header('Content-Type: application/json');
			$fileContent=json_encode($this);
			//download file
			download_file_from_string($fileContent, str_replace(' ', '', $manufacturer->Name."-".$this->Model).".json");
			
			return true;
		}else{
			// tried to export a template that doesn't exist
			return false;
		}
	}

	function ImportTemplate($file){
		global $config;
		$result=array();
		$result["status"]="";
		$result["log"]=array();

		$templatefile=json_decode(file_get_contents($file));

		//manufacturer
		$manufacturer=new Manufacturer();
		$manufacturer->Name=$templatefile->ManufacturerName;
		if (!$manufacturer->GetManufacturerByName()){
			//New Manufacturer
			$manufacturer->CreateManufacturer();
		}
		$templatefile->ManufacturerID=$manufacturer->ManufacturerID;

		$template=new DeviceTemplate();
		foreach($template as $prop => $val){
			$template->$prop=$templatefile->$prop;
		}

		if($template->FrontPictureFile!='' && preg_match("/^data/i",$template->FrontPictureFile)){
			preg_match("/image\/([a-zA-Z0-9]{1,5})/i",$template->FrontPictureFile,$ext_matches);
			list($type, $template->FrontPictureFile) = explode(';', $template->FrontPictureFile);
			list(, $template->FrontPictureFile)      = explode(',', $template->FrontPictureFile);
			$front_file = base64_decode($template->FrontPictureFile);
			$frontfilename=filter_var("{$templatefile->ManufacturerID}.{$templatefile->ManufacturerName}-{$templatefile->Model}-FRONT.{$ext_matches[1]}", FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES | FILTER_FLAG_STRIP_LOW);
			$frontfilename=str_replace(" ","-",$frontfilename);
			$template->FrontPictureFile=$frontfilename;
		}else{
			$template->FrontPictureFile="";
			$front_file=false;
		}

		if($template->RearPictureFile!='' && preg_match("/^data/i",$template->RearPictureFile)){
			preg_match("/image\/([a-zA-Z0-9]{1,5})/i",$template->RearPictureFile,$ext_matches);
			list($type, $template->RearPictureFile) = explode(';', $template->RearPictureFile);
			list(, $template->RearPictureFile)      = explode(',', $template->RearPictureFile);
			$rear_file = base64_decode($template->RearPictureFile);
			$rearfilename=filter_var("{$templatefile->ManufacturerID}.{$templatefile->ManufacturerName}-{$templatefile->Model}-REAR.{$ext_matches[1]}", FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES | FILTER_FLAG_STRIP_LOW);
			$rearfilename=str_replace(" ","-",$rearfilename);
			$template->RearPictureFile=$rearfilename;
		}else{
			$template->RearPictureFile="";
			$rear_file=false;
		}

		//create the template
		if (!$template->CreateTemplate()){
			$result["status"]=__("Import Error");
			$result["log"][0]=__("An error has occurred creating the template.<br>Possibly there is already a template of the same manufacturer and model");
			return $result;
		}

		//get template to this object
		$this->TemplateID=$template->TemplateID;
		$this->GetTemplate();

		//slots
		foreach ($templatefile->slots as $i => $s){
			$slot=new Slot();
			foreach($slot as $prop => $val){
				$slot->$prop=$s->$prop;
			}
			$slot->TemplateID=$this->TemplateID;
			if (($slot->Position<=$this->ChassisSlots && !$slot->BackSide) || ($slot->Position<=$this->RearChassisSlots && $slot->BackSide)){
				if(!$slot->CreateSlot()){
					$result["status"]=__("Import Warning");
					$result["log"][$ierror++]=sprintf(__("An error has occurred creating the slot %s"),$slot->Position."-".($slot->BackSide)?__("Rear"):__("Front"));
				}
			}else{
				$result["status"]=__("Import Warning");
				$result["log"][$ierror++]=sprintf(__("Ignored slot %s"),$slot->Position."-".($slot->BackSide)?__("Rear"):__("Front"));
			}
		}

		//ports
		$colorcodes=ColorCoding::GetCodeList('Name');
		$mediatypes=MediaTypes::GetMediaTypeList('MediaType');
		foreach ($templatefile->ports as $p){
			$tp=new TemplatePorts();
			foreach($tp as $prop => $val){
				$tp->$prop=$p->$prop;
			}
			$tp->TemplateID=$this->TemplateID;

			// deal with any potential color codes
			if($tp->ColorID>0 && (isset($tp->Name) && $tp->Name!='')){
				if(array_key_exists($tp->Name, $colorcodes)){
					$tp->ColorID=$colorcodes[$tp->Name]->ColorID;
				}else{
					$cc=new ColorCoding();
					$cc->Name=$tp->Name;
					if(!$cc->CreateCode()){
						$cc->ColorID=0;
						$result["status"]=__("Import Warning");
						$result["log"][$ierror++]=sprintf(__("An error has occurred creating the color code %s"),$cc->Name);
					}
					$colorcodes["$cc->Name"]=$cc;
					$tp->ColorID=$cc->ColorID;
				}
			}

			// deal with any potential media types
			if($tp->MediaID>0 && (isset($tp->MediaType) && $tp->MediaType!='')){
				if(array_key_exists($tp->MediaType, $mediatypes)){
					$tp->MediaID=$mediatypes[$tp->MediaType]->MediaID;
				}else{
					$mt=new MediaTypes();
					$mt->MediaType=$tp->MediaType;
					$mt->ColorID=$tp->ColorID;
					if(!$mt->CreateType()){
						$mt->MediaID=0;
						$result["status"]=__("Import Warning");
						$result["log"][$ierror++]=sprintf(__("An error has occurred creating the media type %s"),$mt->MediaType);
					}
					$mediatypes[$mt->MediaType]=$mt;
					$tp->MediaID=$mt->MediaID;
				}
			}

			// now try to actually create the template port
			if ($tp->PortNumber<=$this->NumPorts){
				if(!$tp->CreatePort()){
					$result["status"]=__("Import Warning");
					$result["log"][$ierror++]=sprintf(__("An error has occurred creating the port %s"),$tp->PortNumber);
				}
			}else{
				$result["status"]=__("Import Warning");
				$result["log"][$ierror++]=sprintf(__("Ignored port %s"),$tp->PortNumber);
			}
		}

		foreach ($templatefile->powerports as $p){
			$tp=new TemplatePowerPorts();
			foreach($tp as $prop => $val){
				$tp->$prop=$p->$prop;
			}
			$tp->TemplateID=$this->TemplateID;
			if ($tp->PortNumber<=$this->PSCount){
				if(!$tp->CreatePort()){
					$result["status"]=__("Import Warning");
					$result["log"][$ierror++]=sprintf(__("An error has occurred creating the port %s"),$tp->PortNumber);
				}
			}else{
				$result["status"]=__("Import Warning");
				$result["log"][$ierror++]=sprintf(__("Ignored port %s"),$tp->PortNumber);
			}
		}
		//only write out a file if they don't already exist
		$path=$config->ParameterArray['picturepath'];
		if($front_file && !is_file($path.DIRECTORY_SEPARATOR.$frontfilename)){
			file_put_contents($path.DIRECTORY_SEPARATOR.$frontfilename, $front_file);
		}
		if($rear_file && !is_file($path.DIRECTORY_SEPARATOR.$rearfilename)){
			file_put_contents($path.DIRECTORY_SEPARATOR.$rearfilename, $rear_file);
		}
		return $result;
	}
	
	function GetCustomValues() {
		$this->MakeSafe();

		$tdca = array();
		//Table join to make it where we can half ass sort the custom attributes based on the label data
		$sql="SELECT a.Label, v.TemplateID, v.AttributeID, v.Required, v.Value FROM 
			fac_DeviceTemplateCustomValue v, fac_DeviceCustomAttribute a WHERE 
			a.AttributeID=v.AttributeID AND TemplateID=$this->TemplateID ORDER BY Label, 
			AttributeID;";
		foreach($this->query($sql) as $tdcrow) {
			$this->{$tdcrow["Label"]}=$tdcrow["Value"];
			$tdca[$tdcrow["AttributeID"]]["value"]=$tdcrow["Value"];
			$tdca[$tdcrow["AttributeID"]]["required"]=$tdcrow["Required"];
		}	
		$this->CustomValues = $tdca;
	}	

	function DeleteCustomValues() {
		$this->MakeSafe();
		
		$sql="DELETE FROM fac_DeviceTemplateCustomValue WHERE TemplateID=$this->TemplateID;";
		if($this->query($sql)){
			$this->GetCustomValues();
			return true;
		}
		return false;
	}

	function InsertCustomValue($AttributeID, $Value, $Required) {
		$this->MakeSafe();
		// make the custom attirubte stuff safe
		$AttributeID=intval($AttributeID);
		$Required=intval($Required);
		$Value=sanitize(trim($Value));

		$sql="INSERT INTO fac_DeviceTemplateCustomValue
			SET TemplateID=$this->TemplateID,
			    AttributeID=$AttributeID,
			    Required=$Required,
			    Value=\"$Value\";";
		if($this->query($sql)) {
			$this->GetCustomValues();
			return true;
		}
		return false;
	}

	static function getAvailableImages(){
		global $config;
		$array=array();
		$path=$config->ParameterArray['picturepath'];
		if(preg_match('/api\//',str_replace(DIRECTORY_SEPARATOR, '/',getcwd()))){
			$path="../../$path";
		}
		if(is_dir($path)){
			$dir=scandir($path);
			foreach($dir as $i => $f){
				if(is_file($path.DIRECTORY_SEPARATOR.$f) && ($f!='.' && $f!='..' && $f!='P_ERROR.png')){
					$mimeType=mime_content_type($path.DIRECTORY_SEPARATOR.$f);
					if(preg_match('/^image/i', $mimeType)){
						$array[]=$f;
					}
				}
			}
		}
		return $array;
	}
}

?>
