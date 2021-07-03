<?php
	// Set a variable so that misc.inc.php knows not to throw us into an infinite redirect loop
	$loginPage=true;

	require_once('db.inc.php');
	require_once('facilities.inc.php');

	// Uncomment these if you need/want to set a title in the header
	$header=__("openDCIM Login");
	$content="";
	$person=new People();

	// Helper functions
	if(!defined('LDAP_ESCAPE_FILTER')){
		define('LDAP_ESCAPE_FILTER', 'Error Suppression');
	}
	if(!function_exists("ldap_escape")){
		function ldap_escape($str = '') {
			$metaChars = array(
				chr(0x5c), // \
				chr(0x2a), // *
				chr(0x28), // (
				chr(0x29), // )
				chr(0x00) // NUL
			);

			// Build the list of the escaped versions of those characters.
			$quotedMetaChars = array ();
			foreach ($metaChars as $key => $value) {
				$quotedMetaChars[$key] = '\\' .
				str_pad(dechex(ord($value)), 2, '0', STR_PAD_LEFT);
			}

			// Make all the necessary replacements in the input string and return
			// the result.
			return str_replace($metaChars, $quotedMetaChars, $str);
		}
	}
	/*
		Tests if user is in group using AD OID LDAP_MATCHING_RULE_IN_CHAIN filter,
		which will have the Directory Sserver do the checking for membership
		through nested groups for us.
	*/
	function isUserInLDAPGroup(&$config, &$ldapConn, $groupDN, $ldapUser) {
		if($config->ParameterArray['LDAPBaseSearch'] == ""){
			$query="(&(sAMAccountName=$ldapUser)(memberOf:1.2.840.113556.1.4.1941:=$groupDN))";
			$ldapSearch=ldap_search($ldapConn,$config->ParameterArray['LDAPBaseDN'],$query,array("dn"));
		}else{
			$query=str_replace("%userid%",$ldapUser,html_entity_decode($config->ParameterArray['LDAPBaseSearch']));
			$ldapSearch=ldap_read($ldapConn,$groupDN,$query,array("dn"));
		}
		$ldapResults=ldap_get_entries($ldapConn, $ldapSearch);
		// user should only be returned once IF they're a member of the group
		if ($ldapResults["count"] == 1) {
			return true;
		}
		return false;
	}

	$debug_this=($config->ParameterArray['LDAPDebug']=='enabled')?true:false;
	function debug_error_log($stuff) {
		global $debug_this;
		if($debug_this){
			error_log($stuff);
		}
	}

	if ( isset($_GET['logout'])) {
		// Unfortunately session_destroy() doesn't actually clear out existing variables, so let's nuke from orbit
		session_unset();
		$_SESSION=array();
		unset($_SESSION["userid"]);
		session_destroy();
		session_commit();
		$content="<h3>Logout successful.</h3>";
	}

	if(isset($_POST['username'])){
		if ( $config->ParameterArray['LDAP_Debug_Password']!="" ) {
			// We are in debug mode, so instead of authenticating against LDAP, use the value in the db
			$userName = $_POST['username'];
			$password = $_POST['password'];

			if ( $userName == "dcim" && $password == $config->ParameterArray['LDAP_Debug_Password'] ) {
				$_SESSION['userid'] = "dcim";
				$_SESSION['LoginTime']=time();
				$person->UserID = "dcim";
				if ( ! $person->GetPersonByUserID() ) {
					$person->SiteAdmin=true;
					$person->WriteAccess=true;
					$person->ReadAccess=true;
					$person->ContactAdmin=true;
					$person->LastName='Administrator';
					$person->FirstName='Emergency';
					$person->CreatePerson();
				}
				session_commit();

				if(isset($_COOKIE['targeturl'])){
					header('Location: ' . html_entity_decode($_COOKIE['targeturl']));
				}else{
					header('Location: ' . redirect('index.php'));
				}
				exit;
			} else {
				$content="<h3>Login failed.</h3>You are in LDAP debug mode, as set by the site administrator in the database.";
				debug_error_log("LDAP debug mode is set and invalid credentials were passed.");
			}
		} else {
			$ldapConn=ldap_connect($config->ParameterArray['LDAPServer']);
			if(!$ldapConn){
				$content="<h3>Fatal error.  The LDAP server is not reachable.  Please try again later, or contact your system administrator to check the configuration.</h3>";
				debug_error_log("Unable to connect to LDAP Server: {$config->ParameterArray['LDAPServer']}");
			}else{
				ldap_set_option($ldapConn,LDAP_OPT_PROTOCOL_VERSION,3);
				ldap_set_option($ldapConn,LDAP_OPT_REFERRALS,0);

				$ldapUser=ldap_escape(htmlspecialchars($_POST['username']),null,LDAP_ESCAPE_FILTER);
				$ldapDN=str_replace("%userid%",$ldapUser,$config->ParameterArray['LDAPBindDN']);
				$ldapPassword=$_POST['password'];

				$ldapBind=ldap_bind($ldapConn,$ldapDN,$ldapPassword);

				if(!$ldapBind){
					$content="<h3>Login failed.  Incorrect username, password, or rights.</h3>";
					debug_error_log( __("Unable to bind to specified LDAP server with specified username/password.  Username:") . $ldapUser );
				}else{
					// User was able to authenticate, but might not have authorization to access openDCIM.  Here we check for those rights.
					/* If this install doesn't have the new parameter, use the old default */
					if(!isset($config->ParameterArray['LDAPBaseSearch'])){
						$config->ParameterArray['LDAPBaseSearch']="(&(objectClass=posixGroup)(memberUid=%userid%))";
					}
					// Now get some more info about the user
					//Get the DN so I can use the LDAP_MATCHING_RULE_IN_CHAIN function
					// Insert the default 4.2 UserSearch string in case this is an upgrade instance
					if(!isset($config->ParameterArray['LDAPUserSearch'])){
						$config->ParameterArray['LDAPUserSearch']="(|(uid=%userid%))";
					}
					$userSearch=str_replace("%userid%",$ldapUser,html_entity_decode($config->ParameterArray['LDAPUserSearch']));
					debug_error_log('User search filter: '.$userSearch);
					$ldapSearch=ldap_search($ldapConn,$config->ParameterArray['LDAPBaseDN'],$userSearch);

					// Check the result of the ldapSearch to make sure we have a valid object.  ldapBind is registering
					// a valid connection despite not authenticating when an invalid user is attempted with a blank password.
					// This checks the results of our search to make sure they returned a valid object and if not stops the 
					// authentication process.
					if(gettype($ldapSearch) != "resource"){
						debug_error_log( __("LDAP search error, did not return a valid resource.") . $ldapUser );
					}else{
						$ldapResults=ldap_get_entries($ldapConn,$ldapSearch);
	
						// Because we have audit logs to maintain, we need to make a local copy of the User's record
						// to keep in the openDCIM database just in case the user gets removed from LDAP.  This also
						// makes it easier to check access rights by replicating the user's rights from LDAP into the
						// local db for the session.  Revoke all rights every login and pull a fresh set from LDAP.
						$person->UserID=$ldapUser;
						if ( ! $person->GetPersonByUserID() )
							$person->CreatePerson();
	
						$person->revokeAll();
	
						// GetPersonByUserID just populated our person object, update it with the 
						// info we just pulled from ldap, if they are a valid user we'll update the
						// db version of their name below, suppress any errors for missing attributes
						@$person->FirstName=$ldapResults[0][$config->ParameterArray['AttrFirstName']][0];
						@$person->LastName =$ldapResults[0][$config->ParameterArray['AttrLastName']][0];
						@$person->Email    =$ldapResults[0][$config->ParameterArray['AttrEmail']][0];
						@$person->Phone1   =$ldapResults[0][$config->ParameterArray['AttrPhone1']][0];
						@$person->Phone2   =$ldapResults[0][$config->ParameterArray['AttrPhone2']][0];
						@$person->Phone3   =$ldapResults[0][$config->ParameterArray['AttrPhone3']][0];
	
						if($config->ParameterArray['LDAPSiteAccess']=="" || isUserInLDAPGroup($config, $ldapConn, $config->ParameterArray['LDAPSiteAccess'], $ldapUser)){
							// No specific group membership required to access openDCIM or they have a match to the group required
							$_SESSION['userid']=$ldapUser;
							$_SESSION['LoginTime']=time();
							session_commit();
	
							$accessTypes = [
								'ReadAccess',
								'WriteAccess',
								'DeleteAccess',
								'AdminOwnDevices',
								'RackRequest',
								'RackAdmin',
								'ContactAdmin',
								'BulkOperations',
								'SiteAdmin'
							];
							foreach($accessTypes as $accessType){
								$groupDN = $config->ParameterArray["LDAP$accessType"];
								if (isUserInLDAPGroup($config, $ldapConn, $groupDN, $ldapUser)) {
									$person->$accessType = true;
									debug_error_log("$ldapUser has $accessType by membership in $groupDN");
								}
							}
	
							$person->UpdatePerson();
	
							unset($accessType);
						}else{
							debug_error_log(__("LDAP authentication successful, but access denied based on lacking group membership.  Username: $ldapUser"));
						}
	
						if(isset($_SESSION['userid'])){
							if($person->PersonID>0){
								$person->UpdatePerson();
							}else{
								$person->CreatePerson();
							}
							if(isset($_COOKIE['targeturl'])){
								header('Location: ' . html_entity_decode($_COOKIE['targeturl']));
							}else{
								header('Location: ' . redirect('index.php'));
							}
							exit;
						}else{
							$content.="<h3>Login failed.  Incorrect username, password, or rights.</h3>";
							debug_error_log(__("LDAP Authentication failed for username: $ldapUser"));
						}
					}
				}
			}
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
  <link rel="stylesheet"  href="css/ie.css" type="text/css" />
  <![endif]-->
  
  <script type="text/javascript" src="scripts/jquery.min.js"></script>
  <script type="text/javascript" src="scripts/jquery-ui.min.js"></script>
  <script type="text/javascript">
	$(document).ready(function() {
		$("#username").focus();
	});
  </script>
</head>
<body>
<?php include( 'header.inc.php' ); ?>
<div class="page index">
<?php
  include( 'sidebar.inc.php' );
?>
<div class="main">
<div class="center"><div>

<?php echo $content; ?>

<form action="login_ldap.php" method="post">
<div class="table">
  <div>
    <div><label for="username"><?php echo __("Username:"); ?></label></div>
    <div><input type="text" id="username" name="username"></div>
  </div>
  <div>
    <div><label for="password"><?php echo __("Password:"); ?></label></div>
    <div><input type="password" name="password"></div>
  </div>
  <div>
    <div></div>
    <div><input type="submit" name="submit" value="<?php echo __("Submit"); ?>"></div>
  </div>
</div>
</form>


<div>
<?php
if ( file_exists("sitecontact.html")) {
  include("sitecontact.html");
}
?>
</div>
</div></div>
</div><!-- END div.main -->
</div><!-- END div.page -->
</body>
</html>
