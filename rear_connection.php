<?php
	require_once( 'db.inc.php' );
	require_once( 'facilities.inc.php' );

	$subheader=__("Creating an end to end connection");

	$status="";
	global $additionalInfo;
	$additionalInfo="";
	$pathid="";
	$showWarningOverride = false;
	$showWarningOutOfRange = false;
	$warningOutOfRangeText = "";

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
		$selected = "selected";
		foreach($dcList as $d){
			$dcpicklist.="<option value=$d->DataCenterID $selected>$d->Name</option>";
			$selected = "";
		}
		$dcpicklist.='</select>';

		return $dcpicklist;
	}
	
	function portNumberIsInPortArray($portNumber, $array){
		foreach($array as $port){
			if($port->PortNumber == $portNumber){
				return true;
			}
		}
		return false;
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

		// Filter devices by rights and only show the Patch Panel
		$devs=array();
		foreach($dev->ViewDevicesByCabinet(true) as $d){
			if($d->Rights=="Write" && $d->DeviceType=="Patch Panel"){
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
		$ports=($dev->Rights=="Write")?$dp->getAvailableRearPorts():array();
		displayjson($ports);
	}

	if(isset($_POST['actionValidation'])){
		$status = array();
		if(!(isset($_POST['devid1']) && $_POST['devid1']!=0)){
			$status[]=__("Initial device unspecified");
		}
		if(!isset($_POST['port1']) || $_POST['port1']==''){
			$status[]=__("Initial device port unspecified");
		}
		if(!(isset($_POST['devid2']) && $_POST['devid2']!=0)){
			$status[]=__("Final device unspecified");
		}
		if(!isset($_POST['port2']) || $_POST['port2']==''){
			$status[]=__("Final device unspecified");
		}
		if(!isset($_POST['numberOfPorts']) || $_POST['numberOfPorts']==''){
			$status[]=__("Number of ports not specified");
		}
		if(empty($status)){
			//Getting number of connection to make
			$numberOfPath = $_POST['numberOfPorts'];
			$port1Number = intval($_POST['port1']);
			$port2Number = intval($_POST['port2']);
			
			//INITIAL DEVICE
			$dev=new Device();
			$dev->DeviceID=intval($_POST['devid1']);
			$dev->GetDevice();
				
			//FINAL DEVICE
			$finDev=new Device();
			$finDev->DeviceID=intval($_POST['devid2']);
			$finDev->GetDevice();
			
			$dp=new DevicePorts();
			$dp->DeviceID=$dev->DeviceID;
			
			$portsDevArray = $dp->getAvailableRearPorts();

			$dp->DeviceID = $finDev->DeviceID;

			$portsFinDevArray = $dp->getAvailableRearPorts();
			
			$port1=new DevicePorts();
			$port2=new DevicePorts();
			
			$port1->DeviceID=$dev->DeviceID;
			$port2->DeviceID=$finDev->DeviceID;
			
			$port1NumberOfRearPorts = $port1->getNumberOfRearPorts();
			$port2NumberOfRearPorts = $port2->getNumberOfRearPorts();
			
			//Temp port to do the testings of overriding ports 
			$tempPort1 = $port1Number;
			$tempPort2 = $port2Number;
		
			$hasExceedInit = false;
			$hasExceedFinal = false;
			//Validate if we override used ports or if we exceed the port number.
			for($i = 0; $i < $numberOfPath; $i++){
				
				if($hasExceedInit = abs($tempPort1) == $port1NumberOfRearPorts + 1){
					$status[] = "Exceed initial";	
				
				}
				if($hasExceedFinal = abs($tempPort2) == $port2NumberOfRearPorts + 1){
					$status[] = "Exceed final";
				}
				
				if($hasExceedFinal || $hasExceedInit){
					break;
				}
				
				if(!portNumberIsInPortArray($tempPort1, $portsDevArray)){
					$status[] = "Override init";
				}
				if(!portNumberIsInPortArray($tempPort2, $portsFinDevArray)){
					$status[] = "Override final";
				}
				
				$tempPort1--;
				$tempPort2--;
			}
			
		}
		
		//Return the error code.
		displayjson($status);
	}
	
	// AJAX - End
	
	if(!$person->SiteAdmin){
		// No soup for you.
		header('Location: '.redirect());
		exit;
	}
	
	//This is executed after the validation, but it is always good to do a double validation of the requiered fields.
	if(isset($_POST['create_connection_after_validation'])){
		if((isset($_POST['devid1']) && $_POST['devid1']!=0)
			&& isset($_POST['port1']) && $_POST['port1']!=''
			&& (isset($_POST['devid2']) && $_POST['devid2']!=0)
			&& (isset($_POST['port2']) && $_POST['port2']!='')
			&& isset($_POST['numberOfPorts']) && $_POST['numberOfPorts']!=''){
		
			//Getting number of connection to make
			$numberOfPath = $_POST['numberOfPorts'];
			$port1Number = intval($_POST['port1']);
			$port2Number = intval($_POST['port2']);
			
			//INITIAL DEVICE
			$dev=new Device();
			$dev->DeviceID=intval($_POST['devid1']);
				
			//FINAL DEVICE
			$finDev=new Device();
			$finDev->DeviceID=intval($_POST['devid2']);
			
			$connectionMaker = new RearConnectionMaker($dev->DeviceID, $finDev->DeviceID, $port1Number, $port2Number, $numberOfPath);
			$connectionMaker->MakeConnections();
			$additionalInfo = $connectionMaker->GetTable();
		
		} else {
			if(!(isset($_POST['devid1']) && $_POST['devid1']!=0)){
				$status=__("Initial device unspecified");
			}elseif(!isset($_POST['port1']) || $_POST['port1']==''){
				$status=__("Initial device port unspecified");
			}elseif(!(isset($_POST['devid2']) && $_POST['devid2']!=0)){
				$status=__("Final device unspecified");
			}elseif(!isset($_POST['port2']) || $_POST['port2']==''){
				$status=__("Final device port unspecified");
			}elseif(!isset($_POST['numberOfPorts']) || $_POST['numberOfPorts']==''){
				$status=__("Number of ports not specified");
			}else{
				$status=__("Unknown Error");
			}
		}
	}
	
	//We send the output table we want to show to JQuery so we can show it without refreshing the page.	
	if(isset($_POST['create_connection_after_validation'])){
		displayjson($additionalInfo);
	}
?>
<!doctype html>
<html>
<head>
 <style>
    table#info {
		width:100%;
	}
	table#info, th, td {
		border: 1px;
	}
	th, td {
		padding: 5px;
		text-align: left;
	}
	table#info tr:nth-child(even) {
		background-color: #A5DA91;
	}
	table#info tr:nth-child(odd) {
		background-color: #88CC88;
	}
	table#info th {
		background-color: black;
		color: white;
	}
  </style>
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
	$(document).ready(function(){
		$('.main fieldset select').each(function(){
			var isFirstDialog = false;
			var cabl=$('<div>');
			var cabs=$('<div>');
			var cabr=$('<div>').append(cabl).append(cabs);
			var devl=cabl.clone();
			var devs=cabs.clone();
			var devr=$('<div>').append(devl).append(devs);
			var porl=cabl.clone();
			var pors=cabs.clone();
			var porr=$('<div>').append(porl).append(pors);
			var select=$('<select>');
			var opt=$('<option>');
			var inputNumberOfPortsL=cabl.clone();
			var inputNumberOfPortsS=$('<input id="numberOfPorts" size="2" onkeypress="return event.charCode >= 48 && event.charCode <= 57">')
			var inputNumberOfPortsR=$('<div>').append(inputNumberOfPortsL).append(inputNumberOfPortsS);
	
			$(this).change(function(e){
				if($(e.target).parents('div#initial').length){
					isFirstDialog = true;
				}	
				var port=$(this).data('port');
				$.post('',({dc: $(this).val()})).done(function(data){
					var s=select.clone();
					s.children().detach();
					s.append(opt.clone()).attr('id','cab'+port);
					$.each(data, function(i,cab){
						var o=opt.clone().val(cab.CabinetID).text(cab.Location);
						s.append(o);
					});
					s.change(function(e){
						$.post('',({cab: $(this).val()})).done(function(data){
							var ds=select.clone();
							ds.children().detach();
							ds.append(opt.clone()).attr('id','devid'+port);
							$.each(data, function(i,dev){
								var o=opt.clone().val(dev.DeviceID).text(dev.Label);
								ds.append(o);
							});
							ds.change(function(e){
								$.post('',({dev: $(this).val()})).done(function(data){
									select.children().detach();
									select.append(opt.clone()).attr('id','port'+port);
									var isFirst = true;
									$.each(data, function(i,por){
										var o=opt.clone().val(por.PortNumber).text(por.Label);
										if(isFirst){
											o[0].selected = true;
											isFirst = false;
										}
										select.append(o);
									});
									if(isFirstDialog){
										porl.text('<?php echo __("Starting Port");?>');
										
									} else {
										porl.text('<?php echo __("Port");?>');
									}
									pors.html(select.change());
									porr.insertAfter($(e.target).parent('div').parent('div'));
									
									if(isFirstDialog){
										inputNumberOfPortsL.text('<?php echo __("Number of ports")?>');
										inputNumberOfPortsR.insertAfter(porr);
										
									}
								});
								
							});
							devl.text('<?php echo __("Device");?>');
							devs.html(ds.change());
							devr.insertAfter($(e.target).parent('div').parent('div'));
							
						});
						
					});
					cabl.text('<?php echo __("Cabinet");?>');
					cabs.html(s.change());
					cabr.insertAfter($(e.target).parent('div').parent('div'));
					
					
				});
			});
			$(this).change();
		});
		$('#btn_crear').click(function(e){
			$('#btn_crear').attr("disabled", "disabled");
			$.post('',({'actionValidation':'', 
						'dc-front': $('#dc-front')[0].value,
						'dc-rear' : $('#dc-rear')[0].value,
						'devid1' : $('#devid1')[0].value,
						'devid2' : $('#devid2')[0].value,
						'port1' : $('#port1')[0].value,
						'port2' : $('#port2')[0].value,
						'numberOfPorts' : $('#numberOfPorts')[0].value})).done(function(data){

				var canSubmit = false;
				if(data.indexOf("Initial device unspecified") != -1){
					alert("Initial device unspecified");
				}else if(data.indexOf("Initial device port unspecified") != -1){
					alert("Initial device port unspecified");
				}else if(data.indexOf("Final device unspecified") != -1){
					alert("Final device unspecified");
				}else if(data.indexOf("Final device port unspecified") != -1){
					alert("Final device port unspecified");
				}else if(data.indexOf("Number of ports not specified") != -1){
					alert("Number of ports not specified");
				}else{
					canSubmit = true;
				}
				
				var numberOfOverrideInInit = 0;
				var numberOfOverrideInFinal = 0;
				var showExceedInitial = data.indexOf("Exceed initial") != -1;
				var showExceedFinal = data.indexOf("Exceed final") != -1;
				
				if(canSubmit){
					//Counting the number of ports overriden in init.
					if(data.indexOf("Override init") != -1){
						for(var i = 0; i < data.length; i++){
							if(data[i] == "Override init"){
								numberOfOverrideInInit++;
							}
						}
					}
					
					//Counting the number of ports overriden in final.
					if(data.indexOf("Override final") != -1){
						for(var i = 0; i < data.length; i++){
							if(data[i] == "Override final"){
								numberOfOverrideInFinal++;
							}
						}
					}
					
					if (numberOfOverrideInInit > 0 || numberOfOverrideInFinal > 0){
						if(!window.confirm("Creating the links specified will override " + numberOfOverrideInInit + 
										" existing link in inital device and "+numberOfOverrideInFinal+
										" existing links in final device, do you want to proceed?")){
							canSubmit = false;
						}
					}
					
					if(canSubmit && (showExceedFinal || showExceedInitial)){
						var addedText = "";
						if(showExceedFinal && showExceedInitial){
							addedText = "both inital and final device, ";
						}else if(showExceedFinal){
							addedText = "final device, ";
						} else {
							addedText = "initial device, ";
						}
						if(!window.confirm("Creating the links specified will exceed the number of ports on " + addedText + "do you want to proceed?")){
							canSubmit = false;
						}
					}
				}
				
				if(canSubmit){
					$.post('',({'create_connection_after_validation':'', 
						'dc-front': $('#dc-front')[0].value,
						'dc-rear' : $('#dc-rear')[0].value,
						'devid1' : $('#devid1')[0].value,
						'devid2' : $('#devid2')[0].value,
						'port1' : $('#port1')[0].value,
						'port2' : $('#port2')[0].value,
						'numberOfPorts' : $('#numberOfPorts')[0].value})).done(function(data){
							$('#additionalInfo').html(data);
						});
						
						//Reset the form, but keeping the table that was generated.
						$('#rearConnectionForm')[0].reset();
				} 
				$('#btn_crear').removeAttr("disabled");
			});
		});
		
	});
  </script>

</head>
<body>

<?php include( 'header.inc.php' ); ?>
<div class="page">
<?php
	include( 'sidebar.inc.php' );
echo '<div class="main">
<h3>',$status,'</h3>
<div class="center"><div><div>
<form id="rearConnectionForm" action="',$_SERVER["PHP_SELF"],'" method="POST">
<table id=crit_busc>
<tr><td>
<fieldset class=crit_busc>
	<legend>'.__("Initial device").'</legend>
	<div class="table" id="initial">
		<div>
		  	<div><label for="dc-front">',__("Data Center"),'</label></div>
			<div>'.builddclist('dc-front').'</div>
		</div>
	</div>
</fieldset>
</td>
<td>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</td>
<td>
<fieldset class=crit_busc>
	<legend>'.__("Final device").'</legend>
	<div class="table">
		<div>
		  	<div><label for="dc-rear">',__("Data Center"),'</label></div>
			<div>'.builddclist('dc-rear').'</div>
		</div>
	</div>
</fieldset>
</td></tr></table>';
echo '<div style="text-align: center;">';
echo '<button type="button" name="bot_crear" id="btn_crear" value="makepath">',__("Create Connections"),'</button></div>';
echo '</form>';
?>
</div></div>
<?php echo '<br><br><div id="additionalInfo" style="width:100%"></div><br>';
echo '<a href="index.php">[ ',__("Return to Main Menu"),' ]</a>'; ?>
</div><!-- END div.main -->
</div><!-- END div.page -->
<script type="text/javascript">
	$('table#parcheos table tr + tr > td + td:has(table)').css('background-color','transparent');
</script>
</body>
</html>
