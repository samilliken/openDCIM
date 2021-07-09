<?php
	require_once( 'db.inc.php' );
	require_once( 'facilities.inc.php' );

	$subheader=__("Data Center Device Detail");

	$dev=new Device();
	$cab=new Cabinet();

	$validHypervisors=array( "None", "ESX", "ProxMox" );

	$taginsert="";

	// Ajax functions
	// SNMP Test
	if(isset($_POST['snmptest'])){
		// Parse through the post data and pull in site defaults if necessary
		$community=($_POST['SNMPCommunity']=="")?$config->ParameterArray["SNMPCommunity"]:$_POST['SNMPCommunity'];
		$version=($_POST['SNMPVersion']=="" || $_POST['SNMPVersion']=="default")?$config->ParameterArray["SNMPVersion"]:$_POST['SNMPVersion'];
		$v3SecurityLevel=($_POST['v3SecurityLevel']=="")?$config->ParameterArray["v3SecurityLevel"]:$_POST['v3SecurityLevel'];
		$v3AuthProtocol=($_POST['v3AuthProtocol']=="")?$config->ParameterArray["v3AuthProtocol"]:$_POST['v3AuthProtocol'];
		$v3AuthPassphrase=($_POST['v3AuthPassphrase']=="")?$config->ParameterArray["v3AuthPassphrase"]:$_POST['v3AuthPassphrase'];
		$v3PrivProtocol=($_POST['v3PrivProtocol']=="")?$config->ParameterArray["v3PrivProtocol"]:$_POST['v3PrivProtocol'];
		$v3PrivPassphrase=($_POST['v3PrivPassphrase']=="")?$config->ParameterArray["v3PrivPassphrase"]:$_POST['v3PrivPassphrase'];

		// Init the snmp handler
		$snmpHost=new OSS_SNMP\SNMP(
			$_POST['PrimaryIP'],
			$community,
			$version,
			$v3SecurityLevel,
			$v3AuthProtocol,
			$v3AuthPassphrase,
			$v3PrivProtocol,
			$v3PrivPassphrase
		);

		// Try to connect to keep us from killing the system on a failure
		$error=false;
		try {
			$snmpresults=$snmpHost->useSystem()->name();
		}catch (Exception $e){
			$error=true;
		}

		// Show the end user something to make them feel good about it being correct
		if(!$error){
			foreach($snmpHost->realWalk('1.3.6.1.2.1.1') as $oid => $value){
				print "$oid => $value <br>\n";
			}
		}else{
			print __("Something isn't working correctly");
		}
		exit;
	}

	// Get CDU uptime
	if(isset($_POST['cduuptime'])){
		$pdu=new PowerDistribution();
		$pdu->PDUID=$_POST['DeviceID'];
		
		echo $pdu->GetSmartCDUUptime();
		exit;
	}

	// Get log entries
	if(isset($_POST['logging'])){
		$dev->DeviceID=$_POST['devid'];
		$actions=array();
		if($dev->GetDevice()){
			$actions=LogActions::GetLog($dev,false);
		}
		header('Content-Type: application/json');
		echo json_encode($actions);
		exit;
	}
	// Get cabinet height
	if(isset($_POST['cab'])){
		$cab->CabinetID=$_POST['cab'];
		$cab->GetCabinet();
		echo $cab->CabinetHeight;
		exit;
	}
	// Get list of media types
	if(isset($_GET['mt'])){
		header('Content-Type: application/json');
		echo json_encode(MediaTypes::GetMediaTypeList());
		exit;
	}
	// Get list of name patterns
	if(isset($_GET['spn'])){
		header('Content-Type: application/json');
		$PortNamePatterns=array();
		if(isset($_GET['power'])){
			foreach(array('PS(1)','R(1)','Custom',__("From Template"),__("Invert Port Labels")) as $pattern){
				$PortNamePatterns[]['Pattern']=$pattern;
			}
		}else{
			foreach(array('NIC(1)','Port(1)','Fa/(1)','Gi/(1)','Ti/(1)','Custom',__("From Template"),__("Invert Port Labels")) as $pattern){
				$PortNamePatterns[]['Pattern']=$pattern;
			}
		}
		echo json_encode($PortNamePatterns);
		exit;
	}
	// Get connection path for a patch panel connection
	if(isset($_GET['path'])){
		$path=DevicePorts::followPathToEndPoint($_GET['ConnectedDeviceID'], $_GET['ConnectedPort']);

		foreach($path as $port){
			$dev->DeviceID=$port->DeviceID;
			$dev->GetDevice();
			$port->DeviceName=$dev->Label;
		}

		header('Content-Type: application/json');
		echo json_encode($path);
		exit;
	}

	// This will allow any jackass to certify an audit but the function is hidden and 
	// this is the type of function that will be fixed with the API so i'm not fixing it
	// as long as logging is enabled we'll know who triggered it.
	if(isset($_POST['audit'])){
		$dev->DeviceID=$_POST['audit'];
		$dev->Audit();

		$dev->AuditStamp=date('r',strtotime($dev->AuditStamp));
		header('Content-Type: application/json');
		echo json_encode($dev);
		exit;
	};

	if(isset($_POST['olog'])){
		$dev->DeviceID=$_POST['devid'];
		$dev->GetDevice();
		$dev->OMessage=sanitize($_POST['olog']);
		$tmpDev=new Device();
		$tmpDev->DeviceID=$dev->DeviceID;
		$tmpDev->GetDevice();
		$return=(class_exists('LogActions') && $tmpDev->Rights=="Write")?LogActions::LogThis($dev,$tmpDev):false;
		header('Content-Type: application/json');
		echo json_encode($return);
		exit;
	};

	// Set all ports to the same Label pattern, media type or color code
	if(isset($_POST['setall'])){
		$portnames=array();
		if(isset($_POST['spn']) && strlen($_POST['spn'])>0){
			// Special Condition to load ports from the device template and use those names
			if($_POST['spn']==__("From Template") || $_POST['spn']==__("Invert Port Labels")){
				$dev->DeviceID=$_POST['devid'];
				$dev->GetDevice();
				if($_POST['spn']==__("From Template")){
					$ports=(isset($_POST['power']))?new TemplatePowerPorts():new TemplatePorts();
					$ports->TemplateID=$dev->TemplateID;
					foreach($ports->getPorts() as $pn => $portobject){
						$portnames[$pn]=$portobject->Label;
					}
				}else{
					$dp=(isset($_POST['power']))?new PowerPorts():new DevicePorts();
					$pc=(isset($_POST['power']))?$dev->PowerSupplyCount:$dev->Ports;
					$dp->DeviceID=$dev->DeviceID;
					$ports=$dp->getPorts();
					foreach($ports as $portid => $port){
						// patch panels make everything more complicated
						if($portid >0){
							$portnames[$portid]=$ports[($pc-(abs($portid)-1))]->Label;
							if($dev->DeviceType=="PatchPanel"){
								$portnames[($portid*-1)]=$ports[($pc-(abs($portid)-1))]->Label;
							}
						}
					}
				}
			}else{
				//using premade patterns if the input differs and causes an error then fuck em
				list($result, $msg, $idx) = parseGeneratorString($_POST['spn']);
				if($result){
					$dev->DeviceID=$_POST['devid'];
					$dev->GetDevice();
					$portnames=generatePatterns($result, (isset($_POST['power']))?$dev->PowerSupplyCount:$dev->Ports);
					// generatePatterns starts the index at 0, it's more useful to us starting at 1
					array_unshift($portnames, null);
				}
			}
		}
		// Make a new method to set all the ports to a media type?
		$blurg=(isset($_POST['power']))?PowerPorts::getPortList($_POST['devid']):DevicePorts::getPortList($_POST['devid']);
		foreach($blurg as $portnum => $port){
			$port->Label=(isset($_POST['spn']) && (($_POST['setall']=='true' && count($portnames)>0) || (count($portnames)>0 && strlen($port->Label)==0)))?$portnames[abs($port->PortNumber)]:$port->Label;
			if(!isset($_POST['power'])){
				$port->MediaID=(($_POST['setall']=='true' || $port->MediaID==0) && isset($_POST['mt']) && ($_POST['setall']=='true' || intval($_POST['mt'])>0))?$_POST['mt']:$port->MediaID;
				$port->ColorID=(($_POST['setall']=='true' || $port->ColorID==0) && isset($_POST['cc']) && ($_POST['setall']=='true' || intval($_POST['cc'])>0))?$_POST['cc']:$port->ColorID;
			}
			$port->updatePort();
			// Update the other side to keep media types in sync if it is connected same
			// rule applies that it will only be set if it is currently unset
			if($port->ConnectedDeviceID!='NULL'){
				$port->DeviceID=$port->ConnectedDeviceID;
				$port->PortNumber=$port->ConnectedPort;
				$port->getPort();
				if(!isset($_POST['power'])){
					$port->MediaID=(($_POST['setall']=='true' || $port->MediaID==0) && isset($_POST['mt']) && ($_POST['setall']=='true' || intval($_POST['mt'])>0))?$_POST['mt']:$port->MediaID;
					$port->ColorID=(($_POST['setall']=='true' || $port->ColorID==0) && isset($_POST['cc']) && ($_POST['setall']=='true' || intval($_POST['cc'])>0))?$_POST['cc']:$port->ColorID;
				}
				$port->updatePort();
			}
		}
		// Return all the ports for the device then eval just the MT and CC
		$dp=(isset($_POST['power']))?new PowerPorts():new DevicePorts();
		$dp->DeviceID=$_POST['devid'];
		header('Content-Type: application/json');
		$ports=array(
			'mt' => MediaTypes::GetMediaTypeList(),
			'cc' => ColorCoding::GetCodeList(),
			'ports' => $dp->getPorts()
			);
		echo json_encode($ports);
		exit;
	}
	if(isset($_POST['fp'])){
		$dev->DeviceID=$_POST['devid'];
		$dev->GetDevice();
		if($dev->Rights=="Write"){
			if($_POST['fp']==''){ // querying possible first ports
				$deviceclass=$dev->DeviceType.'Info'; //SwitchInfo or CDUInfo
				$portCandidates=$deviceclass::findFirstPort($dev->DeviceID);

				if(count($portCandidates)>0){
					foreach($portCandidates as $id => $portdesc){
						$checked=($id==$dev->FirstPortNum)?' checked':'';
						$disabled=($id=='err')?' disabled':'';
						print '<input type="radio" name="FirstPortNum" id="fp'.$id.'" value="'.$id.'"'.$checked.$disabled.'><label for="fp'.$id.'">'.$portdesc.'</label><br>';
					}
				}else{
					print __("ERROR: No ports found");
				}
			}else{ // setting first port
				$dev->FirstPortNum=$_POST['fp'];
				if($dev->UpdateDevice()){
					echo 'Updated';
				}else{
					echo 'Failure';
				}
			}
		}else{
			// The button to trigger this function is hidden if they don't have rights
			// but users aren't to be trusted.
			echo 'Failure';
		}
		exit;
	};
	if(isset($_POST['swdev'])){
		$dev->DeviceID=$_POST['swdev'];
		$dev->GetDevice();
		if($dev->Rights=="Write"){
			if(isset($_POST['saveport'])){
				$dp=new DevicePorts();
				$dp->DeviceID=$_POST['swdev'];
				$dp->PortNumber=$_POST['pnum'];
				$dp->Label=$_POST['pname'];
				$dp->MediaID=$_POST['porttype'];
				$dp->ColorID=$_POST['portcolor'];
				$dp->Notes=$_POST['cnotes'];
				$dp->ConnectedDeviceID=$_POST['cdevice'];
				$dp->ConnectedPort=$_POST['cdeviceport'];

				if($dp->updatePort()){
					// when updating the media type on a rear port update the mediatype on the front port as well to make sure they match.
					if($dp->PortNumber<0){
						$dp->PortNumber=abs($dp->PortNumber);
						$dp->GetPort();
						$dp->MediaID=$_POST['porttype'];
						$dp->ColorID=$_POST['portcolor'];
						$dp->updatePort();
					}
					echo 1;
				}else{
					echo 0;
				}
				exit;
			}
			if(isset($_POST['delport'])){
				$dp=new DevicePorts();
				$dp->DeviceID=$_POST['swdev'];
				$dp->PortNumber=$_POST['pnum'];
				$ports=end($dp->getPorts());
				function updatedevice($devid){
					$dev=new Device();
					$dev->DeviceID=$devid;
					$dev->GetDevice();
					$dev->Ports=$dev->Ports-1;
					$dev->UpdateDevice();
				}
				// remove the selected port then shuffle the data to fill the hole if needed
				if($ports->PortNumber!=$dp->PortNumber){
					foreach($ports as $i=>$prop){
						if($i!="PortNumber"){
							$dp->$i=$prop;
						}
					}
					if($dp->updatePort()){
						if($ports->removePort()){
							updatedevice($dp->DeviceID);
							echo 1;
							exit;
						}
					}
					echo 0;
				}else{ // Last available port. just delete it.
					if($dp->removePort()){
						updatedevice($dp->DeviceID);
						echo 1;
					}else{
						echo 0;
					}
				}
				exit;
			}
			// Attach all rear ports of patch panel to another patch panel
			if(isset($_POST['rear']) && isset($_POST['cdevice'])){
					$ConnectTo=new Device();
					$ConnectTo->DeviceID=$_POST['cdevice'];
					// error out if connecting device doesn't exist
					if(!$ConnectTo->GetDevice() && $_POST['cdevice']!='clear'){
						echo 'false';
						exit;
					}

					$cp=new DevicePorts();
					$cp->DeviceID=$ConnectTo->DeviceID;
					$cp=$cp->getPorts();
					$dp=new DevicePorts();
					$dp->DeviceID=$dev->DeviceID;
					foreach($dp->getPorts() as $index => $port){
						if($port->PortNumber<0){
							if($_POST['cdevice']=='clear' && $_POST['override']=='true'){
								$port->removeConnection();
							}elseif(isset($cp[$port->PortNumber]) && (is_null($port->ConnectedDeviceID) || (!is_null($port->ConnectedDeviceID) && $_POST['override']=='true'))){
								$port->ConnectedDeviceID=$ConnectTo->DeviceID;
								$port->ConnectedPort=$port->PortNumber;
								$port->updatePort();
							}
						}
					}

					$ports=array();
					$sql="SELECT p.*, d.Label as DeviceLabel, (SELECT Label FROM fac_Device 
						WHERE DeviceID=p.ConnectedDeviceID) AS ConnectedDeviceLabel, (SELECT Label 
						from fac_Ports WHERE DeviceID=p.ConnectedDeviceID AND 
						PortNumber=p.ConnectedPort) AS ConnectedPortLabel FROM fac_Ports p, 
						fac_Device d WHERE p.DeviceID=d.DeviceID AND p.DeviceID=$dev->DeviceID;";
					foreach($dbh->query($sql) as $row){
						$ports[$row['PortNumber']]=$row;
					}
					echo json_encode($ports);
				exit;
			}
		}
		if(isset($_POST['getport'])){
			$dp=new DevicePorts();
			$dp->DeviceID=$_POST['swdev'];
			$dp->PortNumber=$_POST['pnum'];
			$dp->getPort();

			$cd=new DevicePorts();
			$cd->DeviceID=$dp->ConnectedDeviceID;
			$cd->PortNumber=$dp->ConnectedPort;
			$cd->getPort();

			$mt=MediaTypes::GetMediaTypeList();
			$cc=ColorCoding::GetCodeList();
			$dp->MediaName=(isset($mt[$dp->MediaID]))?$mt[$dp->MediaID]->MediaType:'';
			$dp->ColorName=(isset($cc[$dp->ColorID]))?$cc[$dp->ColorID]->Name:'';
			$dev->DeviceID=$dp->ConnectedDeviceID;
			$dp->Label=($dp->Label=='')?abs($dp->PortNumber):$dp->Label;
			$dp->ConnectedDeviceLabel=($dev->GetDevice())?stripslashes($dev->Label):'';
			$dp->ConnectedDeviceType=$dev->DeviceType;
			$dp->ConnectedPort=(!is_null($cd->DeviceID) && $dp->ConnectedPort==0)?'':$dp->ConnectedPort;
			$dp->ConnectedPortLabel=(!is_null($cd->Label) && $cd->Label!='')?$cd->Label:$dp->ConnectedPort;
			($dp->ConnectedPort<0)?$dp->ConnectedPortLabel.=' ('.__("Rear").')':'';
			header('Content-Type: application/json');
			echo json_encode($dp);
			exit;
		}
		$list='';
		if(isset($_POST['listports'])){
			$dp=new DevicePorts();
			$dp->DeviceID=$_POST['thisdev'];
			$list=$dp->getPorts();
			if($config->ParameterArray["MediaEnforce"]=='enabled'){
				$dp->DeviceID=$_POST['swdev'];
				$dp->PortNumber=$_POST['pn'];
				$dp->getPort();
				foreach($list as $key => $port){
					if($port->MediaID!=$dp->MediaID){
						unset($list[$key]); // remove the nonmatching ports
					}
				}
			}
			foreach($list as $key => $port){
				if(!is_null($port->ConnectedDeviceID)){
					if($port->ConnectedDeviceID==$_POST['swdev'] && $port->ConnectedPort==$_POST['pn']){
						// This is what is currently connected so leave it in the list
					}else{
						// Remove any other ports that already have connections
						unset($list[$key]);
					}
				}
			}

			// S.U.T. #2342 I touch myself
			if($dp->DeviceID == $_POST['swdev'] && isset($list[$_POST['pn']])){
				unset($list[$_POST['pn']]);
			}

			// Sort the ports so that all front ports will be first then the rear ports.
			$front=array();
			$rear=array();

			foreach($list as $pn => $port){
				if($pn>0){
					$front[$pn]=$port;
				}else{
					$rear[$pn]=$port;
				}
			}

			// Positive and negative numbers have different sorts to make sure that 1 is on top of the list
			ksort($front);
			krsort($rear);

			$list=array_replace($front,$rear);
		}else{
			$patchpanels=(isset($_POST['rear']))?"true":null;
			$portnumber=(isset($_POST['pn']))?$_POST['pn']:null;
			$limiter=(isset($_POST['limiter']))?$_POST['limiter']:null;
			$list=DevicePorts::getPatchCandidates($_POST['swdev'],$portnumber,null,$patchpanels,$limiter);
		}
		header('Content-Type: application/json');
		echo json_encode($list);
		exit;
	}
	if(isset($_POST['VMrefresh'])){
		$dev->DeviceID=$_POST['VMrefresh'];
		$dev->GetDevice();
		if($dev->Rights=="Write"){
			if ( $dev->Hypervisor == "ESX" ) {
				ESX::RefreshInventory($_POST['VMrefresh']);
			} elseif ( $dev->Hypervisor == "ProxMox" ) {
				PMox::RefreshInventory( $_POST['VMrefresh'], true);
			}
			buildVMtable($_POST['VMrefresh']);
		}
		exit;
	}
	if(isset($_POST['customattrrefresh'])){
		$template=new DeviceTemplate();
		$template->TemplateID=$_POST['customattrrefresh'];
		$template->GetTemplateByID();
		$dev->DeviceID=$_POST['DeviceID'];
		$dev->GetDevice();	
		buildCustomAttributes($template, $dev);
		exit;
	}
	if(isset($_POST['refreshdevice'])){
		header('Content-Type: application/json');
		if(isset($_POST['devicecalling'])) {
			$refreshdevice = $_POST['devicecalling'];
		}else{
			echo json_encode(array());
			exit;
		}

		if(isset($_POST['names'])){
			$dev->DeviceID=$_POST['refreshdevice'];
			$dev->GetDevice();
			$deviceclass=$dev->DeviceType.'Info'; //SwitchInfo or CDUInfo
			$names = $deviceclass::getPortNames($_POST['refreshdevice']);
			// This function should be hidden if they don't have rights, but just in case
			if($dev->Rights=="Write"){
				foreach($names as $PortNumber => $Label){
					if ($refreshdevice == 'switch'){
						$port=new DevicePorts();
					} else {
						$port=new PowerPorts();
					}
					$port->DeviceID=$_POST['refreshdevice'];
					$port->PortNumber=$PortNumber;
					$port->Label=$Label;
					$port->updateLabel();
				}
			}
			echo json_encode($names);
		}elseif(isset($_POST['Notes'])){
			$dev->DeviceID=$_POST['refreshdevice'];
			$dev->GetDevice();
			$deviceclass=$dev->DeviceType.'Info'; //SwitchInfo or CDUInfo
			$alias = $deviceclass::getPortAlias($_POST['refreshdevice']);
			// This function should be hidden if they don't have rights, but just in case
			if($dev->Rights=="Write"){
				$deviceclass=$dev->DeviceType.'Info'; //SwitchInfo or CDUInfo
				foreach($alias as $PortNumber => $Notes){
					if ($refreshdevice == 'switch'){
						$port=new DevicePorts();
					} else {
						$port=new PowerPorts();
					}
					$port->DeviceID=$_POST['refreshdevice'];
					$port->PortNumber=$PortNumber;
					$port->getPort();
					$port->Notes=$Notes;
					$port->updatePort();
				}
			}
			echo json_encode($alias);
		}else{
			$dev->DeviceID = $_POST['refreshdevice'];
			$dev->GetDevice();
			$tagList = $dev->GetTags();
			$deviceclass=$dev->DeviceType.'Info'; //SwitchInfo or CDUInfo
			// The logic here is:
			//	If you select to OptIn switch polling, only poll if you have the Poll tag assigned to this device
			//	but if you are an OptOut site, poll everything unless it has the NoPoll tag assigned
			//
			if( ( $config->ParameterArray["NetworkCapacityReportOptIn"] == "OptIn" && in_array( "Poll", $tagList ) || ( $config->ParameterArray["NetworkCapacityReportOptIn"] == "OptOut" && ! in_array( "NoPoll", $tagList )))) {
				echo json_encode($deviceclass::getPortStatus($_POST['refreshdevice']));
			} else {
				echo json_encode(array());
			}
		}
		exit;
	}

	if(isset($_POST["currwatts"]) && isset($_POST['pduid']) && $_POST['pduid'] >0){
		$pdu=new PowerDistribution();
		$pdu->PDUID=$_POST['pduid'];
		$wattage->Wattage='Err';
		$wattage->LastRead='Err';
		if($pdu->GetPDU()){
			$cab->CabinetID=$pdu->CabinetID;
			$cab->GetCabinet();
			if($person->canWrite($cab->AssignedTo)){
				$wattage=$pdu->LogManualWattage($_POST["currwatts"]);
				$wattage->LastRead=strftime("%c",strtotime($wattage->LastRead));
			}
		}
		header('Content-Type: application/json');
		echo json_encode($wattage);
		exit;
	}
	// END AJAX

	// Not really AJAX calls since there's no return, but special actions
	// Functions to Reset Counters (rc) for SNMP Failures
	if( isset($_GET["rc"]) && isset($_GET['DeviceID']) ) {
		$dev->DeviceID = $_GET['DeviceID'];
		Device::resetCounter( $dev->DeviceID );
		if ( $dev->DeviceID == "ALL" ) {
			// Special case
			header( 'Location: index.php' );
			exit;
		}
	}

	// These objects are used no matter what operation we're performing
	$templ=new DeviceTemplate();
	$mfg=new Manufacturer();
	$esc=new Escalations();
	$escTime=new EscalationTimes();
	$contactList=$person->GetUserList();
	$Dept=new Department();
	$pwrConnection=new PowerPorts();
	$pdu=new PowerDistribution();
	$panel=new PowerPanel();
	$pwrCords=null;
	$chassis="";
	$copy = false;
	$copyerr=__("This device is a copy of an existing device.  Remember to set the new location before saving.");
	$childList=array();

	// This page was called from somewhere so let's do stuff.
	// If this page wasn't called then present a blank record for device creation.
	if(isset($_REQUEST['action'])||isset($_REQUEST['DeviceID'])){
		if(isset($_REQUEST['CabinetID'])){
			$dev->Cabinet=$_REQUEST['CabinetID'];
			$cab->CabinetID=$dev->Cabinet;
			$cab->GetCabinet();
		}
		if(isset($_REQUEST['action'])&&$_REQUEST['action']=='new'){
			// sets install date to today when a new device is being created
			$dev->InstallDate=date("Y-m-d");
			$dev->DeviceType=(isset($_REQUEST['DeviceType']))?$_REQUEST['DeviceType']:$dev->DeviceType;
			// Some fields are pre-populated when you click "Add device to this cabinet"
			// If you are adding a device that is assigned to a specific customer, assume that device is also owned by that customer
			if($cab->AssignedTo >0){
				$dev->Owner=$cab->AssignedTo;
			}
		}

		// if no device id requested then we must be making a new device so skip all data lookups.
		if(isset($_REQUEST['DeviceID'])){
			$dev->DeviceID=intval($_REQUEST['DeviceID']);
			// If no action is requested then we must be just querying a device info.
			// Skip all modification checks
			$tagarray=array();
			if(isset($_POST['tags'])){
				$tagarray=json_decode($_POST['tags']);
			}
			if(isset($_POST['action'])){
				$dev->GetDevice();

				// Pull all properties from a template and apply to the device before we add the values set 
				// on the screen.  This will make sure things like slots are pulled from the template that aren't
				// available to the end user initially
				if($_POST['action']=='Create' && $_POST['TemplateID']>0){
					$templ->TemplateID=$_POST['TemplateID'];
					if($templ->GetTemplateByID()){
						foreach($templ as $prop => $value){
							$dev->$prop=$value;
						}
					}
				}

				// This shouldn't be needed now that we have the pdu model getting extended onto the device model. and we can just reference pdu as a variable to dev
				if($dev->DeviceType=="CDU" || (isset($_POST['DeviceType']) && $_POST['DeviceType']=="CDU")){
					$pdu->PDUID=$dev->DeviceID;
					$pdu->GetPDU();
				}

				if($_POST['action']!='Child'){
					// Preserve this as a special variable to keep an injection from being possible
					$devrights=$dev->Rights;
					// Add in the "all devices" custom attributes 
					$dcaList=DeviceCustomAttribute::GetDeviceCustomAttributeList();
					if(isset($dcaList)) {
						foreach($dcaList as $dca) {
							if($dca->AllDevices==1) {
								// this will add in the attribute if it is empty
								$label=$dca->Label;
								if(!isset($dev->$label)){
									$dev->{$dca->Label}='';
								}
							}
							if($dca->AttributeType=="checkbox"){
								$dev->{$dca->Label}='off';
							}
						}
					}
					// Add in the template specific attributes
					$tmpl=new DeviceTemplate($dev->TemplateID);
					$tmpl->GetTemplateByID();
					if(isset($tmpl->CustomValues)) {
						foreach($tmpl->CustomValues as $index => $value) {
							// this will add in the attribute if it is empty
							if(!isset($dev->{$dcaList[$index]->Label})){
								$dev->{$dcaList[$index]->Label}='';
							}
						}
					}

					foreach($dev as $prop => $val){
						$dev->$prop=(isset($_POST[$prop]))?$_POST[$prop]:$val;
					}

					// Put the device rights back just in case we had someone try to inject them
					$dev->Rights=$devrights;
					// Stupid Cabinet vs CabinetID
					$dev->Cabinet=$_POST['CabinetID'];
					// Checkboxes don't work quite like normal inputs
					$dev->BackSide=(isset($_POST['BackSide']))?($_POST['BackSide']=="on")?1:0:0;
					$dev->HalfDepth=(isset($_POST['HalfDepth']))?($_POST['HalfDepth']=="on")?1:0:0;
					$dev->Reservation=(isset($_POST['Reservation']))?($_POST['Reservation']=="on")?1:0:0;
					$dev->SNMPFailureCount=(isset($_POST['SNMPFailureCount']))?$_POST['SNMPFailureCount']:0;
					// Used by CDU type devices only
					if($dev->DeviceType=='CDU'){
						foreach($pdu as $prop => $val){
							$dev->$prop=(isset($_POST[$prop]))?$_POST[$prop]:$val;
						}
						(isset($_POST['failsafe']))?$dev->FailSafe=($_POST['failsafe']=="on")?1:0:'';
					}
				}

				if(($dev->TemplateID >0)&&(intval($dev->NominalWatts==0))){$dev->UpdateWattageFromTemplate();}

				$write=false;
				$write=($person->canWrite($cab->AssignedTo))?true:$write;
				$write=($dev->Rights=="Write")?true:$write;

				if($dev->Rights=="Write" && $dev->DeviceID >0){
					switch($_POST['action']){
						case 'Update':
							// User has changed the device type from chassis to something else and has said yes
							// that they want to remove the dependant child devices
							if(isset($_POST['killthechildren'])){
								$childList=$dev->GetDeviceChildren();
								foreach($childList as $childDev){
									$childDev->DeleteDevice();
								}
							}

							$dev->SetTags($tagarray);
							if($dev->Cabinet <0){
								$dev->MoveToStorage();
							}else{
								$dev->UpdateDevice();
							}
							break;
						case 'Delete':
							$dev->DeleteDevice();
							//the $dev object should still exist even though we've deleted the db entry now
							if($dev->ParentDevice >0){
								header('Location: '.redirect("devices.php?DeviceID=$dev->ParentDevice"));
							}else{
								if($dev->Cabinet==-1){
									header('Location: '.redirect("storageroom.php?dc=$dev->Position"));
								}else{
									header('Location: '.redirect("cabnavigator.php?cabinetid=$dev->Cabinet"));
								}
							}
							exit;
							break; // the exit should handle it
						case 'Copy':
							$copy=true;
							$parent=($dev->ParentDevice)?$dev->ParentDevice:null;
							if(!$dev->CopyDevice($parent,null,false)){
								$copyerr=__("Device did not copy.  Error.");
							}
							break;
						case 'Child':
							foreach($dev as $prop => $value){
								$dev->$prop=null;
							}
							$dev->ParentDevice=$_REQUEST["ParentDevice"];

							// sets install date to today when a new device is being created
							$dev->InstallDate=date("Y-m-d");
							break;
					}
				// Can't check the device for rights because it shouldn't exist yet
				// but the user could have rights from the cabinet and it is checked above
				// when the device object is populated.
				}elseif($write && $_POST['action']=='Create'){
					// Since the cabinet isn't part of the form for a child device creation
					// it's possible to create a new child that doesn't follow the new cabinet designation
					// we're creatig a device at this point so look up the parent just in case and match
					// the cabinet designations
					if($dev->ParentDevice){
						$pdev=new Device();
						$pdev->DeviceID=$dev->ParentDevice;
						$pdev->getDevice();
						$dev->Cabinet=$pdev->Cabinet;
					}

					if($dev->TemplateID>0 && intval($dev->NominalWatts==0)){
						$dev->UpdateWattageFromTemplate();
					}
					$dev->CreateDevice();
					$dev->SetTags($tagarray);

					// We've, hopefully, successfully created a new device. Force them to the new device page.
					header('Location: '.redirect("devices.php?DeviceID=$dev->DeviceID"));
					exit;
				}
			}

			/*
			 * Prepare data for display
			 *
			 */

			// Finished updating devices or creating them.  Refresh the object with data from the DB
			$dev->GetDevice();

			// Audits are either at the individual device or the full cabinet level, so grab the latest date for both
			$cabAudit = new CabinetAudit();
			$cabAudit->CabinetID = $dev->Cabinet;
			if ( $cabAudit->GetLastAudit() ) {
				$lastCAudit = date("r", strtotime($cabAudit->AuditStamp));
			} else {
				$lastCAudit = "Never";
			}

			if ( $dev->AuditStamp > 0 ) {
				$lastAudit = date("r",strtotime($dev->AuditStamp));
			} else {
				$lastAudit = "Never";
			}

			// Get any tags associated with this device
			$tags=$dev->GetTags();
			if(count($tags)>0){
				// We have some tags so build the javascript elements we need to create the tags themselves
				$taginsert="\t\ttags: {items: ".json_encode($tags)."},\n";
			}

			// Since a device exists we're gonna need some additional info, but only if it's not a copy
			if(!$copy){
				// clearing errors for now
				$LastWattage=$LastRead=$upTime=0;

				$pwrConnection->DeviceID=($dev->ParentDevice>0&&$dev->PowerSupplyCount==0)?$dev->GetRootDeviceID():$dev->DeviceID;
				$pwrCords=$pwrConnection->getPorts();

				if($dev->DeviceType=='CDU'){
					$pdu->PDUID=$dev->DeviceID;
					$pdu->GetPDU();

					$lastreading=$pdu->GetLastReading();
					$LastWattage=($lastreading)?$lastreading->Wattage:0;
					$LastRead=($lastreading)?strftime("%c",strtotime($lastreading->LastRead)):"Never";
				}
			}

			if($dev->ChassisSlots>0 || $dev->RearChassisSlots>0){
				$childList=$dev->GetDeviceChildren();
			}

			if($dev->ParentDevice >0){
				$pDev=new Device();
				$pDev->DeviceID=$dev->ParentDevice;
				$pDev->GetDevice();

				// Get full list of potential ParentDevices
				$parentList=$pDev->GetParentDevices(false);

				//$cab->CabinetID=$pDev->Cabinet;
				//JMGA: changed for multichassis
				$cab->CabinetID=$pDev->GetDeviceCabinetID();
				$cab->GetCabinet();
				$chassis="Chassis";

				// This is a child device and if the action of new is set let's assume the
				// departmental Owner, primary contact, etc are the same as the parent
				if(isset($_POST['action']) && $_POST['action']=='Child'){
					$dev->Owner=$pDev->Owner;
					$dev->EscalationTimeID=$pDev->EscalationTimeID;
					$dev->EscalationID=$pDev->EscalationID;
					$dev->PrimaryContact=$pDev->PrimaryContact;
				}
			}
		}
		$cab->CabinetID=$dev->Cabinet;
		$cab->GetCabinet();
	}else{
		/*
		 * Everything below here will get processed when no DeviceID is present
		 * aka adding a new device
		 */

		// sets install date to today when a new device is being created
		$dev->InstallDate=date("Y-m-d");
	}

	// We don't want someone accidentally adding a chassis device inside of a chassis slot.
	if($dev->ParentDevice>0){
		$devarray=array('Server' => __("Server"),
						'Appliance' => __("Appliance"),
						'Storage Array' => __("Storage Array"),
						'Switch' => __("Switch"),
						'Chassis' => __("Chassis"),
						'Patch Panel' => __("Patch Panel"),
						'Sensor' => __("Sensor"),
						);

		/* If you only have rear slots, don't make the user click Backside, which they forget to do half the time, anyway */
		if ( $pDev->ChassisSlots < 1 && $pDev->RearChassisSlots > 0 ) {
			$dev->BackSide = 1;
		}
	}else{
		$devarray=array('Server' => __("Server"),
						'Appliance' => __("Appliance"),
						'Storage Array' => __("Storage Array"),
						'Switch' => __("Switch"),
						'Chassis' => __("Chassis"),
						'Patch Panel' => __("Patch Panel"),
						'Physical Infrastructure' => __("Physical Infrastructure"),
						'CDU' => __("CDU"),
						'Sensor' => __("Sensor"),
						);
	}

	if($config->ParameterArray["mDate"]=="now"){
		if($dev->MfgDate <= "1970-01-01"){
			$dev->MfgDate=date("Y-m-d");
		}
	}

	if($config->ParameterArray["wDate"]=="now"){
		if($dev->WarrantyExpire <= "1970-01-01"){
			$dev->WarrantyExpire=date("Y-m-d");
		}
	}

	$portList=DevicePorts::getPortList($dev->DeviceID);
	$mediaTypes=MediaTypes::GetMediaTypeList();
	$colorCodes=ColorCoding::GetCodeList();
	$templateList=$templ->GetTemplateList();
	$escTimeList=$escTime->GetEscalationTimeList();
	$escList=$esc->GetEscalationList();
	$deptList=$Dept->GetDepartmentList();

	$templ->TemplateID=$dev->TemplateID;
	$templ->GetTemplateByID();

	if ( $dev->DeviceID == 0 ) {
		$dev->Status="Reserved";
	}
	
	$title=($dev->Label!='')?"$dev->Label :: $dev->DeviceID":__("openDCIM Device Maintenance");

	function buildVMtable($DeviceID){
		$Hyper=new VM();
		$Hyper->DeviceID=$DeviceID;
		$vmList=$Hyper->GetDeviceInventory();

		print "\n<div class=\"table border\"><div><div>".__("VM Name")."</div><div>".__("Status")."</div><div>".__("Owner")."</div><div>".__("Primary Contact")."</div><div>".__("Last Updated")."</div></div>\n";
		foreach($vmList as $vmRow){
			$onOff=(preg_match('/off/i',$vmRow->vmState))?'off':'on';
			$Dept=new Department();
			$Dept->DeptID=$vmRow->Owner;
			if($Dept->DeptID >0){
				$Dept->GetDeptByID();
			}else{
				$Dept->Name=__("Unknown");
			}
			if ( $vmRow->PrimaryContact > 0 ) {
				$con = new People();
				$con->PersonID = $vmRow->PrimaryContact;
				$con->GetPerson();
				$PCName = $con->LastName . ", " . $con->FirstName;
			} else {
				$PCName = __("Unknown");
			}
			print "<div><div>$vmRow->vmName</div><div class=\"$onOff\">$vmRow->vmState</div><div><a href=\"updatevmowner.php?vmindex=$vmRow->VMIndex\">$Dept->Name</a></div><div><a href=\"updatevmowner.php?vmindex=$vmRow->VMIndex\">$PCName</a></div><div>$vmRow->LastUpdated</div></div>\n";
		}
		echo '</div> <!-- END div.table -->';
	}
	
	function buildCustomAttributes($template, $device) {
		$dcaList=DeviceCustomAttribute::GetDeviceCustomAttributeList();
		$tdcaList=$template->CustomValues;

		$customvalues = array();

		// pull the "all devices" custom attributes
		if(isset($dcaList)) {
			foreach($dcaList as $dca) {
				if($dca->AllDevices==1) {
					$customvalues[$dca->AttributeID]["value"]=$dca->DefaultValue;	
					$customvalues[$dca->AttributeID]["type"]=$dca->AttributeType;
					$customvalues[$dca->AttributeID]["required"]=$dca->Required;
				}
			}
		}
		if(isset($tdcaList)) {
			// pull the device template level custom attributes (done second so we overwrite all devices)
			foreach($tdcaList as $AttributeID=>$tdca) {
				$customvalues[$AttributeID]["value"]=$tdca["value"];
				$customvalues[$AttributeID]["type"]=$dcaList[$AttributeID]->AttributeType;
				$customvalues[$AttributeID]["required"]=$tdca["required"];

			}
		}
		foreach($customvalues as $customkey=>$customdata) {
			$prop=$dcaList[$customkey]->Label;
			if ( property_exists( $device, $prop )) {
				$customvalues[$customkey]['value']=$device->$prop;
			}
		}
		echo '<div class="table">';	
		foreach($customvalues as $customkey=>$customdata) {
			$inputname = $dcaList[$customkey]->Label;
			$validation="";
			$cvtype = $customvalues[$customkey]["type"];
			if($customvalues[$customkey]["required"]==1 || $cvtype!="string"){
				$validation=' class="validate[';
				$validationrules=array();
				if($customvalues[$customkey]["required"]==1) {
					$validationrules[]="required";
				}
				if($cvtype!="string" && $cvtype != "checkbox"){
					$validationrules[]='custom['.$cvtype.']';
				}
				$validation.=implode(",",$validationrules);
				$validation.=']" ';
			}
			echo '<div>
				<div><label for="',$inputname,'">',$dcaList[$customkey]->Label,'</label></div>';
			if($cvtype=="checkbox"){
				$checked=($customdata["value"] == "1" || $customdata["value"]=="on")?" checked":"";
				echo '<div><input type="checkbox" name="',$inputname,'" id="',$inputname,'"',$checked,'></div>';
			} else if ($cvtype=="set") {
				echo '<div><select name="',$inputname,'" id="',$inputname,'">';
				foreach(explode(',',$dcaList[$customkey]->DefaultValue) as $dcaValue){
					$selected=(trim($customdata["value"])==trim($dcaValue))?' selected':'';
					print "\n\t<option value=\"$dcaValue\"$selected>$dcaValue</option>";
				}
				echo '</select></div>';
			} else {
				echo '<div><input type="text"',$validation,' name="',$inputname,'" id="',$inputname,'" value="',$customdata["value"],'">';
				if ($cvtype=="url") {
					echo '<button type="button" onclick=window.open("',$customdata["value"],'","_blank"); value="open">',__("Open"),'</button>';
				}
				echo '</div>';
			}
		    echo '</div>';
		}
		echo '</div>';
	}
// In the case of a child device we might define this above and in that case we
// need to preserve the flag
$write=(isset($write))?$write:false;
$write=($person->canWrite($cab->AssignedTo))?true:$write;
$write=($dev->Rights=="Write")?true:$write;

?>
<!doctype html>
<html>
<head>
  <meta http-equiv="X-UA-Compatible" content="IE=Edge">
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">

  <title><?php echo $title; ?></title>
  <link rel="stylesheet" href="css/inventory.php" type="text/css">
  <link rel="stylesheet" href="css/print.css" type="text/css" media="print">
  <link rel="stylesheet" href="css/jquery-ui.css" type="text/css">
  <link rel="stylesheet" href="css/validationEngine.jquery.css" type="text/css">
  <link rel="stylesheet" href="css/jquery-te-1.4.0.css" type="text/css">
  <style type="text/css">#div { border: 1px solid red; margin: -1px; }</style>
  <!--[if lt IE 9]>
  <link rel="stylesheet"  href="css/ie.css" type="text/css" />
  <![endif]-->
  <script type="text/javascript" src="scripts/jquery.min.js"></script>
  <script type="text/javascript" src="scripts/jquery-ui.min.js"></script>
  <script type="text/javascript" src="scripts/mdetect.js"></script>
  <script type="text/javascript" src="scripts/jquery.validationEngine-en.js"></script>
  <script type="text/javascript" src="scripts/jquery.validationEngine.js"></script>
  <script type="text/javascript" src="scripts/jquery-te-1.4.0.min.js"></script>
  <script type="text/javascript" src="scripts/jquery.textext.js"></script>
  <script type="text/javascript" src="scripts/common.js?v<?php echo filemtime('scripts/common.js');?>"></script>

<SCRIPT type="text/javascript" >
var nextField;
function getScan(fieldName){
    var href=window.location.href;
    var ptr=href.lastIndexOf("#");
    if(ptr>0){
        href=href.substr(0,ptr);
    }
	nextField=fieldName;
    window.location.href="zxing://scan/?ret="+escape(href+"#{CODE}");
}
var changingHash=false;
function getHash(){
	if ( !changingHash ) {
		changingHash=true;
		var hash=window.location.hash.substr(1);
		switch (nextField) {
			case "SerialNo":
				$('#SerialNo').val(unescape(hash));
				break;
			case "AssetTag":
				$('#AssetTag').val(unescape(hash));
				break;
			default:
				break;
		}
		// window.location.hash="";
		changingHash=false;
	}
}
</SCRIPT>

<script type="text/javascript">
window.PatchPanelsOnly="<?php print $config->ParameterArray["PatchPanelsOnly"]; ?>";


/*
IE work around
http://stackoverflow.com/questions/5227088/creating-style-node-adding-innerhtml-add-to-dom-and-ie-headaches
*/

function swaplayout(){
	var sheet=document.createElement('style');
	sheet.type='text/css';
	var s=document.getElementsByTagName('style')[0];
	var button=document.getElementById('layout');

	function p(){ // set to portrait view
		s.parentNode.insertBefore(sheet, s);
		button.innerHTML="<?php echo __("Landscape"); ?>";
		setCookie("layout","Portrait");
	}

	function l(){ // set to landscape view
		if(sheet.styleSheet){ //IE
			s.styleSheet.cssText = "";
		}else{
			s.innerHTML = "";
		}
		button.innerHTML="<?php echo __("Portrait"); ?>";
		setCookie("layout","Landscape");
	}

	if(sheet.styleSheet){ // IE
		sheet.styleSheet.cssText = ".device div.left { display: block; }";
		(s.styleSheet.cssText==sheet.styleSheet.cssText)?l():p();
	}else{
		sheet.innerHTML = ".device div.left { display: block; }";
		(s.innerHTML==sheet.innerHTML)?l():p();
	}
}

$(document).ready(function() {
	var isMobile = {
		Android: function() {
			return navigator.userAgent.match(/Android/i);
		},
		BlackBerry: function() {
			return navigator.userAgent.match(/BlackBerry/i);
		},
		iOS: function() {
			return navigator.userAgent.match(/iPhone|iPad|iPod/i);
		},
		Opera: function() {
			return navigator.userAgent.match(/Opera Mini/i);
		},
		Windows: function() {
			return navigator.userAgent.match(/IEMobile/i);
		},
		any: function() {
			return (isMobile.Android() || isMobile.BlackBerry() || isMobile.iOS() || isMobile.Opera() || isMobile.Windows());
		}
	};
	// Mobile devices get a scan barcode button and an accordian interface
	if(isMobile.any()){
		$('.main button').each(function(){
			if($(this).text()=='Scan Barcode'){
				$(this).css('display', 'inline');
			}
		});
		$('.left > fieldset ~ .table').each(function(){
<?php print "			$(this).before($('<h3><a href=\"#\">".__("Notes")."</a></h3>'));"; ?>
		});
		$('.right').contents().appendTo($('.left'));
<?php print "		$('.left').append('<h3><a href=\"#\">".__("Network & Power")."</a></h3>');"; ?>
		$('.right').next('div.table').appendTo($('.left'));
		$('.left legend').each(function(){
			$(this).parent('fieldset').before($('<h3><a href="#">'+$(this).text()+'</a></h3>'));
			$(this).remove();
		});
		$('.left > h3 ~ fieldset').each(function(){
			$a=$(this).children('.table');
			$($a.parent()).before($a);
			$(this).remove();
		});
		$('.table + .table').each(function(){
			$(this).prev().wrap($('<div />'));
			$(this).appendTo($(this).prev());
		});
		$('input[name="ChassisSlots"]').filter($('[type="hidden"]')).insertAfter($('.left'));
		$('.device .table').css('width', 'auto');
		$('.left').after($('<div class="table" id="target" style="width: 100%"></div>'));
		$('.caption').appendTo($('#target'));
		$('.left').accordion({
			autoHeight: false,
			collapsible: true
		}).removeClass('left');
	}

	// add the current ports value to the document data store
	$(document).data('Ports',$('#Ports').val());
	$(document).data('PowerSupplyCount',$('#PowerSupplyCount').val());
	$(document).data('DeviceType', $('select[name="DeviceType"]').val());
	$(document).data('defaultsnmp','<?php echo $config->ParameterArray["SNMPCommunity"]; ?>');
	$(document).data('showdc','<?php echo $config->ParameterArray["AppendCabDC"]; ?>');

	$('#deviceform').validationEngine();
	$('#MfgDate').datepicker({dateFormat: "yy-mm-dd"});
	$('#InstallDate').datepicker({dateFormat: "yy-mm-dd"});
	$('#WarrantyExpire').datepicker({dateFormat: "yy-mm-dd"});
	$('#Owner').next('button').click(function(){
		window.open('contactpopup.php?deptid='+$('#Owner').val(), 'Contacts Lookup', 'width=800, height=700, resizable=no, toolbar=no');
		return false;
	});

	// CDU functions
	$('#PanelID').change( function(){
		$.get('scripts/ajax_panel.php?q='+$(this).val(), function(data) {
			$('#voltage').html(data['PanelVoltage'] +'/'+ Math.floor(data['PanelVoltage']/1.73));
		});
	});
	$('#btn_override').on('click',function(e){
		var btn=$(e.currentTarget);
		var target=$(e.currentTarget.previousSibling);
		if(btn.val()=='edit'){
			var specialinput=$('<input>').attr('size',5).val(target.text());
			specialinput.keypress(function(event){
				if(event.keyCode==10 || event.keyCode==13){
					event.preventDefault();
					btn.click();
				}
			});
			btn.val('submit').text(btn.data('submit')).css('height','2em');
			target.replaceWith(specialinput);
			specialinput.focus().select();
		}else{
			btn.val('edit').text(btn.data('edit')).css('height','');
			$.post('',{currwatts: target.val(), pduid:$('#DeviceID').val()}).done(function(data){
				target.replaceWith($('<span>').text(data.Wattage));
				$('#lastread').text(data.LastRead);
			});
		}
	});

	// Do a call after the page loads to get the CDU uptime to speed up the initial page load
	if($('select[name=DeviceType]').val()=='CDU'){
		$.post('',{cduuptime: '',DeviceID: $('#DeviceID').val()}, function(data) {
			$('#cduuptime').text(data);
		});
	}

	// Hide / Show extra snmp attributes
	$('#SNMPVersion').change(function(){
		if(this.value==3){
			$(':input[id^="v3"]').parent('div').parent('div').show();
		}else{
			$(':input[id^="v3"]').parent('div').parent('div').hide();
		}
	}).trigger('change');

	// Make SNMP community visible
	$('#SNMPCommunity,#v3AuthPassphrase,#v3PrivPassphrase,#APIPassword')
		.focus(function(){$(this).attr('type','text');})
		.blur(function(){$(this).attr('type','password');});

	// What what?! an SNMP test function!?
	$('#PrimaryIP,#SNMPCommunity').on('change keyup keydown', function(){ SNMPTest(); }).change();

	function SNMPTest(){
		var ip=$('#PrimaryIP');
		var snmp=$('#SNMPCommunity');
		var dc=$(document).data('defaultsnmp');
		var community=(snmp.val()!='')?snmp.val():(dc!='')?dc:'';

		if(ip.val()!='' && community!=''){snmp.next('button').show().removeClass('hide');}else{snmp.next('button').hide();}
	}

	$('#btn_snmptest').click(function(e){
		e.preventDefault();
		// Serialize the form data
		var formdata=$('#snmpblock').serializeArray();
		// Add in the IP since it isn't part of the snmp section
		formdata.push({name:'PrimaryIP',value: $('#PrimaryIP').val()});
		// Set the action to snmptest
		formdata.push({name:'snmptest',value: $('#DeviceID').val()});
		$('#pdutest').html('<img src="images/mimesearch.gif" height="150px">Checking...');
		$.post('', formdata, function(data){
			$('#pdutest').html(data);
		});
		$('#pdutest').dialog({minWidth: 850, position: { my: "center", at: "top", of: window },closeOnEscape: true });
	});

	// Add in refresh functions for virtual machines
	var VMtable=$('<div>').addClass('table border').append('<div><div>VM Name</div><div>Status</div><div>Owner</div><div>Last Updated</div></div>');
	var VMbutton=$('<button>',{'type':'button'}).css({'position':'absolute','top':'10px','right':'2px'}).text('Refresh');
	VMbutton.click(VMrefresh);
	if($('#Hypervisor').val()!="None"){
		$('#VMframe').css('position','relative').append(VMbutton);
	}
	function VMrefresh(){
		$.post('',{VMrefresh: $('#DeviceID').val()}).done(function(data){
			$('#VMframe .table ~ .table').replaceWith(data);
		});
	}

	// This is for adding blades to chassis devices
	$('#adddevice').click(function() {
		$(":input").attr("disabled","disabled");
		$('#ParentDevice').removeAttr("disabled");
		$('#adddevice').removeAttr("disabled");
		$(this).submit();
		setTimeout(function(){
			$(":input").removeAttr("disabled"); // if they hit back it makes sure the fields aren't disabled
			$('#ParentDevice').attr("disabled","disabled"); // if they hit back disable this so a chassis doesn't become its own parent
		},100);
	});

	// Device image previews
	$('#deviceimages > div > img').
		on('error',function(){$(this).hide();toggledeviceimages();}).
		on('load',function(){
			if($(this).context.width < $(this).context.height){
				$(this).css({'height':'275px','width':'auto'});
			}else{
				$(this).css({'height':'','width':''});
			}
			$(this).show().on('click',function(){
				var pop=$(this).clone();
				pop.attr('style','');
				$('<div>').html(pop.css({'max-width':'600px','max-height':'600px'})).dialog({
					width: 'auto',
					height: 'auto',
					modal: true
				});
			});
			toggledeviceimages();
		});

	function toggledeviceimages(){
		$('#deviceimages').show();
		var n=0;
		$('#deviceimages > div > img').each(function(){
			if($(this).is(":visible")){ n++;}
		});
		if(n==0){$('#deviceimages').hide();}
	}

	function customattrrefresh(TemplateID){
		$.post('',{customattrrefresh: TemplateID, DeviceID:  $('#DeviceID').val() }).done(function(data){
			$('#customattrs .table ').replaceWith(data);
		});


	}

	// Need to make some changes to the UI for the storage room
	$('#CabinetID').change(function(){
		var Positionrow=$('#Position').parent('div').parent('div');
		if($(this).val()==-1){
			Positionrow.hide();
		}else{
			Positionrow.show();
		}
	}).trigger('change');

	// Auto-Populate fields based on device templates
	$('#TemplateID').change( function(){
		$.get('scripts/ajax_template.php?q='+$(this).val(), function(data) {
			$('#Height').val(data['Height']);
			$('#Ports').val(data['NumPorts']);
			$('#NominalWatts').val(data['Wattage']);
			$('#Weight').val(data['Weight']);
			$('#PowerSupplyCount').val(data['PSCount']);
			$('select[name=DeviceType]').val(data['DeviceType']).trigger('change');
			$('#Height').trigger('change');
			(data['FrontPictureFile']!='')?$('#devicefront').attr('src',"<?php echo $config->ParameterArray['picturepath'];?>"+data['FrontPictureFile']):$('#devicefront').removeAttr('src').hide();
			(data['RearPictureFile']!='')?$('#devicerear').attr('src',"<?php echo $config->ParameterArray['picturepath'];?>"+data['RearPictureFile']):$('#devicerear').removeAttr('src').hide();
			toggledeviceimages();
			customattrrefresh($('#TemplateID').val());
		});
	});

	$('select[name=DeviceType]').change(function(){
// redo this to support a list of special frames hide all special frames except the category
// we're currently dealing with
		if($(this).val()=='Switch'){
			if($(document).data('DeviceType')!='Switch'){
				$('#firstport button:not([name="firstport"])').hide();
			}
			if($('#DeviceID').val()>0){
				$('#firstport').show().removeClass('hide');
				$('.switch div[id^="st"]').show();
			}
		}else{
			$('#firstport').hide();
			$('.switch div[id^="st"]').hide();
		}
		if($(this).val()=='Server'){
			$('#VMframe').show();
		}else{
			$('#VMframe').hide();
		}
		if($(this).val()=='CDU'){
			$('#cdu').show().removeClass('hide');
			$('#NominalWatts').parent('div').parent('div').addClass('hide');
			if($(document).data('DeviceType')!='CDU'){
				$('#cdufirstport button:not([name="cdufirstport"])').hide();
			}
			if($('#DeviceID').val()>0){
				$('#cdufirstport').show().removeClass('hide');
				$('.switch div[id^="ppst"]').show();
			}
		}else{
			$('#cdu').hide();
			$('#NominalWatts').parent('div').parent('div').removeClass('hide');
		}
		resize();
	}).change();

	$('select#Hypervisor').change(function(){
		if($(this).val()=='ProxMox'){
			$('#proxmoxblock').removeClass('hide');
			$('#snmpblock').addClass('hide');
		}else{
			// Put back any hidden / renamed fields
			$('#proxmoxblock').addClass('hide');
			$('#snmpblock').removeClass('hide');
		}
	}).change();

	$('#firstport button[name=firstport]').click(function(){
		getfirstport('switch', $('#DeviceID').val());
	});
	$('#firstport button[name=refresh]').click(function(){
		refreshdevice('switch', $('#DeviceID').val());
	});
	$('#firstport button[name=name]').click(function(){
		refreshdevice('switch', $('#DeviceID').val(),'names');
	});
	$('#firstport button[name=Notes]').click(function(){
		refreshdevice('switch', $('#DeviceID').val(),'Notes');
	});
	if ($(':input[name=DeviceType]').val()=='Switch'){
		refreshdevice('switch', $('#DeviceID').val());
	}

	function getfirstport(devicecalling, devid) {
		// S.U.T. Update the IP and snmp community then click on the switch controls.
		// we'll combat that with a limited device update.
		$.post('api/v1/device/'+$('#DeviceID').val(),{PrimaryIP: $('#PrimaryIP').val(), SNMPCommunity: $('#SNMPCommunity').val()}).done(function(){
			var modal=$('<div />', {id: 'modal', title: 'Select ' + devicecalling + ' first port'}).html('<div id="modaltext"></div><br><div id="modalstatus" class="warning"></div>').dialog({
				appendTo: 'body',
				modal: true,
				close: function(){$(this).dialog('destroy');}
			});
			$.post('',{fp: '', devid: $('#DeviceID').val()}).done(function(data){
				$('#modaltext').html(data);
				$('#modaltext input').change(function(){
					var fpnum=$(this).val();
					$.post('',{fp: fpnum, devid: $('#DeviceID').val()}).done(function(data){
						$('input[name=FirstPortNum]').val(fpnum);
						$('#modalstatus').html(data);
						$('#modal').dialog('destroy');
					}).then(refreshdevice($('#DeviceType').val(),$('#DeviceID').val(),true));
				});
			});
		}).error(function(data){
			$('#messages').text('data.message');
		});

	}

	function refreshdevice(devicecalling,devid,names){
		var modal=$('<div />', {id: 'modal', title: 'Please wait...'}).html('<div id="modaltext"><img src="images/animatedswitch.gif" style="width: 100%;"><br>Polling device...</div><br><div id="modalstatus" class="warning"></div>').dialog({
			appendTo: 'body',
			minWidth: 500,
			closeOnEscape: false,
			dialogClass: "no-close",
			modal: true
		});
		if(names){
			if(names=='names'){
				$.post('',{refreshdevice: devid, names: names, devicecalling: devicecalling}).done(function(data){
					$.each(data, function(i,Label){
						if(devicecalling=='switch') {
							if(Label){
								$('#spn'+i).text(Label);
							}else{
								$('#spn'+i).text('');
							}
						}else{
							if(Label){
								$('#ppcn'+i).text(Label);
							}else{
								$('#ppcn'+i).text('');
							}
						}
					});
					modal.dialog('destroy');
				});
			}else{
				$.post('',{refreshdevice: devid, Notes: names, devicecalling: devicecalling }).done(function(data){
					$.each(data, function(i,Notes){
						if(devicecalling=='switch') {
							if(Notes){
								$('#n'+i).text(Notes);
							}else{
								$('#n'+i).text('');
							}
						}else{
							if(Notes){
								$('#ppn'+i).text(Notes);
							}else{
								$('#ppn'+i).text('');
							}
						}
					});
					modal.dialog('destroy');
				});
			}
		}else{
			$.post('',{refreshdevice: devid, devicecalling: devicecalling}).done(function(data){
				$.each(data, function(i,portstatus){
					if(devicecalling=='switch') {
						$('#st'+i).html($('<span>').addClass('ui-icon').addClass('status').addClass(portstatus));
					}else{
						$('#ppst'+i).html($('<span>').addClass('ui-icon').addClass('status').addClass(portstatus));
					}
				});
				modal.dialog('destroy');
			});
		}
	}

	$('#cdufirstport button[name=cdufirstport]').click(function(){
		getfirstport('CDU', $('#DeviceID').val());
	});
	$('#cdufirstport button[name=cdurefresh]').click(function(){
		refreshdevice('cdu', $('#DeviceID').val());
	});
	$('#cdufirstport button[name=cduname]').click(function(){
		refreshdevice('cdu', $('#DeviceID').val(),'names');
	});
	$('#cdufirstport button[name=cduNotes]').click(function(){
		refreshdevice('cdu', $('#DeviceID').val(),'Notes');
	});
	if ($(':input[name=DeviceType]').val()=='CDU' && $(':input[name=DeviceID]').val()!=''){
		refreshdevice('cdu', $('#DeviceID').val());
	}

<?php
	// hide all the js functions if they don't have write permissions
	if($write){

print "		var dialog=$('<div>').prop('title',\"".__("Verify Delete Device")."\").html('<p><span class=\"ui-icon ui-icon-alert\" style=\"float:left; margin:0 7px 20px 0;\"></span><span></span></p>');";

		// Add an extra alert warning about child devices in chassis
		if($dev->DeviceType=='Chassis'){
?>
		$('select[name=DeviceType]').change(function(){
			var form=$(this).parents('form');
			var btn=$(this);
			if($(this).val()!='Chassis'){
<?php echo '				dialog.find(\'span + span\').text("',__("If this device has blades installed they will be deleted and there is no undo. Are you sure?"),'");'; ?>
				dialog.dialog({
					resizable: false,
					modal: true,
					dialogClass: "no-close",
					buttons: {
<?php echo '				',__("Yes"),': function(){'; ?>
							$(this).dialog("destroy");
							form.append('<input type="hidden" class="killthechildren" name="killthechildren" value="yes">');
						},
<?php echo '				',__("No"),': function(){'; ?>
							$('.killthechildren').remove();
							$('select[name=DeviceType]').val('Chassis').change();
							$(this).dialog("destroy");
						}
					}
				});
			}else{
				$('.killthechildren').remove();
			}
		});
<?php
		}

		// hide cabinet slot picker from child devices
		if($dev->ParentDevice==0){
?>
		$('#CabinetID').change(function(){
			$.post('', {cab: $("select#CabinetID").val()}, function(data){
				var posclass=$('#Position').attr('class');
				if(parseInt(data.trim())>0){
					$('#Position').attr('class',posclass.replace(/max\[([0-9]).*?\]/gi,"max["+data.trim()+"]")).trigger('focusout');
				}
			});
		});
		var tmpheight=$('<input>').attr({'type':'hidden','name':'Position'}).val(0);
		$('#Height').change(function(){
			if($(this).val()==0){
				$('#Position').attr('disabled', 'true');
				$(this).parents('form').append(tmpheight);
			}else{
				$('#Position').removeAttr('disabled');
				tmpheight.remove();
			}
		}).trigger('change');
		$('#Position').focus(function()	{
			var cab=$("select#CabinetID").val();
			var hd=$('#HalfDepth').is(':checked');
			var bs=$('#BackSide').is(':checked');
			$.getJSON('scripts/ajax_cabinetuse.php?cabinet='+cab+'&DeviceID='+$("#DeviceID").val()+'&HalfDepth='+hd+'&BackSide='+bs, function(data) {
				var ucount=Object.keys(data).length;
				var rackhtmlleft='';
				var rackhtmlright='';

				// This code was gonna be repeated so I just made it a function
				function parseusage(u){
					if(data[u]){var cssclass='notavail'}else{var cssclass=''};
					rackhtmlleft+='<div>'+u+'</div>';
					rackhtmlright+='<div val='+u+' class="'+cssclass+'"></div>';
				}

				// If slot 0 is set to top then it will reverse order otherwise high to low
				if(data["0"]=="Top"){
					// low to high
					for(var ucount=1; ucount<=Object.keys(data).length-1; ucount++) {
						parseusage(ucount);
					}
				}else{
					// high to low
					for(var ucount=Object.keys(data).length-1; ucount>=1; ucount--) {
						parseusage(ucount);
					}
				}
				var rackhtml='<div class="table border positionselector"><div><div>'+rackhtmlleft+'</div><div>'+rackhtmlright+'</div></div></div>';
				$('#Positionselector').html(rackhtml);
				setTimeout(function(){
					var divwidth=$('.positionselector').width();
					var divheight=$('.positionselector').height();
					$('#Positionselector').width(divwidth);
					$('#Height').focus(function(){$('#Positionselector').css({'left': '-1000px'});});
					$('#Positionselector').css({
						'left':(($('.right').position().left)-(divwidth+40)),
						'top':(($('.right').position().top))
					});
					$('#Positionselector').mouseleave(function(){
						$('#Positionselector').css({'left': '-1000px'});
					});
					$('.positionselector > div > div + div > div').mouseover(function(){
						$('.positionselector > div > div + div > div').each(function(){
							$(this).removeAttr('style');
						});
						var unum=$("#Height").val();
						if(unum>=1 && $(this).attr('class')!='notavail'){
							var test='';
							var background='green';
							// check each element start with pointer
							for (var x=0; x<unum; x++){
								if(x!=0){
									test+='.prev()';
									eval("if($(this)"+test+".attr('class')=='notavail' || $(this)"+test+".length ==0){background='red';}");
								}else{
									if($(this).attr('class')=='notavail'){background='red';}
								}
							}
							test='';
							if(background=='red'){var pointer='default'}else{var pointer='pointer'}
							for (x=0; x<unum; x++){
								if(x!=0){
									test+='.prev()';
									eval("$(this)"+test+".css({'background-color': '"+background+"'})");
								}else{
									$(this).css({'background-color': background, 'cursor': pointer});
									if(background=='green'){
										$(this).click(function(){
											$('#Position').val($(this).attr('val')).trigger('focusout');
											$('#Positionselector').css({'left': '-1000px'});
										});
									}
								}
							}
						}
					});
				},100);
			}, 'json');
		}).click(function(){
			$(this).trigger('focus');
		});
<?php
		}
?>
		$('select[name=ParentDevice],#BackSide').change(function(){
			var slotcount=($('#BackSide:checked').length)?'rearchassisslots':'chassisslots';
			var maxval=$('select[name=ParentDevice] option:selected').data(slotcount);
			var posclass=$('#Position').attr('class');
			$('#Position').attr('class',posclass.replace(/max\[([0-9]).*?\]/gi,"max["+maxval+"]")).trigger('focusout');
			// Make a pointer to the hidden cabinetid input object
			var hdn_cabinetid=$('input[name=CabinetID]');
			hdn_cabinetid.val($('select[name=ParentDevice] option:selected').data('cabinetid'));
			// Match the cabinet id to something over in the menu so we can show it on the page
			var rack=$('#datacenters a[href$="cabinetid='+hdn_cabinetid.val()+'"]');
			// Update the hidden cabinet id field to match the new parent device and show the name
			hdn_cabinetid.parent('div').text(rack.text()).append(hdn_cabinetid);
		}).trigger('change');

		$('#Reservation').change(function(){
			if(!$(this).prop("checked")){
				var d=new Date();
				$('#InstallDate').datepicker("setDate",d);
			}
		});
		// Delete device confirmation dialog
		$('button[value="Delete"]').click(function(e){
					var form=$(this).parents('form');
			var btn=$(this);
<?php echo '				dialog.find(\'span + span\').text("',__("This device will be deleted and there is no undo. Are you sure?"),'");'; ?>
			dialog.dialog({
				resizable: false,
				modal: true,
				buttons: {
<?php echo '				',__("Yes"),': function(){'; ?>
						$(this).dialog("destroy");
						form.append('<input type="hidden" name="'+btn.attr("name")+'" value="'+btn.val()+'">');
						form.validationEngine("detach");
						form.submit();
					},
<?php echo '				',__("No"),': function(){'; ?>
						$(this).dialog("destroy");
					}
				}
			});
		});

		$('#Ports').change(function(){
			// not sure why .data() is turning an int into a string parseInt is fixing that
			if($(this).val() > parseInt($(document).data('Ports'))){
				//make more ports and add the rows below
				$('button[value="Update"]').click();
			}else if($(this).val()==$(document).data('Ports')){
				// this is the I changed my mind condition.
				$('.device .switch .delete').hide();
			}else{
				//S.U.T. present options to remove ports
				$('.device .switch .delete').show();
			}
		});
		$('#PowerSupplyCount').change(function(){
			// not sure why .data() is turning an int into a string parseInt is fixing that
			if($(this).val() > parseInt($(document).data('PowerSupplyCount'))){
				//make more ports and add the rows below
				$('button[value="Update"]').click();
			}else if($(this).val()==$(document).data('PowerSupplyCount')){
				// this is the I changed my mind condition.
				$('.device .power .delete').hide();
			}else{
				//S.U.T. present options to remove ports
				$('.device .power .delete').show();
			}
		});

<?php
	} // end of javascript editing functions
?>
	// Make connections to other devices
	$('.switch.table > div ~ div, .patchpanel > div ~ div').each(function(){
		var row=$(this);
		if(portrights[row.data('port')]){ // only bind edit functions if they have rights
			row.row();
		}
	});

	$('.power > div ~ div').each(function(){
		var row=$(this);
		row.power();
	});

	function setPreferredLayout() {<?php if(isset($_COOKIE["layout"]) && strtolower($_COOKIE["layout"])==="portrait"){echo 'swaplayout();setCookie("layout","Portrait");';}else{echo 'setCookie("layout","Landscape");';} ?>}
	setPreferredLayout();
	$('#tags').width($('#tags').parent('div').parent('div').innerWidth()-$('#tags').parent('div').prev('div').outerWidth()-5);

	$('#tags').textext({
		plugins : 'autocomplete tags ajax arrow prompt focus',
<?php echo $taginsert; ?>
		ajax : {
			url : 'scripts/ajax_tags.php',
			dataType : 'json'
		}
	});
});

</script>

</head>
<body onhashchange="getHash()">
<?php include( 'header.inc.php' ); ?>
<div class="page device">
<?php
	include( 'sidebar.inc.php' );

echo '<div class="main">
<button id="layout" onClick="swaplayout()">'.__("Portrait").'</button>';
echo($copy)?"<h3>$copyerr</h3>":'';
echo '<div class="center"><div>
<h3></h3><h3 id="messages"></h3>
<div id="Positionselector"></div>
<form name="deviceform" id="deviceform" method="POST">
<div class="left">
<fieldset>
	<legend>'.__("Asset Tracking").'</legend>
	<div class="table">
		<div>
		   <div>'.__("Device ID").'</div>
		   <div><input type="text" name="DeviceID" id="DeviceID" value="'.$dev->DeviceID.'" size="6" readonly></div>
		</div>
		<div>
			<div><label for="Status">'.__("Status").'</label></div>
			<div>
				<select name="Status" id="Status">';
					foreach( DeviceStatus::getStatusNames() as $statRow){
						$selected=($dev->Status==$statRow)?" selected":"";
						print "\t\t\t\t<option value=\"$statRow\"$selected>" . __($statRow) . "</option>\n";
					}
echo '			</select>
			</div>

		</div>
		<div>
		   <div><label for="Label">'.__("Label").'</label></div>
		   <div><input type="text" class="validate[required,minSize[3],maxSize[50]]" name="Label" id="Label" size="40" value="'.$dev->Label.'"></div>
		</div>
		<div>
		   <div><label for="SerialNo">'.__("Serial Number").'</label></div>
		   <div><input type="text" name="SerialNo" id="SerialNo" size="40" value="'.$dev->SerialNo.'">
		   <button class="hide" type="button" onclick="getScan(\'SerialNo\')">',__("Scan Barcode"),'</button></div>
		</div>
		<div>
		   <div><label for="AssetTag">'.__("Asset Tag").'</label></div>
		   <div><input type="text" name="AssetTag" id="AssetTag" size="20" value="'.$dev->AssetTag.'">
		   <button class="hide" type="button" onclick="getScan(\'AssetTag\')">',__("Scan Barcode"),'</button></div>
		</div>
		<div>
		  <div><label for="PrimaryIP">'.__("Primary IP / Host Name").'</label></div>
		  <div><input type="text" name="PrimaryIP" id="PrimaryIP" size="20" value="'.$dev->PrimaryIP.'">
				<input type="hidden" name="FirstPortNum" value="'.$dev->FirstPortNum.'"></div>
		</div>
		<div>
		   <div><label for="MfgDate">'.__("Manufacture Date").'</label></div>
		   <div><input type="text" class="validate[optional,custom[date]] datepicker" name="MfgDate" id="MfgDate" value="'.(($dev->MfgDate>'0000-00-00 00:00:00')?date('Y-m-d',strtotime($dev->MfgDate)):"").'">
		   </div>
		</div>
		<div>
		   <div><label for="InstallDate">'.__("Install Date").'</label></div>
		   <div><input type="text" class="validate[required,custom[date]] datepicker" name="InstallDate" id="InstallDate" value="'.(($dev->InstallDate>'0000-00-00 00:00:00')?date('Y-m-d',strtotime($dev->InstallDate)):"").'"></div>
		</div>
		<div>
		   <div><label for="WarrantyCo">'.__("Warranty Company").'</label></div>
		   <div><input type="text" name="WarrantyCo" id="WarrantyCo" value="'.$dev->WarrantyCo.'"></div>
		</div>
		<div>
		   <div><label for="WarrantyExpire">'.__("Warranty Expiration").'</label></div>
		   <div><input type="text" class="validate[custom[date]] datepicker" name="WarrantyExpire" id="WarrantyExpire" value="'.date('Y-m-d',strtotime($dev->WarrantyExpire)).'"></div>
		</div>
		<div>
		   <div>'.__("Last Audit Completed").'</div>
		   <div><span id="auditdate">Cabinet: '.$lastCAudit.'<br>Device: '.$lastAudit.'</span></div>
		</div>
		<div>
		   <div><label for="Owner">'.__("Departmental Owner").'</label></div>
		   <div>
			<select name="Owner" id="Owner">
				<option value=0>'.__("Unassigned").'</option>';

			foreach($deptList as $deptRow){
				$selected=($dev->Owner==$deptRow->DeptID)?" selected":"";
				print "\t\t\t\t<option value=\"$deptRow->DeptID\"$selected>$deptRow->Name</option>\n";
			}

echo '			</select>
			<button type="button">',__("Show Contacts"),'</button>
		   </div>
		</div>
		<div>
		   <div>&nbsp;</div>
		   <div><fieldset>
		   <legend>',__("Escalation Information"),'</legend>
		   <div class="table">
			<div>
				<div><label for="EscalationTimeID">',__("Time Period"),'</label></div>
				<div><select name="EscalationTimeID" id="EscalationTimeID">
					<option value="">',__("Select..."),'</option>';

				foreach($escTimeList as $escTime){
					$selected=($escTime->EscalationTimeID==$dev->EscalationTimeID)?" selected":"";
					print "\t\t\t\t\t<option value=\"$escTime->EscalationTimeID\"$selected>$escTime->TimePeriod</option>\n";
				}

echo '				</select></div>
			</div>
			<div>
				<div><label for="EscalationID">',__("Details"),'</label></div>
				<div><select name="EscalationID" id="EscalationID">
					<option value="">',__("Select..."),'</option>';

				foreach($escList as $esc){
					$selected=($esc->EscalationID==$dev->EscalationID)?" selected":"";
					print "\t\t\t\t\t<option value=\"$esc->EscalationID\"$selected>$esc->Details</option>\n";
				}

echo '				</select></div>
			</div>
		   </div> <!-- END div.table -->
		   </fieldset></div>
		</div>
		<div>
		   <div><label for="PrimaryContact">',__("Primary Contact"),'</label></div>
		   <div><select name="PrimaryContact" id="PrimaryContact">
				<option value=0>',__("Unassigned"),'</option>';

			foreach($contactList as $contactRow){
				$selected=($contactRow->PersonID==$dev->PrimaryContact)?' selected':'';
				// Only non-disabled User/Contact accounts should be selectable, but be sure not to filter out the currently assigned one
				$disabled=($contactRow->Disabled && $contactRow->PersonID!=$dev->PrimaryContact)?' disabled':'';
				print "\t\t\t\t<option value=\"$contactRow->PersonID\"$selected$disabled>$contactRow->LastName, $contactRow->FirstName</option>\n";
			}

			print "\t\t\t</select>\n";

			if(isset($config->ParameterArray['UserLookupURL']) && isValidURL($config->ParameterArray['UserLookupURL']) && isset($contactUserID)){
				print "<button type=\"button\" onclick=\"window.open( '".$config->ParameterArray["UserLookupURL"]."$contactUserID', 'UserLookup')\">".__("Contact Lookup")."</button>\n";
			}

echo '		   </div>
		</div>
		<div>
			<div><label for="tags">',__("Tags"),'</label></div>
			<div><textarea type="text" name="tags" id="tags" rows="1"></textarea></div>
		</div>
	</div> <!-- END div.table -->
</fieldset>
<fieldset id="customattrs">
<legend>',__("Custom Attributes"),'</legend>';
buildCustomAttributes($templ,$dev);
echo '
</fieldset>
	<div class="table">
		<div>
		  <div><label for="Notes">',__("Notes"),'</label></div>
		  <div><textarea name="Notes" id="Notes" cols="40" rows="8">',$dev->Notes,'</textarea></div>
		</div>
	</div> <!-- END div.table -->
</div><!-- END div.left -->
<div class="right">
<fieldset>
	<legend>',__("Physical Infrastructure"),'</legend>
	<div class="table">
		<div>
			<div><label for="CabinetID">',__("Cabinet"),'</label></div>';

		if($dev->ParentDevice==0){
			print "\t\t\t<div>".$cab->GetCabinetSelectList()."</div>\n";
		}else{
			print "\t\t\t<div>$cab->Location<input type=\"hidden\" name=\"CabinetID\" value=$cab->CabinetID></div>
		</div>
		<div>
			<div><label for=\"ParentDevice\">".__("Parent Device")."</label></div>
			<div><select name=\"ParentDevice\">\n";

			foreach($parentList as $parDev){
				if($pDev->DeviceID==$parDev->DeviceID){$selected=" selected";}else{$selected="";}
				print "\t\t\t\t<option value=\"$parDev->DeviceID\"$selected data-ChassisSlots=$parDev->ChassisSlots data-RearChassisSlots=$parDev->RearChassisSlots data-CabinetID=$parDev->Cabinet>$parDev->Label</option>\n";
			}
			print "\t\t\t</select></div>\n";
		}

echo '		</div>
		<div>
			<div><label for="TemplateID"><a href="device_templates.php?TemplateID=',$dev->TemplateID,'">',__("Device Class"),'</a></label></div>
			<div><select name="TemplateID" id="TemplateID">
				<option value=0>',__("Select a template..."),'</option>';

			foreach($templateList as $tempRow){
				// $devarray is helping to remove invalid device templates from child devices
				if(in_array($tempRow->DeviceType, array_keys($devarray))){
					if($dev->TemplateID==$tempRow->TemplateID){$selected=" selected";}else{$selected="";}
					$mfg->ManufacturerID=$tempRow->ManufacturerID;
					$mfg->GetManufacturerByID();
					print "\t\t\t\t<option value=\"$tempRow->TemplateID\"$selected>$mfg->Name - $tempRow->Model</option>\n";
				}
			}

echo '			</select>
			</div>
		</div>
		<div>
		   <div><label for="Height">',($dev->ParentDevice==0)?__("Height"):__("Number of slots"),'</label></div>
		   <div><input type="number" class="required,validate[custom[onlyNumberSp]]" name="Height" id="Height" value="',$dev->Height,'"></div>
		</div>
		<div>
		   <div><label for="Position">',__("Position"),'</label></div>
		   <div><input type="number" class="required,validate[custom[onlyNumberSp],min[0],max[',$cab->CabinetHeight,']]" name="Position" id="Position" value="',$dev->Position,'"></div>
		</div>
		';

		//JMGA: child devices not use HalfDepth
		if($dev->ParentDevice==0){
echo '		<div>
			<div><label for="HalfDepth">'.__("Half Depth").'</label></div>
			<div><input type="checkbox" name="HalfDepth" id="HalfDepth"'.(($dev->HalfDepth)?" checked":"").'></div>
		</div>';
		}
echo '		<div>
			<div><label for="BackSide">'.__("Back Side").'</label></div>
			<div><input type="checkbox" name="BackSide" id="BackSide"'.(($dev->BackSide)?" checked":"").'></div>
		</div>
		<div id="dphtml">
		   <div><label for="Ports">',__("Number of Data Ports"),'</label></div>
		   <div><input type="number" class="optional,validate[custom[onlyNumberSp]]" name="Ports" id="Ports" value="',$dev->Ports,'"></div>
		</div>
		<div>
		   <div><label for="NominalWatts">',__("Nominal Draw (Watts)"),'</label></div>
		   <div><input type="text" class="optional,validate[custom[onlyNumberSp]]" name="NominalWatts" id="NominalWatts" value="',$dev->NominalWatts,'"></div>
		</div>
		<div>
		   <div><label for="Weight">',__("Weight"),'</label></div>
		   <div><input type="text" class="optional,validate[custom[onlyNumberSp]]" name="Weight" id="Weight" value="',$dev->Weight,'"></div>
		</div>';

		// Blade devices don't have power supplies
		// if($dev->ParentDevice==0){
			echo '		<div>
		   <div><label for="PowerSupplyCount">',__("Power Connections"),'</label></div>
		   <div><input type="number" class="optional,validate[custom[onlyNumberSp]]" name="PowerSupplyCount" id="PowerSupplyCount" value="',$dev->PowerSupplyCount,'"></div>
		</div>';
		// }

		// Show extra info for chassis devices
		if($dev->DeviceType=="Chassis"){
			echo '		<div>
			<div><label>'.__("Chassis Total Draw").'</label></div>
			<div><input value="'.$dev->GetDeviceTotalPower().'" size="6" disabled></input></div>
		</div>
		<div>
			<div><label>'.__("Chassis Total Weight").'</label></div>
			<div><input value="'.$dev->GetDeviceTotalWeight().'" size="6" disabled></input></div>
		</div>';
		}

echo '		<div>
		   <div>',__("Device Type"),'</div>
		   <div><select name="DeviceType">
			<option value=0>',__("Select..."),'</option>';

		foreach($devarray as $devType => $translation){
			if($devType==$dev->DeviceType){$selected=" selected";}else{$selected="";}
			print "\t\t\t<option value=\"$devType\"$selected>$translation</option>\n";
		}
echo '
		   </select></div>
		</div>
	</div> <!-- END div.table -->
</fieldset>';

		if ($dev->DeviceType=='Sensor'){
echo '<fieldset id="sensorreadings">
		   <legend>'.__("Sensor Readings").'</legend>
		   <div id="sensorreadout">
		   </div>
		</fieldset>';
		}

echo '<fieldset id="deviceimages">
	<legend>'.__("Device Images").'</legend>
	<div>';
		$frontpic=($templ->FrontPictureFile!='')?' src="'.$config->ParameterArray['picturepath'].'/'.$templ->FrontPictureFile.'"':'';
		$rearpic=($templ->RearPictureFile!='')?' src="'.$config->ParameterArray['picturepath'].'/'.$templ->RearPictureFile.'"':'';
echo '
		<img id="devicefront" src="'.$config->ParameterArray['picturepath'].'/'.$templ->FrontPictureFile.'" alt="front of device">
		<img id="devicerear" src="'.$config->ParameterArray['picturepath'].'/'.$templ->RearPictureFile.'" alt="rear of device">
	</div>
</fieldset>
<fieldset id="proxmoxblock" class="hide">
	<legend>'.__("ProxMox Configuration").'</legend>
	<div class="table">
		<div>
		  <div><label for="APIUsername">'.__("API Username").'</label></div>
		  <div><input type="text" name="APIUsername" id="APIUsername" value="'.$dev->APIUsername.'"></div>
		</div>
		<div>
		  <div><label for="APIPassword">'.__("API Password").'</label></div>
		  <div><input type="password" name="APIPassword" id="APIPassword" value="'.$dev->APIPassword.'"></div>
		</div>
		<div>
		  <div><label for="APIPort">'.__("API Port").'</label></div>
		  <div><input type="number" name="APIPort" id="APIPort" value="'.$dev->APIPort.'"></div>
		</div>
		<div>
		  <div><label for="ProxMoxRealm">'.__("ProxMox Realm").'</label></div>
		  <div><input type="text" name="ProxMoxRealm" id="ProxMoxRealm" value="'.$dev->ProxMoxRealm.'"></div>
		</div>
	</div>
</fieldset>
<fieldset id="snmpblock">
	<legend>'.__("SNMP Configuration").'</legend>
	<div class="table">
		<div>
		  <div><label for="SNMPVersion">'.__("SNMP Version").'</label></div>
		  <div>
			<select name="SNMPVersion" id="SNMPVersion">
				<option value="">'.__("Configuration Default").'</option>';
			foreach(array(1,'2c',3) as $ver){
				$selected=($dev->SNMPVersion==$ver)?' selected':'';
				print "\n\t\t\t\t<option value=\"$ver\"$selected>$ver</options>";
			}
echo '
			</select>
		  </div>
		</div>
		<div>
		  <div><label for="SNMPCommunity">'.__("SNMP Read Only Community").'</label></div>
		  <div><input type="password" name="SNMPCommunity" id="SNMPCommunity" size="40" value="'.$dev->SNMPCommunity.'"><button type="button" class="hide" id="btn_snmptest">'.__("Test SNMP").'</button></div>
		</div>
		<div>
		  <div><label for="v3SecurityLevel">'.__("SNMPv3 Security Level").'</label></div>
		  <div>
			<select name="v3SecurityLevel" id="v3SecurityLevel">
				<option value="">'.__("Configuration Default").'</option>';
			foreach(array('noAuthNoPriv','authNoPriv','authPriv') as $ver){
				$selected=($dev->v3SecurityLevel==$ver)?' selected':'';
				print "\n\t\t\t\t<option value=\"$ver\"$selected>$ver</options>";
			}
echo '
			</select>
		  </div>
		</div>
		<div>
		  <div><label for="v3AuthProtocol">'.__("SNMPv3 AuthProtocol").'</label></div>
		  <div>
			<select name="v3AuthProtocol" id="v3AuthProtocol">
				<option value="">'.__("Configuration Default").'</option>';
			foreach(array('MD5','SHA') as $ver){
				$selected=($dev->v3AuthProtocol==$ver)?' selected':'';
				print "\n\t\t\t\t<option value=\"$ver\"$selected>$ver</options>";
			}
echo '
			</select>
		  </div>
		</div>
		<div>
		  <div><label for="v3AuthPassphrase">'.__("SNMPv3 Passphrase").'</label></div>
		  <div><input type="password" name="v3AuthPassphrase" id="v3AuthPassphrase" value="'.$dev->v3AuthPassphrase.'"></div>
		</div>
		<div>
		  <div><label for="v3PrivProtocol">'.__("SNMPv3 PrivProtocol").'</label></div>
		  <div>
			<select name="v3PrivProtocol" id="v3PrivProtocol">
				<option value="">'.__("Configuration Default").'</option>';
			foreach(array('DES','AES') as $ver){
				$selected=($dev->v3PrivProtocol==$ver)?' selected':'';
				print "\n\t\t\t\t<option value=\"$ver\"$selected>$ver</options>";
			}
echo '
			</select>
		  </div>
		</div>
		<div>
		  <div><label for="v3PrivPassphrase">'.__("SNMPv3 PrivPassphrase").'</label></div>
		  <div><input type="password" name="v3PrivPassphrase" id="v3PrivPassphrase" value="'.$dev->v3PrivPassphrase.'"></div>
		</div>
		<div>
		  <div><label for="SNMPFailureCount">'.__("Consecutive SNMP Failures").'*</label></div>
		  <div><input type="number" name="SNMPFailureCount" id="SNMPFailureCount" value="'.$dev->SNMPFailureCount.'"></div>
		</div>
	</div>
	<br><span>*'.__("Polling is disabled after three consecutive failures.").'</span>
</fieldset>
<fieldset id="cdu" class="hide">
	<legend>'.__("Power Specifications").'</legend>
	<div class="table">
		<div>
			<div><label for="PanelID">',__("Source Panel"),'</label></div>
			<div>
				<select name="PanelID" id="PanelID" >
					<option value=0>',__("Select Panel"),'</option>';

		$Panel=new PowerPanel();
		$PanelList=$Panel->getPanelList();
		foreach($PanelList as $key=>$value){
			$selected=($value->PanelID == $pdu->PanelID)?' selected':"";
			print "\n\t\t\t\t\t<option value=\"$value->PanelID\"$selected>$value->PanelLabel</option>\n"; 
		}

		echo '
				</select>
			</div>';
		if($pdu->PanelID >0){
			print "<div><a href=\"power_panel.php?PanelID=$pdu->PanelID\">[ ".__("Goto Panel")." ]</a></div>\n";
		}	
		echo '
		</div>
		<div>
			<div><label for="voltage">',__("Voltages:"),'</label></div>
			<div id="voltage">';

			if($pdu->PanelID >0){
				$pnl=new PowerPanel();
				$pnl->PanelID=$pdu->PanelID;
				$pnl->getPanel();
			
				print $pnl->PanelVoltage." / ".intval($pnl->PanelVoltage/1.73);
			}

		echo '</div>
		</div>
		<div>
			<div><label for="BreakerSize">',__("Breaker Size (# of Poles)"),'</label></div>
			<div>
				<select name="BreakerSize" id="BreakerSize">';

			for($i=1;$i<4;$i++){
				if($i==$pdu->BreakerSize){$selected=" selected";}else{$selected="";}
				print "\n\t\t\t\t\t<option value=\"$i\"$selected>$i</option>";
			}

		echo '
				</select>
			</div>
		</div>
		<div>
			<div><label for="PanelPole">',__("Panel Pole Number"),'</label></div>
			<div><input type="text" name="PanelPole" id="PanelPole" size=5 value="',$pdu->PanelPole,'"></div>
		</div>';

		if($pdu->BreakerSize>1) {
			echo '
		<div>
			<div><label for="allbreakerpoles">',__("All Breaker Poles"),'</label></div>
			<div>',$pdu->GetAllBreakerPoles(),'</div>
		</div>';
		}
		echo '
		<div>
			<div><label for="InputAmperage">',__("Input Amperage"),'</label></div>
			<div><input type="text" name="InputAmperage" id="InputAmperage" size=5 value="',$pdu->InputAmperage,'"></div>
		</div>';

		// Only show the version, etc if we aren't creating a CDU
		if($dev->DeviceID>0){
		echo '
		<div>
			<div>',__("Uptime"),'</div>
			<div id="cduuptime">',$upTime,'</div>
		</div>
		<div>
			<div>',__("Firmware Version"),'</div>
			<div>',$pdu->FirmwareVersion,'</div>
		</div>
		<div>
			<div><label for="currwatts">',__("Wattage"),'</label></div>
			<div><span>',$LastWattage,'</span><button type="button" id="btn_override" value="edit" data-edit="',__("Manual Entry"),'" data-submit="',__("Submit"),'">',__("Manual Entry"),'</button></div>
		</div>
		<div>
			<div>',__("Last Update"),':</div>
			<div id="lastread">',$LastRead,'</div>
		</div>';
		}
		echo '
		<div class="caption">
			<fieldset class="noborder">
				<legend>',__("Automatic Transfer Switch"),'</legend>
				<div class="table centermargin border">
					<div>
						<div><label for="failsafe">',__("Fail Safe Switch?"),'</label></div>
						<div><input type="checkbox" name="failsafe" id="failsafe"',(($pdu->FailSafe)?" checked":""),'></div>
					</div>
					<div>
						<div><label for="PanelID2">',__("Source Panel (Secondary Source)"),'</label></div>
						<div>
							<select name="PanelID2" id="PanelID2">
								<option value=0>',__("Select Panel"),'</option>';

				foreach($PanelList as $key=>$value){
					if($value->PanelID==$pdu->PanelID2){$selected=" selected";}else{$selected="";}
					print "\n\t\t\t\t\t\t<option value=$value->PanelID$selected>$value->PanelLabel</option>";
				}

			echo '
							</select>
						</div>
					</div>
					<div>
						<div><label for="PanelPole2">',__("Panel Pole Number (Secondary Source)"),'</label></div>
						<div><input type="text" name="PanelPole2" id="PanelPole2" size=4 value="',$pdu->PanelPole2,'"></div>
					</div>
				</div>
			</fieldset>
		</div>
	</div>
</fieldset>
<fieldset id="firstport" class="hide">
	<legend>'.__("Switch SNMP").'</legend>
	<div><p>'.__("Use these buttons to set the first port for the switch, check the status of the ports again, or attempt to load the Port Name Labels from the switch device.").'</p><button type="button" name="firstport">'.__("Set First Port").'</button><button type="button" name="refresh">'.__("Refresh Status").'</button><button type="button" name="name">'.__("Refresh Port Names").'</button><button type="button" name="Notes">'.__("Refresh Port Notes").'</button></div>
</fieldset>
<fieldset id="cdufirstport" class="hide">
	<legend>'.__("CDU SNMP").'</legend>
	<div><p>'.__("Use these buttons to set the first power outlet for the CDU, check the status of the oultets again, or attempt to load the Outlet Name Labels from the CDU device.").'</p><button type="button" name="cdufirstport">'.__("Set First Outlet").'</button><button type="button" name="cdurefresh">'.__("Refresh Status").'</button><button type="button" name="cduname">'.__("Refresh Port Names").'</button><button type="button" name="cduNotes">'.__("Refresh Port Notes").'</button></div>
</fieldset>';

	//
	// Do not display the chassis contents block if this is a child device (ParentDevice > 0)
	//
	if($dev->DeviceType=='Chassis'){

echo '<fieldset class="chassis">
	<legend>',__("Chassis Contents"),'</legend>
	<div class="table">
		<div>
			<div>&nbsp;</div>
			<div>',__("Front"),'</div>
			<div class="greybg">',__("Rear"),'</div>
		</div>
		<div>
			<div><label for="ChassisSlots">',__("Number of Slots in Chassis:"),'</label></div>
			<div><input type="text" id="ChassisSlots" class="optional,validate[custom[onlyNumberSp]]" name="ChassisSlots" size="4" value="',$dev->ChassisSlots,'"></div>
			<div class="greybg"><input type="text" id="RearChassisSlots" class="optional,validate[custom[onlyNumberSp]]" name="RearChassisSlots" size="4" value="',$dev->RearChassisSlots,'"></div>
		</div>';

	if($dev->ChassisSlots >0 || $dev->RearChassisSlots>0){

echo '	</div>
	<div class="table">
		<div>
			<div>',__("Slot #"),'</div>
			<div>',__("Height"),'</div>
			<div>',__("Device Name"),'</div>
			<div>',__("Device Type"),'</div>
		</div>';

	foreach($childList as $chDev){
		print "\t\t<div".(($chDev->BackSide)?' class="greybg"':'').">
			<div>$chDev->Position</div>
			<div>$chDev->Height</div>
			<div><a href=\"devices.php?DeviceID=$chDev->DeviceID\">$chDev->Label</a></div>
			<div>$chDev->DeviceType</div>
		</div>\n";
	}

echo '		<div class="caption">';

		if ($write) {
			echo '<button type="submit" id="adddevice" value="Child" name="action">',__("Add Device"),'</button>';
		}

echo '		<input type="hidden" id="ParentDevice" name="ParentDevice" disabled value="',$dev->DeviceID,'">
		</div>';
	}else{
echo '		<div class="caption">
			',__("You must first define how many slots are in the chassis before you can add devices."),'
		</div>';
	}
?>
	</div>
</fieldset>
<?php
	}

	// Do not display VM block if device isn't a virtual server and the user doesn't have write access
	if($write && ($dev->DeviceType=="Server" || $dev->DeviceType=="")){
		echo '<fieldset id="VMframe">	<legend>',__("Hypervisor Server Information"),'</legend>';
	// If the user doesn't have write access display the list of VMs but not the configuration information.
		if($write){

echo '	<div class="table">
		<div>
			<div><label for="Hypervisor">'.__("Hypervisor").'</label></div>
			<div><select name="Hypervisor" id="Hypervisor">
';
   foreach ($validHypervisors as $h ) {
		if($dev->Hypervisor==$h){$selected=" selected";}else{$selected="";}
   		print "\t\t\t\t<option value=\"$h\" $selected>$h</option>\n";
   	}

echo '			</select></div>
		</div>
	</div><!-- END div.table -->';

		}
		if($dev->Hypervisor!="None" && $dev->Hypervisor!=""){
			buildVMtable($dev->DeviceID);
		}
		print "</fieldset>\n";
	}
?>
</div><!-- END div.right -->
<div class="table" id="pandn">
<div><div>
<div class="table style">
<?php
// Button block used for selection limiter
$connectioncontrols=($dev->DeviceID>0 && !empty($portList))?'
<div><span style="display: inline-block; vertical-align: super;">'.__("Limit device type selection to").':</span>
<div id="devicetype-limiter" data-role="controlgroup" data-type="horizontal">
	<input type="radio" name="devicetype-limiter" id="dt-choice-1" value="all" />
	<label for="dt-choice-1">All</label>
	<input type="radio" name="devicetype-limiter" id="dt-choice-2" value="server" />
	<label for="dt-choice-2">Server</label>
	<input type="radio" name="devicetype-limiter" id="dt-choice-3" value="switch" />
	<label for="dt-choice-3">Switch</label>
	<input type="radio" name="devicetype-limiter" id="dt-choice-4" value="patchpanel" />
	<label for="dt-choice-4">Patch Panel</label>
	<input type="radio" name="devicetype-limiter" id="dt-choice-5" value="cdu" />
	<label for="dt-choice-5">CDU</label>
</div></div>':'';
$connectioncontrols.=($dev->DeviceID>0 && !empty($portList))?'
<div><span style="display: inline-block; vertical-align: super;">'.__("Limit device selection to").':</span>
<div id="connection-limiter" data-role="controlgroup" data-type="horizontal">
	<input type="radio" name="connection-limiter" id="radio-choice-1" value="row" />
	<label for="radio-choice-1">Row</label>
	<input type="radio" name="connection-limiter" id="radio-choice-2" value="zone" />
	<label for="radio-choice-2">Zone</label>
	<input type="radio" name="connection-limiter" id="radio-choice-3" value="datacenter" />
	<label for="radio-choice-3">Datacenter</label>
	<input type="radio" name="connection-limiter" id="radio-choice-4" value="global" />
	<label for="radio-choice-4">Global</label>
</div></div>':'';

	// Operational log
	// This is an optional block if logging is enabled
	if(class_exists('LogActions') && $dev->DeviceID >0){
		print "\t<div>\n\t\t  <div><a>".__("Operational Log")."</a></div>\n\t\t  <div><div id=\"olog\" class=\"table border\">\n\t\t\t<div><div>".__("Date")."</div></div>\n";

		// Wrapping the actual log events with a table of their own and a div that we can style
		print "\t<div><div><div><div class=\"table\">\n";

		foreach(LogActions::GetLog($dev,false) as $logitem){
			if($logitem->Property=="OMessage"){
				print "\t\t\t<div><div>$logitem->Time</div><div>$logitem->UserID</div><div>$logitem->NewVal</div></div>\n";
			}
		}

		// Closing the row, table for the log events, and the stylable div
		print "\t</div></div></div></div>\n";

		// The input box and button
		$hideaddnotes=(!$write)?"style=\"display: none;\"":"";
		print "\t\t\t<div><div><button type=\"button\" $hideaddnotes>Add note</button><div><input /></div></div></div>\n";


		print "\t\t  </div></div>\n\t\t</div>\n";
		//hide the connection limiters if not on a patch panel.
		print "\t\t<!-- Spacer --><div><div>&nbsp;</div><div>".(($dev->DeviceType=='Patch Panel')?$connectioncontrols:'')."</div></div><!-- END Spacer -->\n"; // spacer row
	}

	//HTML content condensed for PHP logic clarity.
	// If $pwrCords is null then we're creating a device record. Skip power checking.
	if(!is_null($pwrCords)&&((isset($_POST['action'])&&$_POST['action']!='Child')||!isset($_POST['action']))&&(!in_array($dev->DeviceType,array('Physical Infrastructure','Patch Panel')))){
		print "		<div>\n\t\t\t<div><a id=\"power\">$chassis ".__("Power Connections")."</a></div>
			<div><div class=\"table border power\">
				<div>
					<div class=\"delete\" style=\"display: none;\"></div>
					<div>#</div>
					<div id=\"ppcn\">".__("Port Name")."</div>
					<div>".__("Device")."</div>
					<div>".__("Device Port")."</div>
					<div id=\"ppn\">".__("Notes")."</div>";
					if($dev->DeviceType=='CDU'){print "\t\t\t\t<div id=\"ppst\">".__("Status")."</div>";}
print "<!--				<div>".__("Panel")."</div> -->
					</div>\n";
			foreach($pwrCords as $i => $cord){
				$tmppdu=new Device();
				$tmppdu->DeviceID=$cord->ConnectedDeviceID;
				$tmppdu->GetDevice();
//				$panel->PanelID=$pdu->PanelID;
//				$panel->getPanel();
				$tmpcord=new PowerPorts();
				if($cord->ConnectedDeviceID>0 && !is_null($cord->ConnectedDeviceID)){
					$tmpcord->DeviceID=$cord->ConnectedDeviceID;
					$tmpcord->PortNumber=$cord->ConnectedPort;
					$tmpcord->getPort();
				}else{
					$cord->ConnectedDeviceID=0;
					$cord->ConnectedPort=0;
				}
				if($dev->DeviceType=='CDU'){$linkList[$i]=(isset($linkList[$i]))?$linkList[$i]:'err';}
				print "\t\t\t\t<div data-port=$i>
					<div>$i</div>
					<div id=\"ppcn$i\" data-default=\"$cord->Label\">$cord->Label</div>
					<div data-default=$cord->ConnectedDeviceID><a href=\"devices.php?DeviceID=$cord->ConnectedDeviceID\">$tmppdu->Label</a></div>
					<div data-default=$cord->ConnectedPort>$tmpcord->Label</div>
					<div id=\"ppn$i\" data-default=\"$cord->Notes\">$cord->Notes</div>";
					if($dev->DeviceType=='CDU'){print "\t\t\t\t<div id=\"ppst$i\"><span class=\"ui-icon status {$linkList[$i]}\"></span></div>";}
				print "\t\t\t\t</div>\n";
			}

			print "			</div><!-- END div.table --></div>\n		</div><!-- END power connections -->\n		<!-- Spacer --><div><div>&nbsp;</div><div>$connectioncontrols</div></div><!-- END Spacer -->\n";




	}

	$jsondata=array();// array to store user ability to modify a port. index=portnumber, value=true/false
	// New simplified model will apply to all devices except for patch panels and physical infrastructure
	if(!in_array($dev->DeviceType,array('Physical Infrastructure','Patch Panel')) && !empty($portList) ){
		print "		<div>\n		  <div><a id=\"net\">".__("Connections")."</a></div>\n		  <div>\n			<div class=\"table border switch\">\n				<div>
				<div>#</div>
				<div id=\"spn\">".__("Port Name")."</div>
				<div>".__("Device")."</div>
				<div>".__("Device Port")."</div>
				<div>".__("Notes")."</div>";
		if($dev->DeviceType=='Switch'){print "\t\t\t\t<div id=\"st\">".__("Status")."</div>";}
		print "\t\t\t\t<div id=\"mt\">".__("Media Type")."</div>
			<div id=\"cc\">".__("Color Code")."</div>
			</div>\n";

		foreach($portList as $i => $port){
			$tmpDev=new Device();
			$tmpDev->DeviceID=$port->ConnectedDeviceID;
			$tmpDev->GetDevice();
			
			// Allow the user to modify the port if they have rights over the switch itself or
			// the attached device.
			$jsondata[$i]=($dev->Rights=="Write")?true:($tmpDev->Rights=="Write")?true:false;

			$cp=new DevicePorts();
			if($port->ConnectedDeviceID>0 && !is_null($port->ConnectedDeviceID)){
				$cp->DeviceID=$port->ConnectedDeviceID;
				$cp->PortNumber=$port->ConnectedPort;
				$cp->getPort();
			}else{
				$port->ConnectedDeviceID=0;
				$port->ConnectedPort=0;
				$cp->Label="";
			}

			if($cp->DeviceID >0 && $cp->Label==''){$cp->Label=$cp->PortNumber;};

			$mt=(isset($mediaTypes[$port->MediaID]))?$mediaTypes[$port->MediaID]->MediaType:'';
			$cc=(isset($colorCodes[$port->ColorID]))?$colorCodes[$port->ColorID]->Name:'';

			if($dev->DeviceType=='Switch'){$linkList[$i]=(isset($linkList[$i]))?$linkList[$i]:'err';}

			// the data attribute is used to store the previous value of the connection
			print "\t\t\t\t<div data-port=$i>
					<div id=\"sp$i\">$i</div>
					<div id=\"spn$i\">$port->Label</div>
					<div id=\"d$i\" data-default=$port->ConnectedDeviceID><a href=\"devices.php?DeviceID=$port->ConnectedDeviceID\">$tmpDev->Label</a></div>
					<div id=\"dp$i\" data-default=$port->ConnectedPort><a href=\"paths.php?deviceid=$port->ConnectedDeviceID&portnumber=$port->ConnectedPort\">$cp->Label</a></div>
					<div id=\"n$i\" data-default=\"$port->Notes\">$port->Notes</div>";
			if($dev->DeviceType=='Switch'){print "\t\t\t\t<div id=\"st$i\"><span class=\"ui-icon status {$linkList[$i]}\"></span></div>";}
			print "\t\t\t\t<div id=\"mt$i\" data-default=$port->MediaID>$mt</div>
					<div id=\"cc$i\" data-default=$port->ColorID>$cc</div>
				</div>\n";
		}
		echo "			</div><!-- END div.table -->\n		  </div>\n		</div>";
	}

	if($dev->DeviceType=='Patch Panel'){
		print "\n\t<div>\n\t\t<div><a name=\"net\">".__("Connections")."</a></div>\n\t\t<div>\n\t\t\t<div class=\"table border patchpanel\">\n\t\t\t\t<div><div>".__("Front")."</div><div>".__("Device Port")."</div><div>".__("Notes")."</div><div id=\"pp\">".__("Patch Port")."</div><div id=\"mt\">".__("Media Type")."</div><div id=\"rear\">".__("Back")."</div><div>".__("Device Port")."</div><div>".__("Notes")."</div></div>\n";
		for($n=0; $n< sizeof($portList)/2; $n++){
			$i = $n + 1;	// The "port number" starting at 1
			$frontDev=new Device();
			$rearDev=new Device();

			$frontDev->DeviceID=$portList[$i]->ConnectedDeviceID;
			$rearDev->DeviceID=$portList[-$i]->ConnectedDeviceID;
			$frontDev->GetDevice();
			$rearDev->GetDevice();

			// Allow the user to modify the port if they have rights over the patch panel itself or
			// the attached device, but only the front port.  The rear is still reserved for administrators only.
			$jsondata[$i]=($dev->Rights=="Write")?true:($frontDev->Rights=="Write")?true:false;

			$fp=""; //front port Label
			$cPort=new DevicePorts();
			if($frontDev->DeviceID >0){
				$cPort->DeviceID=$frontDev->DeviceID;
				$cPort->PortNumber=$portList[$i]->ConnectedPort;
				$cPort->getPort();
				$fp=($cPort->Label!="")?$cPort->Label:$cPort->PortNumber;
			}

			$mt=(isset($mediaTypes[$portList[$i]->MediaID]))?$mediaTypes[$portList[$i]->MediaID]->MediaType:'';

			// rear port Label
			if($portList[-$i]->ConnectedPort!=''){
				$p=new DevicePorts();
				$p->DeviceID=$portList[-$i]->ConnectedDeviceID;
				$p->PortNumber=$portList[-$i]->ConnectedPort;
				$p->getPort();
				$rp=($p->Label=='')?abs($p->PortNumber):$p->Label;
				($p->PortNumber<0)?$rp.=' ('.__("Rear").')':'';
			}else{
				$rp='';
			}

			$portList[$i]->Label=($portList[$i]->Label=='')?$i:$portList[$i]->Label;
			print "\n\t\t\t\t<div data-port=$i>
					<div id=\"fd$i\" data-default=$frontDev->DeviceID><a href=\"devices.php?DeviceID=$frontDev->DeviceID\">$frontDev->Label</a></div>
					<div id=\"fp$i\" data-default={$portList[$i]->ConnectedPort}><a href=\"paths.php?deviceid=$frontDev->DeviceID&portnumber={$portList[$i]->ConnectedPort}\">$fp</a></div>
					<div id=\"fn$i\" data-default=\"{$portList[$i]->Notes}\">{$portList[$i]->Notes}</div>
					<div id=\"pp$i\">{$portList[$i]->Label}</div>
					<div id=\"mt$i\" data-default={$portList[$i]->MediaID} data-color={$portList[$i]->ColorID}>$mt</div>
					<div id=\"rd$i\" data-default=$rearDev->DeviceID><a href=\"devices.php?DeviceID=$rearDev->DeviceID\">$rearDev->Label</a></div>
					<div id=\"rp$i\" data-default={$portList[-$i]->ConnectedPort}><a href=\"paths.php?deviceid=$rearDev->DeviceID&portnumber={$portList[-$i]->ConnectedPort}\">$rp</a></div>
					<div id=\"rn$i\" data-default=\"{$portList[-$i]->Notes}\">{$portList[-$i]->Notes}</div>
				</div>";
		}
		print "\t\t\t</div><!-- END div.table -->\n\t\t</div>\n\t</div>\n";
	}
?>
		<div><div><div style="position: relative;">&nbsp;<div id="buttonbar" style="position: absolute; min-width: 400px; left: 0px; right: 0px; margin-left: auto; margin-right: auto; text-align: center;">
<?php
	if($write){
		if($dev->DeviceID >0){
			echo '			<button type="submit" name="action" value="Update">',__("Update"),'</button>
			<button type="submit" name="action" value="Copy">', __("Copy"), '</button>
			<button type="button" name="audit">',__("Certify Audit"),'</button>';
		} else {
			echo '			<button type="submit" name="action" value="Create">',__("Create"),'</button>';
		}
	}
	// Delete rights are seperate from write rights
	if(($write || $person->DeleteAccess) && $dev->DeviceID >0){
		echo '		<button type="button" name="action" value="Delete">',__("Delete"),'</button>';
	}
	if($dev->DeviceID >0){
		echo '		<a href="export_port_connections.php?deviceid=',$dev->DeviceID,'"><button type="button">',__("Export Connections"),'</button></a>';
	}
?>

		</div></div></div>
		<div></div></div>
	</div> <!-- END div.table -->
</div></div>
</div> <!-- END div.table -->
</form>
</div></div>
<?php
	if($dev->ParentDevice >0){
		print "   <a href=\"devices.php?DeviceID=$pDev->DeviceID\">[ ".__("Return to Parent Device")." ]</a><br>\n";
		print "   <a href=\"cabnavigator.php?cabinetid=".$dev->GetDeviceCabinetID()."\">[ ".__("Return to Navigator")." ]</a>";
	}elseif($dev->Cabinet >0){
		print "   <a href=\"cabnavigator.php?cabinetid=$cab->CabinetID\">[ ".__("Return to Navigator")." ]</a>";
	}else{
		if ($dev->Position>0){
			print "   <div><a href=\"storageroom.php?dc=$dev->Position\">[ ".__("Return to Storage Room")." ]</a></div>";
		}
		print "   <div><a href=\"storageroom.php\">[ ".__("Return to General Storage Room")." ]</a></div>";
	}
?>

<div id="auditconfirm" class="hide">
	<p><?php print __("Do you certify that you have completed an audit of this device?"); ?></p>
</div>

<div id="pdutest" title="Testing SNMP Communications"></div>

</div><!-- END div.main -->
</div><!-- END div.page -->

<?php
	// disables the jqte editor for Notes if no write access.       
	if ($write) { $nojqte="no";} else { $nojqte="yes";}
	echo '<div><input type="hidden" name="disablejqte" id="disablejqte" value="',$nojqte,'"></div>';
?>

<script type="text/javascript">
	var portrights=$.parseJSON('<?php echo json_encode($jsondata); ?>');
	portrights['admin']=<?php echo ($person->WriteAccess)?'true':'false'; ?>;
<?php
	if(!$write){
		print "\t\t//Disable all input if they don't have rights.
		$('#firstport button[name=firstport],#firstport button[name=name]').hide();
		$('#cdufirstport button[name=cdufirstport],#cdufirstport button[name=cduname]').hide();
		$('.main input, .main select').prop('disabled', true);";
	}
?>
	$(document).ready(function() {

              //Fill in sensorreadings
              $('#sensorreadings').append(function(){
			var devid = $('#DeviceID').val();	
			$.get('api/v1/device/'+devid+'/getsensorreadings',function(data){
                                if(!data.error){
                                        $('#sensorreadout').append('<br>Temp:&nbsp;'+data.sensor.Temperature+'&deg;&nbsp;&nbsp;Humidity:&nbsp;'+data.sensor.Humidity+'<br>Last&nbsp;Read:&nbsp;'+data.sensor.LastRead+'<br>');
                                }else{
                                        $('#sensorreadout').append('<br>'+data.message+'<br>');
                                }
                        });     
              });

		// Don't attempt to open the datacenter tree until it is loaded
		function opentree(){
			if($('#datacenters .bullet').length==0){
				setTimeout(function(){
					opentree();
				},500);
			}else{
				expandToItem('datacenters','cab<?php echo $cab->CabinetID;?>');
			}
		}
		opentree();

		if(navigator.appName.indexOf("Internet Explorer")==-1){
			// experimental print function
			// only works with FF and Chrome.  Once again IE is a broken damnable mess.
			$('<button>', { 'type': 'button' }).text('Print').click(function(e){
				e.preventDefault();
				var insert=$('#header').clone(); // clone header
				var devinfo=$('<div>').addClass('device').addClass('page').append($('#deviceform').clone()); // clone devinfo
				devinfo.find('#pandn .caption').remove(); // remove buttons
				var popup=window.open('template.php',"Print", "menubar=0,location=0,height=700,width=700" );
				popup.onload=function(){
					$(popup.document.body).html(insert).after(devinfo); // put into the popup
					popup.print(); //print
				}
			}).appendTo('#buttonbar');
		}

		// Add a spacer for use when/if port removal options are triggered
		$('.switch > div:first-child, .patchpanel > div:first-child').prepend($('<div>').addClass('delete').hide());
		// Enable Mass Change Options
		$('.switch.table, .patchpanel.table, .power.table').massedit();

		<?php echo (class_exists('LogActions') && $dev->DeviceID>0)?'LameLogDisplay();':''; ?>

		// Scroll the operations log to the bottom
		scrollolog();

		// Linkify URLs in the olog.
		function linkifyolog(){
			$('#olog .table > div').find('div + div').each(function(){
				$(this).html(urlify($(this).text()));
			});
		}
		linkifyolog();

		var zoom=$('<span>').addClass('ui-icon ui-icon-circle-zoomin').css('float','right');
		$('#olog > div:first-child > div').prepend(zoom);
		zoom.click(function(e){
			var dialog=$('<div>').html($('#olog .table').clone().addClass('border'));
			dialog.dialog({
				modal: true,
				width: $(window).width()-50,
				height: $(window).height()-50,
				beforeClose: function(){
					$(this).attr('id','');
				}
			}).attr('id','olog').find('div > div > div').css('padding','3px');
			dialog.find('div > div > div ~ div').css({'max-width':$(window).width()-114-$('#olog .table > div:first-child > div:first-child').width()});
		});

		// Setup an event listener for the enter key and prevent it from submitting the form
		$('#olog input').keypress(function (e) {    
			var charCode = e.charCode || e.keyCode || e.which;
			if (charCode  == 13) {
				// if enter is pressed and there is something in this line then submit a new operations event
				if($(this).val().trim()!=''){
					$('#olog button').trigger('click');
				}
				return false;
			}
		});
		$('#olog button').click(function(){
			if($('#olog input').val().trim()!=''){
				$.post('',{devid: $('#DeviceID').val(), olog: $('#olog input').val()}).done(function(data){
					if(data){
						var row=$('<div>')
							.append($('<div>').text(getISODateTime(new Date())))
							.append($('<div>').text("<?php echo $person->UserID; ?>"))
							.append($('<div>').text($('#olog input').val()));
						$('#olog .table').append(row);
						$('#olog input').val('');
						scrollolog();
						linkifyolog();
					}else{
						$('#olog input').effect('highlight', {color: 'salmon'}, 1500);
					}
				});
			}
		});

		$('#buttonbar > button[name="audit"]').click(function(){
			$('#auditconfirm').removeClass('hide').dialog({
				modal: true,
				width: 'auto',
				buttons: {
					Yes: function(){
						$.post('',{audit: $('#DeviceID').val()}).done(function(data){
							$('#auditdate').text(data.AuditStamp);
						});
						$(this).dialog("close");
					},
					No: function(){
						$(this).dialog("close");
					}
				}
			});
		});

		// Make the cabinet and template selections smart comboboxes if user can write
<?php
	if ($write) {
?>
		$('#CabinetID').combobox();
		$('#TemplateID').combobox();
		$('select[name=ParentDevice]').combobox();
<?php
	}
?>

		// Hide this for now
		$('#devicetype-limiter').parent('div').hide();

		// Connection limitation selection
		$('#connection-limiter, #devicetype-limiter').buttonset().parent('div').css('text-align','right');
		$('#connection-limiter input').click(function(e){
			setCookie("DeviceSelectionLimit",e.currentTarget.value);
			$('.table.switch > div ~ div').each(function(){
				var row=$(this);
				if(row.data('edit')){
					row.row('getdevices');
				}
			});
		});
		// Set the default selection on the filter to the value of the cookie OR default to global
		var dsl=getCookie('DeviceSelectionLimit');
		if(dsl){
			$('#connection-limiter input[value='+dsl+']').select().click();
		}else{
			$('#connection-limiter input[value=global]').select().click();
		}

		// Grab the custom attributes blanks and make them use the update button on pressing enter
		$(':input[id^=customvalue], div.left :input').keypress(function(event){
			if(event.keyCode==10 || event.keyCode==13){
				event.preventDefault();
				$('.caption > button[value=Update]').trigger('click');
			}
		});

		// Safari is garbage
		// This is a hack to correct a problem with safari not rendering the table caption
		// correctly.  Instead I am putting a blank row at the bottom of the device connection
		// table then using css to center it but it has to have the element set to the width
		// of the table
		$('#buttonbar').width($('#pandn').width());
	});
</script>

</body>
</html>
