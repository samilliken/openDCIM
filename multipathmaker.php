<?php
	require_once( 'db.inc.php' );
	require_once( 'facilities.inc.php' );

	$subheader=__("Creating an end to end connection");

	$status="";
	$path="";
	$pathid="";

	function builddclist($id=null){
		$dc=new DataCenter();
		$dcList=$dc->GetDCList();
		$idnum='';

		if(!is_null($id)){
			if($id=="dc-front"){
				$idnum=1;
			}elseif($id=="dc-rear"){
				$idnum=2;
			}
			$id=" name=\"$id\" id=\"$id\"";
		}

		$dcpicklist="<select$id data-port=$idnum><option value=0></option>";
		foreach($dcList as $d){
			$dcpicklist.="<option value=$d->DataCenterID>$d->Name</option>";
		}
		$dcpicklist.='</select>';

		return $dcpicklist;
	}	

	// AJAX - Start

	function displayjson($array){
		header('Content-Type: application/json');
		echo json_encode($array);
		exit;
	}

	if(isset($_POST['dc'])){
		$cab=new Cabinet();
		$cab->DataCenterID=$_POST['dc'];

		displayjson($cab->ListCabinetsByDC());
	}

	if(isset($_POST['cab'])){
		$dev=new Device();
		$dev->Cabinet=$_POST['cab'];

		// Filter devices by rights
		$devs=array();
		foreach($dev->ViewDevicesByCabinet(true) as $d){
			if($d->Rights=="Write" && $d->DeviceType != 'Patch Panel'){
				$devs[]=$d;
			}
		}
		displayjson($devs);
	}

	if(isset($_POST['dev'])){
		$dp=new DevicePorts();
		$dp->DeviceID=$_POST['dev'];
		$dev=new Device();
		$dev->DeviceID=$dp->DeviceID;
		$dev->GetDevice();
		$ports= array();
		if ($dev->Rights=="Write") {
			$parent = new Device($dev->ParentDevice);
			$parent->GetDevice();
            $ports = $dp->getAvailableFrontPorts();
		}
		displayjson($ports);
	}

	// AJAX - End

	if(!$person->SiteAdmin){
		// No soup for you.
		header('Location: '.redirect());
		exit;
	}

	if(isset($_POST['action_implement'])){
		if(isset($_POST['list'])){
			$list = $_POST['list'];
			$listOfPaths = array();
			for($index = 0; $index < count($list); $index++){
				$status = "";
				$dcFrontID = $list[$index]['dc_front'];
				$dcRearID = $list[$index]['dc_rear'];

				$devFrontID = $list[$index]['dev_front'];
				$devRearID = $list[$index]['dev_rear'];

				$portFrontNumber = $list[$index]['port_front'];
				$portRearNumber = $list[$index]['port_rear'];
				//INITIAL DEVICE (Front)

				//Search device
				$dev=new Device();
				$dev->DeviceID=intval($devFrontID);

				if ($dev->GetDevice()){
					$pp=new PlannedPath();
					$pp->devID1=$dev->DeviceID;
					$pp->port1=intval($portFrontNumber);
					$label1=$dev->Label;
				}

				//FINAL DEVICE (Rear)

				//Search device
				$dev=new Device();
				$dev->DeviceID=intval($devRearID);

				if ($dev->GetDevice() && isset($pp)){
					$port2=new DevicePorts();
					$port2->DeviceID = $dev->DeviceID;
					$port2->PortNumber = $portRearNumber;
					$port2->getPort();

					while ($port2->ConnectedPort != null){
						if($pp->devID1 == $port2->DeviceID || $port2->DeviceID == $port2->ConnectedDeviceID ) {
							$status = 'Device already connected';
							break;
						}

						error_log("Device with dev_id " . $port2->DeviceID . " and port " . $port2->PortNumber . " already connected to device with id " . $port2->ConnectedDeviceID . " on port " . $port2->ConnectedPort);
						$port2->DeviceID = $port2->ConnectedDeviceID;
						#Once we switch to the other device, we look for the corresponding front or rear port
						$port2->PortNumber = -1 * $port2->ConnectedPort;
						$port2->getPort();
					}
					$pp->devID2=$port2->DeviceID;
					$pp->port2=$port2->PortNumber;
					$label2=$dev->Label;
				}

				$pp->MakePath();
				$pp->GotoHeadDevice();

				for ($i=1;$i < count($pp->Path);$i++){
					error_log("Port : " . $pp->Path[$i]["PortNumber"]);
					#if ($pp->Path[$i]["PortNumber"] > 0 && $pp->Path[$i + 1]["PortNumber"] < 0) {
						$port1=new DevicePorts();
						$port1->DeviceID = $pp->Path[$i]["DeviceID"];
						$port1->PortNumber = $pp->Path[$i]["PortNumber"];
						$port1->getPort();
						if(isset($_POST['notes'][$index]) and $_POST['notes'][$index] != "") {
							$port1->Notes=($_POST['notes'][$index]);
						}

						$port2=new DevicePorts();
						$port2->DeviceID = $pp->Path[$i + 1]["DeviceID"];
						$port2->PortNumber = -$pp->Path[$i + 1]["PortNumber"];
						$port2->getPort();
						if(isset($_POST['notes'][$index]) and $_POST['notes'][$index] != "") {
							$port2->Notes=($_POST['notes'][$index]);
						}

						DevicePorts::makeConnection($port1,$port2);
					#}
				}
			}
			displayjson("All connections implemented");
		}
	}

	if(isset($_POST['action_validate'])){
		if(isset($_POST['list'])){
			$list = $_POST['list'];
			$listOfPaths = array();
			$fakelyUsedNodes = array();
			for($index = 0; $index < count($list); $index++){
				$status = "";
				$dcFrontID = $list[$index]['dc_front'];
				$dcRearID = $list[$index]['dc_rear'];

				$devFrontID = $list[$index]['dev_front'];
				$devRearID = $list[$index]['dev_rear'];

				$portFrontNumber = $list[$index]['port_front'];
				$portRearNumber = $list[$index]['port_rear'];
				//INITIAL DEVICE (Front)

				//Search device
				$dev=new Device();
				$dev->DeviceID=intval($devFrontID);

				if ($dev->GetDevice()){
					$pp=new PlannedPath();
					$pp->devID1=$dev->DeviceID;
					$pp->port1=intval($portFrontNumber);
					$label1=$dev->Label;
				}

				//FINAL DEVICE (Rear)

				//Search device
				$dev=new Device();
				$dev->DeviceID=intval($devRearID);

				if ($dev->GetDevice() && isset($pp)){
					$port2=new DevicePorts();
					$port2->DeviceID = $dev->DeviceID;
					$port2->PortNumber = $portRearNumber;
					$port2->getPort();

					while ($port2->ConnectedPort != null){
						if($pp->devID1 == $port2->DeviceID || $port2->DeviceID == $port2->ConnectedDeviceID) {
							$status = 'Device already connected';
							break;
						}

						error_log("Device with dev_id " . $port2->DeviceID . " and port " . $port2->PortNumber . " already connected to device with id " . $port2->ConnectedDeviceID . " on port " . $port2->ConnectedPort);
						$port2->DeviceID = $port2->ConnectedDeviceID;
						#Once we switch to the other device, we look for the corresponding front or rear port
						$port2->PortNumber = -1 * $port2->ConnectedPort;
						$port2->getPort();
					}
					$pp->devID2=$port2->DeviceID;
					$pp->port2=$port2->PortNumber;
					$label2=$dev->Label;
				}

				$pathid=$label1."[".$pp->port1."]---".$label2."[".$pp->port2."]";

				//make path
				if ($pp->MakePath($fakelyUsedNodes)){
					//Go to initial device on Path
					if (!$pp->GotoHeadDevice()){
						$status="Error: ".__("Path not initialized")."";
					}
				} else {
					switch ($pp->PathError){
						case 1:
							$status="Error: ".__("Error getting initial device data")."";
							break;
						case 2:
							$status="Error: ".__("Error: initial device is a panel")."";
							break;
						case 3:
							$status="Error: ".__("Error getting initial device port data")."";
							break;
						case 4:
							$status="Error: ".__("Error: initial device port is already connected")."";
							break;
						case 5:
							$status="Error: ".__("Error getting final device data")."";
							break;
						case 6:
							$status="Error: ".__("Error: final device is a panel")."";
							break;
						case 7:
							$status="Error: ".__("Error getting final device port data")."";
							break;
						case 9:
							$status="Error: ".__("Error: Path not found")."";
							break;
					}
				}

				if ($status==""){
					$path="<div style='text-align: center;'>";
					$path.="<div style='font-size: 1.2em;'>".__("Connections")." $pathid</div>\n";

					//Path Table
					$path.="<table id=parcheos>\n\t<tr>\n\t\t<td colspan=6>&nbsp;</td>\n\t</tr>\n\t<tr>\n";
					$path.="\t\t<td>&nbsp;&nbsp;&nbsp;</td>\n\t\t<td>";

					$dev=new Device();
					$end=false;
					$elem_path=0;

					while (!$end) {
						//first device
						//get the device
						$dev->DeviceID=$pp->DeviceID;
						$dev->GetDevice();
						$elem_path++;

						//I get device Lineage (for multi level chassis)
						$devList=array();
						$devList=$dev->GetDeviceLineage();

						//Device table
						$path.="\n\t\t\t<table>\n\t\t\t\t<tr>\n\t\t\t\t\t<th colspan=2>";

						//cabinet
						$cab=new Cabinet();
						$cab->CabinetID=$devList[sizeof($devList)]->Cabinet;
						$cab->GetCabinet();
						$path.=__("Cabinet").": <a href=\"cabnavigator.php?cabinetid=$cab->CabinetID\">$cab->Location</a>";
						$path.="</th>\n\t\t\t\t</tr>\n\t\t\t\t<tr>\n\t\t\t\t\t<td>U:{$devList[sizeof($devList)]->Position}</td>\n";

						//Lineage
						$t=5;
						for ($i=sizeof($devList); $i>1; $i--){
							$path.=str_repeat("\t",$t++)."<td>\n";
							$path.=str_repeat("\t",$t++)."<table>\n";
							$path.=str_repeat("\t",$t++)."<tr>\n";
							$path.=str_repeat("\t",$t--)."<th colspan=2>";
							$path.="<a href=\"devices.php?DeviceID={$devList[$i]->DeviceID}\">{$devList[$i]->Label}</a>";
							$path.="</th>\n";
							$path.=str_repeat("\t",$t)."</tr>\n";
							$path.=str_repeat("\t",$t++)."<tr>\n";
							$path.=str_repeat("\t",$t)."<td>Slot:{$devList[$i-1]->Position}</td>\n";
						}

						//Device
						$port1Label = new DevicePorts();
						$port1Label->PortNumber = abs($pp->PortNumber);
						$port1Label->DeviceID = $pp->DeviceID;
						$port1Label->getPort();

						$path.=str_repeat("\t",$t--)."<td>".
								"<a href=\"devices.php?DeviceID=$dev->DeviceID\">$dev->Label".
								"</a><br>".__("Port").": ".$port1Label->Label."</td>\n";
						$path.=str_repeat("\t",$t--)."</tr>\n";
						$path.=str_repeat("\t",$t--)."</table>\n";

						//ending device table
						for ($i=sizeof($devList); $i>1; $i--){
							$path.=str_repeat("\t",$t--)."</td>\n";
							$path.=str_repeat("\t",$t--)."</tr>\n";
							$path.=str_repeat("\t",$t--)."</table>\n";
						}

						$path.="\t\t</td>";

						if ($elem_path==1  || $dev->DeviceType=="Patch Panel"){
							//half hose
							//Out connection type
							$tipo_con=($pp->PortNumber>0)?"f":"r";

							$path.="\n\t\t<td class=\"$tipo_con-left\"></td>\n";
						}
						//next device, if exist
						if ($pp->GotoNextDevice()) {
							$dev->DeviceID=$pp->DeviceID;
							$dev->GetDevice();

							//In connection type
							$tipo_con=($pp->PortNumber>0)?"r":"f";

							//half hose
							$path.="\n\t\t<td class=\"$tipo_con-right\"></td>\n";

							//Out connection type
							$tipo_con=($pp->PortNumber>0)?"f":"r";

							//Can I follow?
							if ($dev->DeviceType=="Patch Panel"){
								$path.="\n\t\t<td class=\"connection-$tipo_con-1\">";
								// I prepare row separation between patch rows
								$conex="\t\t<td class=\"connection-$tipo_con-3\">&nbsp;</td>\n";
								$conex.="\t\t<td class=\"connection-$tipo_con-2\">&nbsp;</td>\n\t</tr>\n";
							}
							else{
								$conex="";
								$path.="\n\t\t<td>";
							}

							//I get device Lineage (for multi level chassis)
							$devList=array();
							$devList=$dev->GetDeviceLineage();

							//Device Table
							$path.="\n\t\t\t<table>\n\t\t\t\t<tr>\n\t\t\t\t\t<th colspan=2>";

							//cabinet
							$cab=new Cabinet();
							$cab->CabinetID=$devList[sizeof($devList)]->Cabinet;
							$cab->GetCabinet();
							$path.=__("Cabinet").": <a href=\"cabnavigator.php?cabinetid=$cab->CabinetID\">$cab->Location</a>";
							$path.="</th>\n\t\t\t\t</tr>\n\t\t\t\t<tr>\n\t\t\t\t\t<td>U:{$devList[sizeof($devList)]->Position}</td>\n";

							//lineage
							$t=5;
							for ($i=sizeof($devList); $i>1; $i--){
								$path.=str_repeat("\t",$t++)."<td>\n";
								$path.=str_repeat("\t",$t++)."<table>\n";
								$path.=str_repeat("\t",$t++)."<tr>\n";
								$path.=str_repeat("\t",$t--)."<th colspan=2>";
								$path.="<a href=\"devices.php?DeviceID={$devList[$i]->DeviceID}\">{$devList[$i]->Label}</a>";
								$path.="</th>\n";
								$path.=str_repeat("\t",$t)."</tr>\n";
								$path.=str_repeat("\t",$t++)."<tr>\n";
								$path.=str_repeat("\t",$t)."<td>Slot:{$devList[$i-1]->Position}</td>\n";
							}

							$port2Label = new DevicePorts();
							$port2Label->PortNumber = abs($pp->PortNumber);
							$port2Label->DeviceID = $pp->DeviceID;
							$port2Label->getPort();
							//device
							$path.=str_repeat("\t",$t--)."<td>".
									"<a href=\"devices.php?DeviceID=$dev->DeviceID\">$dev->Label".
									"</a><br>".__("Port").": ".$port2Label->Label."</td>\n";
							$path.=str_repeat("\t",$t--)."</tr>\n";

							//ending device table
							for ($i=sizeof($devList); $i>1; $i--){
								$path.=str_repeat("\t",$t--)."</table>\n";
								$path.=str_repeat("\t",$t--)."</td>\n";
								$path.=str_repeat("\t",$t--)."</tr>\n";
							}

							if ($pp->PortNumber>0){
								$t++;
								$path.=str_repeat("\t",$t++)."<tr>\n";
								$path.=str_repeat("\t",$t)."<td colspan=2 class=\"base-f\">\n";
								$path.=str_repeat("\t",$t--)."</td>\n";
								$path.=str_repeat("\t",$t--)."</tr>\n";
							}
							$path.=str_repeat("\t",$t--)."</table>\n";

							//ending row
							$path.="\t\t</td>\n\t\t<td>&nbsp;&nbsp;&nbsp;</td>\n\t</tr>\n";

							if ($pp->GotoNextDevice()) {
								$tipo_con=($pp->PortNumber>0)?"r":"f";  //In connection type

								//row separation between patch rows: draw the connection between panels
								$path.="\t<tr>\n\t\t<td></td><td class=\"connection-$tipo_con-4\">&nbsp;</td>\n";
								$path.="\t\t<td class=\"connection-$tipo_con-3\">&nbsp;</td>\n";
								$path.=$conex;
								$path.="\n<tr>\n\t\t<td>&nbsp;&nbsp;&nbsp;</td>\n\t\t<td>";
							} else {
								//End of path
								$path.="\t<tr>\n\t\t<td></td><td>&nbsp;</td>\n";
								$path.="\t\t<td>&nbsp;</td>\n";
								$path.=$conex;
								$end=true;
							}
						}else {
							//End of path
							$path.="\n\t\t<td></td>\n\t\t<td></td>\n\t\t<td>&nbsp;&nbsp;&nbsp;</td>\n\t</tr>\n";
							$end=true;
						}
						$primero=false;
					}
					//key
					$path.="\t<tr>\n\t\t<td colspan=6>&nbsp;</td>\n\t</tr>";
					$path.="\t<tr>\n";
					$path.="\t\t<td class=\"right\" colspan=2><img src=\"images/leyendaf.png\" alt=\"\"></td>\n";
					$path.="\t\t<td class=\"left\" colspan=4>&nbsp;&nbsp;".__("Front Connection")."</td>\n";
					$path.="\t</tr>\n";
					$path.="\t<tr>\n";
					$path.="\t\t<td class=\"right\" colspan=2><img src=\"images/leyendar.png\" alt=\"\"></td>\n";
					$path.="\t\t<td class=\"left\" colspan=4>&nbsp;&nbsp;".__("Rear Connection")."</td>\n";
					$path.="\t</tr>\n";

					//End of path table
					$path.="\t<tr>\n\t\t<td colspan=6>&nbsp;</td>\n\t</tr></table>";
					$path.="\t<label for=\"notes\">".__("Notes")."</label>\n";
					$path.="\t<input type=\"text\" name=\"notes\" id=\"notes".$index."\" size=20 value=\"\">\n";
					$path.= "</br>";
					array_push($listOfPaths, $path);
				} else {
					displayjson($status);
				}
			}
			displayjson($listOfPaths);
		} else {
			displayjson("Error: No connections passed.");
		}
	}
?>
<!doctype html>
<html>
<head>
  <meta http-equiv="X-UA-Compatible" content="IE=Edge">
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">

  <title>openDCIM Data Center Inventory</title>
  <link rel="stylesheet" href="css/inventory.php" type="text/css">
  <link rel="stylesheet" href="css/jquery-ui.css" type="text/css">
  <!--[if lt IE 9]>
  <link rel="stylesheet"  href="css/ie.css" type="text/css">
  <![endif]-->
  <script type="text/javascript" src="scripts/jquery.min.js"></script>
  <script type="text/javascript" src="scripts/jquery-ui.min.js"></script>
  <script type="text/javascript">
	function populateCabinets(dcValue, cabId){
		$.post('',({dc: dcValue})).done(function(data){
				var s=$('#cabid' + cabId)[0];
				var length = s.length;
				for(i = 0; i < length; i++){
					s.options.remove(0);
				}
				$.each(data, function(i,cab){
					var o=document.createElement("option");
					o.value = cab.CabinetID;
					o.text = cab.Location;
					s.add(o);
				});

				//if there is no options, then pass an empty value so that the other lists can be cleared.
				populateDevices(s.options[0]?s.options[0].value:'', cabId);
			});
	}

	function populateDevices(cabValue, devId){
		$.post('',({cab: cabValue})).done(function(data){
				var s=$('#devid' + devId)[0];
				var length = s.length;
				for(i = 0; i < length; i++){
					s.options.remove(0);
				}
				$.each(data, function(i,dev){
					var o=document.createElement("option");
					o.value = dev.DeviceID;
					o.text = dev.Label;
					s.add(o);
				});
				//if there is no options, then pass an empty value so that the other lists can be cleared.
				populatePorts(s.options[0]?s.options[0].value:'', devId);
			});
	}

	function populatePorts(devValue, portId){
		$.post('',({dev: devValue})).done(function(data){
				var s=$('#port' + portId)[0];
				var length = s.length;
				for(i = 0; i < length; i++){
					s.options.remove(0);
				}
				$.each(data, function(i,por){
					var o=document.createElement("option");
					o.value = por.PortNumber;
					o.text = por.Label;
					s.add(o);
				});
			});
	}

	var pathsToCreate = [];

	function deleteRow(r){
		var listTable = $('#list')[0];
		var i = r.parentNode.parentNode.rowIndex;
		listTable.deleteRow(i);

		//removing row from array, since index 0 is the header, we use i - 1 instead of i.
		pathsToCreate.splice(i - 1, 1);
	}
	$(document).ready(function(){

		$('#dc-front').change(function(e){
			populateCabinets($(this).val(), 1);
			$(window).trigger('resize');
		});

		$('#dc-rear').change(function(e){
			populateCabinets($(this).val(), 2);
			$(window).trigger('resize');
		});

		$('#cabid1').change(function(e){
			populateDevices($(this).val(), 1);
			$(window).trigger('resize');
		});

		$('#cabid2').change(function(e){
			populateDevices($(this).val(), 2);
			$(window).trigger('resize');
		});

		$('#devid1').change(function(e){
			populatePorts($(this).val(), 1);
			$(window).trigger('resize');
		});

		$('#devid2').change(function(e){
			populatePorts($(this).val(), 2);
			$(window).trigger('resize');
		});

		$('#btn_add_list').click(function(e){
			//Getting selected objects
			var dc_front = $('#dc-front option:selected')[0];
			var cab_front = $('#cabid1 option:selected')[0];
			var dev_front = $('#devid1 option:selected')[0];
			var port_front = $('#port1 option:selected')[0];

			var dc_rear = $('#dc-rear option:selected')[0];
			var cab_rear = $('#cabid2 option:selected')[0];
			var dev_rear = $('#devid2 option:selected')[0];
			var port_rear = $('#port2 option:selected')[0];

			function validateEntry(){
				function validateAndAlert(jqueryObject, message){
					if(jqueryObject == null || jqueryObject.text == ""){
						alert(message);
						return false;
					}
					return true;
				}

				if(!validateAndAlert(dc_front, "Please specify a Data Center for the initial Device!")){
					return false;
				}
				if(!validateAndAlert(cab_front, "Please specify a cabinet for the initial Device!")){
					return false;
				}
				if(!validateAndAlert(dev_front, "Please specify a device for the initial Device!")){
					return false;
				}
				if(!validateAndAlert(port_front, "Please specify a port for the initial Device!")){
					return false;
				}

				if(!validateAndAlert(dc_rear, "Please specify a Data Center for the final Device!")){
					return false;
				}
				if(!validateAndAlert(cab_rear, "Please specify a cabinet for the final Device!")){
					return false;
				}
				if(!validateAndAlert(dev_rear, "Please specify a device for the final Device!")){
					return false;
				}
				if(!validateAndAlert(port_rear, "Please specify a port for the final Device!")){
					return false;
				}

				//Check if the devices are not the same
				if(dev_front.value == dev_rear.value){
					alert("The initial and the final device cannot be the same!");
					return false;
				}

				return true;
			}

			if(validateEntry()){
				var dictToAdd = { dc_front : dc_front.value,
							   cab_front : cab_front.value,
							   dev_front : dev_front.value,
							   port_front : port_front.value,
							   dc_rear : dc_rear.value,
							   cab_rear : cab_rear.value,
							   dev_rear : dev_rear.value,
							   port_rear : port_rear.value};
				var canAdd = true;
				//Validating if the dictionnary we want to add is already in the array.
				for(i = 0; i < pathsToCreate.length; i++){
					//Check if an entry with the same values already exists
					if(JSON.stringify(pathsToCreate[i]) === JSON.stringify(dictToAdd) ){
						canAdd = false;
						alert("The connection you are trying to implement is already in the table of connections to add!");
						break;
					}

					//Check if the port of initial Device is already used
					if((dictToAdd['dev_front'] == pathsToCreate[i]['dev_front'] &&
						dictToAdd['port_front'] == pathsToCreate[i]['port_front']) ||
						(dictToAdd['dev_front'] == pathsToCreate[i]['dev_rear'] &&
						dictToAdd['port_front'] == pathsToCreate[i]['port_rear'])){
							canAdd = false;
							alert("The port " + port_front.text + " of device " + dev_front.text + " is already used in the table of connections to add!");
							break;
					}

					//Check if the port of final Device is already used
					if((dictToAdd['dev_rear'] == pathsToCreate[i]['dev_front'] &&
						dictToAdd['port_rear'] == pathsToCreate[i]['port_front']) ||
						(dictToAdd['dev_rear'] == pathsToCreate[i]['dev_rear'] &&
						dictToAdd['port_rear'] == pathsToCreate[i]['port_rear'])){
							canAdd = false;
							alert("The port " + port_rear.text + " of device " + dev_rear.text + " is already used in the table of connections to add!");
							break;
					}
				}
				if(canAdd){
					var listTable = $('#list')[0];
					var row = listTable.insertRow(-1);

					var pos = 0;
					var dcFront = row.insertCell(pos++);
					var cabFront = row.insertCell(pos++);
					var devFront = row.insertCell(pos++);
					var portFront = row.insertCell(pos++);

					//blank space used to make the table easier to read
					row.insertCell(pos++);

					var dcRear = row.insertCell(pos++);
					var cabRear = row.insertCell(pos++);
					var devRear = row.insertCell(pos++);
					var portRear = row.insertCell(pos++);

					var delBtn = row.insertCell(pos++);
					delBtn.innerHTML = "<button type = 'button' onclick='deleteRow(this)'>X</button>";

					dcFront.innerHTML = dc_front.text;
					cabFront.innerHTML = cab_front.text;
					devFront.innerHTML = dev_front.text;
					portFront.innerHTML = port_front.text;

					dcRear.innerHTML = dc_rear.text;
					cabRear.innerHTML = cab_rear.text;
					devRear.innerHTML = dev_rear.text;
					portRear.innerHTML = port_rear.text;
					//This is the list of paths to create.
					pathsToCreate.push(dictToAdd);
				}
			}
		});

		$('#btn_validate').click(function(e){
			$('#btn_validate').attr("disabled", "disabled");
			$.post('',({'action_validate' : '','list': pathsToCreate})).done(function(data){
				$('#btn_validate').removeAttr("disabled");
				data = [].concat( data );
				var hasErrors = false;
				for(var i = 0; i < data.length; i++){
					if(data[i].indexOf('Error') != -1){
						alert(data[i]);
						hasErrors = true;
					}
				}
				if(!hasErrors){
					var path_info = $('#path_info')[0];
					path_info.innerHTML = data;
					path_info.innerHTML += "</br><button id='btn_implement' type='button'>Implement in database</button>";
					$('#btn_implement').click(function(e){
						$('#btn_implement').attr("disabled", "disabled");

						//Obtaining all notes before sending them to the server.
						var i = 0;

						var notes = [];
						while($('#notes' + i)[0] != null){
							notes.push($('#notes' + i)[0].value);
							i++;
						}

						$.post('',({'action_implement' : '','list': pathsToCreate, 'notes': notes})).done(function(data){
							$('#btn_implement').removeAttr("disabled");
							alert(data);
							//Reloading the page to reset all tables and select.
							location.reload();
						});
					});
				}
			});
		});


	});
  </script>

  <style>
	table {
		font-family: arial, sans-serif;
		border-collapse: collapse;
		width: 100%;
	}

	td, th {
		border: 1px solid #dddddd;
		text-align: left;
		padding: 8px;
	}

	tr:nth-child(even) {
		background-color: #dddddd;
	}
</style>

</head>
<body>
<?php include( 'header.inc.php' ); ?>
<div class="page">
<?php
	include( 'sidebar.inc.php' );

echo '<div class="main">
<h3>',$status,'</h3>
<div class="center"><div><div>
<form action="',$_SERVER["PHP_SELF"],'" method="POST">
<table id=crit_busc>
<tr><td>
<fieldset class=crit_busc>
	<legend>'.__("Initial device").'</legend>
	<table id="table">
		<tr>
			<th>',__("Data Center"),'</th>
			<th>',__("Cabinet"),'</th>
			<th>',__("Device"),'</th>
			<th>',__("Port"),'</th>
		</tr>
		<tr>
			<th>'.builddclist("dc-front").'</th>
			<th><select id="cabid1"/></th>
			<th><select id="devid1"/></th>
			<th><select id="port1"/></th>
		</tr>
	</table>
</fieldset>
</td>
<td>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</td>
<td>
<fieldset class=crit_busc>
	<legend>'.__("Final device").'</legend>
	<table id="table">
		<tr>
			<th>',__("Data Center"),'</th>
			<th>',__("Cabinet"),'</th>
			<th>',__("Device"),'</th>
			<th>',__("Port"),'</th>
		</tr>
		<tr>
			<th>'.builddclist("dc-rear").'</th>
			<th><select id="cabid2"/></th>
			<th><select id="devid2"/></th>
			<th><select id="port2"/></th>
		</tr>
	</table>
</fieldset>
</td>
<td style="vertical-align:bottom;"><button type="button" id="btn_add_list">Add to list</button></td>
</tr></table>
<table id="list">
	<tr>
		<th>',__("Initial Data Center"),'</th>
		<th>',__("Initial Cabinet"),'</th>
		<th>',__("Initial Device"),'</th>
		<th>',__("Initial Port"),'</th>
		<th>',__("|"),'</th>
		<th>',__("Final Data Center"),'</th>
		<th>',__("Final Cabinet"),'</th>
		<th>',__("Final Device"),'</th>
		<th>',__("Final Port"),'</th>
	</tr>
</table>';

echo '<div style="text-align: center;">';
echo '<button type="button" id="btn_validate" value="makepath">',__("Validate Path List"),'</button>';
echo '</br></br><div id="path_info"></div></div>';

echo '</form>';
?>
</div></div>
<?php echo "<br><br>",$path,"</div><br>";
echo '<a href="index.php">[ ',__("Return to Main Menu"),' ]</a>'; ?>
</div><!-- END div.main -->
</div><!-- END div.page -->
<script type="text/javascript">
	$('table#parcheos table tr + tr > td + td:has(table)').css('background-color','transparent');
</script>
</body>
</html>
