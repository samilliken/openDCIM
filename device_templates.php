<?php
	require_once('db.inc.php');
	require_once('facilities.inc.php');

	$subheader=__("Data Center Device Templates");

	$timestamp=time();
	$salt=md5('unique_salt' . $timestamp);

	if((isset($_POST['getslots']) || isset($_POST['getports']) || isset($_POST['getpowerports'])) && isset($_POST['TemplateID'])){
		$returndata=array();
		if(isset($_POST['getports']) || isset($_POST['getpowerports'])){
			$tport=(isset($_POST['getports']))?new TemplatePorts():new TemplatePowerPorts();
			$tport->TemplateID=$_POST['TemplateID'];
			$returndata=$tport->GetPorts();
		}else{
			$returndata=Slot::GetAll($_POST['TemplateID']);
		}

		header('Content-Type: application/json');
		echo json_encode($returndata);  
		exit;
	}
	if(isset($_GET['cdutemplate']) || isset($_GET['sensortemplate'])){
		$t=(isset($_GET['cdutemplate']))?new CDUTemplate():new SensorTemplate();
		$t->TemplateID=(isset($_GET['cdutemplate']))?$_GET['cdutemplate']:$_GET['sensortemplate'];
		$t->GetTemplate();

		header('Content-Type: application/json');
		echo json_encode($t);  
		exit;
	}
	// Get list of color codes
	if(isset($_GET['cc'])){
		header('Content-Type: application/json');
		echo json_encode(ColorCoding::GetCodeList());
		exit;
	}
	// Get list of media types
	if(isset($_GET['mt'])){
		header('Content-Type: application/json');
		echo json_encode(MediaTypes::GetMediaTypeList());
		exit;
	}

	if(!$person->WriteAccess){
		// No soup for you.
		header('Location: '.redirect());
		exit;
	}

	$template=new DeviceTemplate();
	$manufacturer=new Manufacturer();

	if(isset($_POST['deleteme'])){
		$template->TemplateID=$_POST['TemplateID'];
		if($template->GetTemplateByID()){
			// First deal with the case that we are transferring
			if($template->TemplateID!=$_POST['transferid'] && $_POST['transferid']!=0){
				// We should do this in bulk,  this has potential to be a real time sink
				foreach(Device::GetDevicesByTemplate($template->TemplateID) as $dev){
					$dev->TemplateID=$_POST['transferid'];
					$dev->UpdateDevice();
				}
			}
			// Transfers are done, delete this shit
			$template->DeleteTemplate();
		}
		echo '1';
		exit;
	}

	$status='';

	if(isset($_FILES['templateFile']) && $_FILES['templateFile']['error']==0 && $_FILES['templateFile']['type']='text/xml'){
		$result=$template->ImportTemplate($_FILES['templateFile']['tmp_name']);
		$status=($result["status"]=="")?__("Template File Imported"):$result["status"].'<a id="import_err" style="margin-left: 1em;" title="'.__("View errors").'" href="#"><img src="images/info.png"></a>';
	}
	
	if(isset($_REQUEST['TemplateID']) && $_REQUEST['TemplateID'] >0){
		//get template
		$template->TemplateID=$_REQUEST['TemplateID'];
		$template->GetTemplateByID();
		$deviceList = Device::GetDevicesByTemplate( $template->TemplateID );
	}
	
	if(isset($_POST['action'])){
		$template->ManufacturerID=$_POST['ManufacturerID'];
		$template->Model=transform($_POST['Model']);
		$template->EndofLife=$_POST['EndofLife'];
		$template->Height=$_POST['Height'];
		$template->Weight=$_POST['Weight'];
		$template->Wattage=(isset($_POST['Wattage']))?$_POST['Wattage']:0;
		$template->DeviceType=$_POST['DeviceType'];
		$template->PSCount=$_POST['PSCount'];
		$template->NumPorts=$_POST['NumPorts'];
		$template->ShareToRepo=isset( $_POST['ShareToRepo'] ) ? 1 : 0;
		$template->KeepLocal=isset( $_POST['KeepLocal'] ) ? 1 : 0;
		$template->Notes=trim($_POST['Notes']);
		$template->Notes=($template->Notes=="<br>")?"":$template->Notes;
		$template->FrontPictureFile=$_POST['FrontPictureFile'];
		$template->RearPictureFile=$_POST['RearPictureFile'];
		$template->ChassisSlots=($template->DeviceType=="Chassis")?$_POST['ChassisSlots']:0;
		$template->RearChassisSlots=($template->DeviceType=="Chassis")?$_POST['RearChassisSlots']:0;
		$template->SNMPVersion=$_POST['SNMPVersion'];

		function UpdateSlotsPorts($template,$status){
			//Update slots
			$template->DeleteSlots();
			for ($i=1; $i<=$template->ChassisSlots;$i++){
				$slot=new Slot();
				$slot->TemplateID=$template->TemplateID;
				$slot->Position=$i;
				$slot->BackSide=False;
				$slot->X=isset($_POST["XF".$i])?$_POST["XF".$i]:0;
				$slot->Y=isset($_POST["YF".$i])?$_POST["YF".$i]:0;
				$slot->W=isset($_POST["WF".$i])?$_POST["WF".$i]:0;
				$slot->H=isset($_POST["HF".$i])?$_POST["HF".$i]:0;
				$status=($slot->CreateSlot())?$status:__("Error updating front slots");
			}
			for ($i=1; $i<=$template->RearChassisSlots;$i++){
				$slot=new Slot();
				$slot->TemplateID=$template->TemplateID;
				$slot->Position=$i;
				$slot->BackSide=True;
				$slot->X=isset($_POST["XR".$i])?$_POST["XR".$i]:0;
				$slot->Y=isset($_POST["YR".$i])?$_POST["YR".$i]:0;
				$slot->W=isset($_POST["WR".$i])?$_POST["WR".$i]:0;
				$slot->H=isset($_POST["HR".$i])?$_POST["HR".$i]:0;
				$status=($slot->CreateSlot())?$status:__("Error updating rear slots");
			}
			//update template ports
			$template->DeletePorts();
			for ($i=1; $i<=$template->NumPorts;$i++){
				$tport=new TemplatePorts();
				$tport->TemplateID=$template->TemplateID;
				$tport->PortNumber=$i;
				$tport->Label=isset($_POST["label".$i])?$_POST["label".$i]:"";
				$tport->MediaID=(isset($_POST["mt".$i]) && $_POST["mt".$i]>0)?$_POST["mt".$i]:0;
				$tport->ColorID=(isset($_POST["cc".$i]) && $_POST["cc".$i]>0)?$_POST["cc".$i]:0;
				$tport->Notes=isset($_POST["portnotes".$i])?$_POST["portnotes".$i]:"";
				$status=($tport->CreatePort())?$status:__("Error updating template ports");
			}
			$template->DeletePowerPorts();
			//update template power connections
			for ($i=1; $i<=$template->PSCount;$i++){
				$tport=new TemplatePowerPorts();
				$tport->TemplateID=$template->TemplateID;
				$tport->PortNumber=$i;
				$tport->Label=isset($_POST["powerlabel".$i])?$_POST["powerlabel".$i]:"";
				$tport->PortNotes=isset($_POST["powerportnotes".$i])?$_POST["powerportnotes".$i]:"";
				$status=($tport->CreatePort())?$status:__("Error updating template power connections");
			}
			return $status;
		}

		function UpdateCustomValues($template,$status) {
			/* Note: if a user changes the value or required status of an attribute that is not "all devices" and does not
				enable it on the device_templates screen, it won't update here. it is a weird ui experience, but it
				seems like the proper functionality since we aren't saving data for anything that isn't enabled */
			$dcaList=DeviceCustomAttribute::GetDeviceCustomAttributeList();
			$template->DeleteCustomValues();
			if ( isset( $_POST['tdca'] ) && is_array( $_POST['tdca'] ) ) {
				foreach($_POST["tdca"] as $dcaid=>$currentdca) {
					if((isset($currentdca["enabled"]) && $currentdca["enabled"]==="on")) {
						$insertval = '';
						$requiredval = 0;
						if(isset($currentdca["value"]) && trim($currentdca["value"] != '')) {
							$insertval = trim($currentdca["value"]);
						}
						if(isset($currentdca["required"]) && $currentdca["required"] == "on") {
							$requiredval = 1;
						}
						$status=($template->InsertCustomValue($dcaid, $insertval,$requiredval))?$status:__("Error updating device template custom values");
					} elseif(array_key_exists($dcaid, $dcaList) && $dcaList[$dcaid]->AllDevices==1) {
					/* since the enabled checkbox for attributes marked as "all devices" is disabled, it doesn't get passed in with the form,
						so parse through and if the value or required status are different than the defaults, add a row for them as well. this
						helps keep the table clean of a bunch of default values too */
						$insertval = $dcaList[$dcaid]->DefaultValue;
						$requiredval = $dcaList[$dcaid]->Required;
						// don't check if the value is empty - this lets us overwrite a default value from the config page with empty here
						if(isset($currentdca["value"])) {
							$insertval = trim($currentdca["value"]);
						}
						if(isset($currentdca["required"]) && $currentdca["required"] == "on") {
							$requiredval = 1;
						}
						if(($insertval != $dcaList[$dcaid]->DefaultValue) || ($requiredval != $dcaList[$dcaid]->Required)) {
							$status=($template->InsertCustomValue($dcaid, $insertval, $requiredval))?$status:__("Error updating device template custom values");
						}
					}
				}
			}
			return $status;
		}

		function updatesensor($template,$status){
			$sensortemplate=new SensorTemplate();
			$sensortemplate->TemplateID=$template->TemplateID;
			$sensortemplate->ManufacturerID=$template->ManufacturerID;
			$sensortemplate->Model=$template->Model;
			$sensortemplate->EndofLife=$template->EndofLife;
			$sensortemplate->TemperatureOID=$_POST['TemperatureOID'];
			$sensortemplate->HumidityOID=$_POST['HumidityOID'];
			$sensortemplate->TempMultiplier=$_POST['TempMultiplier'];
			$sensortemplate->HumidityMultiplier=$_POST['HumidityMultiplier'];
			$sensortemplate->mUnits=$_POST['mUnits'];
			$status=($sensortemplate->UpdateTemplate())?$status:__("Error updating cdu attributes");
			return $status;
		}

		function updatecdu($template,$status){
			$cdutemplate=new CDUTemplate();
			$cdutemplate->TemplateID=$template->TemplateID;
			$cdutemplate->ManufacturerID=$template->ManufacturerID;
			$cdutemplate->Model=$template->Model;
			$cdutemplate->EndofLife=$template->EndofLife;
			$cdutemplate->Managed=isset($_POST['Managed'])?1:0;
			$cdutemplate->ATS=isset($_POST['ATS'])?1:0;
			$cdutemplate->VersionOID=$_POST['VersionOID'];
			$cdutemplate->OutletNameOID=$_POST['OutletNameOID'];
			$cdutemplate->OutletDescOID=$_POST['OutletDescOID'];
			$cdutemplate->OutletCountOID=$_POST['OutletCountOID'];
			$cdutemplate->OutletStatusOID=$_POST['OutletStatusOID'];
			$cdutemplate->OutletStatusOn=$_POST['OutletStatusOn'];
			$cdutemplate->Multiplier=$_POST['Multiplier'];
			$cdutemplate->OID1=$_POST['OID1'];
			$cdutemplate->OID2=$_POST['OID2'];
			$cdutemplate->OID3=$_POST['OID3'];
			$cdutemplate->ATSStatusOID=$_POST['ATSStatusOID'];
			$cdutemplate->ATSDesiredResult=$_POST['ATSDesiredResult'];
			$cdutemplate->ProcessingProfile=$_POST['ProcessingProfile'];
			$cdutemplate->Voltage=$_POST["Voltage"];
			$cdutemplate->Amperage=$_POST["Amperage"];
			$cdutemplate->NumOutlets=$template->PSCount;
			$status=($cdutemplate->UpdateTemplate())?$status:__("Error updating cdu attributes");

			return $status;
		}

		switch($_POST['action']){
			case 'Create':
				if($template->CreateTemplate()){
					$oldstatus=$status;
					$status=UpdateSlotsPorts($template,$status);
					if($oldstatus==$status){
						$status=UpdateCustomValues($template,$status);
					}
					if($oldstatus==$status){
						$status=updatecdu($template,$status);
						$status=updatesensor($template,$status);
					}
				}else{
					$status=__("An error has occured, template not created");
				}
				break;
			case 'Update':
				$status=($template->UpdateTemplate())?__("Updated"):__("Error updating template");
				if($status==__("Updated")){
					$status=UpdateSlotsPorts($template,$status);
				}
				if($status==__("Updated")){
					$status=UpdateCustomValues($template,$status);
				}
				if($status==__("Updated")){
					$status=updatecdu($template,$status);
					$status=updatesensor($template,$status);
				}
				break;
			case 'Device':
				// someone will inevitibly try to update the template values and just click
				// update devices so make sure the template will update before trying to
				// update the device values to match the template
				$status=($template->UpdateTemplate())?__("Updated"):__("Error updating template");
				if ($status==__("Updated")){
					$status=UpdateSlotsPorts($template,$status);
				}
				if ($status==__("Updated")){
					$status=UpdateCustomValues($template,$status);
				}
				if($status==__("Updated")){
					$status=updatecdu($template,$status);
					$status=updatesensor($template,$status);
				}
				if($status==__("Updated")){
					$status=($template->UpdateDevices())?__("Updated"):__("Error updating devices");
				}
				break;
			case 'Export':
				//update template before export
				$status=($template->UpdateTemplate())?__("Updated"):__("Error");
				if ($status==__("Updated")){
					$status=UpdateSlotsPorts($template,$status);
				}
				if ($status==__("Updated")){
					$status=UpdateCustomValues($template,$status);
				}
				if($status==__("Updated")){
					$status=updatecdu($template,$status);
					$status=updatesensor($template,$status);
				}
				if($status==__("Updated")){
					$status=($template->ExportTemplate())?__("Exported"):__("Error");
				}
				break;
			default:
				// do nothing if the value isn't one we expect
		}
	}

	$templateList=$template->GetTemplateList();
	$ManufacturerList=$manufacturer->GetManufacturerList();
	$ManufacturerListByID=$manufacturer->GetManufacturerList(true);
	$mtList=MediaTypes::GetMediaTypeList();
	$ccList=ColorCoding::GetCodeList();
	$dcaList=DeviceCustomAttribute::GetDeviceCustomAttributeList();

	$imageselect='<div id="preview"></div><div id="filelist">';

	$path=$config->ParameterArray['picturepath'];
	$dir=scandir($path);
	foreach($dir as $i => $f){
		if(is_file($path.DIRECTORY_SEPARATOR.$f)){
			// Suppress the getimagesize because it will error out on non-image files
			$mimeType=mime_content_type($path.DIRECTORY_SEPARATOR.$f);
			if(preg_match('/^image/i', $mimeType)){
				$imageselect.="<span>$f</span>\n";
			}
		}
	}
	$imageselect.="</div>";

	
?>
<!doctype html>
<html>
<head>
  <meta http-equiv="X-UA-Compatible" content="IE=Edge">
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
  
  <title>openDCIM Device Class Templates</title>
  <link rel="stylesheet" href="css/inventory.php" type="text/css">
  <link rel="stylesheet" href="css/jquery-ui.css" type="text/css">
  <link rel="stylesheet" href="css/imgareaselect-default.css" type="text/css">
  <link rel="stylesheet" href="css/validationEngine.jquery.css" type="text/css">
  <link rel="stylesheet" href="css/jquery-te-1.4.0.css" type="text/css">
  <link rel="stylesheet" href="css/uploadifive.css" type="text/css">
  <!--[if lt IE 9]>
  <link rel="stylesheet"  href="css/ie.css" type="text/css">
  <![endif]-->
  
  <style type="text/css">
	#deviceimages .center > div { border-width: 1px; border-style: solid; }
  </style>

  <script type="text/javascript" src="scripts/jquery.min.js"></script>
  <script type="text/javascript" src="scripts/jquery-ui.min.js"></script>
  <script type="text/javascript" src="scripts/jquery.uploadifive.js"></script>
  <script type="text/javascript" src="scripts/jquery.validationEngine-en.js"></script>
  <script type="text/javascript" src="scripts/jquery.validationEngine.js"></script>
  <script type="text/javascript" src="scripts/jquery-te-1.4.0.min.js"></script>
  <script type="text/javascript" src="scripts/jquery.textext.js"></script>
  <script type="text/javascript" src="scripts/jquery.imgareaselect.pack.js"></script>
  <script type="text/javascript" src="scripts/common.js?v<?php echo filemtime('scripts/common.js');?>"></script>
  <script type="text/javascript">
	timestamp="<?php echo $timestamp; ?>";
	token="<?php echo $salt; ?>";
	$(document).ready(function(){
		$('#TemplateID').change(function(e){
			location.href='device_templates.php?TemplateID='+this.value;
		});

		function newtab(e){
			var poopup=window.open('search.php?key=dev&TemplateID='+$('#TemplateID').val()+'&search','search');
			poopup.focus();
		}
		$('#templatecount').css({'cursor':'pointer','text-decoration':'underline'}).click(newtab);

		var oModel=$('#Model').val();
		var chgmsg="<?php echo __("This value must be different than"); ?>"+" "+oModel;
		$('#deviceform').validationEngine();
		$('#Model').change(function(){
			if($('#Model').val()==oModel){
				setTimeout(function(){
					$('#Model').validationEngine('showPrompt',chgmsg,'','',true);
				},500);
			}
		});
		$('#EndofLife').datepicker({dateFormat: "yy-mm-dd"});
        
		$('#clone').click(function(){
			$('#TemplateID').val(0);
			$('.caption button:not([value="Update"])').remove();
			$('button[name="action"][value="Update"]').val('Create').text("<?php echo __("Create");?>");
			$('#Model').trigger('change');

			// Flag all ports and slots as changed so they will retain their data
			$('#deviceform [id^="hidden"] .table > div ~ div').data('change',true);
		});

		$('#FrontPictureFile,#RearPictureFile').click(function(){
			var upload=$('<input>').prop({type: 'file', name: 'dev_file_upload', id: 'dev_file_upload'}).data('dir','images');
			var input=this;
			var originalvalue=this.value;
			$("#imageselection").dialog({
				resizable: false,
				height:500,
				width: 670,
				modal: true,
				buttons: {
<?php echo '					',__("Select"),': function() {'; ?>
						if($('#imageselection #preview').attr('image')!=""){
							$(input).val($('#imageselection #preview').attr('image')).trigger('change');
						}
						$(this).dialog("destroy");
					}
				},
				close: function(){
						// they clicked the x, set the value back if something was uploaded
						input.value=originalvalue;
						$(this).dialog("destroy");
					}
			}).data('input',input);
			reload();
			$("#imageselection").next('div').prepend(upload);
			uploadifive();
		});  

		$('#DeviceType').change(function(){
			if($('#DeviceType').val()=="Chassis"){
				$('#DivChassisSlots').css({'display': 'table-row'});
				$('#DivRearChassisSlots').css({'display': 'table-row'});
			}else{
				$('#DivChassisSlots').css({'display': 'none'});
				$('#DivRearChassisSlots').css({'display': 'none'});
			}
			if($('#DeviceType').val()=="CDU"){
				$('#Wattage').parent('div').parent('div').addClass('hide');
				$('#hiddencdudata').removeClass('hide').show();
				buildcdutable();
			}else{
				$('#Wattage').parent('div').parent('div').removeClass('hide');
				$('#hiddencdudata').hide();
			}
			if($('#DeviceType').val()=="Sensor"){
				$('#hiddensensordata').removeClass('hide').show();
				buildsensortable();
			}else{
				$('#hiddensensordata').hide();
			}
			(function hidedisclaimer(){
				if($('#DeviceType').val()=="CDU" || $('#DeviceType').val()=="Sensor"){
					$('.cdudisclaimer').removeClass('hide').show();
				}else{
					$('.cdudisclaimer').hide();
				}
			})();	
		}).change();

		$( "#importButton" ).click(function() {
			$("#dlg_importfile").dialog({
				resizable: false,
				width: 400,
				height: 200,
				modal: true,					
				buttons: {	
					<?php echo __("Import");?>: function() {  			
						//  Llamamos al Formulario 
						$('#frmImport').submit();
					},
					<?php echo __("Cancel");?>: function() {  							     				       
					    $("#dlg_importfile").dialog("close");
					}
				}
			});
		});

		$( "#import_err" ).click(function() {
			$("#dlg_import_err").dialog({
				resizable: false,
				width: 500,
				height: 400,
				modal: true,					
				buttons: {	
					<?php echo __("Close");?>: function() {  							     				       
					    $("#dlg_import_err").dialog("close");  								   
					}
				}
			});
		});

		FetchSlots();
		TemplateButtons();
		$('.templatemaker input[id*=Chassis] + button').each(function(){
			var front=($(this).prev('input').attr('id')=='ChassisSlots')?true:false;
			InsertCoordsTable(front,$(this));
			$(this).on('click',function(){
				FetchSlots();

				// Fill in the coords table
				InsertCoordsTable(front,$(this));

				// Draw the slots on the preview image
				drawSlots();

				// Open the dialog
				Poopup(front);
			});
		});

		buildportstable();
		$('.templatemaker input#NumPorts + button').each(function(){
			$(this).on('click',function(){
				// Fill in the ports table
				buildportstable();

				var generateportnames=$('<select>').append($('<option>'));
				$.get('scripts/ajax_portnames.php').done(function(data){
					$.each(data, function(key,spn){
						var option=$("<option>",({'value':spn.Pattern})).append(spn.Pattern.replace('(1)','x'));
						generateportnames.append(option);
					});
					generateportnames.append($("<option>",({'value':'custom'})).text("<?php echo __("Custom")?>"));
					generateportnames.append($("<option>",({'value':'invert'})).text("<?php echo __("Invert Port Labels")?>"));
				});

				generateportnames.on('change',function(e){
					var inputs=$(e.currentTarget.parentElement.parentElement.parentElement).find('> div > div:nth-child(2) > input');
					var portnames=[];
					if(e.currentTarget.value=='invert'){
						inputs_rev=inputs.get().reverse();
						portnames.push(null);
						for (i = 0; i < inputs_rev.length; i++) {
							portnames.push(inputs_rev[i].value);
						}
					}else if(e.currentTarget.value=='custom'){
						var dialog=$('<div />', {id: 'modal', title: 'Custom port pattern'}).html('<div id="modaltext"></div><br><div id="modalstatus"></div>');
						dialog.find('#modalstatus').prepend('<p>Custom pattern: <input></input></p><p><a href="http://opendcim.org/wiki/index.php?title=NetworkConnections#Custom_Port_Name_Generator_Example_Patterns" target=_blank>Pattern Examples</a></p>');
						dialog.dialog({
							resizable: false,
							modal: true,
							dialogClass: "no-close",
							buttons: {
								OK: function(){
									// can't use .get because of the async
									$.ajax({type:'get',url:'scripts/ajax_portnames.php',async:false,data: {pattern:$('#modalstatus input').val(),count:$('#NumPorts').val()}}).done(function(data){
										portnames=data;
										applynames(inputs,portnames,e);
									});
									$(this).dialog("destroy");
								},
								Cancel: function(){
									$(this).dialog("destroy");
								}
							}
						});
					}else{
						// can't use .get because of the async
						$.ajax({type:'get',url:'scripts/ajax_portnames.php',async:false,data: {pattern:e.currentTarget.value,count:$('#NumPorts').val()}}).done(function(data){
							portnames=data;
						});
					}
					function applynames(inputs,portnames,e){
						// Use the port names we came with above to apply to the screen
						if(portnames.length > $('#NumPorts').val()){
							for (i = 1; i < portnames.length; i++) {
								inputs.trigger('change')[i-1].value=portnames[i];
							} 
						}
						e.currentTarget.value='';
					}
					applynames(inputs,portnames,e);
				});

				// Add mass edit controls
				$('#hiddenports > div.table > div:first-child > div:nth-child(2)').css('position','relative').append(generateportnames.css({'background-color':'transparent','border':'0 none','position':'absolute','width':'auto','top':0,'right':0}));

				// Open the dialog
				PortsPoopup();
			});
		});

		buildpowerportstable();
		$('.templatemaker input#PSCount + button').each(function(){
			$(this).on('click',function(){
				// Fill in the ports table
				buildpowerportstable();

				// Open the dialog
				PowerPortsPoopup();
			});
		});


		$('#FrontPictureFile,#RearPictureFile').change(function(){
			var fp_file=$('#FrontPictureFile');
			var fp_img=$('#img_FrontPictureFile');
			var rp_file=$('#RearPictureFile');
			var rp_img=$('#img_RearPictureFile');

			if(fp_file.val() && rp_file.val()){
				$('#rightside').css('min-width','260px');
				// Resize images
				$('#deviceimages img').css('max-width',$('#rightside').innerWidth()/2);
			}else if(fp_file.val() || rp_file.val()){
				$('#rightside').css('min-width','130px');
				// Resize images
				$('#deviceimages img').css('max-width',$('#rightside').innerWidth());
			}

			fp_img.prop('src',"<?php echo $config->ParameterArray['picturepath'];?>"+fp_file.val());
			rp_img.prop('src',"<?php echo $config->ParameterArray['picturepath'];?>"+rp_file.val());
			if(fp_file.val()){
				fp_img.parent('div').show();
			}else{
				fp_img.parent('div').hide();
			}
			if(rp_file.val()){
				rp_img.parent('div').show();
			}else{
				rp_img.parent('div').hide();
			}
			resize();
			drawSlots();
		}).trigger('change');

		$('#TemplateID').combobox();
		$('#ManufacturerID').combobox();

		// fuckin layout bug
		$('span.custom-combobox').width($('span.custom-combobox').width()+2);
		$('.templatemaker input[type=checkbox]').css('margin','2px');

		$('#FrontPictureFile,#RearPictureFile,#ChassisSlots,#RearChassisSlots,#NumPorts,#PSCount').on('change keyup keydown', function(){ TemplateButtons(); });


	$('#delete').on('click',function(){
		// setup shit we might reuse
		var dlg_content=$('<div>');
		var dlg_select=$('<select>').append($('<option>').text('No').val('false')).append($('<option>').text('Yes').val('true'));
		var dlg_transfer=$('<div>');

		// make the dialog
		dlg_content.append($('<p>').append("There is no undo").addClass('warning'));
		dlg_content.append($('<label>').html("Would you like to transfer all devices using this template to another template?&nbsp;&nbsp;"));
		dlg_content.append(dlg_select);
		dlg_content.append(dlg_transfer);

		var TemplateID2;

		// logic for dealing someone saying yes they want to swap to another template
		dlg_select.on('change',function(){
			// Clone the existing device template list
			TemplateID2=$('#TemplateID').clone().prop('id','TemplateID2').removeAttr('onchange').removeAttr('style');
			// Remove the 'New Template' option
			TemplateID2.find('option:first-child').remove();
			// Remove the currently selected template as a valid option
			TemplateID2.find('option[value='+TemplateID2.val()+']').remove();


			if(eval(this.value)){
				dlg_transfer.append('<br><br>');
				dlg_transfer.append($('<label>').prop('for','TemplateID2').html("Transfer all devices using this template to:&nbsp;&nbsp;"));
				dlg_transfer.append(TemplateID2);
			}else{
				dlg_transfer.html('');
			}

		});

		var dialog=$('<div>').attr('title',"Are you sure you want to delete this template?").html(dlg_content);
			dialog.dialog({
				closeOnEscape: false,
				minHeight: 250,
				width: 840,
				modal: true,
				resizable: false,
				position: { my: "center", at: "top", of: window },
				show: { effect: "blind", duration: 800 },
				beforeClose: function(event,ui){
				},
				buttons: {
					Yes: function(){
						$.post('',{TemplateID: $('#TemplateID').val(), transferid: ((typeof TemplateID2=='undefined')?0:TemplateID2.val()), deleteme: ''}).done(function(data){
							if(data.trim()==1){
								dialog.dialog("destroy");
								// seems like a good idea to direct them to the new template
								// rather than just a new template
								$('#TemplateID').val((typeof TemplateID2=='undefined')?0:TemplateID2.val()).trigger('change');
							}else{
								alert("something is broken");
							}
						});
					},
					No: function(){
						$(this).dialog("destroy");
					}
				}
			});
	});

		// Add some logic to the enabled / required checkboxes so users aren't confused

		// An attribute cannot be required if it isn't enabled so make sure that 
		// required is turned off when enabled is
		$('input[name*=enabled]').on('click',function(e){
			if(!e.currentTarget.checked){
				e.currentTarget.nextElementSibling.checked=false;
			}
		});
		// if a user clicks required it has to be enabled for that to be valid
		// this will make sure that the enabled box is ticked
		$('input[name*=required]').on('click',function(e){
			if(e.currentTarget.checked){
				e.currentTarget.previousElementSibling.checked=true;
			}
		});


	});/* END of $(document).ready function() */
</script>
</head>
<body>
<?php include( 'header.inc.php' ); ?>
<div class="page">
<?php
	include( 'sidebar.inc.php' );

echo '<div class="main">
<div class="templatemaker">
<h3>',$status,'</h3>
<div class="center"><div>
<form id="deviceform" method="POST">
<div class="table">
	<div>
		<div class="left"><div id="regulartemplateattributes">
<!-- Left side of device template -->
<div class="table">
	<div>
		<div><label for="TemplateID">',__("Template"),'</label></div>
		<div><input type="hidden"><select name="TemplateID" id="TemplateID">
		<option value=0>',__("New Template"),'</option>';

	foreach($templateList as $templateRow){
		$selected=($template->TemplateID==$templateRow->TemplateID)?" selected":"";
		print "		<option value=\"$templateRow->TemplateID\"$selected>[{$ManufacturerListByID[$templateRow->ManufacturerID]->Name}] $templateRow->Model</option>\n";
	}

echo '	</select></div>
</div>
<div>
	<div><label for="ManufacturerID">',__("Manufacturer"),'</label></div>
	<div><select name="ManufacturerID" id="ManufacturerID">';

	foreach($ManufacturerList as $ManufacturerRow){
		if($template->ManufacturerID==$ManufacturerRow->ManufacturerID){$selected=" selected";}else{$selected="";}
		print "		<option value=\"$ManufacturerRow->ManufacturerID\"$selected>$ManufacturerRow->Name</option>\n";
	}

echo '    </select>    
   </div>
</div>
<div>
   <div><label for="Model">',__("Model"),'</label></div>
   <div><input type="text" name="Model" id="Model" class="validate[required]" value="',$template->Model,'"></div>
</div>
<div>
   <div><label for="EndofLife">',__("End of Life"),'</label></div>
   <div><input type="text" class="validate[optional,custom[date]] datepicker" name="EndofLife" id="EndofLife" value="'.(($template->EndofLife>'0000-00-00 00:00:00')?date('Y-m-d',strtotime($template->EndofLife)):"").'"></div>
</div>
<div>
   <div><label for="Height">',__("Height"),'</label></div>
   <div><input type="text" name="Height" id="Height" value="',$template->Height,'">&nbsp;&nbsp;<span class="cdudisclaimer sensordisclaimer hide">',__("0 for a vertical mounting"),'</span></div>
</div>
<div>
   <div><label for="Weight">',__("Weight"),'</label></div>
   <div><input type="text" name="Weight" id="Weight" value="',$template->Weight,'"></div>
</div>
<div>
   <div><label for="Wattage">',__("Wattage"),'</label></div>
   <div><input type="text" name="Wattage" id="Wattage" value="',$template->Wattage,'"></div>
</div>
<div>
   <div><label for="DeviceType">',__("Device Type"),'</label></div>
   <div><select name="DeviceType" id="DeviceType">';

	foreach(array('Server','Appliance','Storage Array','Switch','Chassis','Patch Panel','Physical Infrastructure','CDU','Sensor') as $DevType){
		if($DevType==$template->DeviceType){$selected=" selected";}else{$selected="";}
		print "		<option value=\"$DevType\"$selected>$DevType</option>\n";
	}

echo '	</select>
   </div>
</div>
<div>
   <div><label for="PSCount">',__("No. Power Connections"),'</label></div>
   <div><input type="text" name="PSCount" id="PSCount" value="',$template->PSCount,'"><button type="button">',__("Edit Ports"),'</button></div>
</div>
<div>
   <div><label for="NumPorts">',__("No. Ports"),'</label></div>
   <div><input type="text" name="NumPorts" id="NumPorts" value="',$template->NumPorts,'"><button type="button">',__("Edit Ports"),'</button></div>
</div>
<div>
	<div><label for="SNMPVersion">',__("SNMP Version"),'</label></div>
	<div><select name="SNMPVersion" id="SNMPVersion">';

	$snmpv=array("1","2c", "3");
	foreach($snmpv as $unit){
		if ( $template->SNMPVersion == $unit ) {
			$selected = "selected";
		} else {
			$selected = "";
		}
		print "\t\t<option value=\"$unit\" $selected>$unit</option>\n";
	}
	
echo '</select>	
	</div>
</div>

<div>
   <div><label for="FrontPictureFile">',__("Front Picture File"),'</label></div>
   <div><input type="text" name="FrontPictureFile" id="FrontPictureFile" value="',$template->FrontPictureFile,'"></div>
</div>
<div>
   <div><label for="RearPictureFile">',__("Rear Picture File"),'</label></div>
   <div><input type="text" name="RearPictureFile" id="RearPictureFile" value="',$template->RearPictureFile,'"></div>
</div>
<div id="DivChassisSlots" style="display: ',(($template->DeviceType=="Chassis")?'table-row':'none'),';">
   <div><label for="ChassisSlots">',__("Chassis Slots"),'</label></div>
   <div><input type="text" name="ChassisSlots" id="ChassisSlots" value="',$template->ChassisSlots,'"><button type="button">',__("Edit Coordinates"),'</button></div>
</div>
<div id="DivRearChassisSlots" style="display: ',(($template->DeviceType=="Chassis")?'table-row':'none'),';">
   <div><label for="RearChassisSlots">',__("Rear Chassis Slots"),'</label></div>
   <div><input type="text" name="RearChassisSlots" id="RearChassisSlots" value="',$template->RearChassisSlots,'"><button type="button">',__("Edit Coordinates"),'</button></div>
</div>';
foreach($dcaList as $dca) {
	$templatedcaChecked = "";
	$templatedcaDisabled = "";
	$templatedcaValue = "";
	$templatedcaRequired = "";
	if($dca->AllDevices) {
		$templatedcaChecked=" checked";
		$templatedcaDisabled=" disabled";
	}
	//TODO the ui seems weird if the default value gets set and this row isn't enabled
	if($dca->DefaultValue != '') {
		$templatedcaValue=$dca->DefaultValue;
	}
	if($dca->Required == 1) {
		$templatedcaRequired = " checked";
	}
	// this check goes after the all devices check so that the template value overwrites any system-wide default value
	if(isset($template->CustomValues) && array_key_exists($dca->AttributeID, $template->CustomValues)){
		$templatedcaChecked=" checked";
		$templatedcaValue=$template->CustomValues[$dca->AttributeID]["value"];
		$templatedcaRequired=($template->CustomValues[$dca->AttributeID]["required"]==1)?" checked":"";
	}
	echo '<div>
		<div>',$dca->Label,'</div>';
	echo '<div>';
	if($dca->AttributeType=="checkbox") {
		$checked="";
		if($templatedcaValue=="1" || $templatedcaValue=="on"){
			$checked=" checked";
		}
		echo __("Default:").' <input type="checkbox" name="tdca[',$dca->AttributeID,'][value]" ',$checked,'>';
	} else if ($dca->AttributeType=="set") {
		echo '<select name="tdca[',$dca->AttributeID,'][value]">';
		foreach(explode(',',$dca->DefaultValue) as $dcaValue){
			$selected=($templatedcaValue==$dcaValue)?' selected':'';
			print "\n\t<option value=\"$dcaValue\"$selected>$dcaValue</option>";
		}
		echo '</select>';
	} else {
		$validation="";
		if($templatedcaChecked != "" && $dca->AttributeType!="string" && $dca->AttributeType!="checked") {
			$validation=' class="validate[custom['.$dca->AttributeType.']]"';
		}
		echo '<input type="text" name="tdca[',$dca->AttributeID,'][value]"',$validation,' id="tdca[',$dca->AttributeID,'][value]" value="',$templatedcaValue,'">';
		echo '<button type="button" name=tdca[',$dca->AttributeID,'][revert]" id="tdca[',$dca->AttributeID,'][revert]" title="'.__("Revert to default value from configuration page").'" data-val="',$dca->DefaultValue,'" data-id="',$dca->AttributeID,'">'.__("Revert").'</button>';
		echo '<script>
			$("#tdca\\\[',$dca->AttributeID,'\\\]\\\[revert\\\]").click(function(){
				var defaultVal = this.getAttribute("data-val");
				var id = this.getAttribute("data-id");
				var inputid = "tdca\\\["+id+"\\\]\\\[value\\\]";
				$("#"+inputid).val(defaultVal);
			});
			</script>';
	}
	echo __("Enabled?").'<input type="checkbox" name="tdca[',$dca->AttributeID,'][enabled]" id="tdca[',$dca->AttributeID,'][enabled]" data=',$dca->AttributeID,' title="'.__("Enabled?").'"',$templatedcaChecked,$templatedcaDisabled,'>
		'.__("Required?").'<input type="checkbox" name="tdca[',$dca->AttributeID,'][required]" title="'.__("Required?").'"',$templatedcaRequired,'>
		<input type="hidden" name="tdca[',$dca->AttributeID,'][attributetype]" id="tdca[',$dca->AttributeID,'][attributetype]" value="',$dca->AttributeType,'">
		<script>
			$("#tdca\\\[',$dca->AttributeID,'\\\]\\\[enabled\\\]").click(function(){
				var id = this.getAttribute("data");
				var typeid = "tdca\\\["+id+"\\\]\\\[attributetype\\\]";
				var inputid = "tdca\\\["+id+"\\\]\\\[value\\\]";
				var type = $("#"+typeid).val();
				if(this.checked){
					$("#"+inputid).removeClass();
					if(type!="checkbox" && type!="string" && type!="set"){
						$("#"+inputid).addClass("validate[custom["+type+"]]");
					}
				} else {
					$("#"+inputid).removeClass();
				}
			});
		</script>
		</div>
	</div>';
}

if ( $template->TemplateID > 0 && isset( $deviceList ) ) {
	echo '
<div>
	<div>&nbsp;</div>
	<div id="templatecount">' . __("Number of Devices Using This Template:") . ' ' . sizeof( $deviceList ) . '</div>
</div>';
}
	echo '
<div>
   <div><label for="Notes">',__("Notes"),'</label></div>
   <div><textarea name="Notes" id="Notes" cols="40" rows="8">',$template->Notes,'</textarea></div>
</div>
<div class="caption">';

	if($template->TemplateID >0){
		echo '   <button type="submit" name="action" value="Update">',__("Update Template"),'</button><button type="button" id="clone">',__("Clone Template"),'</button><button id="delete" type="button">',__("Delete Template"),'</button><button type="submit" name="action" value="Device" id="device">',__("Update Devices"),'</button><button type="submit" name="action" value="Export">',__("Export"),'</button>';
	}else{
		echo '	 <button type="submit" name="action" value="Create">',__("Create"),'</button>';
	}
	echo '<button type="button" name="importButton" id="importButton" value="Import">',__("Import"),'</button>

</div>
</div><!-- END div.table -->
</div><!-- end regular template attributes -->
		</div><!-- END left side of device template -->
		<div id="rightside" class="right"><!-- right side of device template -->

<div id="deviceimages">
	<div class="table">
		<div>
			<div class="center">
				<!-- front image goes here -->
				<img id="img_FrontPictureFile" src="',$config->ParameterArray['picturepath'],'/',$template->FrontPictureFile,'">
				<br><span>',__("Front"),'</span>
			</div>
			<div class="center">
				<!-- rear image goes here -->
				<img id="img_RearPictureFile" src="',$config->ParameterArray['picturepath'],'/',$template->RearPictureFile,'">
				<br><span>',__("Rear"),'</span>
			</div>
		</div>
	</div>
</div>

<div id="hiddenports">
</div>

<div id="hiddenpowerports">
</div>

<div id="hiddencoords">
	<div class="table front">
		<div>
			<div>
				<div id="previewimage">
				</div>
			</div>
			<div id="coordstable">
			</div>
		</div>
	</div>
	<div class="table rear">
		<div>
			<div>
				<div id="previewimage">
				</div>
			</div>
			<div id="coordstable">
			</div>
		</div>
	</div>
</div>
<div id="hiddencdudata" class="hide">
	<div class="table">
		<div>
		   <div><label for="Managed">',__("Managed"),'</label></div>
		   <div>
				<input type="checkbox" name="Managed" id="Managed">
		   </div>
		</div>
		<div>
			<div><label for="VersionOID">',__("Firmware Version OID"),'</label></div>
			<div><input type="text" name="VersionOID" id="VersionOID" size=40></div>
		</div>
		<div>
			<div><label for="OutletNameOID">',__("Outlet Factory Name")." ".__("OID"),'</label></div>
			<div><input type="text" name="OutletNameOID" id="OutletNameOID" size=40></div>
		</div>
		<div>
			<div><label for="OutletDescOID">',__("Outlet User Description")." ".__("OID"),'</label></div>
			<div><input type="text" name="OutletDescOID" id="OutletDescOID" size=40></div>
		</div>
		<div>
			<div><label for="OutletCountOID">',__("Outlet Count")." ".__("OID"),'</label></div>
			<div><input type="text" name="OutletCountOID" id="OutletCountOID" size=40></div>
		</div>
		<div>
			<div><label for="OutletStatusOID">',__("Outlet State (On/Off)")." ".__("OID"),'</label></div>
			<div><input type="text" name="OutletStatusOID" id="OutletStatusOID" size=40></div>
		</div>
		<div>
			<div><label for="OutletStatusOn">',__("Outlet State (On)"),'</label></div>
			<div><input type="text" name="OutletStatusOn" id="OutletStatusOn" size=40></div>
		</div>
		<div>
		   <div><label for="Multiplier">',__("Multiplier"),'</label></div>
		   <div><select name="Multiplier" id="Multiplier">';
		   
			$Multi=array("0.01","0.1","1","10","100");
			foreach($Multi as $unit){
					print "\t\t<option value=\"$unit\">$unit</option>\n";
				}
			
		echo '   </select>
		   </div>
		</div>
		<div>
		   <div><label for="OID1">',__("OID for Phase1"),'</label></div>
		   <div><input type="text" name="OID1" id="OID1" size=40></div>
		</div>
		<div>
		   <div><label for="OID2">',__("OID for Phase2"),'</label></div>
		   <div><input type="text" name="OID2" id="OID2" size=40></div>
		</div>
		<div>
		   <div><label for="OID3">',__("OID for Phase3"),'</label></div>
		   <div><input type="text" name="OID3" id="OID3" size=40></div>
		</div>
		<div>
		   <div><label for="ProcessingProfile">',__("Processing Scheme"),'</label></div>
		   <div><select name="ProcessingProfile" id="ProcessingProfile">';

			$ProfileList=array("SingleOIDWatts","SingleOIDAmperes","Combine3OIDWatts","Combine3OIDAmperes","Convert3PhAmperes");
			foreach($ProfileList as $prof){
				print "<option value=\"$prof\">$prof</option>";
			}
			
		echo '   </select></div>
		</div>
		<div>
		   <div><label for="Voltage">',__("Voltage"),'</label></div>
		   <div><input type="text" name="Voltage" id="Voltage"></div>
		</div>
		<div>
		   <div><label for="Amperage">',__("Amperage"),'</label></div>
		   <div><input type="text" name="Amperage" id="Amperage"></div>
		</div>
		<div class="caption" id="atsbox">
			<fieldset class="noborder">
				<legend>Automatic Transfer Switch <input type="checkbox" name="ATS" id="ATS"></legend>
				<div class="table centermargin border">
					<div>
					   <div><label for="ATSStatusOID">',__("ATS Status OID"),'</label></div>
					   <div><input type="text" name="ATSStatusOID" id="ATSStatusOID" size=40></div>
					</div>
					<div>
					   <div><label for="ATSDesiredResult">',__("ATS Desired Result"),'</label></div>
					   <div><input type="text" name="ATSDesiredResult" id="ATSDesiredResult" size=40></div>
					</div>
				</div>
			</fieldset>
		</div>

	</div>
</div><!-- END div#hiddencdudata -->
<div id="hiddensensordata" class="sensordisclaimer hide">
	<div class="table">
		<div>
		   <div><label for="TemperatureOID">',__("Temperature OID"),'</label></div>
		   <div><input type="text" name="TemperatureOID" id="TemperatureOID" size=40></div>
		</div>
		<div>
		   <div><label for="HumidityOID">',__("Humidity OID"),'</label></div>
		   <div><input type="text" name="HumidityOID" id="HumidityOID" size=40></div>
		</div>
		<div>
		   <div><label for="TempMultiplier">',__("Temperature Multiplier"),'</label></div>
		   <div><select name="TempMultiplier" id="TempMultiplier">';

			foreach(array("0.01","0.1","1","10","100") as $unit){
				print "\t\t<option value=\"$unit\">$unit</option>\n";
			}
			
		echo '   </select>
		   </div>
		</div>
		<div>
		   <div><label for="HumidityMultiplier">',__("Humidity Multiplier"),'</label></div>
		   <div><select name="HumidityMultiplier" id="HumidityMultiplier">';

			foreach(array("0.01","0.1","1","10","100") as $unit){
				print "\t\t<option value=\"$unit\">$unit</option>\n";
			}

		echo '   </select>
		   </div>
		</div>
		<div>
			<div><label for="mUnits">',__("Temperature Units"),'</label></div>
			<div><select name="mUnits" id="mUnits">';

			$unitofmeasurev=array("english","metric");
			foreach($unitofmeasurev as $unit){
				print "\t\t<option value=\"$unit\">$unit</option>\n";
			}
			
		echo '   </select>
		   </div>
		</div>

	</div>
</div><!-- END div#hiddensensordata -->

		</div><!-- END right side of device Template -->
	</div>
</div><!-- END outer table -->

</form>

</div></div><!-- END div.center -->
</div> <!-- END div.templatemaker-->

<a href="index.php">[ ',__("Return to Main Menu"),' ]</a>';

echo '<div id="imageselection" title="',__("Image file selector"),'">
	',$imageselect,'
</div>';
?>

</div><!-- END div.main -->
</div><!-- END div.page -->

<!-- dialog: importFile -->  
<div id="dlg_importfile" style="display:none;" title="<?php echo __("Import Template From File");?>">  
	<br>
	<form enctype="multipart/form-data" name="frmImport" id="frmImport" method="POST">
		<input type="file" size="60" id="templateFile" name="templateFile" />
	</form>  
</div>
<!-- end dialog: importFile -->  
<!-- dialog: import_err -->  
<div id="dlg_import_err" style="display:none;" title="<?php echo __("Import log");?>">  
<?php 	
if (isset($result["log"])){
	print '<ul style="list-style-type:disc; padding: 5px;">';
	foreach($result["log"] as $logline){
		print "<li style=\"padding: 5px;\">$logline</li>";
	}
	print "</ul>";
}
?>
</div>
<!-- end dialog: importFile -->
<script type="text/javascript">
function reload() {
	$.get('api/v1/devicetemplate/image').done(function(data){
		var filelist=$('#filelist');
		filelist.html('');
		for(var f in data.image){
			filelist.append($('<span>').text(data.image[f]));
		}
		bindevents();
	});
}
function bindevents() {
	$("#imageselection span").each(function(){
		var preview=$('#imageselection #preview');
		$(this).click(function(){
			preview.css({'border-width': '5px', 'width': '380px', 'height': '380px'});
			preview.html("<img src=\"<?php echo $config->ParameterArray['picturepath'];?>"+$(this).text()+'" alt="preview">').attr('image',$(this).text());
			preview.children('img').load(function(){
				var topmargin=0;
				var leftmargin=0;
				if($(this).height()<$(this).width()){
					$(this).width(preview.innerHeight());
					$(this).css({'max-width': preview.innerWidth()+'px'});
					topmargin=Math.floor((preview.innerHeight()-$(this).height())/2);
				}else{
					$(this).height(preview.innerHeight());
					$(this).css({'max-height': preview.innerWidth()+'px'});
					leftmargin=Math.floor((preview.innerWidth()-$(this).width())/2);
				}
				$(this).css({'margin-top': topmargin+'px', 'margin-left': leftmargin+'px'});
			});
			$("#imageselection span").each(function(){
				$(this).removeAttr('style');
			});
			$(this).css({'border':'1px dotted black','background-color':'#eeeeee'});
		});
		if($($("#imageselection").data('input')).val()==$(this).text()){
			$(this).click();
			this.parentNode.scrollTop=(this.offsetTop - (this.parentNode.clientHeight / 2) + (this.scrollHeight / 2) );
		}
	});
}
function uploadifive() {
    $('#dev_file_upload').uploadifive({
		'formData' : {
				'timestamp' : '<?php echo $timestamp;?>',
				'token'     : '<?php echo $salt;?>',
				'dir'		: "<?php echo $config->ParameterArray['picturepath'];?>"
			},
		'buttonText'		: 'Upload new image',
		'width'				: '150',
		'removeCompleted' 	: true,
		'checkScript'		: 'scripts/check-exists.php',
		'uploadScript'		: 'scripts/uploadifive.php',
		'onUploadComplete'	: function(file, data) {
			data=$.parseJSON(data);
			if(data.status=='1'){
				// something broke, deal with it
				var toast=$('<div>').addClass('uploadifive-queue-item complete');
				var close=$('<a>').addClass('close').text('X').click(function(){$(this).parent('div').remove();});
				var span=$('<span>');
				var error=$('<div>').addClass('border').css({'margin-top': '2px', 'padding': '3px'}).text(data.msg);
				toast.append(close);
				toast.append($('<div>').append(span.clone().addClass('filename').text(file.name)).append(span.clone().addClass('fileinfo').text(' - Error')));
				toast.append(error);
				$('#uploadifive-'+this[0].id+'-queue').append(toast);
			}else{
				$($("#imageselection").data('input')).val(file.name.replace(/\s/g,'_'));
				// fuck yeah, reload the file list
				reload($(this).data('dir'));
			}
		}
    });
}

// https://github.com/afriggeri/RYB
// Functions for generating random number of colors.

var placeholder={};
placeholder.F=$('<img>');
placeholder.R=$('<img>');

function drawSlots(){
	var acolor, b, color, el, g, i, number, point, points, r, _i, _ref;

	number = parseInt($('#coordstable > .table > div ~ div').length+1, 10);
	points = new Points(number);
	point = null;
	bordercolors = [];
	for (i = _i = 1; 1 <= number ? _i <= number : _i >= number; i = 1 <= number ? ++_i : --_i) {
		point = points.pick(point);
		_ref = RYB.rgb.apply(RYB, point).map(function(x) {
			return Math.floor(255 * x);
		}), r = _ref[0], g = _ref[1], b = _ref[2];
		color = "rgb(" + r + ", " + g + ", " + b + ")";
		bordercolors[i]=color;
	}
	// the first bordercolor element is null so pop it off
	bordercolors.shift();

	// clear out anything existing
	$('#deviceimages .center > div').remove();

	$('#coordstable > .table > div ~ div > div:first-child').each(function(){
		var row=this.parentNode;
		// figure out the slot we're dealing with
		var slotnum=parseInt(this.textContent.replace(/\ .*/,''));
		// figure out if we're front or rear
		var fr=($(row).parentsUntil('.front').length==3)?'F':'R';

		// define the image and div we're manipulating
		var img=(fr=='F')?$('#img_FrontPictureFile'):$('#img_RearPictureFile');
		var imgDiv=img.parent('div');
		imgDiv.css('position','relative');

		// ie is garbage and this will attempt to make it less suck
		imgDiv.css({'vertical-align':'top'});
		img.css({'position':'absolute','top':'0px','left':'0px'});
		placeholder[fr].attr({'src':'css/blank.gif','width':img.width(),'height':img.height()});
		placeholder[fr].prependTo(imgDiv);

		// amount to multiply the coordinate by to scale it to our image
		var ratio=parseInt(img.width())/parseInt(img.naturalWidth());

		// draw the slot
		$('<div>').css({
			'left':($('input[name="X'+fr+slotnum+'"]').val()*ratio)+'px',
			'top':($('input[name="Y'+fr+slotnum+'"]').val()*ratio)+'px',
			// 6px to account for fat borders on slots
			'width':($('input[name="W'+fr+slotnum+'"]').val()*ratio)-2+'px',
			'height':($('input[name="H'+fr+slotnum+'"]').val()*ratio)-2+'px',
			'border-color':bordercolors[slotnum],
			'background-color':bordercolors[slotnum].replace(/\)/,", .4)").replace(/rgb/,"rgba"),
			'padding-left':0,
			'position':'absolute'
		}).appendTo(imgDiv);

		// color the row to match the slot.
		$(row).css({'background-color':bordercolors[slotnum]});
	});
}

var Points, RYB, display, generateColors, numberColors,
  __hasProp = {}.hasOwnProperty,
  __extends = function(child, parent) { for (var key in parent) { if (__hasProp.call(parent, key)) child[key] = parent[key]; } function ctor() { this.constructor = child; } ctor.prototype = parent.prototype; child.prototype = new ctor; child.__super__ = parent.prototype; return child; };

RYB = {
  white: [1, 1, 1],
  red: [1, 0, 0],
  yellow: [1, 1, 0],
  blue: [0.163, 0.373, 0.6],
  violet: [0.5, 0, 0.5],
  green: [0, 0.66, 0.2],
  orange: [1, 0.5, 0],
  black: [0.2, 0.094, 0.0],
  rgb: function(r, y, b) {
    var i, _i, _results;
    _results = [];
    for (i = _i = 0; _i <= 2; i = ++_i) {
      _results.push(RYB.white[i] * (1 - r) * (1 - b) * (1 - y) + RYB.red[i] * r * (1 - b) * (1 - y) + RYB.blue[i] * (1 - r) * b * (1 - y) + RYB.violet[i] * r * b * (1 - y) + RYB.yellow[i] * (1 - r) * (1 - b) * y + RYB.orange[i] * r * (1 - b) * y + RYB.green[i] * (1 - r) * b * y + RYB.black[i] * r * b * y);
    }
    return _results;
  }
};

Points = (function(_super) {

  __extends(Points, _super);

  Points.name = 'Points';

  function Points(number) {
    var base, n, _i, _ref;
    base = Math.ceil(Math.pow(number, 1 / 3));
    for (n = _i = 0, _ref = Math.pow(base, 3); 0 <= _ref ? _i < _ref : _i > _ref; n = 0 <= _ref ? ++_i : --_i) {
      this.push([Math.floor(n / (base * base)) / (base - 1), Math.floor(n / base % base) / (base - 1), Math.floor(n % base) / (base - 1)]);
    }
    this.picked = null;
    this.plength = 0;
  }

  Points.prototype.distance = function(p1) {
    var _this = this;
    return [0, 1, 2].map(function(i) {
      return Math.pow(p1[i] - _this.picked[i], 2);
    }).reduce(function(a, b) {
      return a + b;
    });
  };

  Points.prototype.pick = function() {
    var index, pick, _, _ref,
      _this = this;
    if (this.picked == null) {
      pick = this.picked = this.shift();
      this.plength = 1;
    } else {
      _ref = this.reduce(function(_arg, p2, i2) {
        var d1, d2, i1;
        i1 = _arg[0], d1 = _arg[1];
        d2 = _this.distance(p2);
        if (d1 < d2) {
          return [i2, d2];
        } else {
          return [i1, d1];
        }
      }, [0, this.distance(this[0])]), index = _ref[0], _ = _ref[1];
      pick = this.splice(index, 1)[0];
      this.picked = [0, 1, 2].map(function(i) {
        return (_this.plength * _this.picked[i] + pick[i]) / (_this.plength + 1);
      });
      this.plength++;
    }
    return pick;
  };

  return Points;

})(Array);

</script>
</body>
</html>
