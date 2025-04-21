<?php
function readOdsFromUrl($url) {
    $odsData = file_get_contents($url);
    if (!$odsData) die("Error downloading file");

    $tempFile = tempnam(sys_get_temp_dir(), 'ods_') . '.ods';
    file_put_contents($tempFile, $odsData);

    $zip = new ZipArchive;
    if ($zip->open($tempFile) !== TRUE) die("Error opening ODS");
    
    $contentXml = $zip->getFromName('content.xml');
    $zip->close();
    unlink($tempFile);

    $xml = simplexml_load_string($contentXml);
    $xml->registerXPathNamespace('table', 'urn:oasis:names:tc:opendocument:xmlns:table:1.0');
    $xml->registerXPathNamespace('text', 'urn:oasis:names:tc:opendocument:xmlns:text:1.0');

    $result = [];
    
    $sheets = $xml->xpath('//table:table');

    foreach ($sheets as $sheet) {
        $sheetName = (string)($sheet->attributes('table', true)->name ?? 'Sheet' . (count($result) + 1));
        $columns = [];
        $data = [];
        
        $rows = $sheet->xpath('.//table:table-row[not(@table:visibility="collapse")]');
        
        if (!empty($rows)) {
            // Get non-empty column names from first row
            $firstRow = $rows[0];
            foreach ($firstRow->xpath('.//table:table-cell') as $cell) {
                $colName = $cell->xpath('.//text:p') ? (string)$cell->xpath('.//text:p')[0] : '';
                if (!empty(trim($colName))) {
                    $columns[] = $colName;
                }
            }
            
            // Skip if no valid columns found
            if (empty($columns)) {
                continue;
            }
            
            // Get non-empty data rows (skip first row)
            foreach (array_slice($rows, 1) as $row) {
                $rowData = [];
                $cellIndex = 0;
                $hasData = false;
                
                foreach ($row->xpath('.//table:table-cell') as $cell) {
                    // Stop if we've processed all columns
                    if ($cellIndex >= count($columns)) {
                        break;
                    }
                    
                    $value = $cell->xpath('.//text:p') ? (string)$cell->xpath('.//text:p')[0] : '';
                    if (!empty(trim($value))) {
                        $hasData = true;
                    }
                    $rowData[] = $value;
                    $cellIndex++;
                }
                
                // Only add row if it has data and matches column count
                if ($hasData && count($rowData) == count($columns)) {
                    $data[] = $rowData;
                }
            }
        }

        // Only add sheet if it has both columns and data
        if (!empty($columns) && !empty($data)) {
            $result[$sheetName] = [
                'columns' => $columns,
                'data' => $data
            ];
        }
    }

    return $result;
}

function createDatabase($data) {
    try {
        $dbFile = 'ods_database.db';
        
        // Close any existing connections and delete old database
        if (file_exists($dbFile)) {
            // Close any existing PDO connections
            if (isset($GLOBALS['db_connection']) && $GLOBALS['db_connection'] instanceof PDO) {
                $GLOBALS['db_connection'] = null;
            }
              // Try multiple times to delete the file
            $maxAttempts = 5;
            $attempt = 0;
            $deleted = false;
            
            while ($attempt < $maxAttempts && !$deleted) {
                if (@unlink($dbFile)) {
                    $deleted = true;
                } else {
                    $attempt++;
                    usleep(100000); // Wait 100ms between attempts
                }
            }
            
            if (!$deleted) {
                die("Error: Could not delete existing database file. Please close any programs using it.");
            }
        }

        // Create new SQLite database
        $db = new PDO('sqlite:' . $dbFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $GLOBALS['db_connection'] = $db; // Store for cleanup

        // Create SQLite database
        $db = new PDO('sqlite:ods_database.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        foreach ($data as $sheetName => $sheetInfo) {
            // Sanitize table name
            $tableName = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($sheetName));
            
            // Create table with ID column
            $columns = ['id INTEGER PRIMARY KEY AUTOINCREMENT'];
            foreach ($sheetInfo['columns'] as $col) {
                $colName = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($col));
                $columns[] = "`$colName` TEXT NOT NULL DEFAULT ''";
            }
            
            $createSQL = "CREATE TABLE IF NOT EXISTS `$tableName` (" 
                        . implode(', ', $columns) . ")";
            $db->exec($createSQL);
            
            // Insert data
            if (!empty($sheetInfo['data'])) {
                $colNames = array_map(function($col) {
                    return '`' . preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($col)) . '`';
                }, $sheetInfo['columns']);
                
                $insertSQL = "INSERT INTO `$tableName` (" 
                           . implode(', ', $colNames) . ") VALUES (" 
                           . implode(', ', array_fill(0, count($colNames), '?')) . ")";
                
                $stmt = $db->prepare($insertSQL);
                foreach ($sheetInfo['data'] as $row) {
                    // Convert empty strings to NULL
                    $preparedRow = array_map(function($value) {
                        return empty(trim($value)) ? null : $value;
                    }, $row);
                    $stmt->execute($preparedRow);
                }
            }
        }
        
        echo "Database created successfully with non-empty data only: ods_database.db\n";
    } catch (PDOException $e) {
        die("Database error: " . $e->getMessage());
    }
}

// Process ODS file and create database
$url = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vQg0VTrvywSIoMxncJSJUuOxBsfXawFc6RS2RWz30g2BwFQ4UPCxlBXo24V4s7u8bn0MEcQ62sTuOpj/pub?output=ods';
$data = readOdsFromUrl($url);

if (empty($data)) {
    die("No valid data found in the ODS file (all sheets were empty or had no columns)");
}

createDatabase($data);
?>
