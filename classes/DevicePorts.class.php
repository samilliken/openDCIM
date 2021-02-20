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

class DevicePorts {
	var $DeviceID;
	var $PortNumber;
	var $Label;
	var $MediaID;
	var $ColorID;
	var $ConnectedDeviceID;
	var $ConnectedPort;
	var $Notes;
	
	function MakeSafe() {
		$this->DeviceID=intval($this->DeviceID);
		$this->PortNumber=intval($this->PortNumber);
		$this->Label=sanitize($this->Label);
		$this->MediaID=intval($this->MediaID);
		$this->ColorID=intval($this->ColorID);
		$this->ConnectedDeviceID=intval($this->ConnectedDeviceID);
		$this->ConnectedPort=intval($this->ConnectedPort);
		$this->Notes=sanitize($this->Notes);

		if($this->ConnectedDeviceID==0 || $this->ConnectedPort==0){
			$this->ConnectedDeviceID="NULL";
			$this->ConnectedPort="NULL";
		}
	}

	function MakeDisplay(){
		$this->Label=stripslashes(trim($this->Label));
		$this->Notes=stripslashes(trim($this->Notes));
	}

	static function RowToObject($dbRow){
		$dp=new DevicePorts();
		$dp->DeviceID=$dbRow['DeviceID'];
		$dp->PortNumber=$dbRow['PortNumber'];
		$dp->Label=$dbRow['Label'];
		$dp->MediaID=$dbRow['MediaID'];
		$dp->ColorID=$dbRow['ColorID'];
		$dp->ConnectedDeviceID=$dbRow['ConnectedDeviceID'];
		$dp->ConnectedPort=$dbRow['ConnectedPort'];
		$dp->Notes=$dbRow['Notes'];

		$dp->MakeDisplay();

		return $dp;
	}

	function getPort(){
		global $dbh;
		$this->MakeSafe();

		$sql="SELECT * FROM fac_Ports WHERE DeviceID=$this->DeviceID AND PortNumber=$this->PortNumber;";

		if(!$row=$dbh->query($sql)->fetch()){
			return false;
		}else{
			foreach(DevicePorts::RowToObject($row) as $prop => $value){
				$this->$prop=$value;
			}
			return true;
		}
	}

	function getPorts( $empty = false ){
		global $dbh;
		$this->MakeSafe();

		if ( $empty ) {
			$clause = "AND (ConnectedDeviceID=0 or ConnectedDeviceID is null)";
		} else {
			$clause = "";
		}

		$sql="SELECT * FROM fac_Ports WHERE DeviceID=$this->DeviceID $clause ORDER BY PortNumber ASC;";

		$ports=array();
		foreach($dbh->query($sql) as $row){
			$ports[$row['PortNumber']]=DevicePorts::RowToObject($row);
		}	
		return $ports;
	}

	function getActivePortCount() {
		global $dbh;
		$this->MakeSafe();
			
		$sql = "select count(*) as ActivePorts from fac_Ports where DeviceID=$this->DeviceID and (ConnectedDeviceID>0 or Notes > '')";
		
		$row = $dbh->query($sql)->fetch();

		return $row["ActivePorts"];
	}
		
	function createPort($ignore_errors=false) {
		global $dbh;
		
		$this->MakeSafe();

		$sql="INSERT INTO fac_Ports SET DeviceID=$this->DeviceID, PortNumber=$this->PortNumber, 
			Label=\"$this->Label\", MediaID=$this->MediaID, ColorID=$this->ColorID, 
			ConnectedDeviceID=$this->ConnectedDeviceID, ConnectedPort=$this->ConnectedPort, 
			Notes=\"$this->Notes\";";
			
		if(!$dbh->query($sql) && !$ignore_errors){
			$info=$dbh->errorInfo();

			error_log("createPort::PDO Error: {$info[2]} SQL=$sql");
			return false;
		}

		(class_exists('LogActions'))?LogActions::LogThis($this):'';
		return true;
	}

	static function createPorts($DeviceID,$update_existing=false){
		// If $update_existing is true then we'll try to create all the ports and as a by product
		// create any new ports.  The setting here will ensure we don't log any errors from the
		// ports that already exist.
		$dev=New Device;
		$dev->DeviceID=$DeviceID;
		if(!$dev->GetDevice()){return false;}

		// Check the user's permissions to modify this device
		if($dev->Rights!='Write'){return false;}
		$portList=array();
		
		if($dev->DeviceType=="Switch"){
			$nameList=SwitchInfo::getPortNames($dev->DeviceID);
			$aliasList=SwitchInfo::getPortAlias($dev->DeviceID);
		}

		// Build the DevicePorts from the existing info in the following priority:
		//  - Template ports table
		//  - SNMP data (if it exists)
		//  - Placeholders
		
		//Search template ports
		$tports=array();
		if($dev->TemplateID>0){
			$tport=new TemplatePorts();
			$tport->TemplateID=$dev->TemplateID;
			$tports=$tport->getPorts();
		}
		
		if($dev->DeviceType=="Switch"){
			for($n=0; $n<$dev->Ports; $n++){
				$i=$n+1;
				$portList[$i]=new DevicePorts();
				$portList[$i]->DeviceID=$dev->DeviceID;
				$portList[$i]->PortNumber=$i;
				if(isset($tports[$i])){
					// Get any attributes from the device template
					foreach($tports[$i] as $key => $value){
						if(array_key_exists($key,$portList[$i])){
							$portList[$i]->$key=$value;
						}
					}
				}
				// pull port name first from snmp then from template then just call it port x
				$portList[$i]->Label=(isset($nameList[$n]))?$nameList[$n]:(isset($tports[$i]) && $tports[$i]->Label)?$tports[$i]->Label:__("Port").$i;
				$portList[$i]->Notes=(isset($aliasList[$n]))?$aliasList[$n]:'';
				$portList[$i]->createPort($update_existing);
			}
		}else{
			for($n=0; $n<$dev->Ports; $n++){
				$i=$n+1;
				$portList[$i]=new DevicePorts();
				$portList[$i]->DeviceID=$dev->DeviceID;
				$portList[$i]->PortNumber=$i;
				if(isset($tports[$i])){
					// Get any attributes from the device template
					foreach($tports[$i] as $key => $value){
						if(array_key_exists($key,$portList[$i])){
							$portList[$i]->$key=$value;
						}
					}
				}
				$portList[$i]->Label=($portList[$i]->Label=="")?__("Port").$i:$portList[$i]->Label;
				$portList[$i]->createPort($update_existing);
				if($dev->DeviceType=="Patch Panel"){
					$label=$portList[$i]->Label;
					$i=$i*-1;
					$portList[$i]=new DevicePorts();
					$portList[$i]->DeviceID=$dev->DeviceID;
					$portList[$i]->PortNumber=$i;
					$portList[$i]->Label=$label;
					$portList[$i]->createPort($update_existing);
				}
			}
		}
		return $portList;
	}


	function updateLabel(){
		global $dbh;

		$this->MakeSafe();

		$sql="UPDATE fac_Ports SET Label=\"$this->Label\" WHERE 
			DeviceID=$this->DeviceID AND PortNumber=$this->PortNumber;";

		if(!$dbh->query($sql)){
			return false;
		}else{
			return true;
		}
	}

	function updatePort($fasttrack=false) {
		global $dbh;

		$oldport=new DevicePorts(); // originating port prior to modification
		$oldport->DeviceID=$this->DeviceID;
		$oldport->PortNumber=$this->PortNumber;
		$oldport->getPort();
		$tmpport=new DevicePorts(); // connecting to here
		$tmpport->DeviceID=$this->ConnectedDeviceID;
		$tmpport->PortNumber=$this->ConnectedPort;
		$tmpport->getPort();
		$dev=new Device();
		$dev->DeviceID=$this->DeviceID;
		$dev->GetDevice();
		// This is gonna be a hack for updating a port when we don't want a recursion loop
		// I'll likely remove the makeConnection method after this
		if(!$fasttrack){
			$oldtmpport=new DevicePorts(); // used for logging
			$oldtmpport->DeviceID=$oldport->ConnectedDeviceID;
			$oldtmpport->PortNumber=$oldport->ConnectedPort;
			$oldtmpport->getPort();

			//check rights before we go any further
			$replacingdev=new Device();
			$replacingdev->DeviceID=$oldport->ConnectedDeviceID;
			$replacingdev->GetDevice();
			$connecteddev=new Device();
			$connecteddev->DeviceID=$this->ConnectedDeviceID;
			$connecteddev->GetDevice();

			$rights=false;
			$rights=($dev->Rights=="Write")?true:$rights;
			$rights=($replacingdev->Rights=="Write")?true:$rights;
			$rights=($connecteddev->Rights=="Write")?true:$rights;

			if(!$rights){
				return false;
			}
		
			$this->MakeSafe();

			// Quick sanity check so we aren't depending on the user
			$this->Label=($this->Label=="")?$this->PortNumber:$this->Label;

			// clear previous connection
			$oldport->removeConnection();
			$tmpport->removeConnection();

			if($this->ConnectedDeviceID==0 || $this->PortNumber==0 || $this->ConnectedPort==0){
				// when any of the above equal 0 this is a delete request
				// skip making any new connections but go ahead and update the device
				// reload tmpport with data from the other device
				$tmpport->DeviceID=$oldport->ConnectedDeviceID;
				$tmpport->PortNumber=$oldport->ConnectedPort;
				$tmpport->getPort();
			}else{
				// make new connection
				$tmpport->ConnectedDeviceID=$this->DeviceID;
				$tmpport->ConnectedPort=$this->PortNumber;
				$tmpport->Notes=$this->Notes;
				$tmpport->MediaID=$this->MediaID;
				$tmpport->ColorID=$this->ColorID;
				$tmpport->updatePort(true);
	// The three lines above were added to sync media and color types with the connection
	//			DevicePorts::makeConnection($tmpport,$this);
			}
		}
		// update port
		$sql="UPDATE fac_Ports SET MediaID=$this->MediaID, ColorID=$this->ColorID, 
			ConnectedDeviceID=$this->ConnectedDeviceID, Label=\"$this->Label\", 
			ConnectedPort=$this->ConnectedPort, Notes=\"$this->Notes\" 
			WHERE DeviceID=$this->DeviceID AND PortNumber=$this->PortNumber;";

		if(!$dbh->query($sql)){
			$info=$dbh->errorInfo();

			error_log("updatePort::PDO Error: {$info[2]} SQL=$sql");
			
			return false;
		}

		// If this is a patch panel and a front port then set the label on the rear 
		// to match only after a successful update, done above.
		if($dev->DeviceType=="Patch Panel" && $this->PortNumber>0 && $this->Label!=$oldport->Label){
			$pport=new DevicePorts();
			$pport->DeviceID=$this->DeviceID;
			$pport->PortNumber=-$this->PortNumber;
			$pport->getPort();
			$pport->Label=$this->Label;
			$pport->updateLabel();
		}

		// two logs, because we probably modified two devices
		(class_exists('LogActions'))?LogActions::LogThis($this,$oldport):'';
		if(!$fasttrack){
			(class_exists('LogActions'))?LogActions::LogThis($tmpport,$oldtmpport):'';
		}
		return true;
	}

	static function followPathToEndPoint( $DeviceID, $PortNumber ) {
		$path = array();
		$n = sizeof( $path );
		
		$dev = new Device();
		$dev->DeviceID=$DeviceID;
		$dev->getDevice();
		
		$path[$n] = new DevicePorts();
		$path[$n]->DeviceID = $DeviceID;
		$path[$n]->PortNumber = ($dev->DeviceType=="Patch Panel")?-$PortNumber:$PortNumber;
		$path[$n]->getPort();
		
		// Follow the trail until you get no more connections
		while ( $path[$n]->ConnectedDeviceID > 0 ) {
			$path[++$n] = new DevicePorts();
			$path[$n]->DeviceID = $path[$n-1]->ConnectedDeviceID;
			// Patch panels have +/- port numbers to designate front/rear, so as you
			// traverse the path, you have to flip
			$path[$n]->PortNumber = -($path[$n-1]->ConnectedPort);
			$path[$n]->getPort();
		}
		
		// If the connected device id is null and the label is empty then the port failed to lookup
		// invert the sign and try to get the port again cause this might be a device and not a 
		// patch panel
		if($path[$n]->ConnectedDeviceID=="NULL" && $path[$n]->Label==""){
			$path[$n]->PortNumber=$path[$n]->PortNumber*-1;
			$path[$n]->getPort();
		}

		return $path;		
	}

	static function makeConnection($port1,$port2){
		global $dbh;

		$port1->MakeSafe();
		$port2->MakeSafe();

		$sql="UPDATE fac_Ports SET ConnectedDeviceID=$port2->DeviceID, 
			ConnectedPort=$port2->PortNumber, Notes=\"$port2->Notes\" WHERE 
			DeviceID=$port1->DeviceID AND PortNumber=$port1->PortNumber; UPDATE fac_Ports 
			SET ConnectedDeviceID=$port1->DeviceID, ConnectedPort=$port1->PortNumber, 
			Notes=\"$port1->Notes\" WHERE DeviceID=$port2->DeviceID AND 
			PortNumber=$port2->PortNumber;";

		if(!$dbh->exec($sql)){
			$info=$dbh->errorInfo();

			error_log("updatePort::PDO Error: {$info[2]} SQL=$sql");
			return false;
		}

		return true;
	}

	function removeConnection(){
		global $dbh;

		$this->getPort();

		$sql="UPDATE fac_Ports SET ConnectedDeviceID=NULL, ConnectedPort=NULL WHERE
			(DeviceID=$this->DeviceID AND PortNumber=$this->PortNumber) OR 
			(ConnectedDeviceID=$this->DeviceID AND ConnectedPort=$this->PortNumber);";

		/* not sure the best way to catch these errors this should modify 2 lines
		   per run. */
		try{
			$dbh->exec($sql);
		}catch(PDOException $e){
			echo $e->getMessage();
			die();
		}

		return true;
	}

	function removePort(){
		/*	Remove a single port from a device */
		global $dbh;

		if(!$this->getport()){
			return false;
		}

		$this->removeConnection();

		$sql="DELETE FROM fac_Ports WHERE DeviceID=$this->DeviceID AND PortNumber=$this->PortNumber;";

		if(!$dbh->query($sql)){
			//delete failed, wtf
			return false;
		}else{
			(class_exists('LogActions'))?LogActions::LogThis($this):'';
			return true;
		}		
	}

// these next two should probably be moved to the device object.

	static function removeConnections($DeviceID){
		/* Drop all network connections on a device */
		global $dbh;

		$dev=new Device(); // make sure we have a real device first
		$dev->DeviceID=$DeviceID;
		if(!$dev->GetDevice()){return false;}

		// Check the user's permissions to modify this device
		if($dev->Rights!='Write'){return false;}

		$sql="UPDATE fac_Ports SET ConnectedDeviceID=NULL, ConnectedPort=NULL, Notes='' WHERE
			DeviceID=$dev->DeviceID OR ConnectedDeviceID=$dev->DeviceID;";

		$dbh->exec($sql); // don't need to log if this fails

		return true;
	}
	
	static function removePorts($DeviceID){
		/*	Remove all ports from a device prior to delete, etc */
		global $dbh;

		$dev=new Device(); // make sure we have a real device first
		$dev->DeviceID=$DeviceID;
		if(!$dev->GetDevice()){return false;}

		// Check the user's permissions to modify this device
		if($dev->Rights!='Write'){return false;}

		DevicePorts::removeConnections($DeviceID);

		$sql="DELETE FROM fac_Ports WHERE DeviceID=$dev->DeviceID;";

		$dbh->exec($sql);

		return true;
	}


	static function getPatchCandidates($DeviceID,$PortNum=null,$listports=null,$patchpanels=null,$scopelimit=null){
		/*
		 * $DeviceID = ID of the device that you are wanting to make a connection from
		 * $PortNum(optional) = Port Number on the device you are wanting to connect,
		 *		mandatory if media enforcing is on
		 * $listports(optional) = Any value will trigger this to kick back a list of
		 * 		valid points that this port can connect to instead of the default list
		 *		of valid devices that it can connect to.
		 */
		global $dbh;
		global $config;
		global $person;

		$dev=new Device(); // make sure we have a real device first
		$dev->DeviceID=$DeviceID;
		if(!$dev->GetDevice()){return false;}

		$mediaenforce="";
		if($config->ParameterArray["MediaEnforce"]=='enabled' && !is_null($PortNum)){
			$dp=new DevicePorts();
			$dp->DeviceID=$DeviceID;
			$dp->PortNumber=$PortNum;
			$dp->getPort();
			$mt=new MediaTypes();
			$mt->MediaID=$dp->MediaID;
			$mt->GetType();

			$mediaenforce=" AND MediaID=$mt->MediaID";
		}elseif($config->ParameterArray["MediaEnforce"]=='enabled' && is_null($PortNum)){
			// Media Type Enforcing is enabled and you didn't supply a port to match type on
			return false;
		}

		$limiter='';
		if(!is_null($scopelimit)){
			$cab=new Cabinet();
			$cab->CabinetID=$dev->Cabinet;
			$cab->GetCabinet();
			switch ($scopelimit){
				case 'cabinet':
					$limiter=" AND Cabinet=$dev->Cabinet";
					break;
				case 'row':
					$limiter=" AND Cabinet IN (SELECT CabinetID FROM fac_Cabinet WHERE CabRowID=$cab->CabRowID AND CabRowID>0)";
					break;
				case 'zone':
					$limiter=" AND Cabinet IN (SELECT CabinetID FROM fac_Cabinet WHERE ZoneID=$cab->ZoneID AND ZoneID>0)";
					break;
				case 'datacenter':
					$limiter=" AND Cabinet IN (SELECT CabinetID FROM fac_Cabinet WHERE DataCenterID=$cab->DataCenterID)";
					break;
				default:
					break;
			}
		}

		$pp="";
		if(!is_null($patchpanels)){
			$pp=' AND DeviceType="Patch Panel"';
		}
		$candidates=array();

		if(is_null($listports)){
			$currentperson=$person;
			if(!$currentperson->WriteAccess){
				$groups=$currentperson->isMemberOf();  // list of groups the current user is member of
				$rights=null;
				foreach($groups as $index => $DeptID){
					if(is_null($rights)){
						$rights="Owner=$DeptID";
					}else{
						$rights.=" OR Owner=$DeptID";
					}
				}
				$rights=(is_null($rights))?null:" AND ($rights)";
			}else{
				$rights=null;
			}

			$cabinetID=$dev->GetDeviceCabinetID();
			
			$sqlSameCabDevice="SELECT * FROM fac_Device WHERE Ports>0 AND 
				Cabinet=$cabinetID $rights$pp$limiter GROUP BY DeviceID ORDER BY Position 
				DESC, Label ASC;";
			$sqlDiffCabDevice="SELECT * FROM fac_Device WHERE Ports>0 AND 
				Cabinet!=$cabinetID $rights$pp$limiter GROUP BY DeviceID ORDER BY Label ASC;";
			
			foreach(array($sqlSameCabDevice, $sqlDiffCabDevice) as $sql){
				foreach($dbh->query($sql) as $row){
					// false to skip rights check we filtered using sql above
					$tmpDev=Device::RowToObject($row,false);
					$candidates[]=array("DeviceID"=>$tmpDev->DeviceID,"Label"=>$tmpDev->Label,"CabinetID"=>$tmpDev->Cabinet);
				}
			}
		}else{
			$sql="SELECT a.*, b.Cabinet as CabinetID FROM fac_Ports a, fac_Device b WHERE 
				Ports>0 AND Cabinet>-1 AND a.DeviceID=b.DeviceID AND 
				a.DeviceID!=$dev->DeviceID AND ConnectedDeviceID IS NULL$mediaenforce$pp;";
			foreach($dbh->query($sql) as $row){
				$candidates[]=array("DeviceID"=>$row["DeviceID"], "Label"=>$row["Label"], "CabinetID"=>$row["CabinetID"]);
			}
		}

		return $candidates;
	}

	static function getPortList($DeviceID){
		global $dbh;
		
		$dev=new Device();
		$dev->DeviceID=$DeviceID;
		if(!$dev->GetDevice()){
			return false;	// This device doesn't exist
		}
		
		$sql="SELECT * FROM fac_Ports WHERE DeviceID=$dev->DeviceID;";
		
		$portList=array();
		foreach($dbh->query($sql) as $row){
			$portList[$row['PortNumber']]=DevicePorts::RowToObject($row);
		}
		
		if( sizeof($portList)==0 && $dev->DeviceType!="Physical Infrastructure" ){
			// somehow this device doesn't have ports so make them now
			$portList=DevicePorts::createPorts($dev->DeviceID);
		}
		
		return $portList;
	}

	function Search($indexedbyid=false,$loose=false){
		global $dbh;
		$o=array();
		// Store any values that have been added before we make them safe 
		foreach($this as $prop => $val){
			if(isset($val)){
				$o[$prop]=$val;
			}
		}

		// Make everything safe for us to search with
		$this->MakeSafe();

		// This will store all our extended sql
		$sqlextend="";
		foreach($o as $prop => $val){
			extendsql($prop,$this->$prop,$sqlextend,$loose);
		}
		$sql="SELECT * FROM fac_Ports $sqlextend ORDER BY DeviceID, PortNumber ASC;";

		$portList=array();

		foreach($dbh->query($sql) as $portRow){
			if($indexedbyid){
				$portList[$portRow["DeviceID"].$portRow["PortNumber"]]=DevicePorts::RowToObject($portRow);
			}else{
				$portList[]=DevicePorts::RowToObject($portRow);
			}
		}

		return $portList;
	}

	// Make a simple reference to a loose search
	function LooseSearch($indexedbyid=false){
		return $this->Search($indexedbyid,true);
	}

	// Returns the device at the end of the path
	function GetEndPathDevice($deviceID, $portNumber){
		global $dbh;

		$path = DevicePorts::followPathToEndPoint($deviceID,$portNumber);

		$dev= new Device;

		foreach($path as $p){
			$tempP = new DevicePorts;
			$tempP->DeviceID = $p->ConnectedDeviceID;
			$tempP->PortNumber = $p->ConnectedPort;
			$tempP->getPort();
			if(in_array($tempP,$path)){
				$dev->DeviceID = $p->DeviceID;
				$dev->GetDevice();
			}
		}
		if($dev->DeviceID == null){
			$dev = null;
		}
		
		return $dev;
	}

}
?>
