<?php
require_once __DIR__ . '/../config/db.php';

try {
    // Add weight and height columns to appointments table
    $sql = "ALTER TABLE appointments 
            ADD COLUMN weight DECIMAL(5,2) DEFAULT NULL,
            ADD COLUMN height DECIMAL(5,2) DEFAULT NULL";

    if ($mysqli->query($sql) === TRUE) {
        echo "Successfully added weight and height columns to appointments table.\n";
    } else {
        throw new Exception("Error adding columns: " . $mysqli->error);
    }

    echo "Database update completed successfully.\n";

} catch (Exception $e) {
    die("Error: " . $e->getMessage() . "\n");
}

$mysqli->close();
