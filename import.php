<?php
// --- MENYALAKAN DETEKTOR ERROR & MEMPERBESAR MEMORI ---
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(500); // Memperpanjang batas waktu proses hingga 500 detik
ini_set('memory_limit', '1024M'); // Memberikan RAM 1GB agar kuat membaca data spasial

// 1. KONEKSI DATABASE
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "db_sesar_opak";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) { 
    die("Database connection failed: " . $conn->connect_error); 
}

// 2. TARGET FILE GEOJSON
$nama_file = 'GeoJSON/Batas_desa_kulonprogo.geojson';

// Cek apakah file benar-benar ada di folder tersebut
if (!file_exists($nama_file)) {
    die("<h2>ERROR: File not found!</h2> Make sure '$nama_file' exists and its name uses the correct letter case.");
}

$file_geojson = file_get_contents($nama_file);
$data = json_decode($file_geojson, true);

if (!$data || !isset($data['features'])) {
    die("<h2>ERROR: Invalid data format!</h2> Could not read the GeoJSON content. Make sure the file is not corrupted.");
}

$berhasil = 0;
$gagal = 0;

echo "Starting import...<br><br>";

foreach ($data['features'] as $feature) {
    $prop = $feature['properties'];
    
    $nama_desa = $prop['WADMKD'] ?? $prop['NAMOBJ'] ?? $prop['nama_desa'] ?? 'Unnamed'; 
    $nama_kecamatan = $prop['WADMKC'] ?? $prop['kecamatan'] ?? '-'; 
    $nama_kabupaten = $prop['WADMKK'] ?? $prop['kabupaten'] ?? 'Bantul'; 
    
    $kepadatan = 1500; 
    
    $geom = json_encode($feature['geometry']);
    $geom_escaped = $conn->real_escape_string($geom);
    
    // Query INSERT sekarang mencakup kecamatan dan kabupaten
    $sql = "INSERT INTO tb_batas_desa (nama_desa, nama_kecamatan, nama_kabupaten, kepadatan_penduduk, geom_batas) 
            VALUES ('$nama_desa', '$nama_kecamatan', '$nama_kabupaten', $kepadatan, ST_GeomFromGeoJSON('$geom_escaped'))";
            
    if ($conn->query($sql) === TRUE) {
        $berhasil++;
// ... (kode foreach dan if-else di atasnya tetap sama)
    } else {
        $gagal++;
        echo "<span style='color:red;'>Error for village $nama_desa: " . $conn->error . "</span><br>";
    }
}

// ---- TAMBAHKAN 3 BARIS INI BIAR KETAHUAN KALAU SUDAH SELESAI ----
echo "<h2>Import Complete!</h2>";
echo "Successfully imported: <b>$berhasil</b> village polygons<br>";
echo "Failed: <b>$gagal</b> polygons<br>";

$conn->close();
?>