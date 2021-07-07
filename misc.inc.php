<?php
/* All functions contained herein will be general use functions */

/* Create a quick reference for datacenter data */
@$_SESSION['datacenters']=DataCenter::GetDCList(true);

/* Generic html sanitization routine */

if(!function_exists("sanitize")){
	function sanitize($string,$stripall=true){
		// Convert null to empty string
		if ( is_null($string) ) {
			$string = "";
		}
		
		// Trim any leading or trailing whitespace
		$clean=trim($string);

		// Convert any special characters to their normal parts
		$clean=html_entity_decode($clean,ENT_COMPAT,"UTF-8");

		// By default strip all html
		$allowedtags=($stripall)?'':'<a><b><i><img><u><br>';

		// Strip out the shit we don't allow
		$clean=strip_tags($clean, $allowedtags);
		// If we decide to strip double quotes instead of encoding them uncomment the 
		//	next line
	//	$clean=($stripall)?str_replace('"','',$clean):$clean;
		// What is this gonna do ?
		$clean=filter_var($clean, FILTER_SANITIZE_SPECIAL_CHARS);

		// There shoudln't be anything left to escape but wtf do it anyway
		$clean=addslashes($clean);

		return $clean;
	}
}

if (!function_exists('curl_file_create')) {
    function curl_file_create($filename, $mimetype = '', $postname = '') {
        return "@$filename;filename="
            . ($postname ?: basename($filename))
            . ($mimetype ? ";type=$mimetype" : '');
    }
}

/* 
Regex to make sure a valid URL is in the config before offering options for contact lookups
http://www.php.net/manual/en/function.preg-match.php#93824

Example Usage:
	if(isValidURL("http://test.com"){//do something}

*/
function isValidURL($url){
	$urlregex="((https?|ftp)\:\/\/)?"; // SCHEME
	$urlregex.="([a-z0-9+!*(),;?&=\$_.-]+(\:[a-z0-9+!*(),;?&=\$_.-]+)?@)?"; // User and Pass
	$urlregex.="([a-z0-9-.]*)\.([a-z]{2,3})"; // Host or IP
	$urlregex.="(\:[0-9]{2,5})?"; // Port
	$urlregex.="(\/([a-z0-9+\$_-]\.?)+)*\/?"; // Path
	$urlregex.="(\?[a-z+&\$_.-][a-z0-9;:@&%=+\/\$_.-]*)?"; // GET Query
	$urlregex.="(#[a-z_.-][a-z0-9+\$_.-]*)?"; // Anchor 
// Testing out the php url validation, leaving the regex for now
//	if(preg_match("/^$urlregex$/",$url)){return true;}
	return filter_var($url, FILTER_VALIDATE_URL);
}

//Convert hex color codes to rgb values
function html2rgb($color){
	if($color[0]=='#'){
		$color=substr($color,1);
	}
	if(strlen($color)==6){
		list($r,$g,$b)=array($color[0].$color[1],$color[2].$color[3],$color[4].$color[5]);
	}elseif(strlen($color)==3){
		list($r,$g,$b)=array($color[0].$color[0], $color[1].$color[1], $color[2].$color[2]);
	}else{
		return false;
	}
	$r = hexdec($r); $g = hexdec($g); $b = hexdec($b);

	return array($r, $g, $b);
}

/*
Used to ensure a properly formatted url in use of instances header("Location")

Example usage:
	header("Location: ".redirect());
	exit;
			- or -
	header("Location: ".redirect('storageroom.php'));
	exit;
			- or -
	$url=redirect("index.php?test=23")
	header("Location: $url");
	exit;
*/
function redirect($target = null) {
	$config = new Config();
	// Get URL from InstallURL, if it's set. Remove any trailing slashes.
	$url = rtrim(trim($config->ParameterArray['InstallURL']), "/");
	if (empty($url)) {
		// If InstallURL is not set, figure out (and get right) the path from REQUEST_URI,
		// determine the scheme (HTTP or HTTPS),
		// and what our server name is from SERVER_NAME.
		$path = explode("/", sanitize($_SERVER['REQUEST_URI']));
		unset($path[count($path) - 1]);
		$path = implode("/", $path);

		if (array_key_exists('HTTPS', $_SERVER) && $_SERVER["HTTPS"]=='on') {
			$url = "https://" . $_SERVER['SERVER_NAME'] . $path;
		} else {
			$url = "http://" . @$_SERVER['SERVER_NAME'] . $path;
		}
	}

	// No argument was passed.  If a referrer was set, send them back to whence they came.
	if (is_null($target)) {
		if (isset($_SERVER["HTTP_REFERER"])){
			return $_SERVER["HTTP_REFERER"];
		} else {
			// No referrer was set so send them to the root application directory
			return $url;
		}
	} else {
		//Try to ensure that a properly formatted uri has been passed in.
		if (substr($target, 0, 4)!='http') {
			//doesn't start with http or https check to see if it is a path
			if (substr($target, 0, 1)!='/') {
				//didn't start with a slash so it must be a filename
				$target = $url . "/" . $target;
			} else {
				//started with a slash let's assume they know what they're doing
				$target = $url . $target;
			}
		}
	}

	return $target;
}

// search haystack for needle and return an array of the key path,
// FALSE otherwise.
// if NeedleKey is given, return only for this key
// mixed ArraySearchRecursive(mixed Needle,array Haystack[,NeedleKey[,bool Strict[,array Path]]])

if(!function_exists("ArraySearchRecursive")){
	function ArraySearchRecursive($Needle,$Haystack,$NeedleKey="",$Strict=false,$Path=array()) {
		if(is_object($Haystack)){
			$Haystack=(array) $Haystack;
		}elseif(!is_array($Haystack)){
			return false;
		}
		foreach($Haystack as $Key => $Val) {
			if((is_array($Val)||is_object($Val))&&$SubPath=ArraySearchRecursive($Needle,$Val,$NeedleKey,$Strict,$Path)) {
				$Path=array_merge($Path,Array($Key),$SubPath);
				return $Path;
			}elseif((!$Strict&&$Val==$Needle&&$Key==(strlen($NeedleKey)>0?$NeedleKey:$Key))||($Strict&&$Val===$Needle&&$Key==(strlen($NeedleKey)>0?$NeedleKey:$Key))) {
				$Path[]=$Key;
				return $Path;
			}
		}
		return false;
	}
}

// Search an array of objects for a specific value on given index parameter
// Returns true if found, false if not
if(!function_exists("objArraySearch")){
    function objArraySearch($array, $index, $value)
    {
        foreach($array as $arrayInf) {
            if($arrayInf->{$index} == $value) {
                return true;
            }
        }
        return false;
    }
}
/*
 * Sort multidimentional array in natural order
 *
 * $array = sort2d ( $array, 'key to sort on')
 */
function sort2d ($array, $index){
	//Create array of key and label to sort on.
	foreach(array_keys($array) as $key){$temp[$key]=$array[$key][$index];}
	//Case insensative natural sorting of temp array.
	natcasesort($temp);
	//Rebuild original array using the newly sorted order.
	foreach(array_keys($temp) as $key){$sorted[$key]=$array[$key];}
	return $sorted;
}  
/*
 * Sort multidimentional array in reverse order
 *
 * $array = sort2d ( $array, 'key to sort on')
 */
function arsort2d ($array, $index){
	//Create array of key and label to sort on.
	foreach(array_keys($array) as $key){$temp[$key]=$array[$key][$index];}
	//Case insensative natural sorting of temp array.
	arsort($temp);
	//Rebuild original array using the newly sorted order.
	foreach(array_keys($temp) as $key){$sorted[$key]=$array[$key];}
	return $sorted;
}  

/*
 * Extend sql queries
 *
 */
function extendsql($prop,$val,&$sql,$loose){
	$method=($loose)?" LIKE \"%$val%\"":"=\"$val\"";
	if($sql){
		$sql.=" AND $prop$method";
	}else{
		$sql.="WHERE $prop$method";
	}
}

function attribsql($attrib,$val,&$sql,$loose){
	$method=($loose)?"AttributeID=$attrib AND Value LIKE \"%$val%\"":"AttributeID=$attrib AND Value=\"$val\"";
	if($sql){
		$sql .= " AND DeviceID IN (SELECT DeviceID FROM fac_DeviceCustomValue WHERE $method)";
	} else {
		$sql = "WHERE $method";
	}
}

/*
 * Define multibyte string functions in case they aren't present
 *
 */

if(!extension_loaded('mbstring')){
	function mb_strtoupper($text,$encoding=null){
		return strtoupper($text);
	}
	function mb_strtolower($text,$encoding=null){
		return strtolower($text);
	}
	function mb_convert_case($string, $transform, $locale){
		switch($transform){
			case 'MB_CASE_UPPER':
				$string=mb_strtoupper($string);
				break;
			case 'MB_CASE_LOWER':
				$string=mb_strtolower($string);
				break;
			case 'MB_CASE_TITLE':
				$string=ucwords(mb_strtolower($string));
				break;
		}
		return $string;
	}
}

/*
 * Transform text to uppercase, lowercase, initial caps, or do nothing based on system config
 * 2nd parameter is optional to override the system default
 *
 */
function transform($string,$method=null){
	$config=new Config();
	$method=(is_null($method))?$config->ParameterArray['LabelCase']:$method;
	switch ($method){
		case 'upper':
			$string=mb_convert_case($string, MB_CASE_UPPER, "UTF-8");
			break;
		case 'lower':
			$string=mb_convert_case($string, MB_CASE_LOWER, "UTF-8");
			break;
		case 'initial':
			$string=mb_convert_case($string, MB_CASE_TITLE, "UTF-8");
			break;
		default:
			// Don't you touch my string.
	}
	return $string;
}


/*
 * Convert ticks given back as uptime from devices into a human readable format
 */
function ticksToTime($ticks) {
	$seconds=floor($ticks/100);
	$dtF=new DateTime("@0");
	$dtT=new DateTime("@$seconds");
	$a=$dtF->diff($dtT)->format('%a');
	$h=$dtF->diff($dtT)->format('%h');
	$i=$dtF->diff($dtT)->format('%i');
	$s=$dtF->diff($dtT)->format('%s');
	if($a>0){
		return $dtF->diff($dtT)->format('%a days, %h hours, %i minutes and %s seconds');
	}else if($h>0){
		return $dtF->diff($dtT)->format('%h hours, %i minutes and %s seconds');
	}else if($i>0){
		return $dtF->diff($dtT)->format(' %i minutes and %s seconds');
	}else{
		return $dtF->diff($dtT)->format('%s seconds');
	}
}







/*
 * Language internationalization slated for v2.0
 *
 */
if(isset($_COOKIE["lang"])){
	$locale=$_COOKIE["lang"];
}else{
	$locale=$config->ParameterArray['Locale'];
}

if(extension_loaded('gettext')){
	if(isset($locale)){
		if ( ! setlocale(LC_ALL,$locale) ) {
			if ( ! setlocale( LC_ALL, $locale . ".UTF8" ) ) {
				error_log( "Gettext error loading locale $locale." );
			}
		}
		putenv("LC_ALL=$locale");
		putenv("LANGUAGE=$locale");
		bindtextdomain("openDCIM","./locale");

		$codeset='utf8';
		if(isset($codeset)){
			bind_textdomain_codeset("openDCIM",$codeset);
		}
		textdomain("openDCIM");
	}
}

function GetValidTranslations() {
	$path='./locale';
	$dir=scandir($path);
	$lang=array();
	global $locale;

	foreach($dir as $i => $d){
		// get list of directories in locale that aren't . or ..
		if(is_dir($path.DIRECTORY_SEPARATOR.$d) && $d!=".." && $d!="."){
			// check the list of valid directories above to see if there is an openDCIM translation file present
			if(file_exists($path.DIRECTORY_SEPARATOR.$d.DIRECTORY_SEPARATOR."LC_MESSAGES".DIRECTORY_SEPARATOR."openDCIM.mo")){
				// build array of valid language choices
				$lang[$d]=$d;
			}
		}
	}
	return $lang;
}

function __($string){
	if(extension_loaded('gettext')){
		return _($string);
	}else{
		return $string;
	}
}


/**
 * Parse a string which contains numeric or alpha repetition specifications. It
 *  returns an array of tokens or a message of the parsing exception encountered
 *  with the location of the failing character.
 *
 * @param string $pat
 * @return mixed
 */
function parseGeneratorString($pat)
{
    $result = array();
    $cstr = '';
    $escape = false;
    $patLen = strlen($pat);

    for ($i=0; $i < $patLen; $i++) {
        if ($escape) {
            $cstr .= $pat[$i];
            $escape = false;
            continue;
        }
        if ($pat[$i] == '\\') {
            $escape = true;
            continue;
        }
        if ($pat[$i] == '(') {
            // current string complete, start of a pattern
            $result[] = array('String', array($cstr));
            $cstr = '';
            list($i, $patSpec, $msg) = parsePatternSpec($pat, $patLen, ++$i);
            if (! $patSpec) {
                echo 'Error: Parse pattern return error - \'', $msg, '\' ', $i, PHP_EOL;
                return array(null, $msg, $i);
            }
            $result[] = $patSpec;
            continue;
        }
        $cstr .= $pat[$i];
    }
    if ($cstr != '') {
        $result[] = array('String', array($cstr));
    }

    return array($result, '', $i);
}

/**
 * Parse the numeric or alpha pattern specification and return the specification
 *  token or a the message explaining the exception encountered and the position
 *  of the character where the exception was detected.
 *
 * @param type $pat
 * @param type $patLen
 * @param type $idx
 * @return type
 */
function parsePatternSpec(&$pat, $patLen, $idx) {
    $stopChars = array(';', ')');
    $patSpec = array();
    $msg = 'Wrong pattern specification';

    $ValueStr = '';
    $token = 'StartValue';
    $startValue = null;
    $increment = 1;
    $patType = '';

    for ($i = $idx; $i < $patLen; ++$i) {
        if (ctype_digit($pat[$i])) {
            list($ValueStr, $i, $msg) = getNumericString($pat, $i, $stopChars);
            $patType = 'numeric';
        } elseif (ctype_alpha($pat[$i])) {
            list($ValueStr, $i, $msg) = getAlphaString($pat, $i, $stopChars);
            $patType = 'alpha';
        } else {
            if ($token == 'StartValue') {
                $msg = 'No start value detected.';
            } elseif ($token == 'Increment') {
                $msg = 'Missing increment value.';
            } else {
                $msg = 'Unexpected character \'' . $pat[$i] . '\'';
            }
            return array($i, null, $msg);
        }
        if (($token == 'StartValue') and ($i >= $patLen)) {
            $msg = 'Incomplete pattern specification, missing stop character [\''
                . implode('\',\'', $stopChars) . '\']';
            return array($i, null, $msg);
        }
        if (($token == 'StartValue') and (in_array($pat[$i], $stopChars))) {
            if ($ValueStr === '') {
                $msg = 'Missing start value';
                return array($i, null, $msg);
            }
            if ($patType == 'numeric') {
                $startValue = intval($ValueStr);
            } else {
                $startValue = $ValueStr;
            }
            $ValueStr = '';
            if ($pat[$i] == ')') {
                $token = 'right_parenthesis';
            } elseif ($pat[$i] == ';') {
                $token = 'Increment';
                continue;
            }
        }
        if (($token == 'Increment')) {
            if ($patType == 'numeric') {
                $increment = intval($ValueStr);
            } else {
                $msg = 'Increment must be a number, wrong value \'' . $ValueStr . '\'';
                return array($i, null, $msg);
            }
        }
        if ($pat[$i] == ')') {
            $patSpec = array('Pattern', array($patType, $startValue, $increment));
            break;
        }
        $msg = 'Unexpected character \'' . $pat[$i] . '\' for token \'' . $token . '\'.';
        return array($i, null, $msg);
    }
    if ((! $patSpec) and ($token == 'Increment')) {
        $msg = 'Incomplete increment specification';
        return array($i, null, $msg);
    }
    return array($i, $patSpec, $msg);
}

/**
 * Parse a numeric string.
 *
 * @param string $pat
 * @param int $idx
 * @param array $stopChars
 * @return mixed
 */
function getNumericString(&$pat, $idx, &$stopChars) {
    $strValue = '';
    for ($i=$idx; $i < strlen($pat); $i++) {
        $char = $pat[$i];
        if (in_array($char, $stopChars)) {
                return array(intval($strValue), $i, 'NumericValue');
        }
        if (ctype_digit($char)) {
            $strValue .= $char;
        } else {
            $msg = 'Non-numeric character encountered \'' . $char . '\' ';
            return array(null, $i, $msg);
        }
    }
    $msg = 'Stop character not encountered [\'' . implode('\',\'', $stopChars) . '\'].';
    return array(null, $i, $msg);
}

/**
 * Parse an alpha string.
 *
 * @param string $pat
 * @param int $idx
 * @param array $stopChars
 * @return mixed
 */
function getAlphaString(&$pat, $idx, &$stopChars) {
    $strValue = '';
    $patType = 'alpha';
    $escaped = false;

    for ($i=$idx; $i < strlen($pat); $i++) {
        $char = $pat[$i];
        if ($char == '\\') {
            $escaped= true;
            continue;
        }
        if ($escaped) {
            $strValue .= $char;
            $escaped = false;
            continue;
        }
        if (in_array($char, $stopChars)) {
            return array($strValue, $i, 'AlphaValue');
        }
        if (ctype_alpha($char)) {
            $strValue .= $char;
        } else {
            $msg = 'Non-numeric character encountered \'' . $char . '\' ';
            return array(null, $i, $msg);
        }
    }
    $msg = 'Stop character not encountered \'' . implode(',', $stopChars) . '\'.';
    return array(null, $i, $msg);
}

// Code provided for num2alpha and alpha2num in
// http://stackoverflow.com/questions/5554369/php-how-to-output-list-like-this-aa-ab-ac-all-the-way-to-zzzy-zzzz-zzzza
//function num2alpha($n, $shift=0) {
//    for ($r = ''; $n >= 0; $n = intval($n / 26) - 1)
//        $r = chr($n % 26 + 0x41 + $shift) . $r;
//    return $r;
//}

/**
 * Return the alpha represenation of the integer based on Excel offset.
 *
 * @param int $n
 * @param int $offset
 * @return string
 */
function num2alpha($n, $offset = 0x40) {
    for ($r = ''; $n >= 0; $n = intval($n / 26) - 1) {
        $r = chr($n % 26 + ($offset + 1)) . $r;
    }
    return $r;
}

/**
 * Return the numeric representation the alpha string  based on Excel offset.
 * @param string $a
 * @param int $offset
 * @return int
 */
function alpha2num($a, $offset = 0x40)
{
    $base = 26;
    $l = strlen($a);
    $n = 0;
    for ($i = 0; $i < $l; $i++) {
        $n = $n*$base + ord($a[$i]) - $offset;
    }
    return $n-1;
}

/**
 * Take the generator string specification produced by parseGeneratorString and
 *  return a list of strings where the patterns are instantiated.
 *
 * @param array $patSpecs
 * @param int $count
 * @return array
 */
function generatePatterns($patSpecs, $count) {
    $patternList = array();
    for ($i=0; $i < $count; $i++) {
        $str = '';
        foreach ($patSpecs as $pat) {
            if ($pat) {
               if ($pat[0] == 'String') {
                    $str .= $pat[1][0];
                } elseif ($pat[0] == 'Pattern') {
                    if ($pat[1][0] == 'numeric') {
                        $str .= (integer)($pat[1][1] + $i*$pat[1][2]);
                    } elseif ($pat[1][0] == 'alpha') {
                        $charIntVal = ord($pat[1][1]);
                        if (($charIntVal >= 65) and ($charIntVal <= 90)) {
                            $offset = 0x40;
                            $charIntVal = alpha2num($pat[1][1]);
                        } else {
                            $offset = 0x60;
                            $charIntVal = alpha2num($pat[1][1], $offset);
                        }
                        $str .= num2alpha(($charIntVal + $i*$pat[1][2]), $offset);
                    }
                }
            }
        }
        $patternList[] = $str;
    }

    return $patternList;
}

// Deal with pesky international number formats that mysql doesn't like
function float_sqlsafe($number){
	$locale=localeconv();
	if($locale['thousands_sep']=='.'){
		$number=str_replace('.','',$number);
	}
	if($locale['decimal_point']==','){
		$number=str_replace(',','.',$number);
	}
	return $number;
}

function locale_number( $number, $decimals=2 ) {
    $locale = localeconv();
    return number_format($number,$decimals,
               $locale['decimal_point'],
               $locale['thousands_sep']);
}

// This will build an array that can be json encoded to represent the makeup of
// the installations containers, zones, rows, etc.  It didn't seem appropriate
// to be on any single class
if(!function_exists("buildNavTreeArray")){
	function buildNavTreeArray(){
		$con=new Container();
		$cabs=Cabinet::ListCabinets();

		$menu=array();

		if(!function_exists("processcontainer")){
			function processcontainer($container,$cabs){
				$menu=array($container);
				foreach($container->GetChildren() as $child){
					if(get_class($child)=='Container'){
						$menu[]=processcontainer($child,$cabs);
					}elseif(get_class($child)=='DataCenter'){
						$menu[]=processdatacenter($child,$cabs);
					}
				}
				return $menu;
			}
		}
		if(!function_exists("processdatacenter")){
			function processdatacenter($dc,$cabs){
				$menu=array($dc);
				foreach($dc->GetChildren() as $child){
					if(get_class($child)=='Zone'){
						$menu[]=processzone($child,$cabs);
					}elseif(get_class($child)=='CabRow'){
						$menu[]=processcabrow($child,$cabs);
					}else{
						$menu[]=processcab($child,$cabs);
					}
				}
				return $menu;
			}
		}
		if(!function_exists("processzone")){
			function processzone($zone,$cabs){
				$menu=array($zone);
				foreach($zone->GetChildren() as $child){
					if(get_class($child)=='CabRow'){
						$menu[]=processcabrow($child,$cabs);
					}else{
						$menu[]=processcab($child,$cabs);
					}
				}
				return $menu;
			}
		}
		if(!function_exists("processcabrow")){
			function processcabrow($row,$cabs){
				$menu=array($row);
				foreach($cabs as $cab){
					if($cab->CabRowID==$row->CabRowID){
						$menu[]=processcab($cab,$cabs);
					}
				}
				return $menu;
			}
		}
		if(!function_exists("processcab")){
			function processcab($cab,$cabs){
				return $cab;
			}
		}

		foreach($con->GetChildren() as $child){
			if(get_class($child)=='Container'){
				$menu[]=processcontainer($child,$cabs);
			}elseif(get_class($child)=='DataCenter'){
				$menu[]=processdatacenter($child,$cabs);
			}
		}

		return $menu;
	}
}

// This will format the array above into the format needed for the side bar navigation
// menu. 

if(!function_exists("buildNavTreeHTML")) {
	function buildNavTreeHTML(){
		global $dbh;

		$st = $dbh->prepare( "select * from fac_DataCache where ItemType='NavMenu'" );
		$st->execute();

		if ( $row = $st->fetch() ) {
			return $row["Value"];
		} else {
			// Oops, there's no tree because this is the first time it's being run.
			updateNavTreeHTML();
			// Don't blindly risk a stuck forever loop (blank database) and only try to go one level deep
			if ( $tree = buildNavTreeHTML() ) {
				return $tree;
			} else {
				// Missing or blank database!   We might be in the installer phase, so just chill.
				return false;
			}
		}
	}
}
if(!function_exists("updateNavTreeHTML")){
	function updateNavTreeHTML($menu=null){
		global $dbh;

		$tl=1; //tree level

		$menu=(is_null($menu))?buildNavTreeArray():$menu;

		if(!function_exists("buildnavmenu")){
			function buildnavmenu($ma,&$tl){
				$menuCode = "";
				foreach($ma as $i => $level){
					if(is_object($level)){
						if(isset($level->Name)){
							$name=$level->Name;
						}elseif(isset($level->Location)){
							$name=$level->Location;
						}else{
							$name=$level->Description;
						}
						if($i==0){--$tl;}
						foreach($level as $prop => $value){
							if(preg_match("/id/i", $prop)){
								$ObjectID=$value;
								break;
							}
						}
						$class=get_class($level);
						$cabclose='';
						if($class=="Container"){
							$href="container_stats.php?container=";
							$id="c$ObjectID";
						}elseif($class=="Cabinet"){
							$href="cabnavigator.php?cabinetid=";
							$id="cab$ObjectID";
							$cabclose="</li>";
						}elseif($class=="Zone"){
							$href="zone_stats.php?zone=";
							$id="zone$ObjectID";
						}elseif($class=="DataCenter"){
							$href="dc_stats.php?dc=";
							$id="dc$ObjectID";
						}elseif($class=="CabRow"){
							$href="rowview.php?row=";
							$id="cr$ObjectID";
						}

						$menuCode .= str_repeat("\t",$tl).'<li class="liClosed" id="'.$id.'"><a class="'.$class.'" href="'.$href.$ObjectID."\">$name</a>$cabclose\n";
						if($i==0){
							++$tl;
							$menuCode .= str_repeat("\t",$tl)."<ul>\n";
						}
					}else{
						$tl++;
						$menuCode .= buildnavmenu($level,$tl);
						if(get_class($level[0])=="DataCenter"){
							$menuCode .= str_repeat("\t",$tl).'<li id="dc-'.$level[0]->DataCenterID.'"><a href="storageroom.php?dc='.$level[0]->DataCenterID.'">Storage Room</a></li>'."\n";
						}
						$menuCode .= str_repeat("\t",$tl)."</ul>\n";
						$tl--;
						$menuCode .= str_repeat("\t",$tl)."</li>\n";
					}
				}

				return $menuCode;
			}
		}

		$menuCode  = '<ul class="mktree" id="datacenters">'."\n";
		$menuCode .= buildnavmenu($menu,$tl);
		$menuCode .=  '<li id="dc-1"><a href="storageroom.php">'.__("General Storage Room")."</a></li>\n</ul>";

		$st = $dbh->prepare( "insert into fac_DataCache set ItemType='NavMenu', Value=:Value on duplicate key update Value=:Value" );
		$st->execute( array( ":Value"=>$menuCode ));
	}
}


/*
	Check if we are doing a new install or an upgrade has been applied.  
	If found then force the user into only running that function.

	To bypass the installer check from running, simply add
	$devMode = true;
	to the db.inc.php file.
*/

/*
	If we are using Saml authentication, go ahead and figure out who
	we are.  It may be needed for the installation.
*/

if( AUTHENTICATION=="Saml" && !isset($_SESSION['userid']) && php_sapi_name()!="cli" && !isset($loginPage))
{
	$savedurl = $_SERVER['SCRIPT_NAME'] . "?" . sanitize($_SERVER['QUERY_STRING']);
	setcookie( 'targeturl', $savedurl, time()+60 );
	header("Location: ".redirect('saml/login.php'));
	exit;
}

if(!(isset($devMode)&&$devMode)) {
	$sql = "select Value from fac_Config where Parameter='Version'";
	if ( $r = $dbh->query( $sql ))
		$version = $r->fetchColumn();
	else
		$version = "";
	if ( VERSION != $version ) {
		if(file_exists("install.php") && basename($_SERVER['SCRIPT_NAME'])!="install.php" ){
			error_log("Newer version codebase than database, redirecting to installation script.");
			// new installs need to run the install first.
			header("Location: ".redirect('install.php'));
			exit;
		}
	}
}

/*
	If we are using Oauth authentication, go ahead and figure out who
	we are.  It may be needed for the installation.
*/

if( AUTHENTICATION=="Oauth" && !isset($_SESSION['userid']) && php_sapi_name()!="cli" ){
	header("Location: ".redirect('oauth/login.php'));
	exit;
}


// Just to keep things from getting extremely wonky and complicated, even though this COULD be in one giant
// if/then/else stanza, I'm breaking it into two

if( AUTHENTICATION=="LDAP" && $config->ParameterArray["LDAPSessionExpiration"] > 0 && isset($_SESSION['userid']) && ((time() - $_SESSION['LoginTime']) > $config->ParameterArray['LDAPSessionExpiration'])) {
	session_unset();
	session_destroy();
	session_start();
}

if( AUTHENTICATION=="LDAP" && !isset($_SESSION['userid']) && php_sapi_name()!="cli" && !isset($loginPage)) {
	$savedurl = $_SERVER['SCRIPT_NAME'] . "?" . $_SERVER['QUERY_STRING'];
	setcookie( 'targeturl', $savedurl, time()+60 );
	header("Location: ".redirect('login_ldap.php'));
	exit;
}

// And just because you're logged in, it doesn't mean that we have your People record...
if(!People::Current()){
	if(AUTHENTICATION=="Oauth"){
		header("Location: ".redirect('oauth/login.php'));
		exit;
	} elseif ( AUTHENTICATION=="Saml" && !isset($loginPage) ){
		header("Location: ".redirect('saml/login.php'));
		exit;
	} elseif ( AUTHENTICATION=="LDAP" && !isset($loginPage) ) {
		header("Location: ".redirect('login_ldap.php'));
		exit;
	} elseif(AUTHENTICATION=="Apache"){
		print "<h1>You must have some form of Authentication enabled to use openDCIM.</h1>";
		exit;
	}
}


/* This is used on every page so we might as well just init it once */
$person=People::Current();
// If we're in the process of logging in, just to get us through this, create an instance with all rights revoked
if ( isset( $loginPage ) ) {
	$person = new People();
	$person->revokeAll();
}

if(($person->Disabled || ($person->PersonID==0 && $person->UserID!="cli_admin")) && $config->ParameterArray["RequireDefinedUser"]=="enabled" && !isset($loginPage)){
	header("Location: ".redirect('unauthorized.php'));
	exit;
}

/* 
 * This is an attempt to be sane about the rights management and the menu.
 * The menu will be built off a master array that is a merger of what options
 * the user has available.  
 *
 * Array structure:
 * 	[]->Top Level Menu Item
 *	[top level menu item]->Array(repeat previous structure)
 *
 */

$menu=$rmenu=$rrmenu=$camenu=$wamenu=$samenu=$lmenu=array();

$rmenu[]='<a href="reports.php"><span>'.__("Reports").'</span></a>';

if($config->ParameterArray["WorkOrderBuilder"]){
	$class=(isset($_COOKIE['workOrder']) && $_COOKIE['workOrder']!='[0]')?'':'hide';
	array_unshift($rmenu , '<a class="'.$class.'" href="workorder.php"><span>'.__("Work Order").'</span></a>');
}

if ( $config->ParameterArray["RackRequests"] == "enabled" && $person->RackRequest ) {
	$rrmenu[]='<a href="rackrequest.php"><span>'.__("Rack Request Form").'</span></a>';
}
if ( $person->ContactAdmin ) {
	$camenu[__("User Administration")][]='<a href="usermgr.php"><span>'.__("User Administration").'</span></a>';
	$camenu[__("User Administration")][]='<a href="departments.php"><span>'.__("Dept. Administration").'</span></a>';
	$camenu[__("Issue Escalation")][]='<a href="timeperiods.php"><span>'.__("Time Periods").'</span></a>';
	$camenu[__("Issue Escalation")][]='<a href="escalations.php"><span>'.__("Escalation Rules").'</span></a>';
	$camenu[]='<a href="project_mgr.php"><span>'.__("Project Catalog").'</span></a>';
}
if ( $person->WriteAccess ) {
	$wamenu[__("Template Management")][]='<a href="device_templates.php"><span>'.__("Edit Device Templates").'</span></a>';
	$wamenu[__("Infrastructure Management")][]='<a href="cabinets.php"><span>'.__("Edit Cabinets").'</span></a>';
	$wamenu[__("Template Management")][]='<a href="image_management.php#pictures"><span>'.__("Device Image Management").'</span></a>';
}
if ($person->BulkOperations) {
	$wamenu[__("Bulk Importer")][]='<a href="bulk_container.php"><span>'.__("Import Container/Datacenter/Zone/Row").'</span></a>';
	$wamenu[__("Bulk Importer")][]='<a href="bulk_users.php"><span>'.__("Import User Accounts").'</span></a>';
	$wamenu[__("Bulk Importer")][]='<a href="bulk_departments.php"><span>'.__("Import Departments/Customers").'</span></a>';
	$wamenu[__("Bulk Importer")][]='<a href="bulk_templates.php"><span>'.__("Import Device Templates").'</span></a>';
	$wamenu[__("Bulk Importer")][]='<a href="bulk_cabinet.php"><span>'.__("Import New Cabinets").'</span></a>';
	$wamenu[__("Bulk Importer")][]='<a href="bulk_importer.php"><span>'.__("Import New Devices").'</span></a>';
	$wamenu[__("Bulk Importer")][]='<a href="bulk_network.php"><span>'.__("Import Network Connections").'</span></a>';
	$wamenu[__("Bulk Importer")][]='<a href="bulk_power.php"><span>'.__("Import Power Connections").'</span></a>';
	$wamenu[__("Bulk Importer")][]='<a href="bulk_moves.php"><span>'.__("Process Bulk Moves").'</span></a>';
}
if ( $person->SiteAdmin ) {
	$samenu[__("Template Management")][]='<a href="device_manufacturers.php"><span>'.__("Edit Manufacturers").'</span></a>';
	$samenu[__("Template Management")][]='<a href="repository_sync_ui.php"><span>'.__("Repository Sync").'</span></a>';
	$samenu[__("Materiel Management")][]='<a href="supplybin.php"><span>'.__("Manage Supply Bins").'</span></a>';
	$samenu[__("Materiel Management")][]='<a href="supplies.php"><span>'.__("Manage Supplies").'</span></a>';
	$samenu[__("Materiel Management")][]='<a href="disposition.php"><span>'.__("Manage Disposal Methods").'</span></a>';
	$samenu[__("Infrastructure Management")][]='<a href="datacenter.php"><span>'.__("Edit Data Centers").'</span></a>';
	$samenu[__("Infrastructure Management")][]='<a href="container.php"><span>'.__("Edit Containers").'</span></a>';
	$samenu[__("Infrastructure Management")][]='<a href="zone.php"><span>'.__("Edit Zones").'</span></a>';
	$samenu[__("Infrastructure Management")][]='<a href="cabrow.php"><span>'.__("Edit Rows of Cabinets").'</span></a>';
	$samenu[__("Infrastructure Management")][]='<a href="image_management.php#drawings"><span>'.__("Facilities Image Management").'</span></a>';
	$samenu[__("Power Management")][]='<a href="power_panel.php"><span>'.__("Edit Power Panels").'</span></a>';
	$samenu[__("Path Connections")][]='<a href="paths.php"><span>'.__("View Path Connection").'</span></a>';
	$samenu[__("Path Connections")][]='<a href="pathmaker.php"><span>'.__("Make Path Connection").'</span></a>';
	$samenu[]='<a href="configuration.php"><span>'.__("Edit Configuration").'</span></a>';
}
if( AUTHENTICATION == "LDAP" ) {
	// Clear out the Reports menu button and create the Login menu button when not logged in
	if ( isset($loginPage) ) {
		$rmenu = array();
	}
	$lmenu[]='<a href="login_ldap.php?logout"><span>'.__("Logout").'</span></a>';
}

if (AUTHENTICATION == "Saml" ) {
	$lmenu[]='<a href="saml/logout.php"><span>'.__("Logout").'</span></a>';
}

# Can really think that this is necessary - here for completemess
#if( AUTHENTICATION == "Saml" ) {
#	// Clear out the Reports menu button and create the Login menu button when not logged in
#	if ( isset($loginPage) ) {
#		$rmenu = array();
#	}
#	$lmenu[]='<a href="saml/logout.php"><span>'.__("Logout").'</span></a>';
#}

function download_file($archivo, $downloadfilename = null) {
	if (file_exists($archivo)) {
		$downloadfilename = $downloadfilename !== null ? $downloadfilename : basename($archivo);
		header('Content-Description: File Transfer');
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename=' . $downloadfilename);
		header('Content-Transfer-Encoding: binary');
		header('Expires: 0');
		header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
		header('Pragma: public');
		header('Content-Length: ' . filesize($archivo));
		ob_clean();
		flush();
		readfile($archivo);
	}
}
function download_file_from_string($string, $downloadfilename) {
	//download_file_from_string("Hola Pepe ¿Qué tal?", "pepe.txt");
	header('Content-Description: File Transfer');
	header('Content-Type: application/octet-stream');
	header('Content-Disposition: attachment; filename=' . $downloadfilename);
	header('Content-Transfer-Encoding: binary');
	header('Expires: 0');
	header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
	header('Pragma: public');
	header('Content-Length: ' . strlen($string));
	flush();
	echo $string;
}

/*
 * In an attempt to keep html generation out of the primary class definitions 
 * this function is being put here to make a quick convenient method of drawing
 * racks.  This will NOT put the devices in the rack.
 *
 * Example usage:  echo BuildCabinet(123);
 *
 * @param int $cabid
 * @param string $face (front,rear,side)
 * @return html table
 *
 */

if(!function_exists("BuildCabinet")){
function BuildCabinet($cabid,$face="front"){
	$cab=new Cabinet($cabid);
	$cab->GetCabinet();
	$order=($cab->U1Position=="Top")?false:true;
	$dev=new Device();
	$dev->Cabinet=$cab->CabinetID;
	$dev->ParentDevice=0;
	$bounds=array(
		'max'=>array('position'=>0,'height'=>0),
		'min'=>array('position'=>0,'height'=>0),
	);

	// Read in all the devices and make sure they fit the cabinet.  If not expand it
	foreach($dev->Search() as $device){
		if($device->Position==0){
			continue;
		}
		$pos=($order)?$device->Position:$device->Position-$device->Height;

		if($device->Position>$bounds['max']['position']){
			$bounds['max']['position']=$device->Position;
			$bounds['max']['height']=$device->Height;
		}
		if($pos<$bounds['min']['position']){
			$bounds['min']['position']=$pos;
			$bounds['min']['height']=1;
		}
	}
	if($order){
		$top=max($cab->CabinetHeight,$bounds['max']['position']+$bounds['max']['height']-1);
		$bottom=min(0,$bounds['min']['position']);
	}else{
		// Reverse order
		$top=min(1,$bounds['min']['position']-$bounds['min']['height']);
		$bottom=max($cab->CabinetHeight,$bounds['max']['position']);
	}

	// Build cabinet HTML
	switch ($face) {
		case "rear":
			$cab->Location="$cab->Location (".__("Rear").")";
			break;
		case "side":
			$cab->Location="$cab->Location (".__("Side").")";
			break;
		default:
			// Leave the location alone
	}

	// helper function to print the rows of the cabinet table
	if(!function_exists("printrow")){
		function printrow($i,$top,$bottom,$order,$face,&$htmlcab,$cabobject){
			$error=($i>$cabobject->CabinetHeight || ($i<=0 && $order)  || ($i<0 && !$order))?' error':'';
			if($order){
				$x=($i<=0)?$i-1:$i;
			}else{
				$x=($i>=0)?$i+1:$i;
			}
			if($i==$top){
				if($face=="rear"){
					$rs="-rear";
				}elseif($face=="side"){
					$rs="-side";
				}else{
					$rs="";
				}
				$rowspan=abs($top)+abs($bottom);
				$height=(((abs($top)+abs($bottom))*ceil(220*(1.75/19))))."px";
				$htmlcab.="\t<tr id=\"pos$x\"><td class=\"pos$error\">$x</td><td rowspan=$rowspan><div id=\"servercontainer$rs\" class=\"freespace\" style=\"width: 220px; height: $height\" data-face=\"$face\"></div></td></tr>\n";
			}else{
				$htmlcab.="\t<tr id=\"pos$x\"><td class=\"pos$error\">$x</td></tr>\n";
			}
		}
	}

	// If they have rights to the device then make the picture clickable
	$clickable=($cab->Rights!="None")?"\t\t<a href=\"cabnavigator.php?cabinetid=$cab->CabinetID\">\n\t":"";
	$clickableend=($cab->Rights!="None")?"\n\t\t</a>\n":"";

	$htmlcab="<table class=\"cabinet\" id=\"cabinet$cab->CabinetID\">
	<tr><th colspan=2>$clickable$cab->Location$clickableend</th></tr>
	<tr><td>Pos</td><td>Device</td></tr>\n";

	// loop here for the height
	// numbered high to low, top to bottom
	if($order){
		for($i=$top;$i>$bottom;$i--){
			printrow($i,$top,$bottom,$order,$face,$htmlcab,$cab);
		}
	}else{ // numbered low to high, top to bottom
		for($i=$top;$bottom>$i;$i++){
			printrow($i,$top,$bottom,$order,$face,$htmlcab,$cab);
		}
	}

	$htmlcab.="</table>\n";

	// Wrap it in a nice div
	$htmlcab='<div class="cabinet">'.$htmlcab.'</div>';

	// debug information
	// print "Cabinet:  $cab->CabinetID   Top: $top   Bottom: $bottom<br>\n";

	return $htmlcab;
}
}

function getNameFromNumber($num){
	// Used to figure out what the Excel column name would be for a given 0-indexed array of data
	$numeric = ($num-1)%26;
	$letter = chr(65+$numeric);
	$num2 = intval(($num-1) / 26);
	if ( $num2 > 0 ) {
		return getNameFromNumber($num2) . $letter;
	} else {
		return $letter;
	}
}

function mangleDate($dateString) {
	// Take various formats of the date that may have been stored in the db and present them in a nice manner according to ISO8601 Format
	if ( $dateString == null ) {
		return "";
	}

	if ( date( "Y-m-d", $dateString ) == "1969-12-31" ) {
		return "";
	} else {
		return date( "Y-m-d", $dateString );
	}
}

function pdo_debugParams($stmt) {
	ob_start();
	$stmt->debugDumpParams();
	$r = ob_get_contents();
	ob_end_clean();
	return $r;
}

class JobQueue {
	var $SessionID;
	var $Percentage;
	var $Status;

	static function startJob( $SessionID ) {
		global $dbh;

		$sql = "insert into fac_Jobs set SessionID=:SessionID, Percentage=0 on duplicate key update Percentage=0";
		$st = $dbh->prepare( $sql );
		$st->execute( array( ":SessionID"=>$SessionID ));

		return;
	}

	static function updatePercentage( $SessionID, $Percentage ) {
		global $dbh;

		if ( $Percentage < 100 ) {
			$sql = "update fac_Jobs set Percentage=:Percentage where SessionID=:SessionID";
			$st = $dbh->prepare( $sql );
			$result = $st->execute( array( ":Percentage"=>$Percentage, ":SessionID"=>$SessionID ));
		} else {
			$sql = "delete from fac_Jobs where SessionID=:SessionID";
			$st = $dbh->prepare( $sql );
			$st->execute( array( ":SessionID"=>$SessionID ));
		}

		return;
	}

	static function updateStatus( $SessionID, $StatusMessage ) {
		global $dbh;

		// Since using prepared statements, PHP will auto sanitize the input
		$sql = "update fac_Jobs set Status=:Status where SessionID=:SessionID";
		$st = $dbh->prepare( $sql );
		$st->execute( array( ":Status"=>$StatusMessage, ":SessionID"=>$SessionID ));

		return;
	}

	static function getStatus( $SessionID ) {
		global $dbh;

		$sql = "select * from fac_Jobs where SessionID=:SessionID";
		$st = $dbh->prepare( $sql );
		$st->execute( array( ":SessionID"=>$SessionID ));
		if ( $row = $st->fetch() ) {
			return $row;
		} else {
			// If a job has already cleared out (or was never initialized) then tell the monitor to stop waiting
			return array( "SessionID"=>$SessionID, "Percentage"=>100, "Status"=>"Completed");
		}
	}
}
?>
