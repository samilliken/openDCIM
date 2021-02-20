<?php
	require_once( "db.inc.php" );
    require_once( "facilities.inc.php" );

    if(isset($_REQUEST["pci"]) && $_REQUEST["pci"]!="" && $_REQUEST["pci"]!=null){
        // Data is a device port so pci[0]=deviceid and pci[1]=portnumber

        $data = json_decode(urldecode($_REQUEST['pci']));
        
        // Creates a sheet which containes the labels of the connections/paths you wanted to remove when deleting a device
        function generate_spreadsheet($pathLabels){
            
            $sheet = new PHPExcel();
            
            $sheet->getProperties()->setCreator("openDCIM");
            $sheet->getProperties()->setLastModifiedBy("openDCIM");
            
            $sheet->setActiveSheetIndex(0);
            $sheet->getActiveSheet()->SetCellValue('A1',__("Device paths to remove"));
            
            $sheet->getActiveSheet()->setTitle(__("Paths To Remove"));
            $row = 2;
            
            $dev = new Device();
            $endDev = new Device();
            $endPort = new DevicePorts();

            foreach($pathLabels as $p){
                $sheet->getActiveSheet()->SetCellValue('A' . $row, $p);
                $row++;                
            }		
            
            foreach( range('A','B') as $columnID) {
                $sheet->getActiveSheet()->getColumnDimension($columnID)->setAutoSize(true);
            }

            return $sheet;
        }

        $writer = new PHPExcel_Writer_Excel2007(generate_spreadsheet($data));
        
        ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header( "Content-Disposition: attachment;filename=\"openDCIM-Paths-To-Remove_".date( "Y-m-d H:i:s" ).".xlsx\"" );	
        
        $writer->save("php://output");
    }    
?>
