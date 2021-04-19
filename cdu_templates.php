<?php
	require_once('db.inc.php');
	require_once('facilities.inc.php');

	$subheader=__("Data Center Cabinet Distribution Unit Templates");

	if(!$person->SiteAdmin){
		// No soup for you.
		header('Location: '.redirect());
		exit;
	}

	$template=new CDUTemplate();
	$manufacturer=new Manufacturer();

	if(isset($_REQUEST['templateid']) && $_REQUEST['templateid'] >0){
		$template->TemplateID=$_REQUEST['templateid'];
		$template->GetTemplate();
	}

	$status='';
	if(isset($_REQUEST['action'])&&(($_REQUEST['action']=='Create')||($_REQUEST['action']=='Update'))){
		$template->ManufacturerID = $_REQUEST['manufacturerid'];
		$template->Model = transform( $_REQUEST['model'] );
		$template->EndofLife = isset($_REQUEST['endoflife'])?1:0;
		$template->Managed = isset($_REQUEST['managed'])?1:0;
		$template->ATS = isset($_REQUEST['ats'])?1:0;
		$template->SNMPVersion = $_REQUEST['snmpversion'];
		$template->VersionOID = $_REQUEST['versionoid'];
		$template->OutletNameOID = $_REQUEST['outletnameoid'];
		$template->OutletDescOID = $_REQUEST['outletdescoid'];
		$template->OutletCountOID = $_REQUEST['outletcountoid'];
		$template->OutletStatusOID = $_REQUEST['outletstatusoid'];
		$template->OutletStatusOn = $_REQUEST['outletstatuson'];
		$template->Multiplier = $_REQUEST['multiplier'];
		$template->OID1 = $_REQUEST['oid1'];
		$template->OID2 = $_REQUEST['oid2'];
		$template->OID3 = $_REQUEST['oid3'];
		$template->ATSStatusOID = $_REQUEST['atsstatusoid'];
		$template->ATSDesiredResult = $_REQUEST['atsdesiredresult'];
		$template->ProcessingProfile = $_REQUEST['processingprofile'];
		$template->Voltage = $_REQUEST["voltage"];
		$template->Amperage = $_REQUEST["amperage"];
		$template->NumOutlets = $_REQUEST["numoutlets"];

		if ( $_REQUEST['action']=='Create' ) {
			$template->CreateTemplate();
		} else {
			$status=__("Updated");
			$template->UpdateTemplate();
		}
	}

	$templateList=$template->GetTemplateList();
	$ManufacturerList=$manufacturer->GetManufacturerList();

	$managed=($template->Managed)?" checked":"";
	$ats=($template->ATS)?" checked":"";
?>
<!doctype html>
<html>
<head>
  <meta http-equiv="X-UA-Compatible" content="IE=Edge">
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
  
  <title>openDCIM Cabinet Distribution Unit Templates</title>
  <link rel="stylesheet" href="css/inventory.php" type="text/css">
  <link rel="stylesheet" href="css/jquery-ui.css" type="text/css">
  <!--[if lt IE 9]>
  <link rel="stylesheet"  href="css/ie.css" type="text/css">
  <![endif]-->
  
  <script type="text/javascript" src="scripts/jquery.min.js"></script>
  <script type="text/javascript" src="scripts/jquery-ui.min.js"></script>
</head>
<body>
<?php include( 'header.inc.php' ); ?>
<div class="page">
<?php
	include( 'sidebar.inc.php' );

echo '<div class="main">
<h3>',$status,'</h3>
<div class="center"><div>
<form method="POST">
<div class="table">
	<div>
		<div><label for="templateid">',__("Template"),'</label></div>
		<div><input type="hidden" name="action" value="query"><select name="templateid" id="templateid" onChange="form.submit()">
		<option value=0>',__("New Template"),'</option>';

	foreach($templateList as $templateRow){
		$manufacturer->ManufacturerID=$templateRow->ManufacturerID;
		$manufacturer->GetManufacturerByID();
		$selected=($template->TemplateID==$templateRow->TemplateID)?' selected':'';
		print "		<option value=\"$templateRow->TemplateID\"$selected>[$manufacturer->Name] $templateRow->Model</option>\n";
	}

echo '	</select></div>
</div>
<div>
	<div><label for="manufacturerid">',__("Manufacturer"),'</label></div>
	<div><select name="manufacturerid" id="manufacturerid">';

	foreach($ManufacturerList as $ManufacturerRow){
		$selected=($template->ManufacturerID==$ManufacturerRow->ManufacturerID)?' selected':'';
		print "		<option value=\"$ManufacturerRow->ManufacturerID\"$selected>$ManufacturerRow->Name</option>\n";
	}

echo '    </select>    
   </div>
</div>
<div>
   <div><label for="model">',__("Model"),'</label></div>
   <div><input type="text" name="model" id="model" value="',$template->Model,'" size=40></div>
</div>
<div>
   <div><label for="endoflife">',__("End of Life"),'</label></div>
   <div><input type="text" class="validate[optional,custom[date]] datepicker" name="endoflife" id="endoflife" value="'.(($template->EndofLife>'0000-00-00 00:00:00')?date('Y-m-d',strtotime($template->EndofLife)):"").'"></div>
</div>
<div>
   <div><label for="managed">',__("Managed"),'</label></div>
   <div>
		<input type="checkbox" name="managed" id="managed"',$managed,'>
   </div>
</div>
<div>
   <div><label for="ats">',__("Automatic Transfer Switch"),'</label></div>
   <div>
		<input type="checkbox" name="ats" id="ats"',$ats,'>
   </div>
</div>
<div>
	<div><label for="snmpversion">',__("SNMP Version"),'</label></div>
	<div><select name="snmpversion" id="snmpversion">';

	$snmpv = array( "1", "2c" );
	foreach ( $snmpv as $unit ) {
		$selected = ( $unit == $template->SNMPVersion ) ? 'selected':'';
		print "\t\t<option value=\"$unit\" $selected>$unit</option>\n";
	}
	
echo '</select>	
	</div>
</div>
<div>
	<div><label for="versionoid">',__("Firmware Version OID"),'</label></div>
	<div><input type="text" name="versionoid" id="versionoid" value="',$template->VersionOID,'" size=40></div>
</div>
<div>
	<div><label for="outletnameoid">',__("Outlet Factory Name")." ".__("OID"),'</label></div>
	<div><input type="text" name="outletnameoid" id="outletnameoid" value="',$template->OutletNameOID,'" size=40></div>
</div>
<div>
	<div><label for="outletdescoid">',__("Outlet User Description")." ".__("OID"),'</label></div>
	<div><input type="text" name="outletdescoid" id="outletdescoid" value="',$template->OutletDescOID,'" size=40></div>
</div>
<div>
	<div><label for="outletcountoid">',__("Outlet Count")." ".__("OID"),'</label></div>
	<div><input type="text" name="outletcountoid" id="outletcountoid" value="',$template->OutletCountOID,'" size=40></div>
</div>
<div>
	<div><label for="outletstatusoid">',__("Outlet State (On/Off)")." ".__("OID"),'</label></div>
	<div><input type="text" name="outletstatusoid" id="outletstatusoid" value="',$template->OutletStatusOID,'" size=40></div>
</div>
<div>
	<div><label for="outletstatuson">',__("Outlet State (On)"),'</label></div>
	<div><input type="text" name="outletstatuson" id="outletstatuson" value="',$template->OutletStatusOn,'" size=40></div>
</div>
<div>
   <div><label for="multiplier">',__("Multiplier"),'</label></div>
   <div><select name="multiplier" id="multiplier">';
   
	$Multi=array("0.01","0.1","1","10","100");
        $mult = 1;

        // Loop to find the template default multiplier, if any
        foreach($Multi as $unit) {
            //$selected = ($unit==$template->Multiplier)?' selected' : '';
            if ($unit == $template->Multiplier) {
                $mult = $template->Multiplier;
                break;
            }
	}
        // Set the "selected" option, using $mult as set above
	foreach($Multi as $unit){
            $selected = ( $unit == $mult ) ? ' selected' : '';
            print "\t\t<option value=\"$unit\"$selected>$unit</option>\n";
        }
	
echo '   </select>
   </div>
</div>
<div>
   <div><label for="oid1">',__("OID1"),'</label></div>
   <div><input type="text" name="oid1" id="oid1" value="',$template->OID1,'" size=40></div>
</div>
<div>
   <div><label for="oid2">',__("OID2"),'</label></div>
   <div><input type="text" name="oid2" id="oid2" value="',$template->OID2,'" size=40></div>
</div>
<div>
   <div><label for="oid3">',__("OID3"),'</label></div>
   <div><input type="text" name="oid3" id="oid3" value="',$template->OID3,'" size=40></div>
</div>
<div>
   <div><label for="atsstatusoid">',__("ATS Status OID"),'</label></div>
   <div><input type="text" name="atsstatusoid" id="atsstatusoid" value="',$template->ATSStatusOID,'" size=40></div>
</div>
<div>
   <div><label for="atsdesiredresult">',__("ATS Desired Result"),'</label></div>
   <div><input type="text" name="atsdesiredresult" id="atsdesiredresult" value="',$template->ATSDesiredResult,'" size=40></div>
</div>
<div>
   <div><label for="processingprofile">',__("Processing Scheme"),'</label></div>
   <div><select name="processingprofile" id="processingprofile">';

	$ProfileList=array("SingleOIDWatts","SingleOIDAmperes","Combine3OIDWatts","Combine3OIDAmperes","Convert3PhAmperes");
	foreach($ProfileList as $prof){
		$selected=($prof == $template->ProcessingProfile)?' selected':'';
		print "<option value=\"$prof\"$selected>$prof</option>";
	}
	
echo '   </select></div>
</div>
<div>
   <div><label for="voltage">',__("Voltage"),'</label></div>
   <div><input type="text" name="voltage" id="voltage" value="',$template->Voltage,'"></div>
</div>
<div>
   <div><label for="amperage">',__("Amperage"),'</label></div>
   <div><input type="text" name="amperage" id="amperage" value="',$template->Amperage,'"></div>
</div>
<div>
   <div><label for="numoutlets">',__("No. of Outlets"),'</label></div>
   <div><input type="text" name="numoutlets" id="numoutlets" value="',$template->NumOutlets,'"></div>
</div>
<div class="caption">';

	if($template->TemplateID >0){
		echo '   <button type="submit" name="action" value="Update">',__("Update"),'</button>';
	}else{
		echo '	 <button type="submit" name="action" value="Create">',__("Create"),'</button>';
	}
?>
</div>
</div><!-- END div.table -->
</form>
</div></div>
<?php echo '<a href="index.php">[ ',__("Return to Main Menu"),' ]</a>'; ?>
</div><!-- END div.main -->
</div><!-- END div.page -->
</body>
</html>
