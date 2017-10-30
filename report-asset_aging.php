<?php
/*	Template file for creating Excel based reports

	Basically just the setup of the front page for consistency
*/

	require_once "db.inc.php";
	require_once "facilities.inc.php";
	require_once "vendor/autoload.php";

	$person = People::Current();

	$workBook = new PHPExcel();

	$workBook->getProperties()->setCreator("openDCIM");
	$workBook->getProperties()->setLastModifiedBy("openDCIM");
	$workBook->getProperties()->setTitle("Data Center Inventory Export");
	$workBook->getProperties()->setSubject("Data Center Inventory Export");
	$workBook->getProperties()->setDescription("Export of the openDCIM database based upon user filtered criteria.");

	// Start off with the TPS Cover Page

	$workBook->setActiveSheetIndex(0);
	$sheet = $workBook->getActiveSheet();

    $sheet->SetTitle('Front Page');
    // add logo
    $objDrawing = new PHPExcel_Worksheet_Drawing();
    $objDrawing->setWorksheet($sheet);
    $objDrawing->setName("Logo");
    $objDrawing->setDescription("Logo");
    $apath = __DIR__ . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR;
    $objDrawing->setPath($apath . $config->ParameterArray['PDFLogoFile']);
    $objDrawing->setCoordinates('A1');
    $objDrawing->setOffsetX(5);
    $objDrawing->setOffsetY(5);

    $logoHeight = getimagesize( $apath . $config->ParameterArray['PDFLogoFile']);
    $sheet->getRowDimension('1')->setRowHeight($logoHeight[1]);

    // set the header of the print out
    $header_range = "A1:B2";
    $fillcolor = $config->ParameterArray['HeaderColor'];
    $fillcolor = (strpos($fillcolor, '#') == 0) ? substr($fillcolor, 1) : $fillcolor;
    $sheet->getStyle($header_range)
        ->getFill()
        ->getStartColor()
        ->setRGB($fillcolor);

    $org_font_size = 20;
    $sheet->setCellValue('A2', $config->ParameterArray['OrgName']);
    $sheet->getStyle('A2')
        ->getFont()
        ->setSize($org_font_size);
    $sheet->getStyle('A2')
        ->getFont()
        ->setBold(true);
    $sheet->getRowDimension('2')->setRowHeight($org_font_size + 2);
    $sheet->setCellValue('A4', 'Report generated by \''
        . $person->UserID
        . '\' on ' . date('Y-m-d H:i:s'));

    // Add text about the report itself
    $sheet->setCellValue('A7', 'Notes');
    $sheet->getStyle('A7')
        ->getFont()
        ->setSize(14);
    $sheet->getStyle('A7')
        ->getFont()
        ->setBold(true);

    $remarks = array( "openDCIM Asset Aging Report",
    		"Each worksheet contains data related to a specific age of an asset." );
    $max_remarks = count($remarks);
    $offset = 8;
    for ($idx = 0; $idx < $max_remarks; $idx ++) {
        $row = $offset + $idx;
        $sheet->setCellValueExplicit('B' . ($row),
            $remarks[$idx],
            PHPExcel_Cell_DataType::TYPE_STRING);
    }
    $sheet->getStyle('B' . $offset . ':B' . ($offset + $max_remarks - 1))
        ->getAlignment()
        ->setWrapText(true);
    $sheet->getColumnDimension('B')->setWidth(120);
    $sheet->getTabColor()->setRGB($fillcolor);

    // Now the real data for the report

    $dept = new Department();
    $con = new People();

    class DeviceAge extends Device
    {
        function GetAge() {
            global $dbh;

            // count devices with unknown age
            $selectSQL = "SELECT COUNT(DeviceID) AS NumDevices,'Unknown age' AS NumYears FROM fac_Device WHERE (CASE WHEN MfgDate>'1969-12-31' THEN MfgDate ELSE InstallDate END)<='1970-01-01'";

            foreach ($dbh->query($selectSQL) as $row) {
                $deptList[$row['NumYears']] = $row['NumDevices'];
            }

            // count devices in each year, years 1 through 5
            for ($year = 1; $year <= 5; $year++) {
                $previous_year = $year - 1;

                $selectSQL = sprintf("SELECT COUNT(DeviceID) AS NumDevices,'%d<=%d years old' AS NumYears FROM fac_Device WHERE (DATEDIFF(NOW(),(CASE WHEN MfgDate>'1969-12-31' THEN MfgDate ELSE InstallDate END))/365)<=%d AND (DATEDIFF(NOW(), (CASE WHEN MfgDate>'1969-12-31' THEN MfgDate ELSE InstallDate END))/365)>%d", $previous_year, $year, $year, $previous_year);

                foreach ($dbh->query($selectSQL) as $row) {
                    $deptList[$row['NumYears']] = $row['NumDevices'];
                }
            }

            // count devices older than 5 years
            $selectSQL = "SELECT COUNT(DeviceID) AS NumDevices,'>5 years old' AS NumYears FROM fac_Device WHERE (DATEDIFF(NOW(),MfgDate)/365)>5 AND MfgDate>'1970-01-01' AND InstallDate>'1970-01-01';";

            foreach ($dbh->query($selectSQL) as $row) {
                $deptList[$row['NumYears']] = $row['NumDevices'];
            }

            return $deptList;
        }

        function GetDeviceByAge($year) {
            global $dbh;
            $deviceList = array();
            if ($year <= 5) {
                $previous_year = $year - 1;
                $selectSQL = sprintf("SELECT DeviceID, Label, Owner, MfgDate, PrimaryContact FROM fac_Device WHERE (DATEDIFF(NOW(), (CASE WHEN MfgDate>'1969-12-31' THEN MfgDate ELSE InstallDate END))/365)<%d AND (DATEDIFF(NOW(), (CASE WHEN MfgDate>'1969-12-31' THEN MfgDate ELSE InstallDate END))/365)>=%d ORDER BY Owner, MfgDate ASC, Label", $year, $previous_year);
                foreach ($dbh->query($selectSQL) as $deviceRow) {
                    $deviceList[$deviceRow['DeviceID']] = Device::RowToObject($deviceRow);
                }
            }
            else {
                $selectSQL = "SELECT DeviceID, Label, Owner, MfgDate, PrimaryContact FROM fac_Device WHERE (DATEDIFF(NOW(), (CASE WHEN MfgDate>'1969-12-31' THEN MfgDate ELSE InstallDate END))/365)>5 AND (CASE WHEN MfgDate>'1969-12-31' THEN MfgDate ELSE InstallDate END)>'1970-01-01' ORDER BY Owner, MfgDate ASC, Label";
                foreach ($dbh->query($selectSQL) as $deviceRow) {
                    $deviceList[$deviceRow['DeviceID']] = Device::RowToObject($deviceRow);
                }
            }
            return $deviceList;
        }
    }

	$sheet = $workBook->createSheet();
	$sheet->setTitle( "My Worksheet" );

	// Put in the relevant data and add more worksheets as needed


	// Now finalize it and send to the client

	header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
	header( sprintf( "Content-Disposition: attachment;filename=\"opendcim-%s.xlsx\"", date( "YmdHis" ) ) );

	$writer = new PHPExcel_Writer_Excel2007($workBook);
	$writer->save('php://output');
?>