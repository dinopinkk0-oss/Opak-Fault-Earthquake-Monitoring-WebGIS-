<?php
// 1. KONEKSI DATABASE
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "db_sesar_opak";
mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) { 
    die("Database connection failed: " . $conn->connect_error); 
}

// --- KONFIGURASI PYTHON UNTUK KONVERSI .GRD ---
// Di server produksi Linux, SANGAT disarankan set path absolut lewat konstanta di bawah ini,
// karena PATH milik user web server (mis. www-data) sering tidak sama dengan PATH shell interaktif kamu.
// Contoh: define('PYTHON_BIN', '/usr/bin/python3');
// Kalau tidak di-override, sistem menebak otomatis: 'py' di Windows (XAMPP), 'python3' di Linux/Mac.
// define('PYTHON_BIN', '/usr/bin/python3');

/**
 * Menentukan executable Python yang dipakai untuk menjalankan convert_grd.py.
 * Selalu lewat sini (bukan hardcode 'py' atau 'python') supaya gampang disesuaikan
 * saat pindah dari XAMPP Windows ke server produksi Linux.
 */
function resolvePythonBinary() {
    if (defined('PYTHON_BIN') && PYTHON_BIN !== '') {
        return PYTHON_BIN;
    }
    return (PHP_OS_FAMILY === 'Windows') ? 'py' : 'python3';
}

/**
 * Membangun & menjalankan perintah convert_grd.py dengan aman (escapeshellarg di setiap argumen,
 * termasuk path executable-nya sendiri supaya aman kalau path mengandung spasi), menangkap
 * stdout+stderr via 2>&1, dan melempar RuntimeException berisi pesan asli kalau gagal.
 * Mengembalikan isi file bounds JSON (string) jika sukses.
 */
function ambilCentroidDesaUntukMmi($conn) {
  $result = $conn->query('SELECT nama_desa, ST_X(ST_Centroid(geom_batas)) AS lng, ST_Y(ST_Centroid(geom_batas)) AS lat FROM tb_batas_desa');
  if (!$result) {
    throw new RuntimeException('Failed to retrieve village centroid coordinates for MMI sampling.');
  }

  $centroids = [];
  while ($desa = $result->fetch_assoc()) {
    if (!is_numeric($desa['lat']) || !is_numeric($desa['lng'])) continue;
    $centroids[] = [
      'nama' => $desa['nama_desa'],
      'lat' => (float)$desa['lat'],
      'lng' => (float)$desa['lng'],
    ];
  }
  return $centroids;
}

function jalankanKonversiGrd($inputGrdPath, $outputPngAbs, $outputBoundsAbs, $desaCentroids, $outputMmiAbs) {
    // Cek dulu apakah fungsi exec() diblokir lewat disable_functions di php.ini (umum di shared hosting)
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('exec', $disabled, true)) {
        throw new RuntimeException('The exec() function is disabled in php.ini (disable_functions). Ask the server administrator to enable it or run .grd conversion through a separate process (cron/queue).');
    }

    $pythonScript = __DIR__ . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'convert_grd.py';
    if (!is_file($pythonScript)) {
        throw new RuntimeException('convert_grd.py was not found at: ' . $pythonScript);
    }

    $pythonBin = resolvePythonBinary();
    $inputCentroidsAbs = tempnam(sys_get_temp_dir(), 'desa-mmi-');
    if ($inputCentroidsAbs === false) {
      throw new RuntimeException('Failed to create a temporary village-coordinate file for MMI sampling.');
    }
    $centroidsJson = json_encode($desaCentroids);
    if ($centroidsJson === false || file_put_contents($inputCentroidsAbs, $centroidsJson) === false) {
      @unlink($inputCentroidsAbs);
      throw new RuntimeException('Failed to prepare village coordinates for MMI sampling.');
    }
    $command = escapeshellarg($pythonBin) . ' '
        . escapeshellarg($pythonScript) . ' '
        . escapeshellarg($inputGrdPath) . ' '
        . escapeshellarg($outputPngAbs) . ' '
      . escapeshellarg($outputBoundsAbs) . ' '
      . escapeshellarg($inputCentroidsAbs) . ' '
      . escapeshellarg($outputMmiAbs) . ' 2>&1';

    exec($command, $output, $return_var);
    @unlink($inputCentroidsAbs);

    if ($return_var !== 0) {
        $pesan = trim(implode("\n", $output));
        if ($pesan === '') {
            $pesan = "Python produced no output. The '$pythonBin' executable may not be in the web server's PATH; try setting PYTHON_BIN to its absolute path.";
        }
        throw new RuntimeException('Python Error: ' . $pesan);
    }
    if (!is_file($outputPngAbs)) {
        throw new RuntimeException('Python completed without errors, but the converted PNG was not found at: ' . $outputPngAbs);
      }
    if (!is_file($outputBoundsAbs)) {
        throw new RuntimeException('Python completed without errors, but bounds.json was not found at: ' . $outputBoundsAbs);
    }
    if (!is_file($outputMmiAbs)) {
      throw new RuntimeException('Python completed without a village MMI sampling result at: ' . $outputMmiAbs);
    }

    return file_get_contents($outputBoundsAbs);
}

/**
 * Sama seperti jalankanKonversiGrd(), tapi memanggil interpolasi_idw.py --
 * dipakai untuk shakemap "komunitas" (dari titik laporan warga), bukan dari
 * file .grd resmi. Input-nya berupa path file JSON titik (lon/lat/mmi), bukan
 * path .grd, jadi argumen pertamanya beda makna walau pola pemanggilannya sama.
 */
function jalankanInterpolasiIdw($inputJsonPath, $outputPngAbs, $outputBoundsAbs) {
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('exec', $disabled, true)) {
        throw new RuntimeException('The exec() function is disabled in php.ini (disable_functions).');
    }

    $pythonScript = __DIR__ . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'interpolasi_idw.py';
    if (!is_file($pythonScript)) {
        throw new RuntimeException('interpolasi_idw.py was not found at: ' . $pythonScript);
    }

    $pythonBin = resolvePythonBinary();
    $command = escapeshellarg($pythonBin) . ' '
        . escapeshellarg($pythonScript) . ' '
        . escapeshellarg($inputJsonPath) . ' '
        . escapeshellarg($outputPngAbs) . ' '
        . escapeshellarg($outputBoundsAbs) . ' 2>&1';

    exec($command, $output, $return_var);

    if ($return_var !== 0) {
        $pesan = trim(implode("\n", $output));
        throw new RuntimeException('Python (IDW) error: ' . ($pesan !== '' ? $pesan : 'Unknown error.'));
    }
    if (!is_file($outputBoundsAbs)) {
        throw new RuntimeException('interpolasi_idw.py completed without errors, but bounds.json was not found.');
    }

    return file_get_contents($outputBoundsAbs);
}

/**
 * Konversi skala MMI dalam bentuk angka Romawi (sesuai pilihan di form Lapor
 * Merasakan: II, III, IV, V, VI+) menjadi angka desimal untuk keperluan
 * interpolasi. VI+ diberi nilai 6.5 (mewakili "VI ke atas").
 */
function skalaMmiKeAngka($skalaMmi) {
    $peta = ['II' => 2.0, 'III' => 3.0, 'IV' => 4.0, 'V' => 5.0, 'VI+' => 6.5];
    return $peta[$skalaMmi] ?? null;
}

// ---> PASTE SCRIPT PERTAMA DI SINI <---
/**
 * Konversi angka desimal hasil perhitungan ke Skala MMI Romawi baku
 */
function angkaKeSkalaMmi($nilaiDesimal) {
    $nilai = (int) round($nilaiDesimal);
    if ($nilai <= 1) return 'I';
    if ($nilai === 2) return 'II';
    if ($nilai === 3) return 'III';
    if ($nilai === 4) return 'IV';
    if ($nilai === 5) return 'V';
    if ($nilai === 6) return 'VI';
    if ($nilai === 7) return 'VII';
    if ($nilai === 8) return 'VIII';
    if ($nilai === 9) return 'IX';
    if ($nilai === 10) return 'X';
    if ($nilai === 11) return 'XI';
    if ($nilai >= 12) return 'XII';
    return 'I';
}

/**
 * Menarik titik-titik (lon, lat, mmi) dari laporan warga untuk 1 gempa tertentu,
 * dengan lokasi tiap laporan diwakili oleh CENTROID poligon desanya (karena warga
 * cuma memilih nama desa, bukan koordinat presisi). Kalau 1 desa dilaporkan lebih
 * dari sekali, nilai MMI-nya dirata-rata dulu -- supaya tidak ada 2 titik yang
 * posisinya PERSIS sama tapi nilainya beda masuk ke interpolasi (rawan bikin
 * pembobotan IDW jadi aneh).
 */
function ambilTitikLaporanUntukInterpolasi($conn, $idGempa) {
    $sql = "SELECT
              d.nama_desa,
              ST_X(ST_Centroid(d.geom_batas)) AS lon,
              ST_Y(ST_Centroid(d.geom_batas)) AS lat,
              l.skala_mmi
            FROM tb_laporan_masyarakat l
            JOIN tb_batas_desa d ON d.nama_desa = l.nama_desa
            WHERE l.id_gempa = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $idGempa);
    $stmt->execute();
    $hasil = $stmt->get_result();

    $perDesa = []; // nama_desa -> ['lon'=>, 'lat'=>, 'nilai'=>[mmi, mmi, ...]]
    while ($baris = $hasil->fetch_assoc()) {
        $angkaMmi = skalaMmiKeAngka($baris['skala_mmi']);
        if ($angkaMmi === null) continue;
        $nama = $baris['nama_desa'];
        if (!isset($perDesa[$nama])) {
            $perDesa[$nama] = ['lon' => (float)$baris['lon'], 'lat' => (float)$baris['lat'], 'nilai' => []];
        }
        $perDesa[$nama]['nilai'][] = $angkaMmi;
    }
    $stmt->close();

    $titik = [];
    foreach ($perDesa as $desa) {
        $titik[] = [
            'lon' => $desa['lon'],
            'lat' => $desa['lat'],
            'mmi' => array_sum($desa['nilai']) / count($desa['nilai']),
        ];
    }
    return $titik;
}

/**
 * Membuat/memperbarui shakemap "komunitas" untuk 1 gempa, dari titik-titik
 * laporan warga yang sudah masuk sejauh ini. Dipanggil otomatis setiap ada
 * laporan baru (lihat action=save_laporan). Sengaja TIDAK melempar exception
 * ke pemanggilnya -- kegagalan di sini (termasuk "belum cukup titik") tidak
 * boleh menggagalkan penyimpanan laporan warga itu sendiri.
 */
function buatShakemapKomunitas($conn, $idGempa) {
    try {
        $titik = ambilTitikLaporanUntukInterpolasi($conn, $idGempa);
        if (count($titik) < 3) {
            return; // Belum cukup desa berbeda untuk bikin permukaan interpolasi yang masuk akal
        }

        $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'shakemap';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            return;
        }

        $inputJsonAbs = $uploadDir . DIRECTORY_SEPARATOR . 'komunitas_' . $idGempa . '_titik.json';
        file_put_contents($inputJsonAbs, json_encode(array_values($titik)));

        $shakemap_komunitas_rel = 'uploads/shakemap/komunitas_' . $idGempa . '.png';
        $bounds_rel = 'uploads/shakemap/komunitas_' . $idGempa . '.json';
        $outputPngAbs = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $shakemap_komunitas_rel);
        $outputBoundsAbs = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $bounds_rel);

        $boundsJson = jalankanInterpolasiIdw($inputJsonAbs, $outputPngAbs, $outputBoundsAbs);

        $stmt = $conn->prepare('UPDATE tb_gempa SET shakemap_komunitas = ?, shakemap_komunitas_bounds = ? WHERE id_gempa = ?');
        if ($stmt) {
            $stmt->bind_param('ssi', $shakemap_komunitas_rel, $boundsJson, $idGempa);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $e) {
        // Diamkan dengan sengaja (best-effort) -- laporan warga tetap tersimpan
        // walau pembuatan shakemap komunitas gagal (misal exec() diblokir server).
    }
}

function tentukanFolderSegmenUpload($latitude, $longitude) {
  $latitude = filter_var($latitude, FILTER_VALIDATE_FLOAT);
  $longitude = filter_var($longitude, FILTER_VALIDATE_FLOAT);
  if ($latitude === false || $longitude === false || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
    return 'luar_segmen';
  }

  global $conn;
  $result = $conn->query("SELECT id_segmen, ST_AsGeoJSON(geom_segmen) AS geojson FROM tb_segmen_opak ORDER BY id_segmen");
  if (!$result) return 'luar_segmen';

  $titikDiDalamCincin = function ($latitude, $longitude, $cincin) {
    $diDalam = false;
    $jumlah = count($cincin);
    for ($i = 0, $j = $jumlah - 1; $i < $jumlah; $j = $i++) {
      $xi = (float)$cincin[$i][0];
      $yi = (float)$cincin[$i][1];
      $xj = (float)$cincin[$j][0];
      $yj = (float)$cincin[$j][1];
      $memotong = (($yi > $latitude) !== ($yj > $latitude)) &&
        ($longitude < ($xj - $xi) * ($latitude - $yi) / (($yj - $yi) ?: 1e-12) + $xi);
      if ($memotong) $diDalam = !$diDalam;
    }
    return $diDalam;
  };

  while ($row = $result->fetch_assoc()) {
    $geometri = json_decode($row['geojson'], true);
    if (!is_array($geometri) || !in_array($geometri['type'] ?? '', ['Polygon', 'MultiPolygon'], true)) continue;
    $geometri['coordinates'] = transformasiKoordinatUtm49S($geometri['coordinates']);
    $poligon = $geometri['type'] === 'Polygon' ? [$geometri['coordinates']] : $geometri['coordinates'];
    foreach ($poligon as $batas) {
      if (!$titikDiDalamCincin((float)$latitude, (float)$longitude, $batas[0])) continue;
      $dalamLubang = false;
      for ($i = 1; $i < count($batas); $i++) {
        if ($titikDiDalamCincin((float)$latitude, (float)$longitude, $batas[$i])) {
          $dalamLubang = true;
          break;
        }
      }
      if (!$dalamLubang) return 'segmen_' . $row['id_segmen'];
    }
  }
  return 'luar_segmen';
}

function uploadGempaFile($fieldName, $allowedExtensions, $maxBytes = 10485760, $eventContext = null) {
  if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) return null;
  $file = $_FILES[$fieldName];
  $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  if ($file['error'] !== UPLOAD_ERR_OK || $extension === '' || !in_array($extension, $allowedExtensions, true) || $file['size'] > $maxBytes) {
    throw new RuntimeException('The ' . $fieldName . ' file is invalid or too large.');
  }
  if (!is_uploaded_file($file['tmp_name'])) {
    throw new RuntimeException('The ' . $fieldName . ' file is invalid (it was not uploaded over HTTP).');
  }
  // Verifikasi isi file benar-benar gambar (bukan sekadar nama diakhiri .png/.jpg) —
  // mencegah file berbahaya yang cuma "dibungkus" ekstensi gambar.
  $extGambar = ['png', 'jpg', 'jpeg'];
  if (in_array($extension, $extGambar, true) && @getimagesize($file['tmp_name']) === false) {
    throw new RuntimeException('The ' . $fieldName . ' file is not a valid image.');
  }
  $namaAsli = pathinfo($file['name'], PATHINFO_FILENAME);
  $namaAman = preg_replace('/[^A-Za-z0-9_-]+/', '_', $namaAsli);
  $namaAman = trim($namaAman, '_-');
  if ($namaAman === '') $namaAman = 'file';

  $waktuNama = date('Ymd_His');
  $folderSegmen = null;
  $bagianMagnitudo = '';
  if (is_array($eventContext)) {
    $tanggalWaktu = ($eventContext['tanggal'] ?? '') . ' ' . ($eventContext['waktu'] ?? '');
    $tanggalWaktuValid = DateTime::createFromFormat('!Y-m-d H:i:s', $tanggalWaktu);
    $kesalahanTanggal = DateTime::getLastErrors();
    if (!$tanggalWaktuValid || $tanggalWaktuValid->format('Y-m-d H:i:s') !== $tanggalWaktu ||
      ($kesalahanTanggal !== false && ($kesalahanTanggal['warning_count'] > 0 || $kesalahanTanggal['error_count'] > 0))) {
      throw new RuntimeException('The uploaded date or time is invalid for naming the file.');
    }

    $magnitudoNama = filter_var($eventContext['magnitudo'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($magnitudoNama === false || !is_finite((float)$magnitudoNama)) {
      throw new RuntimeException('The uploaded magnitude is invalid for naming the file.');
    }
    $waktuNama = $tanggalWaktuValid->format('Ymd_His');
    $folderSegmen = tentukanFolderSegmenUpload($eventContext['latitude'] ?? null, $eventContext['longitude'] ?? null);
    $bagianMagnitudo = '_M' . number_format((float)$magnitudoNama, 1, '.', '');
  }

  $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $fieldName;
  if ($folderSegmen !== null) $uploadDir .= DIRECTORY_SEPARATOR . $folderSegmen;
  if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) throw new RuntimeException('Could not create the upload folder.');
  $namaDasar = $waktuNama . '_' . $namaAman . $bagianMagnitudo;
  $safeName = $namaDasar . '.' . $extension;
  $destPath = $uploadDir . DIRECTORY_SEPARATOR . $safeName;
  $nomorDuplikat = 2;
  while (file_exists($destPath)) {
    $safeName = $waktuNama . '_' . $namaAman . '_' . $nomorDuplikat . $bagianMagnitudo . '.' . $extension;
    $destPath = $uploadDir . DIRECTORY_SEPARATOR . $safeName;
    $nomorDuplikat++;
  }
  if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    throw new RuntimeException('Failed to save the ' . $fieldName . ' file.');
  }
  @chmod($destPath, 0644); // Pastikan tidak executable, terutama penting di server Linux produksi
  $pathRelatif = 'uploads/' . $fieldName . '/';
  if ($folderSegmen !== null) $pathRelatif .= $folderSegmen . '/';
  return $pathRelatif . $safeName;
}

// Endpoint penyimpanan form gempa.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_gempa') {
  header('Content-Type: application/json; charset=utf-8');

  $tanggal = trim($_POST['tanggal'] ?? '');
  $jam = trim($_POST['jam'] ?? '');
  $menit = trim($_POST['menit'] ?? '');
  $detik = trim($_POST['detik'] ?? '');
  $lokasi = trim($_POST['lokasi'] ?? '');
  $keterangan = trim($_POST['keterangan'] ?? '');
  $latitude = filter_var($_POST['lat'] ?? null, FILTER_VALIDATE_FLOAT);
  $longitude = filter_var($_POST['lng'] ?? null, FILTER_VALIDATE_FLOAT);
  $magnitudo = filter_var($_POST['magnitudo'] ?? null, FILTER_VALIDATE_FLOAT);
  $kedalaman = filter_var($_POST['kedalaman'] ?? null, FILTER_VALIDATE_INT);
  $tipeMag = trim($_POST['tipe_mag'] ?? 'ML');
  $status = $_POST['status'] ?? 'Normal';
  $dirasakan = $_POST['dirasakan'] ?? 'Tidak';

  $tanggalValid = DateTime::createFromFormat('Y-m-d', $tanggal);
  $waktu = sprintf('%02d:%02d:%02d', (int)$jam, (int)$menit, (int)$detik);
  $waktuValid = DateTime::createFromFormat('H:i:s', $waktu);
  if (!$tanggalValid || $tanggalValid->format('Y-m-d') !== $tanggal || !$waktuValid || $lokasi === '' ||
    $latitude === false || $longitude === false || $magnitudo === false || $kedalaman === false ||
    $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 ||
    $magnitudo < 0 || $magnitudo > 10 || $kedalaman < 0 ||
    (int)$jam < 0 || (int)$jam > 23 || (int)$menit < 0 || (int)$menit > 59 || (int)$detik < 0 || (int)$detik > 59 ||
    !in_array($tipeMag, ['ML', 'Mw', 'Ms', 'Mb', 'MD'], true) ||
    !in_array($status, ['Normal', 'Waspada', 'Siaga', 'Bahaya'], true) || !in_array($dirasakan, ['Ya', 'Tidak'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'The submitted data is invalid.']);
    $conn->close();
    exit;
  }

  $catatan = 'Epicenter located at ' . $lokasi . ".\nStatus: " . $status . ($keterangan !== '' ? "\n" . $keterangan : '');

  $idGempaEdit = trim($_POST['id_gempa'] ?? '');
  if ($idGempaEdit !== '') {
    if (!ctype_digit($idGempaEdit) || (int)$idGempaEdit < 1) {
      http_response_code(422);
      echo json_encode(['success' => false, 'message' => 'The earthquake ID to edit is invalid.']);
      $conn->close();
      exit;
    }

    $idGempaEdit = (int)$idGempaEdit;
    $stmtCekGempa = $conn->prepare('SELECT 1 FROM tb_gempa WHERE id_gempa = ? LIMIT 1');
    if (!$stmtCekGempa) {
      http_response_code(500);
      echo json_encode(['success' => false, 'message' => 'Failed to check the earthquake record to edit.']);
      $conn->close();
      exit;
    }
    $stmtCekGempa->bind_param('i', $idGempaEdit);
    if (!$stmtCekGempa->execute()) {
      error_log('Failed to check the earthquake record to edit: ' . $stmtCekGempa->error);
      http_response_code(500);
      echo json_encode(['success' => false, 'message' => 'Failed to check the earthquake record to edit.']);
      $stmtCekGempa->close();
      $conn->close();
      exit;
    }
    $stmtCekGempa->store_result();
    $gempaAda = $stmtCekGempa->num_rows > 0;
    $stmtCekGempa->close();
    if (!$gempaAda) {
      http_response_code(404);
      echo json_encode(['success' => false, 'message' => 'The earthquake record to edit could not be found.']);
      $conn->close();
      exit;
    }

    $stmt = $conn->prepare('UPDATE tb_gempa SET tanggal = ?, waktu_ot = ?, latitude = ?, longitude = ?, kedalaman = ?, magnitudo = ?, tipe_mag = ?, keterangan = ?, dirasakan = ? WHERE id_gempa = ?');
    if (!$stmt) {
      http_response_code(500);
      echo json_encode(['success' => false, 'message' => 'Failed to prepare the earthquake data update.']);
      $conn->close();
      exit;
    }
    $waktuSql = $waktuValid->format('H:i:s');
    $stmt->bind_param('ssddidsssi', $tanggal, $waktuSql, $latitude, $longitude, $kedalaman, $magnitudo, $tipeMag, $catatan, $dirasakan, $idGempaEdit);
    if (!$stmt->execute()) {
      http_response_code(500);
      echo json_encode(['success' => false, 'message' => 'Failed to update earthquake data: ' . $stmt->error]);
    } else {
      echo json_encode(['success' => true, 'id_gempa' => $idGempaEdit, 'updated' => true]);
    }
    $stmt->close();
    $conn->close();
    exit;
  }

  $shakemap = null;
  $fileSinyal = null;
  $shakemap_vektor = ''; // Kolom NOT NULL di DB — gunakan string kosong, bukan null
  $shakemap_bounds = ''; // Kolom NOT NULL di DB — gunakan string kosong, bukan null
  $eventUploadContext = [
    'tanggal' => $tanggal,
    'waktu' => $waktu,
    'magnitudo' => $magnitudo,
    'latitude' => $latitude,
    'longitude' => $longitude,
  ];

  try {
    $shakemap = uploadGempaFile('shakemap', ['png', 'jpg', 'jpeg', 'grd', 'asc', 'tif', 'tiff'], 10485760, $eventUploadContext);
    $fileSinyal = uploadGempaFile('file_sinyal', ['mseed', 'miniseed', 'sac', 'seed', 'txt', 'csv', 'zip'], 10485760, $eventUploadContext);

    // --- INTEGRASI PYTHON UNTUK GRD KE PNG (RASTER MMI) + BOUNDS ---
    if ($shakemap !== null) {
        $ext = strtolower(pathinfo($shakemap, PATHINFO_EXTENSION));
        if ($ext === 'grd') {
            $input_grd = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $shakemap);
            $nama_file_dasar = pathinfo($shakemap, PATHINFO_FILENAME);

            // Path relatif dan absolut untuk PNG + JSON bounds
            $folderSegmen = basename(dirname($shakemap));
            $direktoriShakemapRel = 'uploads/shakemap/' . $folderSegmen;
            $shakemap_vektor = $direktoriShakemapRel . '/' . $nama_file_dasar . '.png';
            $bounds_rel = $direktoriShakemapRel . '/' . $nama_file_dasar . '.json';
            $mmi_rel = $direktoriShakemapRel . '/' . $nama_file_dasar . '.mmi.json';
            $output_png_abs = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $shakemap_vektor);
            $output_bounds_abs = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $bounds_rel);
            $output_mmi_abs = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $mmi_rel);

            // Jika Python gagal, jalankanKonversiGrd() akan melempar RuntimeException berisi
            // pesan error asli (stderr/traceback) — supaya tidak diam-diam menyimpan data kosong.
            $shakemap_bounds = jalankanKonversiGrd(
              $input_grd,
              $output_png_abs,
              $output_bounds_abs,
              ambilCentroidDesaUntukMmi($conn),
              $output_mmi_abs
            );
            if (!@unlink($input_grd)) {
                throw new RuntimeException('Conversion succeeded, but the source .grd file could not be deleted.');
            }
            $shakemap = null;
        }
    }
  } catch (RuntimeException $error) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
    $conn->close();
    exit;
  }
  $waktuSql = $waktuValid->format('H:i:s');
  $stmt = $conn->prepare('INSERT INTO tb_gempa (tanggal, waktu_ot, latitude, longitude, kedalaman, magnitudo, tipe_mag, keterangan, dirasakan, shakemap, file_sinyal, shakemap_vektor, shakemap_bounds) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
  $stmt->bind_param('ssddidsssssss', $tanggal, $waktuSql, $latitude, $longitude, $kedalaman, $magnitudo, $tipeMag, $catatan, $dirasakan, $shakemap, $fileSinyal, $shakemap_vektor, $shakemap_bounds);

  if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to save data: ' . $stmt->error]);
  } else {
    echo json_encode(['success' => true, 'id_gempa' => $stmt->insert_id]);
  }
  $stmt->close();
  $conn->close();
  exit;
}

// ==========================================
// ENDPOINT: LAPOR MERASAKAN GEMPA (Felt Report)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_laporan') {
  header('Content-Type: application/json; charset=utf-8');

  try {
    $skalaMmiDiizinkan = ['II', 'III', 'IV', 'V', 'VI+'];
    $skalaMmi = trim($_POST['skala_mmi'] ?? '');
    $namaDesa = trim($_POST['nama_desa'] ?? '');

    // id_gempa OPSIONAL: kalau tombol "Lapor Merasakan" diklik dari dalam pop-up
    // titik gempa tertentu di peta, field ini terisi ID gempa itu (lihat data-id-gempa
    // pada tombol popup). Kalau laporan dikirim lewat tombol umum di panel (tanpa
    // konteks titik gempa), nilainya kosong -> disimpan NULL (laporan umum).
    $idGempaRaw = trim($_POST['id_gempa'] ?? '');
    $idGempa = null;
    if ($idGempaRaw !== '') {
      if (!ctype_digit($idGempaRaw)) {
        throw new RuntimeException('The earthquake ID is invalid.');
      }
      $idGempa = (int) $idGempaRaw;
      $stmtCekGempa = $conn->prepare('SELECT 1 FROM tb_gempa WHERE id_gempa = ? LIMIT 1');
      if (!$stmtCekGempa) {
        throw new RuntimeException('Failed to validate the earthquake record.');
      }
      $stmtCekGempa->bind_param('i', $idGempa);
      $stmtCekGempa->execute();
      $stmtCekGempa->store_result();
      $gempaValid = $stmtCekGempa->num_rows > 0;
      $stmtCekGempa->close();
      if (!$gempaValid) {
        throw new RuntimeException('The earthquake record could not be found.');
      }
    }

    if (!in_array($skalaMmi, $skalaMmiDiizinkan, true)) {
      throw new RuntimeException('The MMI scale is invalid.');
    }
    if ($namaDesa === '') {
      throw new RuntimeException('A village must be selected.');
    }
    // Validasi nama_desa harus benar-benar salah satu desa yang ada di database --
    // dicek langsung ke tabel (bukan lewat variabel $batas_desa) karena endpoint ini
    // dieksekusi lebih awal, sebelum query daftar desa untuk halaman dijalankan.
    $stmtCekDesa = $conn->prepare('SELECT 1 FROM tb_batas_desa WHERE nama_desa = ? LIMIT 1');
    if (!$stmtCekDesa) {
      throw new RuntimeException('Failed to validate the village.');
    }
    $stmtCekDesa->bind_param('s', $namaDesa);
    $stmtCekDesa->execute();
    $stmtCekDesa->store_result();
    $desaValid = $stmtCekDesa->num_rows > 0;
    $stmtCekDesa->close();
    if (!$desaValid) {
      throw new RuntimeException('The village could not be found.');
    }

    $stmt = $conn->prepare('INSERT INTO tb_laporan_masyarakat (id_gempa, skala_mmi, nama_desa) VALUES (?, ?, ?)');
    if (!$stmt) {
      throw new RuntimeException('The community reports table is not ready.');
    }
    $stmt->bind_param('iss', $idGempa, $skalaMmi, $namaDesa);
    if (!$stmt->execute()) {
      $stmt->close();
      throw new RuntimeException('Failed to save the report to the database.');
    }
    $stmt->close();

    // Setiap ada laporan baru masuk untuk gempa tertentu, coba buat/perbarui shakemap
    // komunitas-nya (best-effort -- kalau gagal atau titiknya belum cukup, diamkan
    // saja, tidak menggagalkan respons sukses penyimpanan laporan ini).
    if ($idGempa !== null) {
        buatShakemapKomunitas($conn, $idGempa);
    }

    echo json_encode(['success' => true]);
  } catch (Throwable $error) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
  }
  $conn->close();
  exit;
}

// ==========================================
// ENDPOINT: UPLOAD FOTO GALERI
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_galeri') {
  header('Content-Type: application/json; charset=utf-8');
  $namaFile = null;

  try {
    $deskripsi = trim($_POST['deskripsi'] ?? '');
    if (strlen($deskripsi) > 2000) {
      throw new RuntimeException('The description cannot exceed 2,000 characters.');
    }

    $pathFile = uploadGempaFile('galeri', ['png', 'jpg', 'jpeg', 'webp'], 10485760);
    if ($pathFile === null) {
      throw new RuntimeException('A photo is required.');
    }
    $namaFile = basename($pathFile);

    $stmt = $conn->prepare('INSERT INTO tb_galeri (nama_file, deskripsi) VALUES (?, ?)');
    if (!$stmt) {
      throw new RuntimeException('The gallery table is not ready.');
    }
    $stmt->bind_param('ss', $namaFile, $deskripsi);
    if (!$stmt->execute()) {
      $stmt->close();
      @unlink(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pathFile));
      throw new RuntimeException('Failed to save the photo to the database.');
    }
    $stmt->close();
    echo json_encode(['success' => true]);
  } catch (Throwable $error) {
    if ($namaFile !== null) {
      @unlink(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'galeri' . DIRECTORY_SEPARATOR . $namaFile);
    }
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
  }
  $conn->close();
  exit;
}

// ==========================================
// ENDPOINT: UPLOAD CEPAT REAL-TIME (XML + GRD)
// ==========================================
function normalisasiHeaderGempa($header) {
  $header = strtolower(trim(str_replace(["\xEF\xBB\xBF", "\u{FEFF}"], '', (string)$header)));
  return preg_replace('/[^a-z0-9]+/', '', $header);
}

function ambilKolomHeaderGempa($headers) {
  $alias = [
    'tanggal' => ['tanggal', 'tgl', 'date', 'eventdate', 'origindate', 'tanggalkejadian'],
    'waktu' => ['waktu', 'jam', 'jamutc', 'time', 'timeutc', 'origintime', 'origintimeutc', 'waktuutc', 'waktuorigin', 'datetime', 'origindatetime', 'tanggalwaktu'],
    'latitude' => ['lintang', 'latitude', 'lat', 'latitudedeg', 'latitudedegrees', 'latituden', 'latitudes', 'lintangdeg'],
    'longitude' => ['bujur', 'longitude', 'lon', 'lng', 'long', 'longitudedeg', 'longitudedegrees', 'longitudee', 'longitudew', 'bujurdeg'],
    'kedalaman' => ['kedalaman', 'depth', 'depthkm', 'kedalamankm'],
    'magnitudo' => ['magnitudo', 'magnitude', 'mag', 'magml', 'magmw', 'magmb', 'magmd', 'magnitudeml', 'magnitudemw'],
    'jenis' => ['jenis', 'type', 'tipe', 'tipemagnitudo', 'magnitudetype'],
    'keterangan' => ['keterangan', 'description', 'remark', 'remarks', 'lokasi', 'location', 'eventdescription']
  ];

  $kolom = [];
  foreach ($headers as $index => $header) {
    $nama = normalisasiHeaderGempa($header);
    foreach ($alias as $field => $namaAlias) {
      if (in_array($nama, $namaAlias, true) && !isset($kolom[$field])) {
        $kolom[$field] = $index;
      }
    }
  }
  return $kolom;
}

function parseAngkaGempa($value, $field, $lineNumber, $hemisphereAllowed = false) {
  $value = trim((string)$value);
  $hemisphere = '';
  if ($hemisphereAllowed && preg_match('/(LU|LS|BT|BB|N|S|E|W)\s*$/i', $value, $match)) {
    $hemisphere = strtoupper($match[1]);
    $value = trim(substr($value, 0, -strlen($match[0])));
  }
  $value = str_replace(["\u{2212}", "\u{2013}", "\u{2014}"], '-', $value);
  $value = preg_replace('/[^0-9,.\-+]/', '', $value);
  if ($value === '') {
    throw new RuntimeException("Line {$lineNumber}: {$field} is empty or invalid.");
  }
  if (strpos($value, ',') !== false && strpos($value, '.') !== false) {
    $value = str_replace(',', '', $value);
  } else {
    $value = str_replace(',', '.', $value);
  }
  if (!is_numeric($value)) {
    throw new RuntimeException("Line {$lineNumber}: '{$value}' is not a valid number for {$field}.");
  }
  $number = (float)$value;
  if (in_array($hemisphere, ['S', 'W', 'LS', 'BB'], true)) $number = -abs($number);
  if (in_array($hemisphere, ['N', 'E', 'LU', 'BT'], true)) $number = abs($number);
  if (!is_finite($number)) {
    throw new RuntimeException("Line {$lineNumber}: {$field} is invalid.");
  }
  return $number;
}

function parseTanggalWaktuGempa($dateValue, $timeValue, $lineNumber) {
  $dateValue = trim((string)$dateValue, " \t\n\r\0\x0B\"'");
  $timeValue = trim((string)$timeValue, " \t\n\r\0\x0B\"'");
  if ($dateValue === '' && preg_match('/^(\d{4}[-\/.]\d{1,2}[-\/.]\d{1,2})[T\s]+(\d{1,2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:\s*(?:UTC|GMT|Z))?)$/i', $timeValue, $match)) {
    $dateValue = trim($match[1]);
    $timeValue = trim($match[2]);
  }
  if ($timeValue === '' && preg_match('/^(.+?)\s+(\d{1,2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:\s*(?:UTC|GMT|Z))?)$/i', $dateValue, $match)) {
    $dateValue = trim($match[1]);
    $timeValue = trim($match[2]);
  }

  $tanggal = false;
  foreach (['!Y-m-d', '!Y/m/d', '!Y.m.d', '!d/m/Y', '!d-m-Y', '!d.m.Y', '!m/d/Y'] as $format) {
    $candidate = DateTime::createFromFormat($format, $dateValue);
    $errors = DateTime::getLastErrors();
    if ($candidate && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
      $tanggal = $candidate;
      break;
    }
  }
  if (!$tanggal) {
    throw new RuntimeException("Line {$lineNumber}: date '{$dateValue}' is not recognized. Use YYYY-MM-DD or DD/MM/YYYY.");
  }

  if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?(?:\s*(?:UTC|GMT|Z))?$/i', $timeValue, $timeParts)) {
    throw new RuntimeException("Line {$lineNumber}: time '{$timeValue}' is not recognized. Use HH:MM or HH:MM:SS.");
  }
  $hour = (int)$timeParts[1];
  $minute = (int)$timeParts[2];
  $second = (int)($timeParts[3] ?? 0);
  if ($hour > 23 || $minute > 59 || $second > 59) {
    throw new RuntimeException("Line {$lineNumber}: time is outside the valid range.");
  }

  return [
    'tanggal' => $tanggal->format('Y-m-d'),
    'waktu' => sprintf('%02d:%02d:%02d', $hour, $minute, $second)
  ];
}

function bacaDataGempaTxt($filePath) {
  $contents = file_get_contents($filePath);
  if ($contents === false || trim($contents) === '') {
    throw new RuntimeException('The TXT file is empty or could not be read.');
  }
  $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
  $lines = preg_split('/\r\n|\n|\r/', $contents);
  $headerLineIndex = null;
  $headers = [];
  $kolom = [];
  $delimiter = "\t";

  foreach ($lines as $lineIndex => $line) {
    if (trim($line) === '') continue;
    foreach (["\t", ';', ',', '|'] as $candidateDelimiter) {
      $candidateHeaders = str_getcsv($line, $candidateDelimiter);
      $candidateColumns = ambilKolomHeaderGempa($candidateHeaders);
      if (isset($candidateColumns['latitude'], $candidateColumns['longitude'], $candidateColumns['magnitudo']) &&
        (isset($candidateColumns['tanggal']) || isset($candidateColumns['waktu']))) {
        $headerLineIndex = $lineIndex;
        $headers = $candidateHeaders;
        $kolom = $candidateColumns;
        $delimiter = $candidateDelimiter;
        break 2;
      }
    }
  }

  if ($headerLineIndex === null) {
    throw new RuntimeException('The TXT header is not recognized. Include date/time, latitude, longitude, and magnitude columns; tabs, commas, semicolons, and | are supported as delimiters.');
  }
  $records = [];
  foreach ($lines as $lineIndex => $line) {
    if ($lineIndex <= $headerLineIndex || trim($line) === '') continue;
    $values = str_getcsv($line, $delimiter);
    if (count($values) < count($headers)) {
      $values = array_pad($values, count($headers), '');
    }
    $row = [];
    foreach ($kolom as $field => $columnIndex) {
      $row[$field] = trim((string)($values[$columnIndex] ?? ''), " \t\n\r\0\x0B\"'");
    }

    $lineNumber = $lineIndex + 1;
    $dateValue = $row['tanggal'] ?? '';
    $timeValue = $row['waktu'] ?? '';
    if ($dateValue === '' && $timeValue === '') continue;
    $dateTime = parseTanggalWaktuGempa($dateValue, $timeValue, $lineNumber);
    $latitude = parseAngkaGempa($row['latitude'] ?? '', 'lintang/latitude', $lineNumber, true);
    $longitude = parseAngkaGempa($row['longitude'] ?? '', 'bujur/longitude', $lineNumber, true);
    $magnitude = parseAngkaGempa($row['magnitudo'] ?? '', 'magnitudo', $lineNumber);
    $depth = isset($row['kedalaman']) && $row['kedalaman'] !== ''
      ? parseAngkaGempa($row['kedalaman'], 'kedalaman', $lineNumber)
      : 0;

    if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 || $magnitude < 0 || $magnitude > 10 || $depth < 0) {
      throw new RuntimeException("Line {$lineNumber}: coordinates, magnitude, or depth are outside the valid range.");
    }

    $records[] = [
      'tanggal' => $dateTime['tanggal'],
      'waktu' => $dateTime['waktu'],
      'latitude' => $latitude,
      'longitude' => $longitude,
      'kedalaman' => $depth,
      'magnitudo' => $magnitude,
      'tipe_mag' => ($row['jenis'] ?? '') !== '' ? $row['jenis'] : 'ML',
      'keterangan' => ($row['keterangan'] ?? '') !== '' ? $row['keterangan'] : 'Diimpor dari file TXT.',
    ];
  }

  if (!$records) {
    throw new RuntimeException('No valid earthquake records were found after the TXT header.');
  }
  return $records;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'quick_import') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    if (!isset($_FILES['file_xml']) || $_FILES['file_xml']['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('An XML/TXT event file is required.');
    }

    $eventExtension = strtolower(pathinfo($_FILES['file_xml']['name'], PATHINFO_EXTENSION));
    if (!in_array($eventExtension, ['xml', 'txt', 'csv'], true)) {
        throw new RuntimeException('The event file must be in XML, TXT, or CSV format.');
    }

    // 1. Baca parameter dari XML SeisComP atau semua baris data TXT/CSV bertabel.
    $eventRecords = [];
    if ($eventExtension === 'xml') {
      $xml = simplexml_load_file($_FILES['file_xml']['tmp_name']);
      if (!$xml) throw new RuntimeException('The XML file is invalid or corrupted.');
      $tahun = (int)((string)($xml['year'] ?? date('Y')));
      $bulan = (int)((string)($xml['month'] ?? date('m')));
      $hari  = (int)((string)($xml['day'] ?? date('d')));
      $tanggal = sprintf('%04d-%02d-%02d', $tahun, $bulan, $hari);
      $jam = (int)((string)($xml['hour'] ?? 0));
      $menit = (int)((string)($xml['minute'] ?? 0));
      $detik = (int)((string)($xml['second'] ?? 0));
      $latitude = (float)((string)($xml['lat'] ?? 0));
      $longitude = (float)((string)($xml['lon'] ?? 0));
      $kedalaman = (int)((string)($xml['depth'] ?? 0));
      $magnitudo = (float)((string)($xml['mag'] ?? 0));
      $tipeMag = (string)($xml['type'] ?? 'ML');
      $locstring = (string)($xml['locstring'] ?? '');
      $keterangan = $locstring ? 'Epicenter located at ' . $locstring . '.' : 'Analyzed from SeisComP XML.';
      if (!checkdate($bulan, $hari, $tahun) || $jam > 23 || $menit > 59 || $detik > 59 ||
        $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 ||
        $magnitudo < 0 || $magnitudo > 10 || $latitude === 0.0 && $longitude === 0.0) {
        throw new RuntimeException('The XML file does not contain a valid earthquake date, time, coordinates, and magnitude.');
      }
      $eventRecords[] = [
        'tanggal' => $tanggal,
        'waktu' => sprintf('%02d:%02d:%02d', $jam, $menit, $detik),
        'latitude' => $latitude,
        'longitude' => $longitude,
        'kedalaman' => (float)((string)($xml['depth'] ?? 0)),
        'magnitudo' => $magnitudo,
        'tipe_mag' => $tipeMag,
        'keterangan' => $keterangan,
      ];
    } else {
      $eventRecords = bacaDataGempaTxt($_FILES['file_xml']['tmp_name']);
    }

    if (!$eventRecords) {
      throw new RuntimeException('There is no earthquake data to import.');
    }
    $eventUpload = $eventRecords[0];
    $tanggal = $eventUpload['tanggal'];
    $waktuSql = $eventUpload['waktu'];
    $latitude = $eventUpload['latitude'];
    $longitude = $eventUpload['longitude'];
    $kedalaman = $eventUpload['kedalaman'];
    $magnitudo = $eventUpload['magnitudo'];
    $tipeMag = $eventUpload['tipe_mag'];
    $keterangan = $eventUpload['keterangan'];
    $dirasakan = 'Tidak';
    $eventUploadContext = [
      'tanggal' => $tanggal,
      'waktu' => $waktuSql,
      'magnitudo' => $magnitudo,
      'latitude' => $latitude,
      'longitude' => $longitude,
    ];

    // 2. Input 3: Gambar Peta Detail (layout utuh BMKG, ditampilkan di modal "Lihat Detail")
    //    -> kolom `shakemap`
    $shakemap = null;
    $gambarShakemap = uploadGempaFile('file_shakemap', ['png', 'jpg', 'jpeg'], 10485760, $eventUploadContext);
    if ($gambarShakemap !== null) {
      $shakemap = $gambarShakemap;
    }

    // 3. Input 2: File Grid MMI (.grd) — konversi ke PNG lewat convert_grd.py (sama seperti form manual)
    //    -> kolom `shakemap_vektor` + `shakemap_bounds`
    $shakemap_vektor = ''; // Kolom NOT NULL di DB — gunakan string kosong, bukan null
    $shakemap_bounds = '';

    $gridFile = uploadGempaFile('file_grd', ['grd', 'asc', 'tif', 'tiff'], 10485760, $eventUploadContext);
    if ($gridFile !== null) {
        $input_grd = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $gridFile);
        $nama_file_dasar = pathinfo($gridFile, PATHINFO_FILENAME);

        $folderSegmen = basename(dirname($gridFile));
        $direktoriShakemapRel = 'uploads/shakemap/' . $folderSegmen;
        $direktoriShakemapAbs = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $direktoriShakemapRel);
        if (!is_dir($direktoriShakemapAbs) && !mkdir($direktoriShakemapAbs, 0755, true)) {
          throw new RuntimeException('Could not create the segment ShakeMap folder.');
        }
        $shakemap_vektor = $direktoriShakemapRel . '/' . $nama_file_dasar . '.png';
        $bounds_rel = $direktoriShakemapRel . '/' . $nama_file_dasar . '.json';
        $mmi_rel = $direktoriShakemapRel . '/' . $nama_file_dasar . '.mmi.json';
        $output_png_abs = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $shakemap_vektor);
        $output_bounds_abs = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $bounds_rel);
        $output_mmi_abs = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $mmi_rel);

        // Sama seperti form manual: kalau Python gagal, dilempar sebagai RuntimeException
        // (ditangkap oleh try-catch di uploadGempaFile()'s caller / catch Throwable di bawah)
        $shakemap_bounds = jalankanKonversiGrd(
          $input_grd,
          $output_png_abs,
          $output_bounds_abs,
          ambilCentroidDesaUntukMmi($conn),
          $output_mmi_abs
        );
        if (strtolower(pathinfo($gridFile, PATHINFO_EXTENSION)) === 'grd' && !@unlink($input_grd)) {
            throw new RuntimeException('Conversion succeeded, but the source .grd file could not be deleted.');
        }
    }

    $fileSinyal = null;

    // 5. Simpan setiap gempa yang dibaca. File shakemap/grid melekat ke gempa pertama.
    $stmt = $conn->prepare('INSERT INTO tb_gempa (tanggal, waktu_ot, latitude, longitude, kedalaman, magnitudo, tipe_mag, keterangan, dirasakan, shakemap, file_sinyal, shakemap_vektor, shakemap_bounds) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) throw new RuntimeException('Failed to prepare earthquake data storage: ' . $conn->error);
    $conn->begin_transaction();
    foreach ($eventRecords as $index => $eventRecord) {
      $tanggal = $eventRecord['tanggal'];
      $waktuSql = $eventRecord['waktu'];
      $latitude = $eventRecord['latitude'];
      $longitude = $eventRecord['longitude'];
      $kedalaman = $eventRecord['kedalaman'];
      $magnitudo = $eventRecord['magnitudo'];
      $tipeMag = $eventRecord['tipe_mag'];
      $keterangan = $eventRecord['keterangan'];
      $shakemapRecord = $index === 0 ? $shakemap : null;
      $vektorRecord = $index === 0 ? $shakemap_vektor : '';
      $boundsRecord = $index === 0 ? $shakemap_bounds : '';
      $stmt->bind_param('ssddddsssssss', $tanggal, $waktuSql, $latitude, $longitude, $kedalaman, $magnitudo, $tipeMag, $keterangan, $dirasakan, $shakemapRecord, $fileSinyal, $vektorRecord, $boundsRecord);
      if (!$stmt->execute()) {
        throw new RuntimeException('Failed to save earthquake data: ' . $stmt->error);
      }
    }
    $conn->commit();
    $stmt->close(); $conn->close();
    
    echo json_encode(['success' => true, 'message' => count($eventRecords) . ' earthquake records imported successfully.']);
  } catch (Throwable $error) {
    if (isset($conn) && $conn instanceof mysqli) {
      $conn->rollback();
      $conn->close();
    }
    http_response_code(422); echo json_encode(['success' => false, 'message' => $error->getMessage()]);
  }
  exit;
}
// ==========================================
// ENDPOINT DOWNLOAD DATA
// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'segment_activity_data') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate');

  $resultAktivitasSegmen = $conn->query(
    'SELECT id_gempa, tanggal, waktu_ot, magnitudo, latitude AS lat, longitude AS lng
     FROM tb_gempa
     WHERE tanggal >= (
       SELECT DATE_FORMAT(MAX(tanggal), "%Y-%m-01") FROM tb_gempa
     )
       AND tanggal < DATE_ADD((
         SELECT DATE_FORMAT(MAX(tanggal), "%Y-%m-01") FROM tb_gempa
       ), INTERVAL 1 MONTH)
     ORDER BY tanggal, waktu_ot'
  );
  if (!$resultAktivitasSegmen) {
    error_log('Gagal mengambil aktivitas segmen dari database: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to retrieve segment activity from the database.']);
    $conn->close();
    exit;
  }

  $dataAktivitasSegmen = [];
  while ($gempaSegmen = $resultAktivitasSegmen->fetch_assoc()) {
    $dataAktivitasSegmen[] = $gempaSegmen;
  }

  $resultGempaTerakhir30 = $conn->query(
    'SELECT id_gempa, tanggal, waktu_ot, magnitudo, latitude AS lat, longitude AS lng
     FROM tb_gempa
     ORDER BY tanggal DESC, waktu_ot DESC, id_gempa DESC
     LIMIT 30'
  );
  if (!$resultGempaTerakhir30) {
    error_log('Gagal mengambil 30 gempa terbaru untuk aktivitas segmen: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to retrieve the latest 30 earthquakes for segment activity.']);
    $conn->close();
    exit;
  }

  $gempaTerakhir30 = [];
  while ($gempaTerbaru = $resultGempaTerakhir30->fetch_assoc()) {
    $gempaTerakhir30[] = $gempaTerbaru;
  }
  echo json_encode([
    'success' => true,
    'data' => $dataAktivitasSegmen,
    'latest_30' => $gempaTerakhir30
  ]);
  $conn->close();
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'aftershock_catalog') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate');

  $resultGempaSusulan = $conn->query(
    'SELECT id_gempa, tanggal, waktu_ot, magnitudo, kedalaman, dirasakan,
            latitude AS lat, longitude AS lng
     FROM tb_gempa
     WHERE NOT (
       tanggal = "2014-11-26"
       AND (
         (shakemap_vektor IS NOT NULL AND TRIM(shakemap_vektor) <> "")
         OR (shakemap_komunitas IS NOT NULL AND TRIM(shakemap_komunitas) <> "")
       )
     )
     ORDER BY tanggal DESC, waktu_ot DESC, id_gempa DESC'
  );
  if (!$resultGempaSusulan) {
    error_log('Gagal mengambil katalog gempa untuk analisis gempa susulan: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to retrieve earthquake data for analysis.']);
    $conn->close();
    exit;
  }

  $dataGempaSusulan = [];
  while ($gempaSusulan = $resultGempaSusulan->fetch_assoc()) {
    $dataGempaSusulan[] = $gempaSusulan;
  }
  echo json_encode(['success' => true, 'data' => $dataGempaSusulan]);
  $conn->close();
  exit;
}

// Download TXT format
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'download_txt') {
  header('Content-Type: text/plain; charset=utf-8');
  header('Content-Disposition: attachment; filename="gempa_data_' . date('Y-m-d_H-i-s') . '.txt"');
  
  $servername = "localhost";
  $username = "root";
  $password = "";
  $dbname = "db_sesar_opak";
  $conn = new mysqli($servername, $username, $password, $dbname);
  
  $sql = "SELECT tanggal, waktu_ot AS waktu, latitude AS lat, longitude AS lng, kedalaman, magnitudo, tipe_mag, dirasakan, keterangan FROM tb_gempa ORDER BY tanggal DESC, waktu_ot DESC";
  $result = $conn->query($sql);
  
  echo "TANGGAL\tWAKTU\tLATITUDE\tLONGITUDE\tKEDALAMAN (km)\tMAGNITUDO\tTIPE\tDIRASAKAN\tKETERANGAN\n";
  while ($row = $result->fetch_assoc()) {
    echo $row['tanggal'] . "\t" . $row['waktu'] . "\t" . $row['lat'] . "\t" . $row['lng'] . "\t" . $row['kedalaman'] . "\t" . $row['magnitudo'] . "\t" . $row['tipe_mag'] . "\t" . $row['dirasakan'] . "\t" . str_replace(["\n", "\r"], " ", $row['keterangan']) . "\n";
  }
  $conn->close();
  exit;
}

// Download MXL (XML) format
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'download_mxl') {
  header('Content-Type: application/xml; charset=utf-8');
  header('Content-Disposition: attachment; filename="gempa_data_' . date('Y-m-d_H-i-s') . '.xml"');
  
  $servername = "localhost";
  $username = "root";
  $password = "";
  $dbname = "db_sesar_opak";
  $conn = new mysqli($servername, $username, $password, $dbname);
  
  $sql = "SELECT id_gempa, tanggal, waktu_ot AS waktu, latitude AS lat, longitude AS lng, kedalaman, magnitudo, tipe_mag, dirasakan, keterangan FROM tb_gempa ORDER BY tanggal DESC, waktu_ot DESC";
  $result = $conn->query($sql);
  
  echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
  echo "<gempa_list>\n";
  while ($row = $result->fetch_assoc()) {
    echo "  <gempa id=\"" . htmlspecialchars($row['id_gempa']) . "\">\n";
    echo "    <tanggal>" . htmlspecialchars($row['tanggal']) . "</tanggal>\n";
    echo "    <waktu>" . htmlspecialchars($row['waktu']) . "</waktu>\n";
    echo "    <lokasi>\n";
    echo "      <latitude>" . htmlspecialchars($row['lat']) . "</latitude>\n";
    echo "      <longitude>" . htmlspecialchars($row['lng']) . "</longitude>\n";
    echo "    </lokasi>\n";
    echo "    <kedalaman>" . htmlspecialchars($row['kedalaman']) . "</kedalaman>\n";
    echo "    <magnitudo tipe=\"" . htmlspecialchars($row['tipe_mag']) . "\">" . htmlspecialchars($row['magnitudo']) . "</magnitudo>\n";
    echo "    <dirasakan>" . htmlspecialchars($row['dirasakan']) . "</dirasakan>\n";
    echo "    <keterangan>" . htmlspecialchars($row['keterangan']) . "</keterangan>\n";
    echo "  </gempa>\n";
  }
  echo "</gempa_list>\n";
  $conn->close();
  exit;
}

// ==========================================
// FUNGSI BANTUAN SPASIAL
// Dihitung di sisi PHP (bukan fungsi ST_Buffer/ST_Intersects bawaan MySQL)
// karena dukungan fungsi spasial lanjutan berbeda-beda antar versi
// MySQL/MariaDB di XAMPP. haversineMeter() memakai rumus yang sama
// persis dengan ST_Distance_Sphere, jadi hasilnya tetap akurat.
// ==========================================
function haversineMeter($lat1, $lng1, $lat2, $lng2) {
    $R = 6371000; // radius bumi, meter
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $R * $c;
}

// Fungsi baru untuk mencari Titik Tengah (Centroid) sebuah poligon Desa
function hitungCentroidDesa($coords) {
    $sumX = 0; $sumY = 0; $n = count($coords);
    $total = $n;
    // Abaikan titik terakhir jika posisinya sama dengan titik pertama (karena tertutup)
    if ($n > 0 && $coords[0][0] == $coords[$n - 1][0] && $coords[0][1] == $coords[$n - 1][1]) {
        $total = $n - 1;
    }
    if ($total == 0) return [$coords[0][0], $coords[0][1]];

    for ($i = 0; $i < $total; $i++) {
        $sumX += $coords[$i][0];
        $sumY += $coords[$i][1];
    }
    return [$sumX / $total, $sumY / $total]; // [lng, lat]
}

// Analisis desa dengan metode ketat: Titik Tengah Desa harus masuk lingkaran
function analisisDesaTerdampak($epiLat, $epiLng, $centroidLat, $centroidLng, $radiusMeter) {
  $jarakKePusat = haversineMeter($epiLat, $epiLng, $centroidLat, $centroidLng);

    // 3. Hanya tersentuh jika titik tengahnya ada di dalam radius
    $tersentuh = ($jarakKePusat <= $radiusMeter);
    $status = ($jarakKePusat <= $radiusMeter * 0.5) ? 'Siaga' : 'Waspada';

    return ['tersentuh' => $tersentuh, 'status' => $status, 'jarak_ke_pusat' => $jarakKePusat];
}

// ==========================================
// 2. QUERY BATAS DESA (POLIGON) -- diambil duluan, dipakai buat analisis
// ==========================================
$sql_desa = "SELECT id_desa, nama_desa, kepadatan_penduduk, ST_AsGeoJSON(geom_batas) AS geojson FROM tb_batas_desa";
$result_desa = $conn->query($sql_desa);

$batas_desa = [];
if ($result_desa && $result_desa->num_rows > 0) {
    while($row = $result_desa->fetch_assoc()) {
        $geo = json_decode($row['geojson'], true);
        $coords = null;
        if (isset($geo['type']) && $geo['type'] === 'Polygon') {
          $coords = $geo['coordinates'][0] ?? null;
        } elseif (isset($geo['type']) && $geo['type'] === 'MultiPolygon') {
          $coords = $geo['coordinates'][0][0] ?? null;
        }
        $centroid = $coords ? hitungCentroidDesa($coords) : null;
        $row['centroid_lng'] = $centroid[0] ?? null;
        $row['centroid_lat'] = $centroid[1] ?? null;
        $batas_desa[] = $row;
    }
}

// ==========================================
// 2B. QUERY BATAS SESAR
// ==========================================
$sql_sesar = "SELECT id_sesar, nama_sesar, segmen, mmax, region, tipe_sesar, slip_rate, panjang_km, ST_AsGeoJSON(geom_sesar) AS geojson FROM tb_sesar";
$result_sesar = $conn->query($sql_sesar);
$data_sesar = [];
$sesar_layer_error = null;

if (!$result_sesar) {
    error_log('Gagal mengambil batas sesar dari tb_sesar: ' . $conn->error);
    $sesar_layer_error = 'Fault boundary data could not be loaded. Check the tb_sesar table.';
} else {
    while ($row = $result_sesar->fetch_assoc()) {
        $geometri = json_decode($row['geojson'], true);
        if (!is_array($geometri) || ($geometri['type'] ?? '') !== 'MultiLineString') {
            error_log('Geometri batas sesar tidak valid untuk id_sesar ' . $row['id_sesar'] . '.');
            continue;
        }

        $row['geometry'] = $geometri;
        unset($row['geojson']);
        $data_sesar[] = $row;
    }
}
// ==========================================
// 2C. QUERY BATAS SEGMEN OPAK
// ==========================================
// Geometri segmen disimpan dalam UTM zona 49S, sedangkan peta memakai lintang/bujur WGS 84.
function transformasiKoordinatUtm49S($koordinat) {
    if (!is_array($koordinat)) {
        return $koordinat;
    }

    if (isset($koordinat[0], $koordinat[1]) && is_numeric($koordinat[0]) && is_numeric($koordinat[1])) {
        $x = (float)$koordinat[0];
        $y = (float)$koordinat[1];
        if ($x < 100000 || $x > 900000 || $y < 1000000 || $y > 10000000) {
            return [$x, $y];
        }

        $a = 6378137.0;
        $f = 1 / 298.257223563;
        $e2 = $f * (2 - $f);
        $ep2 = $e2 / (1 - $e2);
        $k0 = 0.9996;
        $x = (float)$koordinat[0] - 500000;
        $y = (float)$koordinat[1] - 10000000;
        $mu = ($y / $k0) / ($a * (1 - $e2 / 4 - 3 * $e2 ** 2 / 64 - 5 * $e2 ** 3 / 256));
        $e1 = (1 - sqrt(1 - $e2)) / (1 + sqrt(1 - $e2));
        $phi1 = $mu
            + (3 * $e1 / 2 - 27 * $e1 ** 3 / 32) * sin(2 * $mu)
            + (21 * $e1 ** 2 / 16 - 55 * $e1 ** 4 / 32) * sin(4 * $mu)
            + (151 * $e1 ** 3 / 96) * sin(6 * $mu)
            + (1097 * $e1 ** 4 / 512) * sin(8 * $mu);
        $sinPhi1 = sin($phi1);
        $cosPhi1 = cos($phi1);
        $tanPhi1 = tan($phi1);
        $n1 = $a / sqrt(1 - $e2 * $sinPhi1 ** 2);
        $t1 = $tanPhi1 ** 2;
        $c1 = $ep2 * $cosPhi1 ** 2;
        $r1 = $a * (1 - $e2) / ((1 - $e2 * $sinPhi1 ** 2) ** 1.5);
        $d = $x / ($n1 * $k0);
        $latitude = $phi1 - ($n1 * $tanPhi1 / $r1) * (
            $d ** 2 / 2
            - (5 + 3 * $t1 + 10 * $c1 - 4 * $c1 ** 2 - 9 * $ep2) * $d ** 4 / 24
            + (61 + 90 * $t1 + 298 * $c1 + 45 * $t1 ** 2 - 252 * $ep2 - 3 * $c1 ** 2) * $d ** 6 / 720
        );
        $longitude = deg2rad(111) + (
            $d
            - (1 + 2 * $t1 + $c1) * $d ** 3 / 6
            + (5 - 2 * $c1 + 28 * $t1 - 3 * $c1 ** 2 + 8 * $ep2 + 24 * $t1 ** 2) * $d ** 5 / 120
        ) / $cosPhi1;

        return [rad2deg($longitude), rad2deg($latitude)];
    }

    return array_map('transformasiKoordinatUtm49S', $koordinat);
}

function normalisasiGeometriSegmen($geometri, $srid = null) {
    if (!is_array($geometri) || !in_array($geometri['type'] ?? '', ['Polygon', 'MultiPolygon'], true)) {
        return null;
    }

    if ((int)$srid === 32749 || (int)$srid === 0) {
        $geometri['coordinates'] = transformasiKoordinatUtm49S($geometri['coordinates']);
    }
    return $geometri;
}

$sql_segmen = "SELECT id_segmen, nama_segmen, ST_SRID(geom_segmen) AS srid, ST_AsGeoJSON(geom_segmen) AS geojson FROM tb_segmen_opak ORDER BY id_segmen";
$result_segmen = $conn->query($sql_segmen);
$data_segmen = [];
$segmen_layer_error = null;

if (!$result_segmen) {
    error_log('Gagal mengambil batas segmen dari tb_segmen_opak: ' . $conn->error);
    $segmen_layer_error = 'Fault segment data could not be loaded. Check the tb_segmen_opak table.';
} else {
    while ($row = $result_segmen->fetch_assoc()) {
        $geometri = json_decode($row['geojson'], true);
        if (!is_array($geometri) || !in_array($geometri['type'] ?? '', ['Polygon', 'MultiPolygon'], true)) {
            error_log('Geometri batas segmen tidak valid untuk id_segmen ' . $row['id_segmen'] . '.');
            continue;
        }

        $geometri = normalisasiGeometriSegmen($geometri, $row['srid'] ?? null);
        if (!is_array($geometri)) {
            error_log('Geometri segmen tidak dapat dinormalisasi untuk id_segmen ' . $row['id_segmen'] . '.');
            continue;
        }
        $row['geometry'] = $geometri;
        unset($row['geojson']);
        $data_segmen[] = $row;
    }
    if (!$data_segmen) {
        $segmen_layer_error = 'No valid segment geometry was found in tb_segmen_opak.';
    }
}
// ==========================================
// 3. QUERY DATA RUMAH (TITIK) -- diambil duluan, dipakai buat analisis
// ==========================================
$sql_rumah = "SELECT id_rumah, nama_pemilik, kategori_bangunan, ST_Y(geom_lokasi) AS lat, ST_X(geom_lokasi) AS lng FROM tb_rumah";
$result_rumah = $conn->query($sql_rumah);

$data_rumah_arr = [];
if ($result_rumah && $result_rumah->num_rows > 0) {
    while($row = $result_rumah->fetch_assoc()) {
        $data_rumah_arr[] = $row; 
    }
}

// ==========================================
// 4. QUERY TITIK GEMPA & ANALISIS DAMPAK (Hazard -> Buffer -> Irisan)
// ==========================================
// Menyesuaikan query dengan struktur tabel baru (Seiscomp3)
// Kita gabungkan 'tanggal' dan 'waktu_ot' menjadi 'waktu' menggunakan CONCAT
// LEFT JOIN subquery GROUP_CONCAT: setiap gempa dapat 1 kolom `laporan_warga`
// berisi rangkuman laporan masyarakat (mis. "Desa Muntuk (III MMI), Desa Terong (IV MMI)").
// LEFT JOIN (bukan JOIN biasa) supaya gempa yang belum ada laporannya tetap muncul (laporan_warga = NULL).
$sql_gempa = "SELECT
  g.id_gempa,
  g.tanggal,
  CONCAT(g.tanggal, ' ', g.waktu_ot) AS waktu,
  g.magnitudo,
  g.kedalaman,
  g.tipe_mag,
  COALESCE(g.keterangan, '') AS keterangan,
  COALESCE(g.dirasakan, 'Tidak') AS dirasakan,
  g.shakemap,
  g.shakemap_vektor,
  g.shakemap_bounds,
  g.shakemap_komunitas,
  g.shakemap_komunitas_bounds,
  g.file_sinyal,
  g.latitude AS lat,
  g.longitude AS lng,
  lap.laporan_warga
FROM tb_gempa g
LEFT JOIN (
  SELECT
    id_gempa,
    GROUP_CONCAT(CONCAT(nama_desa, ' (', skala_mmi, ' MMI)') ORDER BY id_laporan SEPARATOR ', ') AS laporan_warga
  FROM tb_laporan_masyarakat
  WHERE id_gempa IS NOT NULL
  GROUP BY id_gempa
) lap ON lap.id_gempa = g.id_gempa
ORDER BY g.tanggal DESC, g.waktu_ot DESC, g.id_gempa DESC";

$result_gempa = $conn->query($sql_gempa);

$sql_riwayat_gempa_shakemap = "SELECT id_gempa
  FROM tb_gempa
  WHERE (shakemap_vektor IS NOT NULL AND TRIM(shakemap_vektor) <> '')
     OR (shakemap_komunitas IS NOT NULL AND TRIM(shakemap_komunitas) <> '')
  ORDER BY tanggal DESC, waktu_ot DESC";
$result_riwayat_gempa_shakemap = $conn->query($sql_riwayat_gempa_shakemap);
if (!$result_riwayat_gempa_shakemap) {
  throw new RuntimeException('Failed to retrieve the earthquake history containing ShakeMaps: ' . $conn->error);
}

$id_gempa_riwayat_shakemap = [];
while ($rowRiwayatShakemap = $result_riwayat_gempa_shakemap->fetch_assoc()) {
  $id_gempa_riwayat_shakemap[] = (string)$rowRiwayatShakemap['id_gempa'];
}

$laporanMmiPerGempa = [];
$resultLaporanMmi = $conn->query("SELECT id_gempa, nama_desa, skala_mmi FROM tb_laporan_masyarakat WHERE id_gempa IS NOT NULL ORDER BY id_laporan");
if ($resultLaporanMmi && $resultLaporanMmi->num_rows > 0) {
  while ($laporanMmi = $resultLaporanMmi->fetch_assoc()) {
    $idGempaLaporan = (string)$laporanMmi['id_gempa'];
    $namaDesaLaporan = $laporanMmi['nama_desa'];
    $skalaMmiLaporan = strtoupper(trim($laporanMmi['skala_mmi']));
    if (!isset($laporanMmiPerGempa[$idGempaLaporan][$namaDesaLaporan])) {
      $laporanMmiPerGempa[$idGempaLaporan][$namaDesaLaporan] = [];
    }
    if (in_array($skalaMmiLaporan, ['II', 'III', 'IV', 'V', 'VI+'], true)) {
      $laporanMmiPerGempa[$idGempaLaporan][$namaDesaLaporan][$skalaMmiLaporan] = true;
    }
  }
}

$galeri_sesar = [];
$result_galeri = $conn->query("SELECT id, nama_file, COALESCE(deskripsi, '') AS deskripsi, tanggal_upload FROM tb_galeri ORDER BY tanggal_upload DESC, id DESC");
if ($result_galeri && $result_galeri->num_rows > 0) {
  while ($row = $result_galeri->fetch_assoc()) {
    $galeri_sesar[] = $row;
  }
}

$titik_gempa = [];
$total_gempa = 0; $dirasakan = 0; $max_mag = 0; $max_mag_waktu = "-";

if ($result_gempa && $result_gempa->num_rows > 0) {
    while($row = $result_gempa->fetch_assoc()) {
        $total_gempa++;
        
        if ($row['dirasakan'] === 'Ya') { $dirasakan++; }
        if ($row['magnitudo'] > $max_mag) {
            $max_mag = $row['magnitudo'];
            $max_mag_waktu = date('Y-m-d', strtotime($row['waktu'])); 
        }

        // Radius buffer terdampak (simulasi): magnitudo * 1000 meter
        $radiusBuffer = $row['magnitudo'] * 1000;

        $gridMmiPerDesa = [];
        $gridMmiTersedia = false;
        if (!empty($row['shakemap_vektor'])) {
          $mmiRel = preg_replace('/\.png$/i', '.mmi.json', $row['shakemap_vektor']);
          $mmiAbs = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $mmiRel);
          if (is_file($mmiAbs)) {
            $mmiPayload = json_decode(file_get_contents($mmiAbs), true);
            if (isset($mmiPayload['desa_mmi']) && is_array($mmiPayload['desa_mmi'])) {
              $gridMmiTersedia = true;
              foreach ($mmiPayload['desa_mmi'] as $hasilMmi) {
                if (isset($hasilMmi['nama'])) {
                  $gridMmiPerDesa[$hasilMmi['nama']] = $hasilMmi['mmi'] ?? null;
                }
              }
            }
          }
        }

        // Irisan terhadap batas desa (tb_batas_desa) dengan metode centroid
        $desaTerdampak = [];
        foreach ($batas_desa as $desa) {
          if (!is_numeric($desa['centroid_lat']) || !is_numeric($desa['centroid_lng'])) continue;
          $analisis = analisisDesaTerdampak(
            $row['lat'],
            $row['lng'],
            $desa['centroid_lat'],
            $desa['centroid_lng'],
            $radiusBuffer
          );
          $nilaiMmiGrid = $gridMmiPerDesa[$desa['nama_desa']] ?? null;
          $skalaMmiLaporan = array_keys(
            $laporanMmiPerGempa[(string)$row['id_gempa']][$desa['nama_desa']] ?? []
          );
          $punyaMmiGrid = is_numeric($nilaiMmiGrid);
          $punyaMmiLaporan = count($skalaMmiLaporan) > 0;

          // Perkirakan intensitas di setiap centroid, bukan hanya di dalam radius
          // simulasi magnitudo, agar desa terdampak ringan tetap teridentifikasi.
          $jarakEpiKm = $analisis['jarak_ke_pusat'] / 1000;
          $kedalamanKm = max(1, (float)$row['kedalaman']);
          $r = sqrt(pow($jarakEpiKm, 2) + pow($kedalamanKm, 2));
          $hitungMmi = (1.5 * (float)$row['magnitudo']) - (1.5 * log10($r)) - 0.5;
          $desaMemilikiDataMmi = $punyaMmiGrid || $punyaMmiLaporan || $hitungMmi >= 1.5;

          if ($desaMemilikiDataMmi) {
            $estimasiMmi = angkaKeSkalaMmi($hitungMmi);

            // Tentukan Nilai MMI dan Sumber Datanya
            $mmiValue = $punyaMmiGrid
                ? angkaKeSkalaMmi((float)$nilaiMmiGrid) // Format juga data dari Grid .grd jika bentuknya masih desimal
                : ($punyaMmiLaporan ? implode(', ', $skalaMmiLaporan) : $estimasiMmi);

            $sumberMmi = $punyaMmiGrid
                ? 'Grid MMI'
                : ($punyaMmiLaporan ? 'Community Report' : 'Estimated MMI');

            $desaTerdampak[] = [
              'nama' => $desa['nama_desa'],
              'mmi' => $mmiValue,
              'sumber_mmi' => $sumberMmi,
              'jarak_m' => round($analisis['jarak_ke_pusat'])
            ];
          }
        } // <--- Pastikan kurung kurawal penutup foreach ini tetap ada

// 1. Urutkan desa terdampak berdasarkan jarak (paling dekat di atas)
        usort($desaTerdampak, function ($a, $b) {
            return $a['jarak_m'] <=> $b['jarak_m'];
        });

        // 2. Saring duplikat: pastikan satu nama desa hanya masuk satu kali
        // (Karena sudah diurutkan, sistem otomatis mengambil jarak yang paling dekat)
        $desaUnik = [];
        $namaTercatat = [];
        foreach ($desaTerdampak as $d) {
            if (!in_array($d['nama'], $namaTercatat)) {
                $desaUnik[] = $d;
                $namaTercatat[] = $d['nama'];
            }
        }

        // 3. Batasi daftar agar tidak terlalu panjang di popup (Maksimal 8 desa unik)
        $desaTerdampak = array_slice($desaUnik, 0, 8);

        $row['radius_buffer'] = $radiusBuffer;
        $row['grid_mmi_tersedia'] = $gridMmiTersedia;
        $row['desa_terdampak'] = $desaTerdampak;
        $titik_gempa[] = $row;
    }
}

$tanggalTerbaruChart = null;
foreach ($titik_gempa as $gempaChart) {
  if (!isset($gempaChart['tanggal']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $gempaChart['tanggal'])) continue;
  if ($tanggalTerbaruChart === null || $gempaChart['tanggal'] > $tanggalTerbaruChart) {
    $tanggalTerbaruChart = $gempaChart['tanggal'];
  }
}
$chartTanggalAcuan = $tanggalTerbaruChart !== null
  ? new DateTimeImmutable($tanggalTerbaruChart)
  : new DateTimeImmutable('today');
$chartStartDate = $chartTanggalAcuan->modify('first day of this month');
$chartEndDate = $chartTanggalAcuan->modify('last day of this month');
$chartLabels = [];
$chartDailyCounts = [];
$chartMagnitudeCounts = [0, 0, 0, 0];
$chartTotal = 0;
$cursorDate = $chartStartDate;
while ($cursorDate <= $chartEndDate) {
  $dateKey = $cursorDate->format('Y-m-d');
  $chartLabels[] = $cursorDate->format('d/m');
  $chartDailyCounts[$dateKey] = 0;
  $cursorDate = $cursorDate->modify('+1 day');
}
foreach ($titik_gempa as $gempaChart) {
  $dateKey = $gempaChart['tanggal'];
  if (!isset($chartDailyCounts[$dateKey])) continue;
  $chartDailyCounts[$dateKey]++;
  $chartTotal++;
  $magnitude = (float)$gempaChart['magnitudo'];
  if ($magnitude >= 5) $chartMagnitudeCounts[0]++;
  elseif ($magnitude >= 4) $chartMagnitudeCounts[1]++;
  elseif ($magnitude >= 3) $chartMagnitudeCounts[2]++;
  else $chartMagnitudeCounts[3]++;
}
$chartDailyCounts = array_values($chartDailyCounts);
$namaBulanChart = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
$chartPeriodText = $namaBulanChart[(int)$chartTanggalAcuan->format('n') - 1] . ' ' . $chartTanggalAcuan->format('Y');

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Opak Fault - Sleman Class I Geophysics Station, BMKG</title>

  <!-- Google Fonts: Inter -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

  <!-- Tailwind CSS -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    // Palet warna identitas BMKG untuk WebGIS Sesar Opak. Dipakai lewat class
    // bg-brand-blue / text-brand-blue / dst, di samping palet default Tailwind
    // yang tetap dipakai untuk warna status/bahaya (hijau-kuning-oranye-merah)
    // supaya keduanya tetap terlihat berbeda secara sengaja.
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            'brand-blue': '#2347C5',
            'brand-blue-dark': '#182F87',
            'brand-soft-blue': '#5E7FC8',
            'brand-green': '#4CAF50',
            'brand-sage': '#A9C8B9',
            'brand-offwhite': '#F7FAF8',
            'brand-dark': '#1F2933',
          },
          fontFamily: {
            sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
          },
          borderRadius: {
            'card': '16px',
          },
        }
      }
    };
  </script>

  <!-- Leaflet CSS & JS -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

  <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <!-- Lucide Icons -->
  <script src="https://unpkg.com/lucide@latest"></script>

  <style>
    body { min-height: 100vh; display: flex; flex-direction: column; font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; }
    main { flex: 1; display: flex; flex-direction: column; }
    .page { flex: 1; display: flex; flex-direction: column; }
    #map { height: 100%; min-height: 620px; z-index: 1; }
    .shakemap-quake-icon {
        background: transparent;
        border: 0;
    }
    .gempa-terkini-pulse {
        transform-box: fill-box;
        transform-origin: center;
        fill: none;
        fill-opacity: 0;
        stroke: #ef4444;
        stroke-width: 2.5px;
        animation: gempa-terkini-pulse 2s ease-out infinite;
    }
    @keyframes gempa-terkini-pulse {
        0% { transform: scale(0.8); opacity: 0.9; }
        100% { transform: scale(3); opacity: 0; }
    }
    @media (prefers-reduced-motion: reduce) {
        .gempa-terkini-pulse { animation: none; }
    }
    .shakemap-halus {
        /* Memulai efek transparan jauh sebelum batas tepi kotak (mulai pudar di 30%, hilang total di 75%) */
        -webkit-mask-image: radial-gradient(ellipse at center, black 30%, transparent 75%);
        mask-image: radial-gradient(ellipse at center, black 30%, transparent 75%);

        /* Membaurkan warna putih/terang dengan basemap */
        mix-blend-mode: multiply;

        /* Menghaluskan piksel di dalam gambar itu sendiri */
        filter: blur(4px);
    }
    /* Overlay shakemap KOMUNITAS (hasil IDW dari laporan warga) -- efek serupa
       shakemap-halus tapi sedikit lebih halus lagi, karena ini data crowdsourced
       yang secara visual memang perlu terkesan "lebih ringan" dari data resmi. */
    .shakemap-komunitas-halus {
        -webkit-mask-image: radial-gradient(ellipse at center, black 25%, transparent 70%);
        mask-image: radial-gradient(ellipse at center, black 25%, transparent 70%);
        mix-blend-mode: multiply;
        filter: blur(5px);
    }
    .menu-card { transition: box-shadow .2s ease, transform .2s ease; }
    .menu-card:hover { box-shadow: 0 8px 16px -6px rgba(35, 71, 197, 0.14); transform: translateY(-2px); }
    .leaflet-popup-content-wrapper { border-radius: 16px; font-size: 13px; font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; box-shadow: 0 4px 14px rgba(31, 41, 51, 0.12); }
    .leaflet-popup-content { margin: 12px 14px; line-height: 1.5; }
    .leaflet-popup-tip { background: white; }
    .leaflet-control-layers {
      border-radius: 12px !important;
      padding: 6px 10px !important;
      border: 1px solid #E5E7EB !important;
      box-shadow: 0 2px 8px rgba(31, 41, 51, 0.08) !important;
      font-family: inherit;
      font-size: 12px;
    }
    .nav-link.is-active { color: #2347C5; background: rgba(35,71,197,0.08); }
    .nav-link.is-active .nav-indicator { opacity: 1; }
    .nav-indicator { opacity: 0; transition: opacity .15s ease; }
    .mobile-link.is-active { color: #2347C5; background: rgba(35,71,197,0.08); }
/* CSS Khusus untuk Label Grid */
    .grid-label-custom {
      background-color: #1F2933;
      color: #ffffff;
      padding: 3px 6px;
      font-size: 9px;
      font-weight: 700;
      border-radius: 6px;
      white-space: nowrap;
      text-align: center;
      border: 1px solid rgba(255, 255, 255, 0.2);
      box-shadow: 0 2px 4px rgba(0,0,0,0.3);
    }
    .btn-grid-aktif {
      background-color: #ffffff !important;
      color: #1F2933 !important;
    }
  </style>
</head>
<body class="bg-brand-offwhite font-sans text-brand-dark">

  <!-- NAVBAR -->
  <header class="bg-white text-slate-700 sticky top-0 z-[1100] shadow-sm border-b border-slate-100">
    <div class="px-4 sm:px-6 py-3 flex flex-wrap items-center justify-between gap-3">

      <button data-nav="beranda" class="flex items-center gap-4 sm:gap-5 text-left">
        <div class="flex items-center gap-2.5 sm:gap-3">
          <div class="w-12 h-12 rounded-full bg-white p-1.5 shadow-sm ring-2 ring-blue-100 overflow-hidden">
            <img src="./Logo BMKG 2.jpg.png" alt="Logo BMKG" class="w-full h-full object-contain" />
          </div>
          <div class="w-12 h-12 rounded-full bg-white p-1.5 shadow-sm ring-2 ring-blue-100 overflow-hidden">
            <img src="./Logo_Universitas_Pembangunan_Nasional_Veteran_Yogyakarta-removebg-preview (1).png" alt="Logo UPN" class="w-full h-full object-contain" />
          </div>
        </div>
        <div class="pl-1 sm:pl-2 border-l border-slate-200">
          <h1 class="text-xl font-bold tracking-wide text-brand-blue leading-tight">Sesar Opak</h1>
          <p class="text-xs text-slate-500 mt-0.5">Sleman Class I Geophysics Station</p>
        </div>
      </button>

      <!-- DESKTOP NAV -->
      <nav class="hidden lg:flex items-center gap-1 text-sm font-medium">
        <button data-nav="beranda" class="nav-link relative flex items-center gap-1.5 px-3 py-2 rounded-xl text-slate-600 hover:text-brand-blue hover:bg-blue-50 transition-colors">
          <i data-lucide="home" class="w-4 h-4"></i> Home
          <span class="nav-indicator absolute left-3 right-3 -bottom-1 h-0.5 bg-brand-blue rounded-full"></span>
        </button>
        <button data-nav="tentang" class="nav-link relative flex items-center gap-1.5 px-3 py-2 rounded-xl text-slate-600 hover:text-brand-blue hover:bg-blue-50 transition-colors">
          <i data-lucide="info" class="w-4 h-4"></i> About the Opak Fault
          <span class="nav-indicator absolute left-3 right-3 -bottom-1 h-0.5 bg-brand-blue rounded-full"></span>
        </button>
        <div class="relative" id="gempaDropdownWrap">
          <button id="gempaDropdownBtn" class="nav-link relative flex items-center gap-1.5 px-3 py-2 rounded-xl text-slate-600 hover:text-brand-blue hover:bg-blue-50 transition-colors">
            <i data-lucide="activity" class="w-4 h-4"></i> Earthquakes
            <i data-lucide="chevron-down" class="w-3.5 h-3.5"></i>
            <span class="nav-indicator absolute left-3 right-3 -bottom-1 h-0.5 bg-brand-blue rounded-full"></span>
          </button>
          <div id="gempaDropdownMenu" class="hidden absolute top-full mt-2 left-0 w-52 bg-white text-slate-700 rounded-xl shadow-lg border border-slate-200 overflow-hidden py-1.5">
            <button data-nav="aktivitas" class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm hover:bg-slate-50 text-left">
              <i data-lucide="map" class="w-4 h-4 text-brand-blue"></i> Earthquake Activity
            </button>
            <button data-nav="repository" class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm hover:bg-slate-50 text-left">
              <i data-lucide="database" class="w-4 h-4 text-brand-blue"></i> Earthquake Database
            </button>
            <button data-nav="aftershock" class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm hover:bg-slate-50 text-left">
              <i data-lucide="bar-chart-3" class="w-4 h-4 text-brand-blue"></i> Aftershock Charts
            </button>
          </div>
        </div>
        <div class="relative" id="infoDropdownWrap">
          <button id="infoDropdownBtn" class="nav-link relative flex items-center gap-1.5 px-3 py-2 rounded-xl text-slate-600 hover:text-brand-blue hover:bg-blue-50 transition-colors">
            <i data-lucide="newspaper" class="w-4 h-4"></i> Information
            <i data-lucide="chevron-down" class="w-3.5 h-3.5"></i>
            <span class="nav-indicator absolute left-3 right-3 -bottom-1 h-0.5 bg-brand-blue rounded-full"></span>
          </button>
          <div id="infoDropdownMenu" class="hidden absolute top-full mt-2 left-0 w-52 bg-white text-slate-700 rounded-xl shadow-lg border border-slate-200 overflow-hidden py-1.5">
            <button data-nav="berita" class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm hover:bg-slate-50 text-left">
              <i data-lucide="newspaper" class="w-4 h-4 text-brand-blue"></i> News
            </button>
            <button data-nav="edukasi" class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm hover:bg-slate-50 text-left">
              <i data-lucide="shield-check" class="w-4 h-4 text-brand-blue"></i> Preparedness
            </button>
          </div>
        </div>

        <button data-nav="kontak" class="nav-link relative flex items-center gap-1.5 px-3 py-2 rounded-xl text-slate-600 hover:text-brand-blue hover:bg-blue-50 transition-colors">
          <i data-lucide="mail" class="w-4 h-4"></i> Contact
          <span class="nav-indicator absolute left-3 right-3 -bottom-1 h-0.5 bg-brand-blue rounded-full"></span>
        </button>
      </nav>

      <div class="flex items-center gap-3">
        <div class="bg-blue-50 border border-blue-100 px-3 py-1.5 rounded-lg text-xs hidden sm:flex items-center gap-2">
          <i data-lucide="calendar" class="w-4 h-4 text-brand-blue"></i>
          <span id="live-clock" class="text-slate-700">Loading...</span>
        </div>
        <button id="mobileMenuBtn" class="lg:hidden w-9 h-9 flex items-center justify-center rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700">
          <i data-lucide="menu" class="w-5 h-5"></i>
        </button>
      </div>
    </div>

    <!-- MOBILE NAV -->
    <div id="mobileNav" class="hidden lg:hidden border-t border-slate-100 px-4 py-3 space-y-1 bg-white">
      <div class="sm:hidden bg-blue-50 border border-blue-100 px-3 py-1.5 rounded-lg text-xs flex items-center gap-2 mb-2">
        <i data-lucide="calendar" class="w-4 h-4 text-brand-blue"></i>
        <span id="live-clock-mobile" class="text-slate-700">Loading...</span>
      </div>
      <button data-nav="beranda" class="mobile-link w-full flex items-center gap-2 px-3 py-2 rounded-xl text-sm text-slate-600 hover:bg-blue-50 text-left"><i data-lucide="home" class="w-4 h-4"></i> Home</button>
      <button data-nav="tentang" class="mobile-link w-full flex items-center gap-2 px-3 py-2 rounded-xl text-sm text-slate-600 hover:bg-blue-50 text-left"><i data-lucide="info" class="w-4 h-4"></i> About the Opak Fault</button>
      <p class="px-3 pt-2 text-[11px] uppercase tracking-wide text-slate-400 font-semibold">Earthquakes</p>
      <button data-nav="aktivitas" class="mobile-link w-full flex items-center gap-2 px-3 py-2 rounded-xl text-sm text-slate-600 hover:bg-blue-50 text-left ml-2"><i data-lucide="map" class="w-4 h-4"></i> Earthquake Activity</button>
      <button data-nav="repository" class="mobile-link w-full flex items-center gap-2 px-3 py-2 rounded-xl text-sm text-slate-600 hover:bg-blue-50 text-left ml-2"><i data-lucide="database" class="w-4 h-4"></i> Earthquake Database</button>
      <button data-nav="aftershock" class="mobile-link w-full flex items-center gap-2 px-3 py-2 rounded-xl text-sm text-slate-600 hover:bg-blue-50 text-left ml-2"><i data-lucide="bar-chart-3" class="w-4 h-4"></i> Aftershock Charts</button>
      <p class="px-3 pt-2 text-[11px] uppercase tracking-wide text-slate-400 font-semibold">Information</p>
      <button data-nav="berita" class="mobile-link w-full flex items-center gap-2 px-3 py-2 rounded-xl text-sm text-slate-600 hover:bg-blue-50 text-left ml-2"><i data-lucide="newspaper" class="w-4 h-4"></i> News</button>
      <button data-nav="edukasi" class="mobile-link w-full flex items-center gap-2 px-3 py-2 rounded-xl text-sm text-slate-600 hover:bg-blue-50 text-left ml-2"><i data-lucide="shield-check" class="w-4 h-4"></i> Preparedness</button>
      <button data-nav="kontak" class="mobile-link w-full flex items-center gap-2 px-3 py-2 rounded-xl text-sm text-slate-600 hover:bg-blue-50 text-left"><i data-lucide="mail" class="w-4 h-4"></i> Contact</button>
    </div>
  </header>

  <main class="flex-1 flex flex-col">

  <!-- ============================================================ -->
  <!-- PAGE: BERANDA                                                -->
  <!-- ============================================================ -->
  <section id="page-beranda" class="page bg-white">
    <div class="max-w-[1800px] mx-auto px-4 sm:px-6 py-16 sm:py-20 lg:py-24">
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">

        <!-- KOLOM KIRI: Judul, deskripsi, dan kartu menu -->
        <div>
          <span class="inline-flex items-center gap-2 text-[11px] font-semibold tracking-wide uppercase bg-blue-50 text-blue-700 border border-blue-100 px-3 py-1 rounded-full">
            <i data-lucide="map-pin" class="w-3.5 h-3.5"></i> Stasiun Geofisika Kelas I Sleman &middot; BMKG
          </span>
          <h1 class="mt-5 text-5xl sm:text-6xl font-black text-brand-blue leading-tight">Sesar Opak</h1>
          <div class="w-16 h-1 bg-brand-blue rounded-full mt-5 mb-5"></div>
          <p class="text-slate-500 max-w-lg text-sm sm:text-base leading-relaxed">
            An active fault extending from Prambanan to the southern coast of Parangtritis, continuously monitored by BMKG.
          </p>

          <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 mt-8">
            <button data-nav="tentang" class="menu-card flex flex-col items-center justify-center gap-2 bg-white rounded-2xl shadow-sm border border-slate-100 py-5 px-3 text-center">
              <span class="w-11 h-11 rounded-full bg-blue-50 text-brand-blue flex items-center justify-center">
                <i data-lucide="info" class="w-5 h-5"></i>
              </span>
              <span class="text-xs font-semibold text-slate-700">About the<br>Opak Fault</span>
            </button>

            <button data-nav="aktivitas" class="menu-card flex flex-col items-center justify-center gap-2 bg-white rounded-2xl shadow-sm border border-slate-100 py-5 px-3 text-center">
              <span class="w-11 h-11 rounded-full bg-blue-50 text-brand-blue flex items-center justify-center">
                <i data-lucide="activity" class="w-5 h-5"></i>
              </span>
              <span class="text-xs font-semibold text-slate-700">Earthquake<br>Activity</span>
            </button>

            <button data-nav="berita" class="menu-card flex flex-col items-center justify-center gap-2 bg-white rounded-2xl shadow-sm border border-slate-100 py-5 px-3 text-center">
              <span class="w-11 h-11 rounded-full bg-blue-50 text-brand-blue flex items-center justify-center">
                <i data-lucide="newspaper" class="w-5 h-5"></i>
              </span>
              <span class="text-xs font-semibold text-slate-700">Latest<br>News</span>
            </button>

            <button data-nav="edukasi" class="menu-card flex flex-col items-center justify-center gap-2 bg-white rounded-2xl shadow-sm border border-slate-100 py-5 px-3 text-center">
              <span class="w-11 h-11 rounded-full bg-blue-50 text-brand-blue flex items-center justify-center">
                <i data-lucide="shield-check" class="w-5 h-5"></i>
              </span>
              <span class="text-xs font-semibold text-slate-700">Disaster<br>Preparedness</span>
            </button>

            <button data-nav="kontak" class="menu-card flex flex-col items-center justify-center gap-2 bg-white rounded-2xl shadow-sm border border-slate-100 py-5 px-3 text-center">
              <span class="w-11 h-11 rounded-full bg-blue-50 text-brand-blue flex items-center justify-center">
                <i data-lucide="mail" class="w-5 h-5"></i>
              </span>
              <span class="text-xs font-semibold text-slate-700">Contact<br>Us</span>
            </button>
          </div>
        </div>

        <!-- KOLOM KANAN: Gambar lokasi Sesar Opak -->
        <div>
          <img src="./Sesar Opak.jpg" alt="Location of the Opak Fault" class="w-full h-full object-cover rounded-[2rem] shadow-sm">
        </div>

      </div>
    </div>
  </section>

  <!-- ============================================================ -->
  <!-- PAGE: TENTANG SESAR OPAK                                     -->
  <!-- ============================================================ -->
  <section id="page-tentang" class="page hidden px-4 sm:px-6 py-6 max-w-[1800px] mx-auto space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-brand-blue">About the Opak Fault</h1>
      <p class="text-sm text-slate-500 mt-1">Learn about the active fault that extends across the Yogyakarta region.</p>
    </div>

    <div class="grid lg:grid-cols-3 gap-6">
      <div class="lg:col-span-2 bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-4 text-sm leading-relaxed text-slate-600">
        <div class="flex items-center justify-between gap-3 border-b border-slate-100 pb-2">
          <p class="text-xs font-semibold uppercase tracking-wide text-brand-blue">Source: PuSGeN 2024</p>
        </div>
        <p>
          <b class="text-slate-800">The Opak Fault</b> is an active fault in the Special Region of Yogyakarta. It follows the Opak River from Prambanan in the north to the southern coast near Parangtritis, Bantul. In geological and seismic studies, the fault is recognized as an active structure that plays an important role in the region's tectonic setting and earthquake hazard.
        </p>
        <p>
          According to the PuSGeN 2024 publication, the Opak Fault is an active fault whose dominant movement is strike-slip, with possible shallow deformation that can trigger earthquakes in densely populated areas. This makes the fault a key focus for disaster risk reduction, as it crosses areas with dense settlements, infrastructure, and economic activity.
        </p>
        <p>
          The magnitude 6.3 Yogyakarta earthquake on May 27, 2006, was a significant event that strengthened understanding of the relationship between Opak Fault activity and the damage in Bantul and surrounding areas. It also demonstrated that shaking from this fault may affect not only nearby areas but also communities farther along its path.
        </p>

        <h2 class="font-bold text-brand-blue text-base pt-2">Monitoring Segments</h2>
        <p>
          For this monitoring summary, earthquake locations are grouped by whether they fall within the latest segment boundaries in the <code>tb_segmen_opak</code> table. Recorded events are used to show activity for each segment.
        </p>
        <div class="grid sm:grid-cols-2 gap-3 pt-1">
          <?php foreach ($data_segmen as $segmenInfo): ?>
            <div class="border border-slate-100 rounded-xl p-3 bg-slate-50/50">
              <p class="font-bold text-slate-700 text-xs mb-1"><?php echo htmlspecialchars($segmenInfo['nama_segmen'], ENT_QUOTES, 'UTF-8'); ?></p>
              <p class="text-xs text-slate-500">Segment boundaries are loaded from the current database.</p>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="pt-2">
          <button data-nav="aktivitas" class="text-xs font-semibold text-brand-blue hover:underline inline-flex items-center gap-1">
            View activity by segment <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
          </button>
        </div>

        <div class="bg-slate-50 border border-slate-200 rounded-2xl p-4 mt-4">
          <h3 class="font-bold text-brand-blue text-sm mb-2">References</h3>
          <ul class="text-xs text-slate-600 space-y-2 leading-relaxed">
            <li>1. National Center for Earthquake Studies (PuSGeN). 2024. <b>Indonesia Earthquake Source and Hazard Map 2024</b>. Bandung: Ministry of Public Works and Housing.</li>
            <li>2. BMKG. 2024. Catalogue and Monitoring of Fault and Earthquake Activity in Indonesia.</li>
            <li>3. Susilo, S., et al. 2023. Tectonics and Fault Activity in Southern Java. (Regional geological context for the Opak Fault.)</li>
          </ul>
        </div>
      </div>

      <div class="space-y-4">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
          <h3 class="font-bold text-brand-blue text-sm mb-3 flex items-center gap-2"><i data-lucide="ruler" class="w-4 h-4"></i> General Characteristics</h3>
          <dl class="text-xs divide-y divide-slate-100">
            <div class="flex justify-between py-2"><dt class="text-slate-500">Fault length</dt><dd class="font-semibold text-slate-800">&plusmn; 40 km</dd></div>
            <div class="flex justify-between py-2"><dt class="text-slate-500">Movement type</dt><dd class="font-semibold text-slate-800">Strike-slip</dd></div>
            <div class="flex justify-between py-2"><dt class="text-slate-500">Depth</dt><dd class="font-semibold text-slate-800">Shallow (&lt;50 km)</dd></div>
            <div class="flex justify-between py-2"><dt class="text-slate-500">Areas affected</dt><dd class="font-semibold text-slate-800 text-right">Sleman, Bantul, Klaten, Gunungkidul</dd></div>
          </dl>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
          <h3 class="font-bold text-brand-blue text-sm mb-3 flex items-center gap-2"><i data-lucide="history" class="w-4 h-4"></i> Earthquake History</h3>
          <ul class="text-xs space-y-3">
            <li class="flex gap-3">
              <span class="font-bold text-brand-blue flex-shrink-0">27 Mei 2006</span>
              <span class="text-slate-500">A magnitude 6.3 earthquake struck Yogyakarta and surrounding areas, causing widespread damage in Bantul.</span>
            </li>
            <li class="flex gap-3">
              <span class="font-bold text-brand-blue flex-shrink-0">2020 – kini</span>
              <span class="text-slate-500">Microseismic monitoring along the fault has been intensified.</span>
            </li>
            <li class="flex gap-3">
              <span class="font-bold text-brand-blue flex-shrink-0">2026</span>
              <span class="text-slate-500">Small to moderate earthquakes continue to be recorded periodically.</span>
            </li>
          </ul>

          <div class="mt-5 border-t border-slate-100 pt-4">
            <h4 class="font-bold text-brand-blue text-sm mb-3 flex items-center gap-2"><i data-lucide="images" class="w-4 h-4"></i> Opak Fault Gallery</h4>
            <?php if (count($galeri_sesar) > 0): ?>
              <div id="galeriSlider" class="relative mb-4 overflow-hidden rounded-xl border border-slate-200 bg-slate-50">
                <div class="relative aspect-[4/3]">
                  <?php foreach ($galeri_sesar as $index => $foto): ?>
                    <figure data-gallery-slide class="absolute inset-0 <?php echo $index === 0 ? '' : 'hidden'; ?>">
                      <img src="uploads/galeri/<?php echo rawurlencode(basename($foto['nama_file'])); ?>" alt="Opak Fault photo" class="h-full w-full object-cover" loading="lazy">
                      <?php if (trim($foto['deskripsi']) !== ''): ?>
                        <figcaption class="absolute inset-x-0 bottom-0 bg-slate-900/75 p-3 text-xs leading-relaxed text-white"><?php echo nl2br(htmlspecialchars($foto['deskripsi'], ENT_QUOTES, 'UTF-8')); ?></figcaption>
                      <?php endif; ?>
                    </figure>
                  <?php endforeach; ?>
                </div>
                <?php if (count($galeri_sesar) > 1): ?>
                  <button type="button" data-gallery-prev aria-label="Previous photo" class="absolute left-2 top-1/2 inline-flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 text-brand-blue shadow hover:bg-white"><i data-lucide="chevron-left" class="h-4 w-4"></i></button>
                  <button type="button" data-gallery-next aria-label="Next photo" class="absolute right-2 top-1/2 inline-flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 text-brand-blue shadow hover:bg-white"><i data-lucide="chevron-right" class="h-4 w-4"></i></button>
                  <span data-gallery-counter class="absolute right-2 top-2 rounded-full bg-slate-900/70 px-2 py-1 text-[10px] font-semibold text-white">1/<?php echo count($galeri_sesar); ?></span>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <p class="mb-4 rounded-lg bg-slate-50 p-3 text-xs text-slate-500">No photos have been uploaded yet.</p>
            <?php endif; ?>

            <form id="galeriForm" class="space-y-3 rounded-xl border border-dashed border-blue-200 bg-blue-50/40 p-3" enctype="multipart/form-data">
              <p class="text-xs font-semibold text-brand-blue">Add a photo</p>
              <input type="file" name="galeri" accept="image/png,image/jpeg,image/webp" required class="block w-full text-xs text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-blue file:px-3 file:py-2 file:text-xs file:font-semibold file:text-white hover:file:bg-blue-800">
              <textarea name="deskripsi" rows="2" maxlength="2000" placeholder="Photo description (optional)" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs text-slate-700 outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100"></textarea>
              <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-brand-blue px-3 py-2 text-xs font-semibold text-white hover:bg-brand-blue-dark"><i data-lucide="upload" class="w-3.5 h-3.5"></i> Upload photo</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ============================================================ -->
  <!-- PAGE: AKTIVITAS GEMPA (dashboard)                            -->
  <!-- ============================================================ -->
  <section id="page-aktivitas" class="page hidden px-4 py-4 sm:px-6 sm:py-6 max-w-[1800px] mx-auto space-y-5">

    <div>
      <h1 class="text-2xl font-bold text-brand-blue">Earthquake Activity</h1>
      <p class="text-sm text-slate-500 mt-1">Map and statistics of recent seismic activity along the Opak Fault.</p>
    </div>

    <!-- ROW 1: PETA & STATS + SEGMEN CARDS -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

      <!-- MAP SECTION (7 cols) -->
      <div class="lg:col-span-7 grid grid-cols-1 sm:grid-cols-12 gap-4">

        <!-- Peta (porsi lebih lebar) -->
        <div class="sm:col-span-9 bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden relative flex flex-col min-h-[620px]">
          <div id="map" class="w-full h-full z-[0]"></div>
          <!-- TOMBOL TOGGLE GRID -->
          <div class="absolute top-4 left-1/2 -translate-x-1/2 z-[1000]">
            <button id="toggleGridBtn" class="bg-brand-dark/80 hover:bg-brand-dark backdrop-blur-sm text-white text-[10px] font-semibold px-3 py-1.5 rounded-full shadow-sm flex items-center gap-1.5 transition">
              <i data-lucide="grid-3x3" class="w-3.5 h-3.5"></i> Coordinate grid
            </button>
          </div>
          <div class="absolute top-4 right-4 z-[1000] flex flex-col gap-2">
            <div class="rounded-2xl bg-white shadow-md border border-slate-200 overflow-hidden">
              <button id="zoomInBtn" class="w-10 h-10 flex items-center justify-center text-xl font-semibold text-slate-700 hover:bg-slate-50 border-b border-slate-100" title="Zoom in">+</button>
              <button id="zoomOutBtn" class="w-10 h-10 flex items-center justify-center text-xl font-semibold text-slate-700 hover:bg-slate-50" title="Zoom out">&minus;</button>
            </div>

            <button id="locateBtn" class="w-10 h-10 rounded-2xl bg-white shadow-md border border-slate-200 flex items-center justify-center text-slate-700 hover:bg-slate-50" title="Find my location">
              <i data-lucide="locate" class="w-4 h-4"></i>
            </button>

            <div class="w-10 h-10 rounded-2xl bg-white shadow-md border border-slate-200 flex items-center justify-center text-slate-700" title="Compass direction">
              <svg viewBox="0 0 24 24" class="w-5 h-5" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="12" cy="12" r="9" stroke="#64748b" stroke-width="1.5"></circle>
                <path d="M12 4L12 20" stroke="#2347C5" stroke-width="1.8" stroke-linecap="round"></path>
                <path d="M12 4L15 8" stroke="#2347C5" stroke-width="1.8" stroke-linecap="round"></path>
                <path d="M12 4L9 8" stroke="#2347C5" stroke-width="1.8" stroke-linecap="round"></path>
                <path d="M12 20L10 16" stroke="#64748b" stroke-width="1.2" stroke-linecap="round"></path>
                <path d="M12 20L14 16" stroke="#64748b" stroke-width="1.2" stroke-linecap="round"></path>
                <text x="12" y="11" text-anchor="middle" font-size="5" font-family="Arial, sans-serif" fill="#1F2933">N</text>
              </svg>
            </div>
          </div>

          <!-- Legenda Intensitas MMI: berderet menyamping, pojok kiri bawah peta -->
          <div class="absolute bottom-12 left-4 z-[1000] bg-white/90 backdrop-blur-md p-2.5 rounded-xl shadow-sm border border-slate-200">
            <p class="text-[10px] font-bold text-slate-600 uppercase tracking-wide mb-1.5">MMI Scale</p>
            <div class="flex items-end gap-1">
              <div class="flex flex-col items-center gap-0.5">
                <span class="w-5 h-4 rounded-sm bg-white border border-slate-300"></span>
                <span class="text-[9px] font-semibold text-slate-600">I</span>
              </div>
              <div class="flex flex-col items-center gap-0.5">
                <span class="w-5 h-4 rounded-sm bg-blue-200"></span>
                <span class="text-[9px] font-semibold text-slate-600">II-III</span>
              </div>
              <div class="flex flex-col items-center gap-0.5">
                <span class="w-5 h-4 rounded-sm bg-cyan-300"></span>
                <span class="text-[9px] font-semibold text-slate-600">IV</span>
              </div>
              <div class="flex flex-col items-center gap-0.5">
                <span class="w-5 h-4 rounded-sm bg-green-400"></span>
                <span class="text-[9px] font-semibold text-slate-600">V</span>
              </div>
              <div class="flex flex-col items-center gap-0.5">
                <span class="w-5 h-4 rounded-sm bg-yellow-400"></span>
                <span class="text-[9px] font-semibold text-slate-600">VI</span>
              </div>
              <div class="flex flex-col items-center gap-0.5">
                <span class="w-5 h-4 rounded-sm bg-orange-500"></span>
                <span class="text-[9px] font-semibold text-slate-600">VII</span>
              </div>
              <div class="flex flex-col items-center gap-0.5">
                <span class="w-5 h-4 rounded-sm bg-orange-700"></span>
                <span class="text-[9px] font-semibold text-slate-600">VIII</span>
              </div>
              <div class="flex flex-col items-center gap-0.5">
                <span class="w-5 h-4 rounded-sm bg-red-600"></span>
                <span class="text-[9px] font-semibold text-slate-600">IX</span>
              </div>
              <div class="flex flex-col items-center gap-0.5">
                <span class="w-5 h-4 rounded-sm bg-red-900"></span>
                <span class="text-[9px] font-semibold text-slate-600">X+</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Panel Kontrol Layer -->
        <div class="sm:col-span-3 bg-white rounded-2xl border border-slate-200 shadow-sm p-4 flex flex-col min-h-[620px]">
          <button type="button" id="laporGempaBtn" class="w-full flex items-center justify-center gap-2 bg-brand-blue hover:bg-brand-blue-dark text-white text-xs font-semibold px-4 py-2.5 rounded-xl shadow-sm mb-4">
            <i data-lucide="megaphone" class="w-4 h-4"></i> I Felt an Earthquake
          </button>

          <p class="font-bold text-slate-700 text-xs uppercase tracking-wide mb-3">Map Layers</p>

          <!-- Batas Desa: default UNCHECKED, hanya render desa yang beririsan dgn MMI gempa aktif -->
          <label class="flex cursor-pointer items-center gap-2 mb-3 pb-3 border-b border-slate-100">
            <input type="checkbox" data-layer="desa" class="h-4 w-4 rounded border-slate-300 text-black-600 focus:ring-black-500">
            <span class="inline-flex items-center gap-2 text-xs text-slate-700"><span class="h-2.5 w-2.5 rounded-sm bg-black-400"></span> Affected Village Boundaries</span>
          </label>

          <!-- Batas Sesar: diambil dari tb_sesar -->
          <label class="flex cursor-pointer items-center gap-2 mb-3 pb-3 border-b border-slate-100">
            <input type="checkbox" data-layer="sesar" class="h-4 w-4 rounded border-slate-300 text-red-600 focus:ring-red-500"<?php echo $sesar_layer_error !== null ? ' disabled' : ''; ?>>
            <span class="inline-flex items-center gap-2 text-xs text-slate-700"><span class="inline-block w-3 h-0.5 bg-red-700"></span> Fault Trace</span>
          </label>
          <?php if ($sesar_layer_error !== null): ?>
            <p class="text-[11px] text-red-600 mb-3" role="status"><?php echo htmlspecialchars($sesar_layer_error, ENT_QUOTES, 'UTF-8'); ?></p>
          <?php endif; ?>

          <!-- FILTER CEPAT -->
           <!-- Batas Segmen: diambil dari tb_segmen_opak -->
          <label class="flex cursor-pointer items-center gap-2 mb-3 pb-3 border-b border-slate-100">
            <input type="checkbox" data-layer="segmen" class="h-4 w-4 rounded border-slate-300 text-orange-600 focus:ring-orange-500"<?php echo $segmen_layer_error !== null ? ' disabled' : ''; ?>>
            <span class="inline-flex items-center gap-2 text-xs text-slate-700"><span class="h-2.5 w-2.5 rounded-sm bg-orange-400"></span> Fault Segments</span>
          </label>
          <?php if ($segmen_layer_error !== null): ?>
            <p class="text-[11px] text-red-600 mb-3" role="status"><?php echo htmlspecialchars($segmen_layer_error, ENT_QUOTES, 'UTF-8'); ?></p>
          <?php endif; ?>
          <p class="font-bold text-slate-700 text-xs uppercase tracking-wide mb-2">Filters</p>
          <div class="space-y-2 mb-4 pb-3 border-b border-slate-100">
            <label class="flex cursor-pointer items-center gap-2">
              <input type="checkbox" id="toggleGempaTerkini" class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
              <span class="text-xs font-bold text-brand-blue">Latest Earthquake</span>
            </label>
            <label class="flex cursor-pointer items-center gap-2">
              <input type="checkbox" id="toggleGempa30Hari" class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
              <span class="text-xs font-semibold text-slate-700">Latest 30 Earthquakes</span>
            </label>
            <p id="statusGempa30Hari" class="hidden pl-6 text-[10px] text-slate-500" role="status">None of the 30 latest earthquakes are within the segment boundaries.</p>
          </div>

          <!-- Peta Guncangan -->
          <p class="font-bold text-slate-700 text-xs uppercase tracking-wide mb-2">Shake Map</p>
          <select id="filterMagnitudoRiwayat" class="w-full text-xs border border-slate-200 rounded-lg px-2 py-1.5 mb-2 bg-slate-50 focus:outline-none focus:ring-2 focus:ring-blue-300 text-slate-700">
            <option value="semua">All Magnitudes</option>
            <option value="2.5">M 2.5</option>
            <option value="2.6">M 2.6</option>
            <option value="2.7">M 2.7</option>
            <option value="2.8">M 2.8</option>
            <option value="2.9">M 2.9</option>
            <option value="3.0">M 3.0</option>
            <option value="3.1">M 3.1</option>
            <option value="3.2">M 3.2</option>
            <option value="3.3">M 3.3</option>
            <option value="3.4">M 3.4</option>
            <option value="3.5">M 3.5</option>
            <option value="3.6">M 3.6</option>
            <option value="3.7">M 3.7</option>
            <option value="3.8">M 3.8</option>
            <option value="3.9">M 3.9</option>
            <option value="4.0">M 4.0</option>
            <option value="4.1">M 4.1</option>
            <option value="4.2">M 4.2</option>
            <option value="4.3">M 4.3</option>
            <option value="4.4">M 4.4</option>
            <option value="4.5">M 4.5</option>
            <option value="4.6">M 4.6</option>
            <option value="4.7">M 4.7</option>
            <option value="4.8">M 4.8</option>
            <option value="4.9">M 4.9</option>
          </select>
          
          <div id="riwayatGempaList" class="h-[110px] flex-none space-y-2 overflow-y-auto overscroll-contain pr-1"></div>
          <!-- Legenda Magnitudo (M): ukuran titik menunjukkan besar magnitudo -->
          <div class="mt-3 flex flex-1 flex-col justify-center border-t border-slate-100 pt-3">
            <p class="font-bold text-slate-700 text-xs uppercase tracking-wide mb-2">Magnitude (M)</p>
            <div class="space-y-1.5 text-xs">
              <div class="flex items-center gap-2"><span class="w-[10px] h-[10px] rounded-full bg-red-600 border border-white shadow-sm flex-shrink-0"></span> M &lt; 2.0</div>
              <div class="flex items-center gap-2"><span class="w-[13px] h-[13px] rounded-full bg-red-600 border border-white shadow-sm flex-shrink-0"></span> 2.0 &le; M &lt; 3.0</div>
              <div class="flex items-center gap-2"><span class="w-[15px] h-[15px] rounded-full bg-red-600 border border-white shadow-sm flex-shrink-0"></span> 3.0 &le; M &lt; 4.0</div>
              <div class="flex items-center gap-2"><span class="w-[18px] h-[18px] rounded-full bg-red-600 border border-white shadow-sm flex-shrink-0"></span> 4.0 &le; M</div>
            </div>
          </div>
        </div>
      </div>

      <!-- STATS & SEGMENT CARDS (5 cols) -->
      <div class="lg:col-span-5 flex flex-col gap-6">

        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
          <div class="flex justify-between items-center mb-3">
            <h2 class="font-bold text-sm text-brand-blue">Earthquake Activity by Opak Fault Segment</h2>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <?php foreach ($data_segmen as $segmenCard): ?>
                  <?php $idSegmenCard = (string)$segmenCard['id_segmen']; ?>
                  <div class="border border-slate-100 rounded-2xl p-4 bg-slate-50/50">
                    <div class="flex justify-between items-center text-xs mb-1 gap-2">
                      <span class="font-bold text-slate-700 truncate" title="<?php echo htmlspecialchars($segmenCard['nama_segmen'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($segmenCard['nama_segmen'], ENT_QUOTES, 'UTF-8'); ?></span>
                      <span data-segmen-month="<?php echo htmlspecialchars($idSegmenCard, ENT_QUOTES, 'UTF-8'); ?>" class="bg-slate-100 text-slate-600 px-3 py-1 rounded-full text-xs font-semibold whitespace-nowrap">Loading...</span>
                    </div>
                    <div class="h-28"><canvas id="chartSeg<?php echo htmlspecialchars($idSegmenCard, ENT_QUOTES, 'UTF-8'); ?>"></canvas></div>
                    <div class="flex justify-between text-xs text-slate-500 mt-1 border-t pt-2">
                      <span>Total: <b data-segmen-total="<?php echo htmlspecialchars($idSegmenCard, ENT_QUOTES, 'UTF-8'); ?>" class="text-brand-blue">0</b></span>
                      <span>Largest: <b data-segmen-max="<?php echo htmlspecialchars($idSegmenCard, ENT_QUOTES, 'UTF-8'); ?>" class="text-brand-blue">-</b></span>
                    </div>
                  </div>
                <?php endforeach; ?>

          </div>
        </div>
        <aside class="bg-white flex-1 flex flex-col p-4 rounded-2xl border border-slate-200 shadow-sm" aria-label="Seismic activity status">
          <div class="flex items-center mb-3 border-b border-slate-100 pb-3">
            <h2 class="font-bold text-sm text-brand-blue">Seismic Activity Status</h2>
          </div>
          <div id="seismicStatusSummary" class="space-y-3" aria-live="polite">
            <p class="text-xs text-slate-500">Calculating activity status...</p>
          </div>
        </aside>
        </div>

      </div>
    </div>

    <!-- ROW 2: MAIN CHARTS & DONUT -->
    <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm grid grid-cols-1 lg:grid-cols-12 gap-6">
      <div class="lg:col-span-8">
        <div class="mb-2">
          <h2 class="font-bold text-sm text-brand-blue">All Earthquakes &ndash; <span id="chartPeriodTitle"><?php echo htmlspecialchars($chartPeriodText, ENT_QUOTES, 'UTF-8'); ?></span></h2>
          <p class="text-xs text-slate-500 mt-1">Latest month: <span id="chartPeriodRange"><?php echo htmlspecialchars($chartPeriodText, ENT_QUOTES, 'UTF-8'); ?></span></p>
        </div>
        <div class="h-56"><canvas id="mainChart"></canvas></div>
      </div>

      <div class="lg:col-span-4 border-t lg:border-t-0 lg:border-l border-slate-100 pt-4 lg:pt-0 lg:pl-6 flex flex-col justify-between">
        <div>
          <h2 class="font-bold text-sm text-brand-blue">Magnitude Distribution</h2>
          <p class="text-xs text-slate-400 mb-2">(<span id="magnitudePeriodTitle"><?php echo htmlspecialchars($chartPeriodText, ENT_QUOTES, 'UTF-8'); ?></span>)</p>
        </div>
        <div class="h-44 flex items-center justify-center">
          <canvas id="donutChart"></canvas>
        </div>
        <div class="text-center mt-2 text-xs font-bold text-brand-blue">
                Total: <span id="chartGempaTotal"><?php echo $chartTotal; ?></span> earthquakes
              </div>
            </div>
          </div>
          </section> <!-- Ini menutup halaman Aktivitas Gempa -->

          <!-- ============================================================ -->
          <!-- PAGE: EARTHQUAKE DATA REPOSITORY                            -->
          <!-- ============================================================ -->
          <section id="page-repository" class="page hidden px-4 py-4 sm:px-6 sm:py-6 max-w-[1800px] mx-auto space-y-5">

            <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
              <div class="flex flex-col xl:flex-row xl:items-center xl:justify-between gap-4 mb-5">
                <div>
                  <h2 class="font-bold text-sm text-brand-blue">Earthquake Database &amp; Data Entry</h2>
                  <p class="text-xs text-slate-500 mt-1">Earthquake distribution map, event table, and earthquake entry form.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                  <button id="downloadTxtBtn" class="bg-brand-blue hover:bg-brand-blue-dark text-white text-xs font-semibold px-3 py-2 rounded-xl transition inline-flex items-center gap-2">
                    <i data-lucide="download" class="w-3.5 h-3.5"></i> Download TXT
                  </button>
                  <button id="downloadMxlBtn" class="bg-brand-blue hover:bg-brand-blue-dark text-white text-xs font-semibold px-3 py-2 rounded-xl transition inline-flex items-center gap-2">
                    <i data-lucide="download" class="w-3.5 h-3.5"></i> Download MXL
                  </button>
                  <button id="resetDatabaseBtn" class="bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-semibold px-3 py-2 rounded-xl transition inline-flex items-center gap-2">
                    <i data-lucide="trash-2" class="w-3.5 h-3.5"></i> Reset Database
                  </button>
                </div>
              </div>

              <!-- ROW 1: PETA (Lebar Kiri) & TABEL DATA (Kanan) -->
              <div class="grid lg:grid-cols-12 gap-6 mb-6">
                <!-- Kolom Kiri: Peta Episentrum -->
                <div class="lg:col-span-7 bg-slate-100 border border-slate-200 rounded-2xl overflow-hidden relative min-h-[450px]">
                  <div id="mapRepository" class="absolute inset-0 z-[0]"></div>
                  <div class="absolute top-4 left-1/2 -translate-x-1/2 z-[1000]">
                    <button id="toggleRepositoryGridBtn" class="bg-brand-dark/80 hover:bg-brand-dark backdrop-blur-sm text-white text-[10px] font-semibold px-3 py-1.5 rounded-full shadow-sm flex items-center gap-1.5 transition">
                      <i data-lucide="grid-3x3" class="w-3.5 h-3.5"></i> Coordinate grid
                    </button>
                  </div>
                  <div class="absolute top-4 right-4 z-[1000] flex flex-col gap-2">
                    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-md">
                      <button id="zoomInRepositoryBtn" type="button" class="flex h-10 w-10 items-center justify-center border-b border-slate-100 text-xl font-semibold text-slate-700 hover:bg-slate-50" title="Zoom in">+</button>
                      <button id="zoomOutRepositoryBtn" type="button" class="flex h-10 w-10 items-center justify-center text-xl font-semibold text-slate-700 hover:bg-slate-50" title="Zoom out">&minus;</button>
                    </div>
                    <div class="flex h-10 w-10 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-700 shadow-md" title="Compass direction">
                      <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" xmlns="http://www.w3.org/2000/svg" aria-label="North">
                        <circle cx="12" cy="12" r="9" stroke="#64748b" stroke-width="1.5"></circle>
                        <path d="M12 4L12 20" stroke="#2347C5" stroke-width="1.8" stroke-linecap="round"></path>
                        <path d="M12 4L15 8" stroke="#2347C5" stroke-width="1.8" stroke-linecap="round"></path>
                        <path d="M12 4L9 8" stroke="#2347C5" stroke-width="1.8" stroke-linecap="round"></path>
                        <path d="M12 20L10 16" stroke="#64748b" stroke-width="1.2" stroke-linecap="round"></path>
                        <path d="M12 20L14 16" stroke="#64748b" stroke-width="1.2" stroke-linecap="round"></path>
                        <text x="12" y="11" text-anchor="middle" font-size="5" font-family="Arial, sans-serif" fill="#1F2933">N</text>
                      </svg>
                    </div>
                  </div>
                  <div class="absolute bottom-3 left-3 z-[1000] rounded-xl border border-slate-200 bg-white/90 p-2.5 shadow-sm backdrop-blur-md">
                    <p class="mb-1.5 text-[10px] font-bold uppercase tracking-wide text-slate-600">Magnitude (M)</p>
                    <div class="flex items-end gap-3 text-[9px] font-semibold text-slate-600">
                      <div class="flex flex-col items-center gap-1"><span class="h-[10px] w-[10px] rounded-full border border-white bg-red-600 shadow-sm"></span><span>M &lt; 2.0</span></div>
                      <div class="flex flex-col items-center gap-1"><span class="h-[13px] w-[13px] rounded-full border border-white bg-red-600 shadow-sm"></span><span>2.0–2.9</span></div>
                      <div class="flex flex-col items-center gap-1"><span class="h-[15px] w-[15px] rounded-full border border-white bg-red-600 shadow-sm"></span><span>3.0–3.9</span></div>
                      <div class="flex flex-col items-center gap-1"><span class="h-[18px] w-[18px] rounded-full border border-white bg-red-600 shadow-sm"></span><span>M &ge; 4.0</span></div>
                    </div>
                  </div>
                </div>

                <!-- Kolom Kanan: Tabel Data Tersimpan -->
                <div class="lg:col-span-5 relative flex flex-col h-[450px]">
                  <div class="border border-slate-200 rounded-2xl overflow-hidden bg-slate-50/50 flex flex-col h-full">
                    <div class="px-4 py-3 border-b border-slate-200 bg-white shrink-0">
                      <div class="flex flex-col gap-3">
                        <h3 class="font-bold text-sm text-brand-blue">Earthquake Data Table</h3>
                        <div class="grid grid-cols-2 gap-2">
                          <div>
                            <label class="block text-[10px] font-semibold uppercase tracking-wide text-slate-500 mb-1">From</label>
                            <input id="dateFromFilter" type="date" class="w-full text-xs border border-slate-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-300">
                          </div>
                          <div>
                            <label class="block text-[10px] font-semibold uppercase tracking-wide text-slate-500 mb-1">To</label>
                            <input id="dateToFilter" type="date" class="w-full text-xs border border-slate-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-300">
                          </div>
                        </div>
                        <div class="flex gap-2">
                          <button id="applyDateFilterBtn" class="flex-1 bg-brand-blue hover:bg-brand-blue-dark text-white text-[10px] font-semibold px-2 py-2 rounded-xl transition">Apply Filter</button>
                          <button id="resetDateFilterBtn" class="flex-1 bg-slate-200 hover:bg-slate-300 text-slate-700 text-[10px] font-semibold px-2 py-2 rounded-xl transition">All Data</button>
                        </div>
                      </div>
                    </div>
                    <div class="flex-1 min-h-0 overflow-auto">
                      <table class="min-w-full text-left text-xs">
                        <thead class="bg-slate-100 text-slate-600 sticky top-0 z-10">
                          <tr>
                            <th class="px-3 py-2 font-semibold">Time</th>
                            <th class="px-3 py-2 font-semibold">Depth (km)</th>
                            <th class="px-3 py-2 font-semibold">Magnitude</th>
                            <th class="px-3 py-2 font-semibold">Type</th>
                            <th class="px-3 py-2 font-semibold">Location (Lat, Long)</th>
                            <th class="px-3 py-2 font-semibold">Community MMI</th>
                            <th class="px-3 py-2 font-semibold">Edit</th>
                          </tr>
                        </thead>
                        <tbody id="dataTableBody" class="bg-white divide-y divide-slate-100"></tbody>
                      </table>
                    </div>
                  </div>
                </div>
              </div>

              <!-- ROW 2: INPUT DATA (Kotak Memanjang di Bawah) -->
              <div class="border-t border-slate-200 pt-6">
                <h3 class="font-bold text-sm text-brand-blue mb-4 flex items-center gap-2"><i data-lucide="edit-3" class="w-4 h-4"></i> Add or Edit Earthquake Data</h3>
                
                <div class="grid lg:grid-cols-12 items-stretch gap-6">
                  <!-- Kotak Impor (Kiri) -->
                  <div class="lg:col-span-4 bg-white p-3 rounded-xl border border-slate-200 shadow-sm h-full flex flex-col">
                    <div class="mb-1">
                      <h3 class="font-bold text-sm text-slate-800">Import ShakeMap Files</h3>
                    </div>
                    <p class="text-[11px] text-slate-600 mb-3">Upload an event file, grid, detailed ShakeMap image, and overlay image.</p>
                    <div class="space-y-3">
                      <div>
                        <label class="text-[10px] font-semibold text-slate-700 uppercase block mb-1">1. Event file (.xml / .txt / .csv)</label>
                        <input id="quickXml" name="file_xml" type="file" accept=".xml,.txt,.csv" class="w-full text-xs border border-slate-300 rounded-lg px-2 py-2 bg-slate-50" required>
                      </div>
                      <div>
                        <label class="text-[10px] font-semibold text-slate-700 uppercase block mb-1">2. MMI grid file (.grd)</label>
                        <input id="quickGrd" name="file_grd" type="file" accept=".grd,.asc,.tif,.tiff" class="w-full text-xs border border-slate-300 rounded-lg px-2 py-2 bg-slate-50">
                      </div>
                      <div>
                        <label class="text-[10px] font-semibold text-slate-700 uppercase block mb-1">3. Intensity map (.png)</label>
                        <input id="quickShakemap" name="file_shakemap" type="file" accept=".png,.jpg,.jpeg" class="w-full text-xs border border-slate-300 rounded-lg px-2 py-2 bg-slate-50">
                      </div>
                    </div>
                    <div class="mt-auto pt-4 flex justify-end">
                      <button id="quickImportBtn" type="button" class="w-full bg-brand-blue hover:bg-brand-blue-dark text-white text-xs font-bold px-5 py-2 rounded-xl shadow-sm">
                        Import Files
                      </button>
                    </div>
                  </div>

                  <!-- Kotak Manual (Kanan, Melebar) -->
                  <div class="lg:col-span-8 flex">
                    <form id="dataEntryForm" class="bg-white p-3 rounded-xl border border-slate-200 shadow-sm space-y-2 w-full h-full flex flex-col">
                      <h3 class="font-bold text-sm text-slate-800 border-b border-slate-100 pb-1.5">Manual Entry / Edit Data</h3>

                      <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-2">
                        <div>
                          <label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide block mb-1">Date</label>
                          <input name="tanggal" type="date" class="w-full text-xs border border-slate-200 rounded-lg px-2 py-1.5 focus:ring-2 focus:ring-blue-300" required>
                        </div>
                        <div>
                          <label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide block mb-1">Time (hour:min:sec)</label>
                          <div class="grid grid-cols-3 gap-2">
                            <input name="jam" type="number" min="0" max="23" placeholder="HH" class="w-full text-xs border border-slate-200 rounded-lg px-2 py-1.5" required>
                            <input name="menit" type="number" min="0" max="59" placeholder="MM" class="w-full text-xs border border-slate-200 rounded-lg px-2 py-1.5" required>
                            <input name="detik" type="number" min="0" max="59" placeholder="SS" class="w-full text-xs border border-slate-200 rounded-lg px-2 py-1.5" required>
                          </div>
                        </div>
                        <div>
                          <label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide block mb-1">Quick Location</label>
                          <select id="lokasiGempa" class="w-full text-xs border border-slate-200 rounded-lg px-2 py-1.5">
                          <option value="">Select a location...</option>
                          <option value="Bantul, DIY" data-lat="-7.8881" data-lng="110.3282">Bantul, DIY</option>
                          <option value="Kota Yogyakarta, DIY" data-lat="-7.7956" data-lng="110.3695">Kota Yogyakarta, DIY</option>
                          <option value="Sleman, DIY" data-lat="-7.7167" data-lng="110.3556">Sleman, DIY</option>
                          <option value="Gunungkidul, DIY" data-lat="-8.0305" data-lng="110.6169">Gunungkidul, DIY</option>
                          <option value="Laut Selatan DIY" data-lat="-8.1000" data-lng="110.4500">Laut Selatan DIY</option>
                          </select>
                        </div>
                      </div>

                      <div class="grid sm:grid-cols-3 gap-2">
                        <div>
                          <label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide block mb-1">Latitude</label>
                          <input name="lat" type="number" step="any" placeholder="-7.88" class="w-full text-xs border border-slate-200 rounded-lg px-2 py-1.5" required>
                        </div>
                        <div>
                          <label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide block mb-1">Longitude</label>
                          <input name="lng" type="number" step="any" placeholder="110.33" class="w-full text-xs border border-slate-200 rounded-lg px-2 py-1.5" required>
                        </div>
                        <div>
                          <label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide block mb-1">Depth (km)</label>
                          <input name="kedalaman" type="number" min="0" step="1" placeholder="10" class="w-full text-xs border border-slate-200 rounded-lg px-2 py-1.5" required>
                        </div>
                      </div>

                      <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-2">
                        <div>
                          <label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide block mb-1">Magnitude</label>
                          <input name="magnitudo" type="number" step="0.1" min="0" max="10" placeholder="4.8" class="w-full text-xs border border-slate-200 rounded-lg px-2 py-1.5" required>
                        </div>
                        <div>
                          <label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide block mb-1">Magnitude Type</label>
                          <select name="tipe_mag" class="w-full text-xs border border-slate-200 rounded-lg px-2 py-1.5" required>
                            <option value="ML">ML - Local</option><option value="Mw">Mw - Moment</option><option value="Ms">Ms</option><option value="Mb">Mb</option><option value="MD">MD</option>
                          </select>
                        </div>
                        <div>
                          <label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide block mb-1">Status</label>
                          <select name="status" class="w-full text-xs border border-slate-200 rounded-lg px-2 py-1.5" required><option value="Normal">Normal</option><option value="Waspada">Watch</option><option value="Siaga">Alert</option><option value="Bahaya">Danger</option></select>
                        </div>
                        <div>
                          <label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide block mb-1">Felt</label>
                          <select name="dirasakan" class="w-full text-xs border border-slate-200 rounded-lg px-2 py-1.5" required><option value="Tidak">No</option><option value="Ya">Yes</option></select>
                        </div>
                      </div>
                      
                      <input type="hidden" name="lokasi" id="lokasiTersembunyi" value="Titik Manual">

                      <div class="mt-auto space-y-2 pt-2 border-t border-slate-100">
                        <input type="hidden" name="id_gempa" value="">
                        <textarea name="keterangan" rows="1" maxlength="2000" placeholder="Additional notes (optional)" class="w-full text-xs border border-slate-200 rounded-lg px-2 py-1.5"></textarea>
                        <div class="space-y-2">
                          <button type="button" id="cancelEditGempaBtn" class="hidden w-full bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-semibold px-3 py-2 rounded-xl">Cancel Edit</button>
                          <button type="submit" class="w-full bg-brand-blue hover:bg-brand-blue-dark text-white text-xs font-bold px-4 py-2 rounded-xl shadow-sm text-center">Save Earthquake Data</button>
                        </div>
                      </div>
                    </form>
                  </div>
                </div>
              </div>
            </div>
          </section>

  <!-- ============================================================ -->
  <!-- PAGE: GRAFIK GEMPA SUSULAN                                  -->
  <!-- ============================================================ -->
  <section id="page-aftershock" class="page hidden w-full px-4 py-4 sm:px-6 sm:py-6 max-w-[1800px] mx-auto space-y-5">
    <div>
      <h1 class="text-2xl font-bold text-brand-blue">Aftershock Charts</h1>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-12 gap-5">
      <div class="xl:col-span-8 bg-slate-100 border border-slate-200 rounded-2xl overflow-hidden relative min-h-[460px]">
        <div id="mapAftershock" class="absolute inset-0 z-[0]"></div>
        <div class="absolute top-4 left-1/2 -translate-x-1/2 z-[1000]">
          <button id="toggleAftershockGridBtn" class="bg-brand-dark/80 hover:bg-brand-dark backdrop-blur-sm text-white text-[10px] font-semibold px-3 py-1.5 rounded-full shadow-sm flex items-center gap-1.5 transition">
            <i data-lucide="grid-3x3" class="w-3.5 h-3.5"></i> Coordinate grid
          </button>
        </div>
        <div class="absolute top-4 right-4 z-[1000] flex flex-col gap-2">
          <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-md">
            <button id="zoomInAftershockBtn" type="button" class="flex h-10 w-10 items-center justify-center border-b border-slate-100 text-xl font-semibold text-slate-700 hover:bg-slate-50" title="Zoom in">+</button>
            <button id="zoomOutAftershockBtn" type="button" class="flex h-10 w-10 items-center justify-center text-xl font-semibold text-slate-700 hover:bg-slate-50" title="Zoom out">&minus;</button>
          </div>
          <div class="flex h-10 w-10 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-700 shadow-md" title="Compass direction">
            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" xmlns="http://www.w3.org/2000/svg" aria-label="North">
              <circle cx="12" cy="12" r="9" stroke="#64748b" stroke-width="1.5"></circle>
              <path d="M12 4L12 20" stroke="#2347C5" stroke-width="1.8" stroke-linecap="round"></path>
              <path d="M12 4L15 8" stroke="#2347C5" stroke-width="1.8" stroke-linecap="round"></path>
              <path d="M12 4L9 8" stroke="#2347C5" stroke-width="1.8" stroke-linecap="round"></path>
              <path d="M12 20L10 16" stroke="#64748b" stroke-width="1.2" stroke-linecap="round"></path>
              <path d="M12 20L14 16" stroke="#64748b" stroke-width="1.2" stroke-linecap="round"></path>
              <text x="12" y="11" text-anchor="middle" font-size="5" font-family="Arial, sans-serif" fill="#1F2933">N</text>
            </svg>
          </div>
        </div>
        <div class="absolute bottom-3 left-3 z-[1000] rounded-xl border border-slate-200 bg-white/90 p-2.5 shadow-sm backdrop-blur-md">
          <div class="flex items-center gap-3 text-[10px] font-semibold text-slate-600">
            <span class="inline-flex items-center gap-1.5"><i class="h-2.5 w-2.5 rounded-full bg-red-600"></i> Mainshock</span>
            <span class="inline-flex items-center gap-1.5"><i class="h-2.5 w-2.5 rounded-full bg-blue-600"></i> Aftershocks</span>
          </div>
        </div>
      </div>

      <aside class="xl:col-span-4 flex flex-col bg-white p-4 sm:p-5 rounded-2xl border border-slate-200 shadow-sm space-y-4">
        <div>
          <h2 class="font-bold text-sm text-brand-blue">Analysis Parameters</h2>
        </div>
        <div>
          <label for="aftershockMainEvent" class="block text-[10px] font-semibold uppercase tracking-wide text-slate-500 mb-1">Mainshock (date &amp; origin time)</label>
          <select id="aftershockMainEvent" class="w-full text-xs border border-slate-200 rounded-lg px-2.5 py-2 focus:outline-none focus:ring-2 focus:ring-blue-300" disabled>
            <option value="">Loading earthquake data...</option>
          </select>
        </div>
        <div>
          <label for="aftershockWindowHours" class="block text-[10px] font-semibold uppercase tracking-wide text-slate-500 mb-1">Observation period after origin time</label>
          <select id="aftershockWindowHours" class="w-full text-xs border border-slate-200 rounded-lg px-2.5 py-2 focus:outline-none focus:ring-2 focus:ring-blue-300">
            <option value="3">3 hours</option>
            <option value="6">6 hours</option>
            <option value="12">12 hours</option>
            <option value="24" selected>24 hours (1 day)</option>
            <option value="48">2 days</option>
            <option value="72">3 days</option>
            <option value="168">7 days</option>
            <option value="336">14 days</option>
            <option value="720">1 month (30 days)</option>
          </select>
        </div>
        <div id="aftershockStatus" class="hidden text-xs text-slate-500 bg-slate-50 border border-slate-100 rounded-xl p-3" role="status" aria-live="polite">
        </div>
        <div class="mt-auto grid flex-1 grid-rows-2 gap-3 text-xs">
          <div class="flex flex-col justify-center rounded-xl bg-blue-50 p-3">
            <p class="text-[10px] uppercase tracking-wide text-slate-500">Aftershocks counted</p>
            <p id="aftershockCount" class="mt-1 text-lg font-bold text-brand-blue">0</p>
          </div>
          <div class="flex flex-col justify-center rounded-xl bg-orange-50 p-3">
            <p class="text-[10px] uppercase tracking-wide text-slate-500">Felt earthquakes</p>
            <p id="feltAftershockCount" class="mt-1 text-lg font-bold text-orange-600">0</p>
          </div>
        </div>
      </aside>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-5 items-stretch">
      <article class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
        <h2 class="font-bold text-sm text-brand-blue mb-3">Earthquakes per 3 Hours</h2>
        <div class="h-72"><canvas id="aftershockChart3Hours" aria-label="Earthquakes per 3 hours over time by day"></canvas></div>
      </article>
      <article class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
        <h2 class="font-bold text-sm text-brand-blue mb-3">Earthquakes per 6 Hours</h2>
        <div class="h-72"><canvas id="aftershockChart6Hours" aria-label="Earthquakes per 6 hours over time by day"></canvas></div>
      </article>
      <article class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
        <h2 class="font-bold text-sm text-brand-blue mb-3">Earthquakes per 12 Hours</h2>
        <div class="h-72"><canvas id="aftershockChart12Hours" aria-label="Earthquakes per 12 hours over time by month"></canvas></div>
      </article>
      <article class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
        <h2 class="font-bold text-sm text-brand-blue mb-3">Earthquakes per 24 Hours</h2>
        <div class="h-72"><canvas id="aftershockChart24Hours" aria-label="Earthquakes per 24 hours over time by month"></canvas></div>
      </article>
    </div>
  </section>

  <!-- ============================================================ -->
  <!-- PAGE: BERITA                                                 -->
  <!-- ============================================================ -->
  <section id="page-berita" class="page hidden px-4 sm:px-6 py-6 max-w-[1800px] mx-auto space-y-6">
    <div>
      <p class="text-xs font-semibold text-brand-blue uppercase tracking-wide mb-1">Information</p>
      <h1 class="text-2xl font-bold text-brand-blue">News</h1>
      <p class="text-sm text-slate-500 mt-1">The latest updates on Opak Fault activity and earthquakes in the Special Region of Yogyakarta.</p>
    </div>

    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
      <article class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden hover:shadow-md transition">
        <div class="h-32 bg-gradient-to-br from-blue-800 to-blue-950 flex items-center justify-center"><i data-lucide="activity" class="w-8 h-8 text-blue-300"></i></div>
        <div class="p-4">
          <span class="text-[10px] font-semibold uppercase tracking-wide text-red-600 bg-red-50 px-2 py-0.5 rounded-full">Earthquake</span>
          <h3 class="font-bold text-sm text-slate-800 mt-2 mb-1">BMKG Records 68 Earthquakes Along the Opak Fault in the Past 30 Days</h3>
          <p class="text-xs text-slate-500 mb-2">Most earthquakes were small and were not felt by the public.</p>
          <p class="text-[11px] text-slate-400">August 11, 2026</p>
        </div>
      </article>

      <article class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden hover:shadow-md transition">
        <div class="h-32 bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center"><i data-lucide="zap" class="w-8 h-8 text-amber-100"></i></div>
        <div class="p-4">
          <span class="text-[10px] font-semibold uppercase tracking-wide text-red-600 bg-red-50 px-2 py-0.5 rounded-full">Earthquake</span>
          <h3 class="font-bold text-sm text-slate-800 mt-2 mb-1">Magnitude 5.4 Earthquake Shakes Bantul; BMKG Urges Calm</h3>
          <p class="text-xs text-slate-500 mb-2">The largest earthquake in the past month does not pose a tsunami threat.</p>
          <p class="text-[11px] text-slate-400">August 8, 2026</p>
        </div>
      </article>

      <article class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden hover:shadow-md transition">
        <div class="h-32 bg-gradient-to-br from-emerald-600 to-teal-700 flex items-center justify-center"><i data-lucide="shield-check" class="w-8 h-8 text-emerald-100"></i></div>
        <div class="p-4">
          <span class="text-[10px] font-semibold uppercase tracking-wide text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full">Activity</span>
          <h3 class="font-bold text-sm text-slate-800 mt-2 mb-1">BMKG Holds Earthquake Preparedness Drill in the Opak Fault Area</h3>
          <p class="text-xs text-slate-500 mb-2">The drill involved Bantul residents to strengthen disaster preparedness.</p>
          <p class="text-[11px] text-slate-400">July 25, 2026</p>
        </div>
      </article>

      <article class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden hover:shadow-md transition">
        <div class="h-32 bg-gradient-to-br from-indigo-700 to-blue-900 flex items-center justify-center"><i data-lucide="map" class="w-8 h-8 text-indigo-200"></i></div>
        <div class="p-4">
          <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-blue bg-blue-50 px-2 py-0.5 rounded-full">Education</span>
          <h3 class="font-bold text-sm text-slate-800 mt-2 mb-1">Opak Fault Segments and Their Potential Hazards</h3>
          <p class="text-xs text-slate-500 mb-2">A brief overview of the characteristics of each fault segment.</p>
          <p class="text-[11px] text-slate-400">August 2, 2026</p>
        </div>
      </article>

      <article class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden hover:shadow-md transition">
        <div class="h-32 bg-gradient-to-br from-slate-700 to-slate-900 flex items-center justify-center"><i data-lucide="radio" class="w-8 h-8 text-slate-300"></i></div>
        <div class="p-4">
          <span class="text-[10px] font-semibold uppercase tracking-wide text-red-600 bg-red-50 px-2 py-0.5 rounded-full">Earthquake</span>
          <h3 class="font-bold text-sm text-slate-800 mt-2 mb-1">A Series of Small Earthquakes Recorded on Opak Fault Segment 2</h3>
          <p class="text-xs text-slate-500 mb-2">BMKG reports that activity remains within normal levels.</p>
          <p class="text-[11px] text-slate-400">July 19, 2026</p>
        </div>
      </article>

      <article class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden hover:shadow-md transition">
        <div class="h-32 bg-gradient-to-br from-rose-700 to-red-900 flex items-center justify-center"><i data-lucide="siren" class="w-8 h-8 text-rose-200"></i></div>
        <div class="p-4">
          <span class="text-[10px] font-semibold uppercase tracking-wide text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full">Activity</span>
          <h3 class="font-bold text-sm text-slate-800 mt-2 mb-1">Earthquake Preparedness Outreach Reaches Schools in Bantul</h3>
          <p class="text-xs text-slate-500 mb-2">Hundreds of students took part in independent evacuation training.</p>
          <p class="text-[11px] text-slate-400">July 10, 2026</p>
        </div>
      </article>
    </div>
  </section>

  <!-- ============================================================ -->
  <!-- PAGE: PREPAREDNESS                                         -->
  <!-- ============================================================ -->
  <section id="page-edukasi" class="page hidden px-4 sm:px-6 py-6 max-w-[1800px] mx-auto space-y-6">
    <div>
      <p class="text-xs font-semibold text-brand-blue uppercase tracking-wide mb-1">Information</p>
      <h1 class="text-2xl font-bold text-brand-blue">Earthquake Preparedness</h1>
      <p class="text-sm text-slate-500 mt-1">How to prepare for earthquakes in the Opak Fault hazard area.</p>
    </div>

    <div class="grid md:grid-cols-3 gap-5">
      <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
        <div class="w-10 h-10 rounded-xl bg-blue-100 text-brand-blue flex items-center justify-center mb-3"><i data-lucide="clipboard-list" class="w-5 h-5"></i></div>
        <h3 class="font-bold text-sm text-brand-blue mb-3">Before an Earthquake</h3>
        <ul class="space-y-2 text-xs text-slate-600">
          <li class="flex gap-2"><i data-lucide="check" class="w-4 h-4 text-emerald-600 flex-shrink-0"></i> Identify evacuation routes and assembly points near your home or workplace.</li>
          <li class="flex gap-2"><i data-lucide="check" class="w-4 h-4 text-emerald-600 flex-shrink-0"></i> Prepare an emergency kit with essential supplies.</li>
          <li class="flex gap-2"><i data-lucide="check" class="w-4 h-4 text-emerald-600 flex-shrink-0"></i> Make sure buildings follow earthquake-resistant construction standards.</li>
          <li class="flex gap-2"><i data-lucide="check" class="w-4 h-4 text-emerald-600 flex-shrink-0"></i> Take part in preparedness training or drills regularly.</li>
        </ul>
      </div>

      <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
        <div class="w-10 h-10 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center mb-3"><i data-lucide="alert-triangle" class="w-5 h-5"></i></div>
        <h3 class="font-bold text-sm text-brand-blue mb-3">During an Earthquake</h3>
        <ul class="space-y-2 text-xs text-slate-600">
          <li class="flex gap-2"><i data-lucide="check" class="w-4 h-4 text-emerald-600 flex-shrink-0"></i> Drop, cover, and hold on under a sturdy table.</li>
          <li class="flex gap-2"><i data-lucide="check" class="w-4 h-4 text-emerald-600 flex-shrink-0"></i> Stay away from glass, shelves, and objects that could fall.</li>
          <li class="flex gap-2"><i data-lucide="check" class="w-4 h-4 text-emerald-600 flex-shrink-0"></i> If outdoors, move away from buildings and power lines.</li>
          <li class="flex gap-2"><i data-lucide="check" class="w-4 h-4 text-emerald-600 flex-shrink-0"></i> Stay calm. Do not run or use elevators.</li>
        </ul>
      </div>

      <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
        <div class="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center mb-3"><i data-lucide="heart-pulse" class="w-5 h-5"></i></div>
        <h3 class="font-bold text-sm text-brand-blue mb-3">After an Earthquake</h3>
        <ul class="space-y-2 text-xs text-slate-600">
          <li class="flex gap-2"><i data-lucide="check" class="w-4 h-4 text-emerald-600 flex-shrink-0"></i> Check yourself and those around you for injuries.</li>
          <li class="flex gap-2"><i data-lucide="check" class="w-4 h-4 text-emerald-600 flex-shrink-0"></i> Be alert for aftershocks.</li>
          <li class="flex gap-2"><i data-lucide="check" class="w-4 h-4 text-emerald-600 flex-shrink-0"></i> Turn off electricity and gas if you suspect a leak.</li>
          <li class="flex gap-2"><i data-lucide="check" class="w-4 h-4 text-emerald-600 flex-shrink-0"></i> Follow official updates from BMKG and relevant authorities.</li>
        </ul>
      </div>
    </div>

    <div class="bg-brand-blue rounded-2xl p-6 text-white flex flex-col md:flex-row gap-5 md:items-center">
      <div class="w-12 h-12 rounded-xl bg-white/10 flex items-center justify-center flex-shrink-0"><i data-lucide="backpack" class="w-6 h-6 text-amber-300"></i></div>
      <div class="flex-1">
        <h3 class="font-bold mb-1">Emergency Kit Essentials</h3>
        <p class="text-sm text-blue-100">Drinking water, non-perishable food, personal medication, a flashlight, a radio, important documents, and some cash. Keep the kit somewhere easy to reach and check its contents regularly.</p>
      </div>
    </div>
  </section>

  <!-- ============================================================ -->
  <!-- PAGE: KONTAK                                                 -->
  <!-- ============================================================ -->
  <section id="page-kontak" class="page hidden px-4 sm:px-6 py-6 max-w-[1800px] mx-auto space-y-6 flex-1">
    <div>
      <h1 class="text-2xl font-bold text-brand-blue">Contact</h1>
      <p class="text-sm text-slate-500 mt-1">Contact the Sleman Class I Geophysics Station for more information.</p>
    </div>

    <div class="grid lg:grid-cols-3 gap-6">
      <div class="lg:col-span-1 bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-5">
        <div class="flex gap-3">
          <div class="w-9 h-9 rounded-lg bg-blue-100 text-brand-blue flex items-center justify-center flex-shrink-0"><i data-lucide="map-pin" class="w-4 h-4"></i></div>
          <div>
            <p class="text-xs font-semibold text-slate-500 uppercase">Address</p>
            <p class="text-sm text-slate-700">Jl. Wates KM 8, Jitengan, Balecatur, Gamping, Sleman, DIY 55295</p>
          </div>
        </div>
        <div class="flex gap-3">
          <div class="w-9 h-9 rounded-lg bg-emerald-100 text-emerald-600 flex items-center justify-center flex-shrink-0"><i data-lucide="phone" class="w-4 h-4"></i></div>
          <div>
            <p class="text-xs font-semibold text-slate-500 uppercase">Phone</p>
            <p class="text-sm text-slate-700">(0274) 6498383</p>
          </div>
        </div>
        <div class="flex gap-3">
          <div class="w-9 h-9 rounded-lg bg-amber-100 text-amber-600 flex items-center justify-center flex-shrink-0"><i data-lucide="mail" class="w-4 h-4"></i></div>
          <div>
            <p class="text-xs font-semibold text-slate-500 uppercase">Email</p>
            <p class="text-sm text-slate-700">stageof.yogya@bmkg.go.id</p>
          </div>
        </div>
        <div class="flex gap-3">
          <div class="w-9 h-9 rounded-lg bg-purple-100 text-purple-600 flex items-center justify-center flex-shrink-0"><i data-lucide="clock" class="w-4 h-4"></i></div>
          <div>
            <p class="text-xs font-semibold text-slate-500 uppercase">Service Hours</p>
            <p class="text-sm text-slate-700">Monday &ndash; Friday, 08:00 &ndash; 16:00 WIB &middot; 24-hour earthquake monitoring</p>
          </div>
        </div>
        <div class="flex gap-3 pt-2 border-t border-slate-100">
          <a href="https://www.google.com/maps/search/?api=1&query=Stasiun+Geofisika+Kelas+I+Sleman+BMKG" target="_blank" rel="noopener" class="text-xs font-semibold text-brand-blue hover:underline flex items-center gap-1">
            Open in Google Maps <i data-lucide="external-link" class="w-3.5 h-3.5"></i>
          </a>
        </div>
      </div>

      <div class="lg:col-span-2 bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
        <h3 class="font-bold text-brand-blue text-sm mb-4">Send a Message</h3>
        <form class="space-y-4" onsubmit="return false;">
          <div class="grid sm:grid-cols-2 gap-4">
            <div>
              <label class="text-xs font-semibold text-slate-500 mb-1 block">Name</label>
              <input type="text" placeholder="Full name" class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-300">
            </div>
            <div>
              <label class="text-xs font-semibold text-slate-500 mb-1 block">Email</label>
              <input type="email" placeholder="name@example.com" class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-300">
            </div>
          </div>
          <div>
            <label class="text-xs font-semibold text-slate-500 mb-1 block">Message</label>
            <textarea rows="5" placeholder="Write your message here..." class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-300"></textarea>
          </div>
          <button type="submit" class="bg-brand-blue hover:bg-brand-blue-dark text-white text-sm font-semibold px-5 py-2.5 rounded-xl transition inline-flex items-center gap-2">
            <i data-lucide="send" class="w-4 h-4"></i> Send Message
          </button>
        </form>
      </div>
    </div>
  </section>

  <!-- FOOTER -->
  <footer class="bg-brand-blue-dark text-blue-100 mt-4">
    <div class="max-w-[1800px] mx-auto px-6 py-10 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8 text-sm">
      <div>
        <p class="text-white font-bold mb-1">&copy; 2026 Stasiun Geofisika Kelas I Sleman &ndash; BMKG</p>
        <p class="text-blue-300 text-xs">Monitoring earthquake activity around the Opak Fault.</p>
      </div>
      <div>
        <h4 class="text-white font-bold mb-2">Data &amp; Sources</h4>
        <ul class="space-y-1 text-blue-200 text-xs">
          <li>Data Source: BMKG &ndash; Sleman Class I Geophysics Station</li>
          <li>Data Period: July 12, 2026 &ndash; August 11, 2026</li>
          <li>Coordinate System: WGS 84</li>
        </ul>
      </div>
      <div>
        <h4 class="text-white font-bold mb-2">Quick Links</h4>
        <ul class="space-y-1 text-xs">
          <li><button data-nav="beranda" class="hover:underline hover:text-white">Home</button></li>
          <li><button data-nav="tentang" class="hover:underline hover:text-white">About the Opak Fault</button></li>
          <li><button data-nav="aktivitas" class="hover:underline hover:text-white">Earthquake Activity</button></li>
          <li><button data-nav="berita" class="hover:underline hover:text-white">News</button></li>
          <li><button data-nav="edukasi" class="hover:underline hover:text-white">Preparedness</button></li>
          <li><button data-nav="kontak" class="hover:underline hover:text-white">Contact</button></li>
        </ul>
      </div>
      <div>
        <h4 class="text-white font-bold mb-2">Follow Us</h4>
        <div class="flex gap-2">
          <a href="https://www.instagram.com/stageof.sleman?igsh=aTFjeWxodGh3YWlx" target="_blank" rel="noopener" aria-label="Instagram" class="w-9 h-9 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-white">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <rect x="3.5" y="3.5" width="17" height="17" rx="5"></rect>
              <circle cx="12" cy="12" r="4"></circle>
              <circle cx="17.2" cy="6.8" r="1.1" fill="currentColor" stroke="none"></circle>
            </svg>
          </a>
          <a href="https://youtube.com/@stageof.sleman?si=HeqTy3kptAWd70XO" target="_blank" rel="noopener" aria-label="YouTube" class="w-9 h-9 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-white">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true">
              <path d="M22.5 12.2c0-1.4-.2-2.8-.6-4.1-.5-1.5-1.6-2.6-3.1-3.1C17 4.3 14.8 4 12 4s-5 .3-7 .9c-1.5.5-2.6 1.6-3.1 3.1A16.5 16.5 0 0 0 1.3 12c0 1.4.2 2.8.6 4.1.5 1.5 1.6 2.6 3.1 3.1 2 .6 4.2.9 7 .9s5-.3 7-.9c1.5-.5 2.6-1.6 3.1-3.1.4-1.3.6-2.7.6-4.1ZM10 15.6V8.4l6 3.6-6 3.6Z"></path>
            </svg>
          </a>
          <a href="https://whatsapp.com/channel/0029Vb6qAIrHAdNQdzOYNz2s" target="_blank" rel="noopener" aria-label="WhatsApp" class="w-9 h-9 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-white">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true">
              <path d="M12 2.2A9.8 9.8 0 0 0 4.4 17L3 21l4.2-1.3A9.8 9.8 0 1 0 12 2.2Zm5.7 13.5c-.3.8-1.7 1.5-2.3 1.6-.6.1-1.4.1-4.4-1.1-3.6-1.4-6-5.2-6.2-5.5-.2-.3-1.6-2.1-1.6-4s1-2.8 1.4-3.2c.3-.4.7-.5 1-.5h.7c.2 0 .5 0 .8.6.3.7.9 2.3.9 2.5s.1.4-.2.7c-.2.2-.4.5-.6.8-.2.2-.4.4-.2.8.2.4.8 1.3 1.7 2.1 1.2 1 2 1.4 2.4 1.5.4.1.7.1 1-.3.3-.4.9-1.1 1.2-1.5.3-.3.6-.4 1-.2l2.9 1.3c.4.2.7.4.8.7.1.4.1.8-.2 1.2Z"></path>
            </svg>
          </a>
        </div>
      </div>
    </div>
  </footer>

  </main>

  <script>
    // Init Lucide Icons
    lucide.createIcons();

    // ---------- CLOCK ----------
    function updateClock() {
      const now = new Date();
      const options = {
        day: '2-digit', month: 'long', year: 'numeric',
        hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false
      };
      const formatted = new Intl.DateTimeFormat('en-US', options).format(now);
      ['live-clock', 'live-clock-mobile'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.innerHTML = `${formatted.replace(',', '')} WIB`;
      });
    }
    updateClock();
    setInterval(updateClock, 1000);

    // ---------- PAGE ROUTING ----------
    const pages = ['beranda', 'tentang', 'aktivitas', 'repository', 'aftershock', 'berita', 'edukasi', 'kontak'];
    let aktivitasInitialized = false;
    let aftershockMap = null;
    let aftershockMapInitialized = false;
    let aftershockCatalogLoaded = false;
    let aftershockCatalogLoading = false;
    let aftershockCatalog = [];
    let aftershockCharts = [];

// VARIABEL PETA REPOSITORY
    let repoMapInitialized = false;
    let repoMap = null;
    let repoMarkers = L.layerGroup();
    const repoMarkerById = new Map();
    let gempaIdsDalamBatasSegmen = null;

    function getMagnitudeMarkerRadius(magnitude) {
        const mag = Number.isFinite(magnitude) ? magnitude : 0;
        return 4 + (mag > 0 ? mag : 1);
    }

    function getDepthMarkerColor(depth) {
        if (!Number.isFinite(depth)) return '#10b981';
        if (depth < 50) return '#dc2626';
        if (depth < 100) return '#f97316';
        if (depth < 250) return '#fbbf24';
        return '#10b981';
    }

    function createEarthquakeCircleMarker(lat, lng, magnitude, depth, className = '') {
        return L.circleMarker([lat, lng], {
            radius: getMagnitudeMarkerRadius(magnitude),
            color: '#dc2626',
            weight: 1,
            fillColor: getDepthMarkerColor(depth),
            fillOpacity: 0.95,
            className: className
        });
    }

    function createShakemapMarker(lat, lng, magnitude, depth) {
        const radius = getMagnitudeMarkerRadius(magnitude);
        const size = radius * 2;
        const label = Number.isFinite(magnitude) ? magnitude.toFixed(1) : '-';
        const icon = L.divIcon({
            className: 'shakemap-quake-icon',
            html: `<div style="width:${size}px;height:${size}px;display:flex;align-items:center;justify-content:center;border:1px solid #dc2626;border-radius:50%;background:${getDepthMarkerColor(depth)};box-shadow:0 0 3px rgba(0,0,0,.65);color:#fff;font-size:${Math.max(8, Math.min(11, size * 0.38))}px;font-weight:800;line-height:1;">${label}</div>`,
            iconSize: [size, size],
            iconAnchor: [size / 2, size / 2]
        });
        return L.marker([lat, lng], { icon: icon });
    }

    function escapeTableText(value) {
      return String(value ?? '').replace(/[&<>'"]/g, function(character) {
        return {'&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#39;', '"':'&quot;'}[character];
      });
    }

    function buatDesaTerdampakHTML(gempa) {
      const desaTerdampak = Array.isArray(gempa.desa_terdampak) ? gempa.desa_terdampak : [];
      const daftarDesa = desaTerdampak.length
        ? desaTerdampak.map(function(desa) {
            const nama = escapeTableText(desa.nama || 'Unknown');
            const mmi = desa.mmi ? escapeTableText(desa.mmi) : 'No data';
            return '<li>' + nama + ' (MMI: ' + mmi + ')</li>';
          }).join('')
        : '<li>No villages have an estimated intensity of at least MMI II.</li>';

      return '<div style="margin-top:8px;"><div style="font-size:12px;font-weight:700;color:#0f172a;margin-bottom:4px;">Affected Villages (estimated MMI)</div>' +
        '<ul style="font-size:12px;color:#334155;margin:0;padding-left:16px;line-height:1.6;">' + daftarDesa + '</ul></div>';
    }

    function initRepositoryMap() {
        if (repoMapInitialized) return;
        
        repoMap = L.map('mapRepository', { zoomControl: false }).setView([-7.94, 110.42], 11);
        const subdomains = ['mt0', 'mt1', 'mt2', 'mt3'];
        const gmapsStreets = L.tileLayer('https://{s}.google.com/vt/lyrs=m&x={x}&y={y}&z={z}', {
            maxZoom: 20,
            subdomains: subdomains,
            attribution: '&copy; Google Maps'
        });
        const gmapsSatellite = L.tileLayer('https://{s}.google.com/vt/lyrs=s,h&x={x}&y={y}&z={z}', {
            maxZoom: 20,
            subdomains: subdomains,
            attribution: '&copy; Google Maps'
        }).addTo(repoMap);
        const gmapsTerrain = L.tileLayer('https://{s}.google.com/vt/lyrs=p&x={x}&y={y}&z={z}', {
            maxZoom: 20,
            subdomains: subdomains,
            attribution: '&copy; Google Maps'
        });
        const osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap'
        });
        const baseMaps = {
            'Google Streets': gmapsStreets,
            'Google Satellite (Hybrid)': gmapsSatellite,
            'Google Terrain': gmapsTerrain,
            'OpenStreetMap': osm
        };
        L.control.layers(baseMaps, null, { position: 'topleft' }).addTo(repoMap);
        document.getElementById('zoomInRepositoryBtn').addEventListener('click', function() {
            repoMap.zoomIn();
        });
        document.getElementById('zoomOutRepositoryBtn').addEventListener('click', function() {
            repoMap.zoomOut();
        });

        const gridStyle = document.createElement('style');
        gridStyle.textContent = `
            .grid-label-custom {
                background-color: #1F2933 !important;
                color: #ffffff !important;
                padding: 4px 6px !important;
                font-size: 10px !important;
                font-weight: 800 !important;
                border-radius: 6px !important;
                white-space: nowrap !important;
                text-align: center !important;
                border: 1px solid rgba(255, 255, 255, 0.4) !important;
                box-shadow: 0 2px 5px rgba(0, 0, 0, 0.5) !important;
                font-family: 'Inter', sans-serif !important;
            }
            .btn-grid-aktif {
                background-color: #ffffff !important;
                color: #1F2933 !important;
            }
        `;
        document.head.appendChild(gridStyle);

        const gridLayer = L.layerGroup();
        let gridVisible = false;
        const gridButton = document.getElementById('toggleRepositoryGridBtn');

        function drawRepositoryCoordinateGrid() {
            gridLayer.clearLayers();
            if (!gridVisible) return;

            const bounds = repoMap.getBounds();
            const zoom = repoMap.getZoom();
            let interval = 0.1;
            if (zoom < 10) interval = 0.5;
            if (zoom < 8) interval = 1.0;
            if (zoom > 12) interval = 0.05;

            const west = Math.floor(bounds.getWest() / interval) * interval;
            const east = Math.ceil(bounds.getEast() / interval) * interval;
            const south = Math.floor(bounds.getSouth() / interval) * interval;
            const north = Math.ceil(bounds.getNorth() / interval) * interval;
            const latMarginBottom = bounds.getSouth() + ((bounds.getNorth() - bounds.getSouth()) * 0.04);
            const lngMarginLeft = bounds.getWest() + ((bounds.getEast() - bounds.getWest()) * 0.04);

            for (let lng = west; lng <= east; lng += interval) {
                L.polyline([[-90, lng], [90, lng]], {
                    color: 'white', weight: 1.5, dashArray: '4, 4', opacity: 0.7, interactive: false
                }).addTo(gridLayer);
                const longitudeIcon = L.divIcon({
                    className: '',
                    html: '<div class="grid-label-custom" style="position:absolute;left:-27px;top:-10px;width:55px;">' +
                        Math.abs(lng).toFixed(2) + '° E</div>',
                    iconSize: [0, 0]
                });
                L.marker([latMarginBottom, lng], { icon: longitudeIcon, interactive: false }).addTo(gridLayer);
            }

            for (let lat = south; lat <= north; lat += interval) {
                L.polyline([[lat, -180], [lat, 180]], {
                    color: 'white', weight: 1.5, dashArray: '4, 4', opacity: 0.7, interactive: false
                }).addTo(gridLayer);
                const latitudeIcon = L.divIcon({
                    className: '',
                    html: '<div class="grid-label-custom" style="position:absolute;left:-27px;top:-10px;width:55px;transform:rotate(-90deg);transform-origin:center;">' +
                        Math.abs(lat).toFixed(2) + '° S</div>',
                    iconSize: [0, 0]
                });
                L.marker([lat, lngMarginLeft], { icon: latitudeIcon, interactive: false }).addTo(gridLayer);
            }
        }

        repoMap.on('moveend', drawRepositoryCoordinateGrid);
        repoMap.on('zoomend', drawRepositoryCoordinateGrid);
        gridButton.addEventListener('click', function(event) {
            event.preventDefault();
            gridVisible = !gridVisible;
            if (gridVisible) {
                repoMap.addLayer(gridLayer);
                gridButton.classList.add('btn-grid-aktif');
                drawRepositoryCoordinateGrid();
            } else {
                repoMap.removeLayer(gridLayer);
                gridButton.classList.remove('btn-grid-aktif');
            }
        });
        
        repoMap.addLayer(repoMarkers);
        repoMapInitialized = true;
    }

    function updateRepoMapMarkers(filteredDataList) {
        if (!repoMap) return;
        repoMarkers.clearLayers();
        repoMarkerById.clear();
        
        const bounds = L.latLngBounds();
        let hasPoints = false;

        filteredDataList.forEach(gempa => {
            const lat = parseFloat(gempa.lat);
            const lng = parseFloat(gempa.lng);
            const mag = parseFloat(gempa.magnitudo);
            const depth = parseFloat(gempa.kedalaman);
            const idGempa = String(gempa.id_gempa);
            
            if (gempaIdsDalamBatasSegmen !== null && !gempaIdsDalamBatasSegmen.has(idGempa)) return;
            if (Number.isFinite(lat) && Number.isFinite(lng) &&
                lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180) {
                const marker = createEarthquakeCircleMarker(lat, lng, mag, depth);
                
                marker.bindPopup(`
                    <div class="font-sans">
                        <div class="text-center">
                            <strong class="text-brand-blue text-sm">M ${mag.toFixed(1)}</strong><br>
                            <span class="text-xs text-slate-500">${escapeTableText(gempa.tanggal)}</span>
                        </div>
                    </div>
                `);
                
                repoMarkers.addLayer(marker);
                repoMarkerById.set(idGempa, marker);
                bounds.extend([lat, lng]);
                hasPoints = true;
            }
        });

        if (hasPoints) {
            repoMap.fitBounds(bounds, { padding: [30, 30], maxZoom: 12 });
        }
    }

    function setAftershockStatus(message) {
      const status = document.getElementById('aftershockStatus');
      status.textContent = message;
      status.classList.toggle('hidden', message === '');
    }

    function initAftershockAnalysis() {
      const mainEventSelect = document.getElementById('aftershockMainEvent');

      if (!aftershockMapInitialized) {
        const subdomains = ['mt0', 'mt1', 'mt2', 'mt3'];
        const streets = L.tileLayer('https://{s}.google.com/vt/lyrs=m&x={x}&y={y}&z={z}', {
          maxZoom: 20, subdomains: subdomains, attribution: '&copy; Google Maps'
        });
        const satellite = L.tileLayer('https://{s}.google.com/vt/lyrs=s,h&x={x}&y={y}&z={z}', {
          maxZoom: 20, subdomains: subdomains, attribution: '&copy; Google Maps'
        });
        const terrain = L.tileLayer('https://{s}.google.com/vt/lyrs=p&x={x}&y={y}&z={z}', {
          maxZoom: 20, subdomains: subdomains, attribution: '&copy; Google Maps'
        });
        const osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
          attribution: '&copy; OpenStreetMap'
        });

        aftershockMap = L.map('mapAftershock', { zoomControl: false, layers: [satellite] })
          .setView([-7.94, 110.42], 11);
        L.control.layers({
          'Google Streets': streets,
          'Google Satellite (Hybrid)': satellite,
          'Google Terrain': terrain,
          'OpenStreetMap': osm
        }, null, { position: 'topleft' }).addTo(aftershockMap);
        document.getElementById('zoomInAftershockBtn').addEventListener('click', function() {
          aftershockMap.zoomIn();
        });
        document.getElementById('zoomOutAftershockBtn').addEventListener('click', function() {
          aftershockMap.zoomOut();
        });

        const aftershockGridLayer = L.layerGroup();
        const aftershockGridButton = document.getElementById('toggleAftershockGridBtn');
        let aftershockGridVisible = false;
        function drawAftershockCoordinateGrid() {
          aftershockGridLayer.clearLayers();
          if (!aftershockGridVisible) return;

          const bounds = aftershockMap.getBounds();
          const zoom = aftershockMap.getZoom();
          let interval = 0.1;
          if (zoom < 10) interval = 0.5;
          if (zoom < 8) interval = 1.0;
          if (zoom > 12) interval = 0.05;

          const west = Math.floor(bounds.getWest() / interval) * interval;
          const east = Math.ceil(bounds.getEast() / interval) * interval;
          const south = Math.floor(bounds.getSouth() / interval) * interval;
          const north = Math.ceil(bounds.getNorth() / interval) * interval;
          const latMarginBottom = bounds.getSouth() + ((bounds.getNorth() - bounds.getSouth()) * 0.04);
          const lngMarginLeft = bounds.getWest() + ((bounds.getEast() - bounds.getWest()) * 0.04);

          for (let lng = west; lng <= east; lng += interval) {
            L.polyline([[-90, lng], [90, lng]], {
              color: 'white', weight: 1.5, dashArray: '4, 4', opacity: 0.7, interactive: false
            }).addTo(aftershockGridLayer);
            const longitudeIcon = L.divIcon({
              className: '',
              html: '<div class="grid-label-custom" style="position:absolute;left:-27px;top:-10px;width:55px;">' +
                Math.abs(lng).toFixed(2) + '° E</div>',
              iconSize: [0, 0]
            });
            L.marker([latMarginBottom, lng], { icon: longitudeIcon, interactive: false }).addTo(aftershockGridLayer);
          }

          for (let lat = south; lat <= north; lat += interval) {
            L.polyline([[lat, -180], [lat, 180]], {
              color: 'white', weight: 1.5, dashArray: '4, 4', opacity: 0.7, interactive: false
            }).addTo(aftershockGridLayer);
            const latitudeIcon = L.divIcon({
              className: '',
              html: '<div class="grid-label-custom" style="position:absolute;left:-27px;top:-10px;width:55px;transform:rotate(-90deg);transform-origin:center;">' +
                Math.abs(lat).toFixed(2) + '° S</div>',
              iconSize: [0, 0]
            });
            L.marker([lat, lngMarginLeft], { icon: latitudeIcon, interactive: false }).addTo(aftershockGridLayer);
          }
        }

        aftershockMap.on('moveend', drawAftershockCoordinateGrid);
        aftershockMap.on('zoomend', drawAftershockCoordinateGrid);
        aftershockGridButton.addEventListener('click', function(event) {
          event.preventDefault();
          aftershockGridVisible = !aftershockGridVisible;
          if (aftershockGridVisible) {
            aftershockMap.addLayer(aftershockGridLayer);
            aftershockGridButton.classList.add('btn-grid-aktif');
            drawAftershockCoordinateGrid();
          } else {
            aftershockMap.removeLayer(aftershockGridLayer);
            aftershockGridButton.classList.remove('btn-grid-aktif');
          }
        });

        aftershockCharts = [
          { intervalHours: 3, canvasId: 'aftershockChart3Hours', axis: 'day' },
          { intervalHours: 6, canvasId: 'aftershockChart6Hours', axis: 'day' },
          { intervalHours: 12, canvasId: 'aftershockChart12Hours', axis: 'month' },
          { intervalHours: 24, canvasId: 'aftershockChart24Hours', axis: 'month' }
        ].map(function(config) {
          return {
            config: config,
            chart: new Chart(document.getElementById(config.canvasId), {
              type: 'line',
              data: {
                labels: [],
                datasets: [{
                  label: 'Aftershocks',
                  data: [],
                  showLine: false,
                  borderColor: '#2347C5',
                  backgroundColor: '#2347C5',
                  pointRadius: 4,
                  pointHoverRadius: 6,
                  borderWidth: 2
                }, {
                  label: 'Felt earthquakes',
                  data: [],
                  showLine: false,
                  borderColor: '#ea580c',
                  backgroundColor: '#ea580c',
                  pointRadius: 4,
                  pointHoverRadius: 6,
                  borderWidth: 2
                }]
              },
              options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                  legend: { display: true, position: 'bottom', labels: { boxWidth: 10, font: { size: 9 } } }
                },
                scales: {
                  x: {
                    title: { display: true, text: config.axis === 'day' ? 'Date (daily)' : 'Month' },
                    grid: { display: false },
                    ticks: { autoSkip: false, maxRotation: 90, minRotation: 90, font: { size: 8 } }
                  },
                  y: {
                    beginAtZero: true,
                    ticks: { precision: 0 },
                    title: { display: true, text: 'Number of earthquakes' },
                    grid: { color: '#f1f5f9' }
                  }
                }
              },
              plugins: [{
                id: 'aftershockLollipopStems',
                beforeDatasetsDraw: function(chart) {
                  const baseline = chart.scales.y.getPixelForValue(0);
                  const context = chart.ctx;
                  context.save();
                  chart.data.datasets.forEach(function(dataset, datasetIndex) {
                    if (!chart.isDatasetVisible(datasetIndex)) return;
                    context.strokeStyle = dataset.borderColor;
                    context.lineWidth = 2;
                    chart.getDatasetMeta(datasetIndex).data.forEach(function(point) {
                      context.beginPath();
                      context.moveTo(point.x, baseline);
                      context.lineTo(point.x, point.y);
                      context.stroke();
                    });
                  });
                  context.restore();
                }
              }]
            })
          };
        });
        aftershockMapInitialized = true;
      }

      setTimeout(function() {
        if (aftershockMap) aftershockMap.invalidateSize();
      }, 150);

      if (aftershockCatalogLoaded || aftershockCatalogLoading) return;
      aftershockCatalogLoading = true;
      setAftershockStatus('Loading earthquake catalog...');
      fetch('index.php?action=aftershock_catalog', {
        headers: { Accept: 'application/json' },
        cache: 'no-store'
      }).then(function(response) {
        return response.json().then(function(result) {
          if (!response.ok || !result.success || !Array.isArray(result.data)) {
            throw new Error(result.message || 'The earthquake catalog data is invalid.');
          }
          return result.data;
        });
      }).then(function(data) {
        aftershockCatalog = data.map(function(gempa) {
          const dateParts = String(gempa.tanggal || '').split('-').map(Number);
          const timeParts = String(gempa.waktu_ot || '').split(':').map(Number);
          if (dateParts.length !== 3 || timeParts.length < 2 ||
              dateParts.some(function(part) { return !Number.isFinite(part); }) ||
              timeParts.some(function(part) { return !Number.isFinite(part); })) return null;

          return Object.assign({}, gempa, {
            timestamp: Date.UTC(
              dateParts[0], dateParts[1] - 1, dateParts[2],
              timeParts[0], timeParts[1], timeParts[2] || 0
            )
          });
        }).filter(Boolean);

        if (!aftershockCatalog.length) {
          throw new Error('No earthquake records with a valid date and origin time were found.');
        }

        mainEventSelect.innerHTML = '';
        aftershockCatalog.forEach(function(gempa) {
          const option = document.createElement('option');
          option.value = String(gempa.id_gempa);
          const magnitude = Number(gempa.magnitudo);
          option.textContent = gempa.tanggal + ' ' + gempa.waktu_ot +
            ' | M' + (Number.isFinite(magnitude) ? magnitude.toFixed(1) : '-');
          mainEventSelect.appendChild(option);
        });
        mainEventSelect.disabled = false;
        aftershockCatalogLoaded = true;
        aftershockCatalogLoading = false;
        setAftershockStatus('');
        updateAftershockAnalysis();
      }).catch(function(error) {
        aftershockCatalogLoading = false;
        setAftershockStatus('Failed to load data: ' + error.message);
        console.error('Gagal memuat katalog analisis gempa susulan:', error);
      });

      if (!mainEventSelect.dataset.bound) {
        mainEventSelect.dataset.bound = 'true';
        mainEventSelect.addEventListener('change', updateAftershockAnalysis);
        document.getElementById('aftershockWindowHours').addEventListener('change', updateAftershockAnalysis);
      }
    }

    function updateAftershockAnalysis() {
      if (!aftershockCatalogLoaded || !aftershockMap) return;

      const windowSelect = document.getElementById('aftershockWindowHours');
      const windowHours = Number(windowSelect.value);
      if (![3, 6, 12, 24, 48, 72, 168, 336, 720].includes(windowHours)) {
        setAftershockStatus('Select an observation period between 3 hours and 1 month.');
        return;
      }

      const mainEvent = aftershockCatalog.find(function(gempa) {
        return String(gempa.id_gempa) === document.getElementById('aftershockMainEvent').value;
      });
      if (!mainEvent) {
        setAftershockStatus('Select a mainshock first.');
        return;
      }

      const windowEnd = mainEvent.timestamp + windowHours * 60 * 60 * 1000;
      const aftershocks = aftershockCatalog.filter(function(gempa) {
        return gempa.timestamp > mainEvent.timestamp && gempa.timestamp <= windowEnd;
      });
      const feltAftershocks = aftershocks.filter(function(gempa) {
        return String(gempa.dirasakan || '').trim().toLowerCase() === 'ya';
      });
      const mapEvents = [mainEvent].concat(aftershocks);
      const bounds = L.latLngBounds();
      aftershockMap.eachLayer(function(layer) {
        if (layer instanceof L.CircleMarker && layer.options.aftershockEventMarker) {
          aftershockMap.removeLayer(layer);
        }
      });

      mapEvents.forEach(function(gempa) {
        const lat = gempa.lat === null || String(gempa.lat).trim() === '' ? NaN : Number(gempa.lat);
        const lng = gempa.lng === null || String(gempa.lng).trim() === '' ? NaN : Number(gempa.lng);
        if (!Number.isFinite(lat) || !Number.isFinite(lng) ||
            lat < -90 || lat > 90 || lng < -180 || lng > 180) return;

        const isMainEvent = String(gempa.id_gempa) === String(mainEvent.id_gempa);
        const magnitude = Number(gempa.magnitudo);
        const marker = L.circleMarker([lat, lng], {
          radius: Math.max(5, Math.min(12, 4 + (Number.isFinite(magnitude) ? magnitude : 1))),
          color: '#ffffff',
          weight: 1.5,
          fillColor: isMainEvent ? '#dc2626' : '#2563eb',
          fillOpacity: 0.9,
          aftershockEventMarker: true
        }).addTo(aftershockMap);
        marker.on('click', function() {
          document.getElementById('aftershockMainEvent').value = String(gempa.id_gempa);
          updateAftershockAnalysis();
        });
        const popup = document.createElement('div');
        const title = document.createElement('strong');
        title.textContent = isMainEvent ? 'Mainshock' : 'Aftershock';
        const details = document.createElement('div');
        details.textContent = gempa.tanggal + ' ' + gempa.waktu_ot +
          ' | M' + (Number.isFinite(magnitude) ? magnitude.toFixed(1) : '-');
        popup.appendChild(title);
        popup.appendChild(details);
        marker.bindPopup(popup);
        bounds.extend([lat, lng]);
      });

      if (bounds.isValid()) aftershockMap.fitBounds(bounds, { padding: [35, 35], maxZoom: 12 });

      aftershockCharts.forEach(function(chartEntry) {
        const intervalHours = chartEntry.config.intervalHours;
        const intervalMs = intervalHours * 60 * 60 * 1000;
        const bucketCount = Math.ceil(windowHours / intervalHours);
        const aftershockCounts = Array(bucketCount).fill(0);
        const feltCounts = Array(bucketCount).fill(0);
        const labels = [];
        let previousAxisPeriod = '';

        for (let bucketIndex = 0; bucketIndex < bucketCount; bucketIndex++) {
          const bucketDate = new Date(mainEvent.timestamp + bucketIndex * intervalMs);
          const year = bucketDate.getUTCFullYear();
          const month = String(bucketDate.getUTCMonth() + 1).padStart(2, '0');
          const day = String(bucketDate.getUTCDate()).padStart(2, '0');
          const period = chartEntry.config.axis === 'day'
            ? year + '-' + month + '-' + day
            : year + '-' + month;
          labels.push(period === previousAxisPeriod
            ? ''
            : chartEntry.config.axis === 'day' ? day + '/' + month : month + '/' + year);
          previousAxisPeriod = period;
        }

        aftershocks.forEach(function(gempa) {
          const bucketIndex = Math.floor((gempa.timestamp - mainEvent.timestamp) / intervalMs);
          if (bucketIndex < 0 || bucketIndex >= bucketCount) return;
          aftershockCounts[bucketIndex]++;
          if (String(gempa.dirasakan || '').trim().toLowerCase() === 'ya') {
            feltCounts[bucketIndex]++;
          }
        });

        chartEntry.chart.data.labels = labels;
        chartEntry.chart.data.datasets[0].data = aftershockCounts;
        chartEntry.chart.data.datasets[1].data = feltCounts;
        chartEntry.chart.update('none');
      });

      document.getElementById('aftershockCount').textContent = String(aftershocks.length);
      document.getElementById('feltAftershockCount').textContent = String(feltAftershocks.length);
      setAftershockStatus('');
    }

    function showPage(name) {
      if (!pages.includes(name)) return;

      pages.forEach(p => {
        const el = document.getElementById('page-' + p);
        if (el) el.classList.toggle('hidden', p !== name);
      });

      document.querySelectorAll('[data-nav]').forEach(btn => {
        const isMatch = btn.getAttribute('data-nav') === name;
        btn.classList.toggle('is-active', isMatch);
      });

      const infoBtn = document.getElementById('infoDropdownBtn');
      if (infoBtn) infoBtn.classList.toggle('is-active', name === 'berita' || name === 'edukasi');

      const gempaBtn = document.getElementById('gempaDropdownBtn');
      if (gempaBtn) gempaBtn.classList.toggle('is-active', name === 'aktivitas' || name === 'repository' || name === 'aftershock');

      document.getElementById('infoDropdownMenu')?.classList.add('hidden');
      document.getElementById('gempaDropdownMenu')?.classList.add('hidden');
      document.getElementById('mobileNav')?.classList.add('hidden');

      window.scrollTo({ top: 0, behavior: 'smooth' });

      // 1. Pastikan wadah peta Repository disiapkan DULUAN jika tab ini dibuka
      if (name === 'repository' && !repoMapInitialized) {
        initRepositoryMap();
      }

      if (name === 'aftershock') {
        initAftershockAnalysis();
      }

      // 2. Muat data gempa dari MySQL (kalau belum pernah dimuat)
      if ((name === 'aktivitas' || name === 'repository') && !aktivitasInitialized) {
        initAktivitasGempa();
        aktivitasInitialized = true;
      }

      // 3. Paksa data untuk digambar ulang ke tabel & Peta Repository setiap kali tab dibuka
      if (name === 'repository' && typeof window.renderDataTableGlobal === 'function') {
        window.renderDataTableGlobal();
        setTimeout(() => { if (repoMap) repoMap.invalidateSize(); }, 150);
      }

      if (name === 'aktivitas') {
        setTimeout(() => { if (window.__opakMap) window.__opakMap.invalidateSize(); }, 150);
      }
    }

    document.querySelectorAll('[data-nav]').forEach(btn => {
      btn.addEventListener('click', () => showPage(btn.getAttribute('data-nav')));
    });

// Informasi dropdown (desktop)
    const infoDropdownBtn = document.getElementById('infoDropdownBtn');
    const infoDropdownMenu = document.getElementById('infoDropdownMenu');
    if (infoDropdownBtn) {
      infoDropdownBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        infoDropdownMenu.classList.toggle('hidden');
        document.getElementById('gempaDropdownMenu')?.classList.add('hidden'); // Tutup gempa kalau info dibuka
      });
    }

    // Gempa dropdown (desktop)
    const gempaDropdownBtn = document.getElementById('gempaDropdownBtn');
    const gempaDropdownMenu = document.getElementById('gempaDropdownMenu');
    if (gempaDropdownBtn) {
      gempaDropdownBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        gempaDropdownMenu.classList.toggle('hidden');
        document.getElementById('infoDropdownMenu')?.classList.add('hidden'); // Tutup info kalau gempa dibuka
      });
    }

    // Tutup semua dropdown jika klik di luar area
    document.addEventListener('click', (e) => {
      if (document.getElementById('infoDropdownWrap') && !document.getElementById('infoDropdownWrap').contains(e.target)) {
        document.getElementById('infoDropdownMenu')?.classList.add('hidden');
      }
      if (document.getElementById('gempaDropdownWrap') && !document.getElementById('gempaDropdownWrap').contains(e.target)) {
        document.getElementById('gempaDropdownMenu')?.classList.add('hidden');
      }
    });

    // Mobile menu toggle
    document.getElementById('mobileMenuBtn').addEventListener('click', () => {
      document.getElementById('mobileNav').classList.toggle('hidden');
    });

    function initGaleriSlider() {
      const galeriSlider = document.getElementById('galeriSlider');
      if (!galeriSlider) return;

      const gallerySlides = Array.from(galeriSlider.querySelectorAll('[data-gallery-slide]'));
      const galleryCounter = galeriSlider.querySelector('[data-gallery-counter]');
      let galleryIndex = 0;

      function showGallerySlide(index) {
        galleryIndex = (index + gallerySlides.length) % gallerySlides.length;
        gallerySlides.forEach((slide, slideIndex) => {
          slide.classList.toggle('hidden', slideIndex !== galleryIndex);
        });
        if (galleryCounter) {
          galleryCounter.textContent = `${galleryIndex + 1}/${gallerySlides.length}`;
        }
      }

      galeriSlider.querySelector('[data-gallery-prev]')?.addEventListener('click', function() {
        showGallerySlide(galleryIndex - 1);
      });
      galeriSlider.querySelector('[data-gallery-next]')?.addEventListener('click', function() {
        showGallerySlide(galleryIndex + 1);
      });
    }

    // Set initial page
    showPage('beranda');
    initGaleriSlider();

    // ---------- AKTIVITAS GEMPA: MAP + CHARTS (lazy init) ----------
    function initAktivitasGempa() {
      // 1. MAP INITIALIZATION & GOOGLE MAPS LAYERS
      const gmapsStreets = L.tileLayer('https://{s}.google.com/vt/lyrs=m&x={x}&y={y}&z={z}', {
        maxZoom: 20, subdomains: ['mt0', 'mt1', 'mt2', 'mt3'], attribution: '&copy; Google Maps'
      });
      const gmapsSatellite = L.tileLayer('https://{s}.google.com/vt/lyrs=s,h&x={x}&y={y}&z={z}', {
        maxZoom: 20, subdomains: ['mt0', 'mt1', 'mt2', 'mt3'], attribution: '&copy; Google Maps'
      });
      const gmapsTerrain = L.tileLayer('https://{s}.google.com/vt/lyrs=p&x={x}&y={y}&z={z}', {
        maxZoom: 20, subdomains: ['mt0', 'mt1', 'mt2', 'mt3'], attribution: '&copy; Google Maps'
      });
      const osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap'
      });

      const map = L.map('map', { zoomControl: false, layers: [gmapsSatellite] }).setView([-7.94, 110.42], 11);
      window.__opakMap = map;
// ===================== GENERATOR GRID KOORDINAT (ALL-IN-ONE) =====================
      // 1. Suntikkan CSS ke halaman secara otomatis
      var styleGrid = document.createElement('style');
      styleGrid.innerHTML = `
        .grid-label-custom {
          background-color: #1F2933 !important;
          color: #ffffff !important;
          padding: 4px 6px !important;
          font-size: 10px !important;
          font-weight: 800 !important;
          border-radius: 6px !important;
          white-space: nowrap !important;
          text-align: center !important;
          border: 1px solid rgba(255, 255, 255, 0.4) !important;
          box-shadow: 0 2px 5px rgba(0,0,0,0.5) !important;
          font-family: 'Inter', sans-serif !important;
        }
        /* Tombol saat aktif jadi putih, teks & ikon jadi gelap */
        .btn-grid-aktif {
          background-color: #ffffff !important;
          color: #1F2933 !important;
        }
      `;
      document.head.appendChild(styleGrid);

      // 2. Suntikkan Tombol ke Atas Peta secara otomatis
      var oldBtn = document.getElementById('toggleGridBtn');
      if (oldBtn) oldBtn.remove(); 

      var btnContainer = document.createElement('div');
      btnContainer.className = 'absolute top-4 left-1/2 -translate-x-1/2 z-[1000]';
      btnContainer.innerHTML = `
        <button id="toggleGridBtn" class="bg-brand-dark/80 hover:bg-brand-dark backdrop-blur-sm text-white text-[10px] font-semibold px-3 py-1.5 rounded-full shadow-sm flex items-center gap-1.5 transition">
          <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="3" y1="15" x2="21" y2="15"></line><line x1="9" y1="3" x2="9" y2="21"></line><line x1="15" y1="3" x2="15" y2="21"></line></svg>
          Grid koordinat
        </button>
      `;
      document.getElementById('map').parentElement.appendChild(btnContainer);

      // 3. Logika Garis Grid & Label Angka
      var gridLayer = L.layerGroup();
      var gridVisible = false;

      function gambarGridKoordinat() {
        gridLayer.clearLayers();
        if (!gridVisible) return;

        var bounds = map.getBounds();
        var zoom = map.getZoom();
        
        var interval = 0.1; 
        if (zoom < 10) interval = 0.5;
        if (zoom < 8) interval = 1.0;
        if (zoom > 12) interval = 0.05;

        var west = Math.floor(bounds.getWest() / interval) * interval;
        var east = Math.ceil(bounds.getEast() / interval) * interval;
        var south = Math.floor(bounds.getSouth() / interval) * interval;
        var north = Math.ceil(bounds.getNorth() / interval) * interval;

        // Margin 4% supaya label tidak mepet tepi layar
        var latMarginBawah = bounds.getSouth() + ((bounds.getNorth() - bounds.getSouth()) * 0.04);
        var lngMarginKiri = bounds.getWest() + ((bounds.getEast() - bounds.getWest()) * 0.04);

        for (var lng = west; lng <= east; lng += interval) {
          L.polyline([[-90, lng], [90, lng]], { color: 'white', weight: 1.5, dashArray: '4, 4', opacity: 0.7, interactive: false }).addTo(gridLayer);
          
          // className dikosongkan agar Leaflet tidak error posisinya
          var ikonX = L.divIcon({ 
            className: '', 
            html: '<div class="grid-label-custom" style="position: absolute; left: -27px; top: -10px; width: 55px;">' + Math.abs(lng).toFixed(2) + '° E</div>', 
            iconSize: [0, 0] 
          });
          L.marker([latMarginBawah, lng], { icon: ikonX, interactive: false }).addTo(gridLayer);
        }

        for (var lat = south; lat <= north; lat += interval) {
          L.polyline([[lat, -180], [lat, 180]], { color: 'white', weight: 1.5, dashArray: '4, 4', opacity: 0.7, interactive: false }).addTo(gridLayer);
          
          // Rotasi diletakkan di div dalam (inner HTML) supaya tidak mengacaukan titik koordinat Leaflet!
          var ikonY = L.divIcon({ 
            className: '', 
            html: '<div class="grid-label-custom" style="position: absolute; left: -27px; top: -10px; width: 55px; transform: rotate(-90deg); transform-origin: center;">' + Math.abs(lat).toFixed(2) + '° S</div>', 
            iconSize: [0, 0] 
          });
          L.marker([lat, lngMarginKiri], { icon: ikonY, interactive: false }).addTo(gridLayer);
        }
      }

      map.on('moveend', gambarGridKoordinat);
      map.on('zoomend', gambarGridKoordinat);

      document.getElementById('toggleGridBtn').addEventListener('click', function(e) {
        e.preventDefault();
        gridVisible = !gridVisible;
        if (gridVisible) {
          map.addLayer(gridLayer);
          this.classList.add('btn-grid-aktif');
          gambarGridKoordinat();
        } else {
          map.removeLayer(gridLayer);
          this.classList.remove('btn-grid-aktif');
        }
      });
      // =====================================================================

      // ===================== STATE LAYER =====================
      // layerGroups.desa      : layer batas desa — TIDAK di-addTo(map) secara default (unchecked),
      //                         dan isinya di-generate ulang tiap kali gempa aktif berubah (lihat
      //                         refreshLayerDesaBerdasarkanGempaAktif()).
      // layerGroups.sesar     : layer batas sesar — dikontrol melalui checkbox tersendiri.
      // layerGroupsGempaById  : SATU L.layerGroup per event gempa (baik "gempa terkini" maupun tiap
      //                         item di panel "Riwayat Gempa"), key = id_gempa.
      // gempaAktifIds         : Set id_gempa yang overlay/marker-nya SEDANG tampil di peta saat ini.
      const layerGroups = {
        desa: L.layerGroup(),
        sesar: L.layerGroup(),
        segmen: L.layerGroup()
      };
      const layerGroupsGempaById = {};
      const gempaAktifIds = new Set();

      document.querySelectorAll('[data-layer]').forEach((checkbox) => {
        checkbox.addEventListener('change', function () {
          if (this.dataset.layer === 'desa') {
            if (this.checked) {
              refreshLayerDesaBerdasarkanGempaAktif();
              map.addLayer(layerGroups.desa);
            } else {
              map.removeLayer(layerGroups.desa);
            }
          } else if (this.dataset.layer === 'sesar') {
            if (this.checked) {
              map.addLayer(layerGroups.sesar);
            } else {
              map.removeLayer(layerGroups.sesar);
            }
          } else if (this.dataset.layer === 'segmen') {
            if (this.checked) {
              map.addLayer(layerGroups.segmen);
            } else {
              map.removeLayer(layerGroups.segmen);
            }
          }
        });
      });

      const baseMaps = {
        "Google Streets": gmapsStreets,
        "Google Satellite (Hybrid)": gmapsSatellite,
        "Google Terrain": gmapsTerrain,
        "OpenStreetMap": osm
      };
      L.control.layers(baseMaps, null, { position: 'topleft' }).addTo(map);

      const zoomInBtn = document.getElementById('zoomInBtn');
      const zoomOutBtn = document.getElementById('zoomOutBtn');
      const locateBtn = document.getElementById('locateBtn');

      zoomInBtn.addEventListener('click', () => map.zoomIn());
      zoomOutBtn.addEventListener('click', () => map.zoomOut());

      let userMarker = null;
      let userCircle = null;

      locateBtn.addEventListener('click', () => {
        if (!navigator.geolocation) {
          alert('Geolocation is not supported by your browser.');
          return;
        }
        locateBtn.classList.add('opacity-70');
        navigator.geolocation.getCurrentPosition(
          (position) => {
            const { latitude, longitude } = position.coords;
            const target = [latitude, longitude];
            map.flyTo(target, 15, { duration: 1.5 });
            if (userMarker) map.removeLayer(userMarker);
            if (userCircle) map.removeLayer(userCircle);
            userCircle = L.circle(target, { radius: 40, color: '#2347C5', fillColor: '#5E7FC8', fillOpacity: 0.2 }).addTo(map);
            userMarker = L.circleMarker(target, { radius: 7, color: '#2347C5', fillColor: '#ffffff', weight: 3 }).addTo(map);
            locateBtn.classList.remove('opacity-70');
          },
          (error) => {
            locateBtn.classList.remove('opacity-70');
            let message = 'Unable to retrieve your location.';
            if (error.code === 1) message = 'Location access was blocked. Please allow location access.';
            if (error.code === 2) message = 'Your location is currently unavailable.';
            if (error.code === 3) message = 'The location request timed out.';
            alert(message);
          },
          { enableHighAccuracy: true, timeout: 10000 }
        );
      });

      function toRadians(deg) { return deg * Math.PI / 180; }

      function haversineKm(lat1, lng1, lat2, lng2) {
        const R = 6371;
        const dLat = toRadians(lat2 - lat1);
        const dLng = toRadians(lng2 - lng1);
        const a = Math.sin(dLat / 2) ** 2 + Math.cos(toRadians(lat1)) * Math.cos(toRadians(lat2)) * Math.sin(dLng / 2) ** 2;
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return R * c;
      }

      function getDirectionFromBearing(lat1, lng1, lat2, lng2) {
        const y = Math.sin(toRadians(lng2 - lng1)) * Math.cos(toRadians(lat2));
        const x = Math.cos(toRadians(lat1)) * Math.sin(toRadians(lat2)) - Math.sin(toRadians(lat1)) * Math.cos(toRadians(lat2)) * Math.cos(toRadians(lng2 - lng1));
        const bearing = (Math.atan2(y, x) * 180 / Math.PI + 360) % 360;
        const directions = ['north', 'northeast', 'east', 'southeast', 'south', 'southwest', 'west', 'northwest'];
        const index = Math.round(bearing / 45) % 8;
        return directions[index];
      }

      function getSpatialLocationText(lat, lng) {
        const regions = [
          { name: 'Aceh Selatan', lat: 3.42, lng: 97.17, type: 'darat', box: { latMin: 2.8, latMax: 4.0, lngMin: 96.5, lngMax: 98.5 } },
          { name: 'Bantul, DIY', lat: -7.88, lng: 110.33, type: 'darat', box: { latMin: -8.2, latMax: -7.6, lngMin: 110.0, lngMax: 110.7 } },
          { name: 'Yogyakarta', lat: -7.80, lng: 110.37, type: 'darat', box: { latMin: -8.0, latMax: -7.6, lngMin: 110.2, lngMax: 110.6 } }
        ];
        const matchedRegion = regions.find(region =>
          lat >= region.box.latMin && lat <= region.box.latMax && lng >= region.box.lngMin && lng <= region.box.lngMax
        );
        const reference = matchedRegion || regions.reduce((best, region) => {
          const distance = haversineKm(lat, lng, region.lat, region.lng);
          return !best || distance < best.distance ? { region, distance } : best;
        }, null).region;
        const distanceKm = Math.max(1, Math.round(haversineKm(lat, lng, reference.lat, reference.lng)));
        const direction = getDirectionFromBearing(lat, lng, reference.lat, reference.lng);
        return `Epicenter is ${distanceKm} km ${direction} of ${reference.name}`;
      }

      // --- DATA MENTAH DARI PHP ---
      // dataDesa TIDAK langsung dirender ke peta di sini — polygon batas desa cuma dibangun sesuai
      // permintaan lewat refreshLayerDesaBerdasarkanGempaAktif(), supaya cuma desa yang beririsan
      // dengan area MMI gempa aktif yang ditampilkan (lihat penjelasan filter spasial di bawah).
      var dataDesa = <?php echo json_encode($batas_desa); ?>;
      var dataSesar = <?php echo json_encode($data_sesar, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

      dataSesar.forEach(function (sesar) {
          var namaSesar = escapeTableText(sesar.nama_sesar || 'Unknown');
          var isiPopup = '<b>Fault name:</b> ' + namaSesar;
          if (sesar.segmen) isiPopup += '<br><b>Segment:</b> ' + escapeTableText(sesar.segmen);
          if (sesar.tipe_sesar) isiPopup += '<br><b>Type:</b> ' + escapeTableText(sesar.tipe_sesar);
          if (sesar.mmax !== null && sesar.mmax !== '') isiPopup += '<br><b>Mmax:</b> ' + escapeTableText(sesar.mmax);
          if (sesar.panjang_km !== null && sesar.panjang_km !== '') isiPopup += '<br><b>Length:</b> ' + escapeTableText(sesar.panjang_km) + ' km';

          var garisSesar = L.geoJSON(sesar.geometry, {
              style: { color: '#b91c1c', weight: 3, opacity: 0.9 }
          });
          garisSesar.bindPopup(isiPopup);
          layerGroups.sesar.addLayer(garisSesar);
      });

      // Menggambar Segmen dari MySQL
      var dataSegmen = <?php echo json_encode($data_segmen, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
      dataSegmen.forEach(function (segmen) {
          if (!segmen.geometry) return;
          var layerSegmen = L.geoJSON(segmen.geometry, {
              style: {
                  color: "#f97316", // Oranye batas
                  weight: 2,
                  fillColor: "#fbd38d", // Oranye isian
                  fillOpacity: 0.15
              }
          });
          layerSegmen.bindPopup("<div class='text-sm font-bold text-orange-600'>Fault Segment:</div><div class='text-xs text-slate-700'>" + escapeTableText(segmen.nama_segmen) + "</div>");
          layerGroups.segmen.addLayer(layerSegmen);
      });
      // Layer "Rumah" dihapus sepenuhnya sesuai permintaan — tidak ada lagi dataRumah/layerGroups.rumah.

      // --- MENGGAMBAR TITIK GEMPA, OVERLAY MMI, & POP-UP ---
      var dataGempa = <?php echo json_encode($titik_gempa); ?>;
      var idGempaRiwayatShakemap = new Set(<?php echo json_encode($id_gempa_riwayat_shakemap); ?>);
      var poligonSegmen = dataSegmen
        .map(function (segmen) { return segmen.geometry; })
        .filter(function (geometri) {
          return geometri && (geometri.type === 'Polygon' || geometri.type === 'MultiPolygon');
        });
      var segmenAcuan = dataSegmen.map(function (segmen) {
        return {
          id: String(segmen.id_segmen),
          nama: segmen.nama_segmen || ('Segmen ' + segmen.id_segmen),
          wilayah: segmen.nama_segmen || ('Segmen ' + segmen.id_segmen)
        };
      });

      var gempaPerSegmen = {};
      segmenAcuan.forEach(function (segmen) {
        gempaPerSegmen[segmen.id] = [];
      });
      var poligonDarat = dataDesa.map(function (desa) {
        return JSON.parse(desa.geojson);
      });

      function titikDiDalamCincin(lng, lat, cincin) {
        var diDalam = false;
        for (var i = 0, j = cincin.length - 1; i < cincin.length; j = i++) {
          var titikA = cincin[i];
          var titikB = cincin[j];
          var silang = (lng - titikA[0]) * (titikB[1] - titikA[1]) -
            (lat - titikA[1]) * (titikB[0] - titikA[0]);
          if (Math.abs(silang) < 1e-10 &&
              lng >= Math.min(titikA[0], titikB[0]) && lng <= Math.max(titikA[0], titikB[0]) &&
              lat >= Math.min(titikA[1], titikB[1]) && lat <= Math.max(titikA[1], titikB[1])) {
            return true;
          }

          var memotong = (titikA[1] > lat) !== (titikB[1] > lat) &&
            lng < (titikB[0] - titikA[0]) * (lat - titikA[1]) /
              (titikB[1] - titikA[1]) + titikA[0];
          if (memotong) diDalam = !diDalam;
        }
        return diDalam;
      }

      function titikDiDalamGeometri(lat, lng, geometri) {
        var daftarPoligon = geometri.type === 'Polygon'
          ? [geometri.coordinates]
          : geometri.type === 'MultiPolygon'
            ? geometri.coordinates
            : [];
        return daftarPoligon.some(function (poligon) {
          if (!poligon.length || !titikDiDalamCincin(lng, lat, poligon[0])) return false;
          return !poligon.slice(1).some(function (lubang) {
            return titikDiDalamCincin(lng, lat, lubang);
          });
        });
      }

      function gempaDiDalamBatasSegmen(gempa) {
        var lat = parseFloat(gempa.lat);
        var lng = parseFloat(gempa.lng);
        return Number.isFinite(lat) && Number.isFinite(lng) &&
          lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180 &&
          poligonSegmen.some(function (geometri) {
            return titikDiDalamGeometri(lat, lng, geometri);
          });
      }

      function cariSegmenGempa(gempa) {
        var lat = parseFloat(gempa.lat);
        var lng = parseFloat(gempa.lng);
        if (!Number.isFinite(lat) || !Number.isFinite(lng) ||
            lat < -90 || lat > 90 || lng < -180 || lng > 180) return null;

        return dataSegmen.find(function (segmen) {
          return segmen.geometry &&
            titikDiDalamGeometri(lat, lng, segmen.geometry);
        }) || null;
      }

      var dataGempaTerakhir30 = dataGempa.slice(0, 30);
      var dataGempaTerakhir30DalamBatasSegmen = dataGempaTerakhir30.filter(gempaDiDalamBatasSegmen);
      function kunciGempa(gempa) {
        return String(gempa.id_gempa || [
          gempa.tanggal,
          gempa.waktu_ot,
          gempa.lat,
          gempa.lng,
          gempa.magnitudo
        ].join('|'));
      }
      var gempaIdsDiketahui = new Set(dataGempa.map(kunciGempa));
      var gempaAktivitasPerSegmen = {};
      segmenAcuan.forEach(function (segmen) {
        gempaAktivitasPerSegmen[segmen.id] = [];
      });
      function kelompokkanGempaTerakhir30PerSegmen(daftarGempa) {
        var perSegmen = {};
        segmenAcuan.forEach(function (segmen) {
          perSegmen[segmen.id] = [];
        });
        var gempaTerurut = daftarGempa.slice().sort(function (a, b) {
          var waktuA = String(a.tanggal || '') + ' ' + String(a.waktu_ot || '');
          var waktuB = String(b.tanggal || '') + ' ' + String(b.waktu_ot || '');
          return waktuB.localeCompare(waktuA) ||
            Number(b.id_gempa || 0) - Number(a.id_gempa || 0);
        }).slice(0, 30);

        gempaTerurut.forEach(function (gempa) {
          var segmen = cariSegmenGempa(gempa);
          if (segmen && perSegmen[String(segmen.id_segmen)]) {
            perSegmen[String(segmen.id_segmen)].push(gempa);
          }
        });
        return perSegmen;
      }
      gempaAktivitasPerSegmen = kelompokkanGempaTerakhir30PerSegmen(dataGempaTerakhir30DalamBatasSegmen);
      var dataGempaDalamBatasSegmen = dataGempa.filter(gempaDiDalamBatasSegmen);
      gempaIdsDalamBatasSegmen = new Set(dataGempaDalamBatasSegmen.map(function (gempa) {
        return String(gempa.id_gempa);
      }));

      function titikDiDarat(lat, lng) {
        return poligonDarat.some(function (geometri) {
          return titikDiDalamGeometri(lat, lng, geometri);
        });
      }

      function kelompokkanGempaPerSegmen(daftarGempa) {
        gempaPerSegmen = {};
        segmenAcuan.forEach(function (segmen) {
          gempaPerSegmen[segmen.id] = [];
        });
        daftarGempa.forEach(function (gempa) {
          var segmen = cariSegmenGempa(gempa);
          if (!segmen) {
            gempa.segmenId = null;
            return;
          }
          gempa.segmenId = String(segmen.id_segmen);
          gempaPerSegmen[gempa.segmenId].push(gempa);
        });
      }
      kelompokkanGempaPerSegmen(dataGempaDalamBatasSegmen);

      var gempa30HariLayer = L.layerGroup();
      var toggleGempa30Hari = document.getElementById('toggleGempa30Hari');
      function perbaruiLayerGempa30Hari() {
        gempa30HariLayer.clearLayers();
        var jumlahTitikGempa30Hari = 0;
        dataGempaTerakhir30.forEach(function (gempa) {
          var tanggalGempa = String(gempa.tanggal || '').split(' ')[0];
          var segmen = cariSegmenGempa(gempa);
          if (!segmen) return;

          var magnitudo = parseFloat(gempa.magnitudo) || 0;
          var kedalaman = parseFloat(gempa.kedalaman);
          var marker = createEarthquakeCircleMarker(
            parseFloat(gempa.lat),
            parseFloat(gempa.lng),
            magnitudo,
            kedalaman
          );
          marker.bindPopup(
            '<div class="text-xs"><strong>' + escapeTableText(segmen.nama_segmen) +
            '</strong><br>M ' + magnitudo.toFixed(1) +
            '<br>' + escapeTableText(gempa.waktu || tanggalGempa) + '</div>'
          );
          marker.addTo(gempa30HariLayer);
          jumlahTitikGempa30Hari++;
        });
        var statusGempa30Hari = document.getElementById('statusGempa30Hari');
        if (statusGempa30Hari) {
          statusGempa30Hari.classList.toggle('hidden', jumlahTitikGempa30Hari > 0);
        }
      }

      if (toggleGempa30Hari) {
        toggleGempa30Hari.checked = false;
        toggleGempa30Hari.addEventListener('change', function () {
          if (this.checked) {
            perbaruiLayerGempa30Hari();
            map.addLayer(gempa30HariLayer);
          } else {
            map.removeLayer(gempa30HariLayer);
          }
        });
      }

      function getStatusAktivitas(jumlahGempa) {
        if (jumlahGempa <= 2) {
          return { label: 'NORMAL', className: 'bg-emerald-100 text-emerald-700', jumlahGempa: jumlahGempa };
        }
        if (jumlahGempa <= 4) {
          return { label: 'WATCH', className: 'bg-yellow-100 text-yellow-800', jumlahGempa: jumlahGempa };
        }
        if (jumlahGempa <= 6) {
          return { label: 'ALERT', className: 'bg-orange-100 text-orange-800', jumlahGempa: jumlahGempa };
        }
        return { label: 'DANGER', className: 'bg-red-100 text-red-700', jumlahGempa: jumlahGempa };
      }

      function pilihSuaraAlarmLakiLaki() {
        if (!('speechSynthesis' in window)) return null;
        var voices = window.speechSynthesis.getVoices();
        var english = voices.filter(function (suara) {
          return /^en(-|_)/i.test(suara.lang);
        });
        var kandidat = english.length ? english : voices;
        return kandidat.find(function (suara) {
          var nama = (suara.name || '').toLowerCase();
          return /male|pria|laki|guy|david|mark|daniel|andrew/.test(nama);
        }) || kandidat[0] || voices[0] || null;
      }

      function bacakanNotifikasiAlarm(teksAlarm) {
        if (!('speechSynthesis' in window)) return;
        var ucapan = new SpeechSynthesisUtterance(teksAlarm);
        var suaraLakiLaki = pilihSuaraAlarmLakiLaki();
        ucapan.lang = 'en-US';
        ucapan.rate = 0.88;
        ucapan.pitch = 0.85;
        ucapan.volume = 1;
        if (suaraLakiLaki) ucapan.voice = suaraLakiLaki;
        window.speechSynthesis.speak(ucapan);
      }

      function tampilkanNotifikasiAlarm(aktivitasPerSegmen, gempaIdsPemicu) {
        var segmenBerstatusAlarm = aktivitasPerSegmen.map(function (item) {
          var status = getStatusAktivitas(item.jumlahGempaHariIni);
          var gempaHariIni = (gempaAktivitasPerSegmen[item.segmen.id] || []).filter(function (gempa) {
            return String(gempa.tanggal || '').split(' ')[0] === (
              String(item.tanggalTerbaru || '').split(' ')[0]
            );
          });
          return {
            nama: item.segmen.nama,
            jumlah: item.jumlahGempaHariIni,
            status: status.label,
            adaGempaPemicu: gempaHariIni.some(function (gempa) {
              return gempaIdsPemicu.has(kunciGempa(gempa));
            })
          };
        }).filter(function (item) {
          return item.status !== 'NORMAL' && item.adaGempaPemicu;
        });

        var modal = document.getElementById('seismicAlarmModal');
        if (!segmenBerstatusAlarm.length) return;
        var teksDaftar = segmenBerstatusAlarm.map(function (item) {
          return item.status + ' on ' + item.nama + ': ' + item.jumlah + ' earthquakes';
        }).join('. ') + '.';
        var teksAlarm = 'Seismic activity warning. ' + teksDaftar;
        var judul = document.getElementById('seismicAlarmTitle');
        var isi = document.getElementById('seismicAlarmMessage');
        if (judul) judul.textContent = segmenBerstatusAlarm.some(function (item) { return item.status === 'DANGER'; })
          ? 'SEISMIC ACTIVITY DANGER WARNING'
          : 'SEISMIC ACTIVITY WARNING';
        if (isi) isi.textContent = teksDaftar;
        if (modal) modal.classList.remove('hidden');
        bacakanNotifikasiAlarm(teksAlarm);
      }

      function perbaruiRingkasanSegmen(daftarGempaAktivitas, gempaIdsPemicu) {
        gempaAktivitasPerSegmen = kelompokkanGempaTerakhir30PerSegmen(daftarGempaAktivitas || []);
        var tanggalTerbaru = (daftarGempaAktivitas || []).reduce(function (tanggalMaks, gempa) {
          var tanggal = String(gempa.tanggal || '').split(' ')[0];
          return /^\d{4}-\d{2}-\d{2}$/.test(tanggal) && tanggal > tanggalMaks ? tanggal : tanggalMaks;
        }, '');
        var bagianTanggalTerbaru = tanggalTerbaru ? tanggalTerbaru.split('-').map(Number) : null;
        var tahunBulanTerbaru = bagianTanggalTerbaru
          ? bagianTanggalTerbaru[0] + '-' + String(bagianTanggalTerbaru[1]).padStart(2, '0')
          : '';
        var teksBulanTerbaru = bagianTanggalTerbaru
          ? new Intl.DateTimeFormat('en-US', { month: 'long', year: 'numeric' }).format(
              new Date(bagianTanggalTerbaru[0], bagianTanggalTerbaru[1] - 1, 1)
            )
          : '-';
        var aktivitasPerSegmen = [];

        segmenAcuan.forEach(function (segmen) {
          var daftarGempa = gempaAktivitasPerSegmen[segmen.id] || [];
          var daftarGempaBulanIni = daftarGempa.filter(function (gempa) {
            return String(gempa.tanggal || '').slice(0, 7) === tahunBulanTerbaru;
          });
          var daftarGempaHariIni = daftarGempa.filter(function (gempa) {
            return String(gempa.tanggal || '').split(' ')[0] === tanggalTerbaru;
          });
          aktivitasPerSegmen.push({
            segmen: segmen,
            jumlahGempa: daftarGempaBulanIni.length,
            jumlahGempaHariIni: daftarGempaHariIni.length,
            tanggalTerbaru: tanggalTerbaru
          });

          var magnitudoTerbesar = daftarGempaBulanIni.reduce(function (terbesar, gempa) {
            return Math.max(terbesar, parseFloat(gempa.magnitudo) || 0);
          }, 0);
          
          var labelBulanSegmen = document.querySelector('[data-segmen-month="' + segmen.id + '"]');
          var totalSegmen = document.querySelector('[data-segmen-total="' + segmen.id + '"]');
          var maksimumSegmen = document.querySelector('[data-segmen-max="' + segmen.id + '"]');

          if (labelBulanSegmen) labelBulanSegmen.textContent = teksBulanTerbaru;
          if (totalSegmen) totalSegmen.textContent = daftarGempaBulanIni.length;
          if (maksimumSegmen) maksimumSegmen.textContent = daftarGempaBulanIni.length ? magnitudoTerbesar.toFixed(1) : '-';
        });

        var ringkasanStatusSeismic = document.getElementById('seismicStatusSummary');
        if (ringkasanStatusSeismic) {
          ringkasanStatusSeismic.replaceChildren();
          var aktivitasTertinggi = aktivitasPerSegmen.reduce(function (tertinggi, item) {
            return !tertinggi || item.jumlahGempaHariIni > tertinggi.jumlahGempaHariIni ? item : tertinggi;
          }, null);
          if (aktivitasTertinggi) {
            var statusTertinggi = getStatusAktivitas(aktivitasTertinggi.jumlahGempaHariIni);
            var statusElement = document.createElement('div');
            statusElement.className = 'w-full flex justify-center items-center py-6';
            var labelElement = document.createElement('span');
            labelElement.className = 'inline-flex rounded-full px-8 py-4 text-3xl sm:text-4xl font-bold ' + statusTertinggi.className;
            labelElement.textContent = statusTertinggi.label === 'NORMAL'
              ? statusTertinggi.label
              : statusTertinggi.label + ' - ' + aktivitasTertinggi.segmen.nama.toUpperCase();
            statusElement.appendChild(labelElement);
            ringkasanStatusSeismic.appendChild(statusElement);
          }
        }

        tampilkanNotifikasiAlarm(aktivitasPerSegmen, gempaIdsPemicu || new Set());
      }
      var gempaIdsSaatHalamanDibuka = new Set(dataGempaTerakhir30.map(kunciGempa));
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
          perbaruiRingkasanSegmen(dataGempaTerakhir30, gempaIdsSaatHalamanDibuka);
        }, { once: true });
      } else {
        perbaruiRingkasanSegmen(dataGempaTerakhir30, gempaIdsSaatHalamanDibuka);
      }

      var gempaUntukModal = null;
      var markerGempaById = {};

      // Parsing aman untuk gempa.shakemap_bounds: DB menyimpannya sebagai teks JSON, jadi teks
      // ini bisa saja kosong, rusak (truncated), atau strukturnya tidak sesuai harapan.
      // Fungsi ini SELALU mengembalikan array bounds yang valid untuk L.imageOverlay, atau null
      // kalau tidak bisa dipakai — supaya pemanggilnya bisa fallback tanpa membuat JS crash.
      function parseImageBounds(rawBoundsText) {
          if (!rawBoundsText || typeof rawBoundsText !== 'string') return null;
          var parsed;
          try {
              parsed = JSON.parse(rawBoundsText);
          } catch (err) {
              console.warn('shakemap_bounds bukan JSON yang valid:', err);
              return null;
          }
          var bounds = parsed && parsed.bounds;
          var pasanganValid = function (pair) {
              return Array.isArray(pair) && pair.length === 2 &&
                  typeof pair[0] === 'number' && isFinite(pair[0]) &&
                  typeof pair[1] === 'number' && isFinite(pair[1]);
          };
          if (!Array.isArray(bounds) || bounds.length !== 2 || !pasanganValid(bounds[0]) || !pasanganValid(bounds[1])) {
              console.warn('Struktur shakemap_bounds tidak sesuai format [[south,west],[north,east]]:', parsed);
              return null;
          }
          return bounds;
      }

      // Menentukan URL gambar yang dipakai tombol "Lihat Detail". Sejak skema 3-file
      // (file_xml/file_grd/file_shakemap), `shakemap` SELALU berarti gambar layout
      // detail (Input 3) untuk modal.
      function getGambarDetailUrl(gempa) {
          if (gempa.shakemap && /\.(jpg|jpeg|png)$/i.test(gempa.shakemap)) return gempa.shakemap;
          return null;
      }

      // Menentukan URL gambar overlay transparan RESMI untuk L.imageOverlay() di peta.
      // Selalu dari `shakemap_vektor` (hasil convert_grd.py dari file .grd resmi).
      function getGambarOverlayUrl(gempa) {
          if (gempa.shakemap_vektor && /\.(jpg|jpeg|png)$/i.test(gempa.shakemap_vektor)) return gempa.shakemap_vektor;
          return null;
      }

      // Overlay KOMUNITAS: hasil interpolasi IDW dari titik laporan warga (`shakemap_komunitas`,
      // diisi otomatis oleh buatShakemapKomunitas() di PHP tiap ada laporan baru). Terpisah dari
      // overlay resmi di atas -- keduanya bisa tampil bersamaan kalau datanya sama-sama ada.
      function getGambarOverlayKomunitasUrl(gempa) {
          if (gempa.shakemap_komunitas && /\.(jpg|jpeg|png)$/i.test(gempa.shakemap_komunitas)) return gempa.shakemap_komunitas;
          return null;
      }

      // Modal "Lihat Detail" — dibuka lewat delegated event listener supaya tidak perlu
      // memasang listener satu-per-satu ke tiap tombol di dalam popup Leaflet (yang isinya
      // string HTML statis dan bisa dibuat ulang kapan saja).
      function bukaModalDetailGambar(url, judul) {
          var modal = document.getElementById('modalDetailGambar');
          var img = document.getElementById('modalDetailGambarImg');
          var teksJudul = document.getElementById('modalDetailGambarJudul');
          if (!modal || !img) return;
          img.src = url;
          if (teksJudul) teksJudul.textContent = judul || 'ShakeMap Details';
          modal.classList.remove('hidden');
      }

      function tutupModalDetailGambar() {
          var modal = document.getElementById('modalDetailGambar');
          if (modal) modal.classList.add('hidden');
      }

      document.addEventListener('click', function (event) {
          var tombolDetail = event.target.closest('.btn-lihat-detail');
          if (tombolDetail) {
              bukaModalDetailGambar(tombolDetail.getAttribute('data-img-url'), tombolDetail.getAttribute('data-img-judul'));
              return;
          }
          // Tombol "Lapor Merasakan" di dalam pop-up titik gempa (punya data-id-gempa) --
          // ID-nya disetel ke field hidden #laporIdGempa, jadi saat form dikirim, laporan
          // otomatis nempel ke gempa yang sedang diklik, bukan ke gempa terkini.
          var tombolLapor = event.target.closest('.btn-lapor-gempa');
          if (tombolLapor) {
              var idGempaDipilih = tombolLapor.getAttribute('data-id-gempa') || '';
              var inputIdGempa = document.getElementById('laporIdGempa');
              if (inputIdGempa) inputIdGempa.value = idGempaDipilih;
              var infoEl = document.getElementById('laporGempaInfo');
              if (infoEl) {
                  infoEl.textContent = idGempaDipilih
                      ? ('Reporting for earthquake #' + idGempaDipilih)
                      : 'General report (not linked to a specific earthquake)';
              }
              var modalLapor = document.getElementById('modalLaporGempa');
              if (modalLapor) modalLapor.classList.remove('hidden');
              return;
          }
          // Tutup modal kalau klik di area backdrop (data-modal-dismiss) atau tombol close-nya.
          // Digeneralisasi supaya berlaku untuk modal manapun (bukan cuma Detail Shakemap) --
          // cukup cari wadah modal terdekat (id diawali "modal") lalu sembunyikan itu saja.
          if (event.target.closest('[data-modal-dismiss]')) {
              var modalTerbuka = event.target.closest('[id^="modal"]');
              if (modalTerbuka) modalTerbuka.classList.add('hidden');
          }
      });

      // --- MODAL "LAPOR MERASAKAN GEMPA" (Felt Report) ---
      // Tombol umum di panel (bukan dari pop-up peta) -> laporan umum, id_gempa dikosongkan.
      var laporGempaBtn = document.getElementById('laporGempaBtn');
      if (laporGempaBtn) {
          laporGempaBtn.addEventListener('click', function () {
              var inputIdGempa = document.getElementById('laporIdGempa');
              if (inputIdGempa) inputIdGempa.value = '';
              var infoEl = document.getElementById('laporGempaInfo');
              if (infoEl) infoEl.textContent = 'General report (not linked to a specific earthquake)';
              var modal = document.getElementById('modalLaporGempa');
              if (modal) modal.classList.remove('hidden');
          });
      }

      var formLaporGempa = document.getElementById('formLaporGempa');
      if (formLaporGempa) {
          formLaporGempa.addEventListener('submit', function (event) {
              event.preventDefault(); // Cegah reload halaman penuh -- kirim via fetch() saja

              var tombolKirim = document.getElementById('kirimLaporGempaBtn');
              var teksTombolSemula = tombolKirim.textContent;
              tombolKirim.disabled = true;
              tombolKirim.textContent = 'Sending...';

              var formData = new FormData(formLaporGempa);
              formData.append('action', 'save_laporan');

              fetch(window.location.pathname, {
                  method: 'POST',
                  body: formData
              })
                  .then(function (response) { return response.json(); })
                  .then(function (data) {
                      if (!data.success) {
                          throw new Error(data.message || 'Failed to submit report.');
                      }
                      alert('Your report has been submitted.');

                      var modal = document.getElementById('modalLaporGempa');
                      if (modal) modal.classList.add('hidden');
                      formLaporGempa.reset();

                      // Reload halaman supaya pop-up di peta menampilkan laporan warga terbaru
                      // (query $sql_gempa akan menarik ulang GROUP_CONCAT laporan_warga dari database).
                      window.location.reload();
                  })
                  .catch(function (error) {
                      alert('Failed to submit report: ' + error.message);
                      tombolKirim.disabled = false;
                      tombolKirim.textContent = teksTombolSemula;
                  });
          });
      }

      // Hitung gempa terbesar (M >= 5.0) di SELURUH data (bukan cuma yang sedang aktif di peta) —
      // perilaku ini sama seperti sebelumnya, cuma dipisah dari proses pembuatan layer.
      dataGempa.forEach(function (gempa) {
          var mag = parseFloat(gempa.magnitudo);
          if (mag >= 5.0 && (!gempaUntukModal || mag > parseFloat(gempaUntukModal.magnitudo))) {
              gempaUntukModal = gempa;
          }
      });

      /**
       * Membangun SATU L.layerGroup untuk satu event gempa: overlay MMI (atau cincin fallback)
       * + marker titik pusat + popup. Dipakai baik untuk "Gempa Terkini" maupun tiap item di
       * panel "Riwayat Gempa" — supaya tiap event benar-benar independen dan bisa di-toggle sendiri.
       */
      function buatLayerGempa(gempa) {
          var grup = L.layerGroup();

          var lat = parseFloat(gempa.lat);
          var lng = parseFloat(gempa.lng);
          var mag = parseFloat(gempa.magnitudo);
          var radiusBuffer = gempa.radius_buffer || (mag * 1000);
          var kedalaman = gempa.kedalaman ? gempa.kedalaman : '-';

          var locationDescription = getSpatialLocationText(lat, lng);
          var coordinateText = `${Math.abs(lat).toFixed(2)} ${lat >= 0 ? 'N' : 'S'} - ${Math.abs(lng).toFixed(2)} ${lng >= 0 ? 'E' : 'W'}`;
          var magnitudoText = mag.toFixed(1);
          var tipeMagnitudo = String(gempa.tipe_mag || '').trim();
          var tipeMagnitudoHTML = tipeMagnitudo.toUpperCase() === 'SS'
              ? ''
              : `<div style="font-size:12px; color:#334155; margin-bottom:10px;"><strong>Type:</strong> ${tipeMagnitudo || '-'}</div>`;

          // URL gambar untuk tombol "Lihat Detail" (null kalau tidak ada gambar shakemap sama sekali)
          var gambarDetailUrl = getGambarDetailUrl(gempa);
          var judulModalEscaped = (locationDescription + ' — ' + gempa.waktu).replace(/"/g, '&quot;');
          var tombolLihatDetailHTML = gambarDetailUrl
              ? `<button type="button" class="btn-lihat-detail" data-img-url="${gambarDetailUrl}" data-img-judul="${judulModalEscaped}" style="font-size:11px; font-weight:600; color:#fff; background:#2347C5; border:none; padding:6px 12px; border-radius:10px; cursor:pointer; white-space:nowrap;">View Details</button>`
              : '';

          // Tombol "Lapor Merasakan" -- data-id-gempa dibaca oleh event delegation di bawah,
          // supaya laporan yang dikirim otomatis nempel ke ID gempa titik ini (bukan gempa lain).
          var tombolLaporHTML = `<button type="button" class="btn-lapor-gempa" data-id-gempa="${gempa.id_gempa}" style="font-size:11px; font-weight:600; color:#fff; background:#2347C5; border:none; padding:6px 12px; border-radius:10px; cursor:pointer; white-space:nowrap;">Submit Felt Report</button>`;

          // Rangkuman laporan warga (hasil GROUP_CONCAT dari tb_laporan_masyarakat, lewat PHP)
          var laporanWarga = gempa.laporan_warga ? gempa.laporan_warga : null;
          var merasakanHTML = laporanWarga
              ? `<div style="font-size:12px; color:#334155;"><strong>Felt:</strong> Yes</div>
                 <div style="font-size:11.5px; color:#475569; margin-top:2px;"><strong>Community reports:</strong> ${laporanWarga}</div>`
              : `<div style="font-size:12px; color:#334155;"><strong>Felt:</strong> No reports yet</div>`;

          // Desain Pop-up Keren
          var popupContent = `
            <div style="font-family:Arial, sans-serif; padding:6px 8px; min-width:280px; max-width:320px; line-height:1.5;">
              <div style="display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-top:1px solid #e2e8f0; border-bottom:1px solid #e2e8f0; margin:8px 0;">
                <div>
                  <div style="font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:0.04em;">Magnitude</div>
                  <div style="font-size:18px; font-weight:700; color:#2347C5;">${magnitudoText}</div>
                </div>
                <div>
                  <div style="font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:0.04em;">Depth</div>
                  <div style="font-size:18px; font-weight:700; color:#2347C5;">${kedalaman} km</div>
                </div>
              </div>
              
              <div style="font-size:13px; color:#334155; margin-bottom:10px;"><strong>Location:</strong> ${coordinateText}</div>
              ${tipeMagnitudoHTML}

              <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:10px;">
                <div>${merasakanHTML}</div>
                ${tombolLihatDetailHTML}
              </div>

              <div style="display:flex; justify-content:flex-end; margin-bottom:10px;">
                ${tombolLaporHTML}
              </div>

              ${buatDesaTerdampakHTML(gempa)}
            </div>
          `;

          // --- 1. RENDER LAPISAN BAWAH: Gambar PNG Overlay (MUTLAK hasil render grid Python) ATAU Efek Cincin (Riak Gelombang) ---
          var shakemapBerhasilDirender = false;
          var imageBounds = parseImageBounds(gempa.shakemap_bounds);
          var urlOverlay = getGambarOverlayUrl(gempa);
          if (urlOverlay && imageBounds) {
              try {
                  var shakemapOverlay = L.imageOverlay(urlOverlay, imageBounds, {
                      opacity: 0.82,
                      interactive: true,
                      className: 'shakemap-halus'
                  });
                  shakemapOverlay.bindPopup(popupContent, { maxWidth: 320, autoClose: true, closeOnClick: true });
                  grup.addLayer(shakemapOverlay);
                  shakemapBerhasilDirender = true;
              } catch (err) {
                  console.error("Gagal memuat raster:", err);
              }
          }

          // --- RENDER OVERLAY KOMUNITAS (hasil IDW dari laporan warga) ---
          // Independen dari overlay resmi di atas -- kalau dua-duanya ada datanya,
          // dua-duanya digambar sekaligus (komunitas di atas, garis putus-putus).
          var imageBoundsKomunitas = parseImageBounds(gempa.shakemap_komunitas_bounds);
          var urlOverlayKomunitas = getGambarOverlayKomunitasUrl(gempa);
          if (urlOverlayKomunitas && imageBoundsKomunitas) {
              try {
                  var shakemapKomunitasOverlay = L.imageOverlay(urlOverlayKomunitas, imageBoundsKomunitas, {
                      opacity: 0.78,
                      interactive: true,
                      className: 'shakemap-komunitas-halus'
                  });
                  shakemapKomunitasOverlay.bindPopup(popupContent, { maxWidth: 320, autoClose: true, closeOnClick: true });
                  grup.addLayer(shakemapKomunitasOverlay);
              } catch (err) {
                  console.error("Gagal memuat overlay komunitas:", err);
              }
          }

          if (!shakemapBerhasilDirender) {
              // Jika tidak ada file grid, buat efek gelombang 3 lapis!
              var tingkatCincin = [0.2, 0.6, 1.0];
              tingkatCincin.forEach(function (persen) {
                  grup.addLayer(L.circle([lat, lng], {
                      radius: radiusBuffer * persen,
                      color: '#dc2626',
                      weight: 2,
                      fillOpacity: 0, // Bolong di tengah
                      interactive: false
                  }));
              });
          }

          // --- 2. RENDER TITIK PUSAT: magnitudo tampil di dalam titik untuk layer peta guncangan.
          var quakeMarker = createShakemapMarker(lat, lng, mag, parseFloat(gempa.kedalaman));

          quakeMarker.bindPopup(popupContent, { maxWidth: 320, autoClose: true, closeOnClick: true });
          grup.addLayer(quakeMarker);
          markerGempaById[gempa.id_gempa] = quakeMarker;

          // Simpan bounding box "area terdampak" gempa ini untuk dipakai filter spasial Batas Desa.
          // Kalau ada overlay MMI (raster), pakai bounds rectangle-nya persis. Kalau tidak ada
          // (fallback cincin), aproksimasi pakai bounding box dari radiusBuffer di sekitar episenter.
          if (imageBounds) {
              grup._boundsGempa = L.latLngBounds(imageBounds);
          } else {
              var derajatLat = radiusBuffer / 111320; // ~111.32 km per derajat lintang
              var derajatLng = radiusBuffer / (111320 * Math.cos(lat * Math.PI / 180));
              grup._boundsGempa = L.latLngBounds(
                  [lat - derajatLat, lng - derajatLng],
                  [lat + derajatLat, lng + derajatLng]
              );
          }

          return grup;
      }

      /**
       * FILTER SPASIAL "BATAS DESA": hanya render polygon desa yang bounding box-nya beririsan
       * dengan bounding box area terdampak (MMI/buffer) dari gempa-gempa yang SEDANG aktif di peta.
       *
       * Pendekatan: pakai L.LatLngBounds.intersects() bawaan Leaflet — TIDAK perlu Turf.js.
       * Alasannya: overlay MMI itu sendiri berbentuk rectangle (imageBounds dari .grd), jadi soal
       * "polygon desa vs area MMI" secara efektif adalah "polygon desa vs rectangle", dan cara
       * termurah untuk itu adalah membandingkan bounding box masing-masing — cukup akurat untuk
       * polygon administratif desa yang bentuknya relatif cembung (convex-ish), jauh lebih cepat
       * dihitung ulang tiap kali checkbox di-toggle, dan tidak menambah dependency baru.
       *
       * Turf.js (booleanIntersects) baru benar-benar dibutuhkan kalau kamu butuh presisi geometris
       * penuh (misal desa berbentuk sangat tidak beraturan/cekung di tepi area MMI). Kalau nanti mau
       * upgrade ke itu, cara paling efisien adalah pola dua tahap: filter cepat pakai bounding box
       * dulu (seperti di sini) untuk membuang mayoritas desa yang jelas-jelas jauh, baru jalankan
       * turf.booleanIntersects() hanya pada sisa kandidat yang lolos tahap pertama.
       */
      function refreshLayerDesaBerdasarkanGempaAktif() {
          layerGroups.desa.clearLayers();
          if (gempaAktifIds.size === 0) return; // Tidak ada gempa aktif -> tidak ada desa yang relevan

          var daftarBoundsAktif = [];
          gempaAktifIds.forEach(function (id) {
              var grup = layerGroupsGempaById[id];
              if (grup && grup._boundsGempa) daftarBoundsAktif.push(grup._boundsGempa);
          });

          dataDesa.forEach(function (desa) {
              var geojsonObj = JSON.parse(desa.geojson);
              var desaLayer = L.geoJSON(geojsonObj, {
                  style: function () {
                      return { color: "#000000", weight: 0.75, fillColor: "#000000", fillOpacity: 0 };
                  }
              });
              var bboxDesa = desaLayer.getBounds();
              if (!bboxDesa.isValid()) return;

              var beririsan = daftarBoundsAktif.some(function (b) {
                  return b.isValid() && b.intersects(bboxDesa);
              });
              if (beririsan) {
                  desaLayer.bindPopup("<b>Village: </b> " + desa.nama_desa + "<br><b>Population density: </b> " + desa.kepadatan_penduduk + " people/km2");
                  layerGroups.desa.addLayer(desaLayer);
              }
          });
      }

      // --- RIWAYAT GEMPA: dikelompokkan per segmen, dapat difilter berdasarkan magnitudo ---
      var riwayatContainer = document.getElementById('riwayatGempaList');
      var filterMagnitudoSelect = document.getElementById('filterMagnitudoRiwayat');

      // Buat layer group untuk setiap gempa terlebih dahulu
      dataGempaDalamBatasSegmen.forEach(function (gempa) {
          if (!layerGroupsGempaById[gempa.id_gempa]) {
              var grup = buatLayerGempa(gempa);
              layerGroupsGempaById[gempa.id_gempa] = grup;
          }
      });
      var toggleGempaTerkini = document.getElementById('toggleGempaTerkini');
      var markerGempaTerkini = null;
      var ringPulseGempaTerkini = null;
      if (toggleGempaTerkini && dataGempaDalamBatasSegmen.length > 0) {
          // Data mengikuti urutan tanggal dan waktu terbaru dari query database.
          var gempaTerbaru = dataGempaDalamBatasSegmen[0];
          var markerGempaTerakhir = markerGempaById[gempaTerbaru.id_gempa];
          if (markerGempaTerakhir) {
              var posisiGempaTerakhir = markerGempaTerakhir.getLatLng();
              markerGempaTerkini = createEarthquakeCircleMarker(
                  posisiGempaTerakhir.lat,
                  posisiGempaTerakhir.lng,
                  parseFloat(gempaTerbaru.magnitudo),
                  parseFloat(gempaTerbaru.kedalaman)
              );
              ringPulseGempaTerkini = L.circleMarker(
                  [posisiGempaTerakhir.lat, posisiGempaTerakhir.lng],
                  {
                      radius: getMagnitudeMarkerRadius(parseFloat(gempaTerbaru.magnitudo)) + 12,
                      color: '#ef4444',
                      weight: 2.5,
                      opacity: 0.9,
                      fill: false,
                      className: 'gempa-terkini-pulse'
                  }
              );
              var popupGempaTerakhir = markerGempaTerakhir.getPopup();
              if (popupGempaTerakhir) {
                  var isiPopupGempaTerakhir = String(popupGempaTerakhir.getContent() || '');
                  var tanggalGempaTerbaru = String(gempaTerbaru.tanggal || '').split(' ')[0];
                  var waktuGempaTerbaru = String(gempaTerbaru.waktu || '').split(' ')[1] || '';
                  if (!waktuGempaTerbaru && String(gempaTerbaru.tanggal || '').indexOf(' ') >= 0) {
                      waktuGempaTerbaru = String(gempaTerbaru.tanggal || '').split(' ')[1] || '';
                  }

                  var bagianTanggal = /^\d{4}-\d{2}-\d{2}$/.test(tanggalGempaTerbaru)
                      ? tanggalGempaTerbaru.split('-').map(Number)
                      : null;
                  var namaBulan = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
                  var tanggalTampil = bagianTanggal && namaBulan[bagianTanggal[1] - 1]
                      ? namaBulan[bagianTanggal[1] - 1] + ' ' + bagianTanggal[2] + ', ' + bagianTanggal[0]
                      : tanggalGempaTerbaru;
                  var waktuTampil = /^\d{1,2}:\d{2}(:\d{2})?$/.test(waktuGempaTerbaru) ? waktuGempaTerbaru : '';
                  var informasiWaktu = (tanggalTampil || '-') + (waktuTampil ? ' · ' + waktuTampil + ' WIB' : '');
                  var barisTanggalWaktu = '<div style="text-align:center; color:#2347C5; font-size:12px; font-weight:700; margin-bottom:6px;">' +
                      escapeTableText(informasiWaktu) + '</div>';
                  var penandaBarisMagnitudo = '<div style="display:flex; justify-content:space-between; align-items:center;';
                  isiPopupGempaTerakhir = isiPopupGempaTerakhir.includes(penandaBarisMagnitudo)
                      ? isiPopupGempaTerakhir.replace(penandaBarisMagnitudo, barisTanggalWaktu + penandaBarisMagnitudo)
                      : isiPopupGempaTerakhir;

                  markerGempaTerkini.bindPopup(isiPopupGempaTerakhir, {
                      maxWidth: 320,
                      autoClose: true,
                      closeOnClick: true
                  });
              }

              toggleGempaTerkini.checked = true;
              map.addLayer(ringPulseGempaTerkini);
              map.addLayer(markerGempaTerkini);
              markerGempaTerkini.openPopup();
          } else {
              toggleGempaTerkini.checked = false;
              toggleGempaTerkini.disabled = true;
          }

          toggleGempaTerkini.addEventListener('change', function () {
              if (!markerGempaTerkini) return;
              if (this.checked) {
                  map.addLayer(ringPulseGempaTerkini);
                  map.addLayer(markerGempaTerkini);
                  markerGempaTerkini.openPopup();
              } else {
                  map.removeLayer(markerGempaTerkini);
                  map.removeLayer(ringPulseGempaTerkini);
              }
          });
      } else if (toggleGempaTerkini) {
          toggleGempaTerkini.disabled = true;
      }

      function sinkronkanChecklistRiwayat() {
          if (!riwayatContainer) return;
          riwayatContainer.querySelectorAll('[data-riwayat-id]').forEach(function (checkbox) {
              var id = String(checkbox.dataset.riwayatId);
              checkbox.checked = gempaAktifIds.has(id);
          });
      }

      function renderRiwayatGempa(magnitudoFilter) {
          if (!riwayatContainer) return;
          riwayatContainer.innerHTML = '';

          segmenAcuan.forEach(function (segmen) {
              var gempaSegmen = dataGempaDalamBatasSegmen.filter(function (gempa) {
                  if (String(gempa.segmenId) !== segmen.id) return false;
                  if (!idGempaRiwayatShakemap.has(String(gempa.id_gempa))) return false;
                  if (gempaAktifIds.has(String(gempa.id_gempa))) return true;
                  var magnitudoGempa = parseFloat(gempa.magnitudo) || 0;
                  if (magnitudoGempa < 2.5) return false;
                  return magnitudoFilter === 'semua' ||
                      Math.abs(magnitudoGempa - parseFloat(magnitudoFilter)) < 0.001;
              });
              if (!gempaSegmen.length) return;

              var wrapper = document.createElement('div');
              wrapper.className = 'border border-slate-200 rounded-xl overflow-hidden';

              var tombolHeader = document.createElement('button');
              tombolHeader.type = 'button';
              tombolHeader.setAttribute('data-accordion-toggle', '');
              tombolHeader.className = 'w-full flex items-center justify-between gap-2 px-3 py-2 bg-slate-50 hover:bg-slate-100 text-left';
              tombolHeader.innerHTML = `
                <span class="text-xs font-semibold text-slate-700">${segmen.nama} <span class="text-slate-400 font-normal">(${gempaSegmen.length})</span></span>
                <i data-lucide="chevron-right" class="w-4 h-4 text-slate-500 transition-transform duration-150"></i>
              `;

              var panel = document.createElement('div');
              panel.setAttribute('data-accordion-panel', '');
              panel.className = 'hidden px-2.5 py-2 space-y-1.5 border-t border-slate-100';

              gempaSegmen.sort(function(a, b) {
                  var magA = parseFloat(a.magnitudo) || 0;
                  var magB = parseFloat(b.magnitudo) || 0;
                  return magB - magA || String(b.waktu || '').localeCompare(String(a.waktu || ''));
              }).forEach(function (gempa) {
                  var item = document.createElement('label');
                  item.className = 'flex cursor-pointer items-center gap-2 text-xs text-slate-700 hover:bg-blue-50 px-1 py-1 rounded';
                  var checkbox = document.createElement('input');
                  checkbox.type = 'checkbox';
                  checkbox.setAttribute('data-riwayat-id', gempa.id_gempa);
                  checkbox.className = 'h-4 w-4 flex-shrink-0 rounded border-slate-300 text-red-600 focus:ring-red-500';

                  var detail = document.createElement('span');
                  detail.innerHTML = '<strong>M' + (parseFloat(gempa.magnitudo) || 0).toFixed(1) + '</strong> &middot; ' + (gempa.tanggal || '');

                  item.appendChild(checkbox);
                  item.appendChild(detail);
                  panel.appendChild(item);
              });

              wrapper.appendChild(tombolHeader);
              wrapper.appendChild(panel);
              riwayatContainer.appendChild(wrapper);
          });

          if (window.lucide) { lucide.createIcons(); }
          sinkronkanChecklistRiwayat();
      }

      if (riwayatContainer) {
          // Render awal menampilkan tiga segmen dalam keadaan tertutup.
          renderRiwayatGempa('semua');

          if (filterMagnitudoSelect) {
              filterMagnitudoSelect.addEventListener('change', function(e) {
                  renderRiwayatGempa(e.target.value);
              });
          }

          // Event buka/tutup akordeon segmen
          riwayatContainer.addEventListener('click', function (event) {
              var tombolToggle = event.target.closest('[data-accordion-toggle]');
              if (!tombolToggle) return;

              var panel = tombolToggle.nextElementSibling;
              if (!panel) return;
              var akanDibuka = panel.classList.contains('hidden');
              riwayatContainer.querySelectorAll('[data-accordion-toggle]').forEach(function (tombolLain) {
                  if (tombolLain === tombolToggle) return;
                  var panelLain = tombolLain.nextElementSibling;
                  if (panelLain) panelLain.classList.add('hidden');
                  var ikonLain = tombolLain.querySelector('[data-lucide="chevron-right"]');
                  if (ikonLain) ikonLain.classList.remove('rotate-90');
              });
              panel.classList.toggle('hidden', !akanDibuka);

              var ikonPanah = tombolToggle.querySelector('[data-lucide="chevron-right"]');
              if (ikonPanah) ikonPanah.classList.toggle('rotate-90', akanDibuka);
          });

          // Menangani checklist untuk memunculkan gempa
          riwayatContainer.addEventListener('change', function (event) {
              var checkbox = event.target.closest('[data-riwayat-id]');
              if (!checkbox) return;
              var id = checkbox.dataset.riwayatId;
              var grup = layerGroupsGempaById[id];
              if (!grup) return;

              if (checkbox.checked) {
                  gempaAktifIds.forEach(function (idAktif) {
                      if (idAktif === String(id)) return;
                      var grupAktif = layerGroupsGempaById[idAktif];
                      if (grupAktif) map.removeLayer(grupAktif);
                      gempaAktifIds.delete(idAktif);
                  });
                  map.addLayer(grup);
                  gempaAktifIds.add(String(id));
              } else {
                  map.removeLayer(grup);
                  gempaAktifIds.delete(String(id));
              }
              sinkronkanChecklistRiwayat();

              var checkboxDesa = document.querySelector('[data-layer="desa"]');
              if (checkboxDesa && checkboxDesa.checked) {
                  refreshLayerDesaBerdasarkanGempaAktif();
              }
          });
      }

      function renderDataTable() {
        const from = document.getElementById('dateFromFilter').value;
        const to = document.getElementById('dateToFilter').value;
        const grouped = {};
        const filteredDataList = []; // Array data untuk dilempar ke peta

        dataGempa.filter(function(gempa) {
          const tanggalGempa = String(gempa.tanggal || '').split(' ')[0];
          const isSkenarioShakemap = tanggalGempa === '2014-11-26' &&
            idGempaRiwayatShakemap.has(String(gempa.id_gempa));
          return !isSkenarioShakemap &&
            (!from || tanggalGempa >= from) && (!to || tanggalGempa <= to);
        }).forEach(function(gempa) {
          if (!grouped[gempa.tanggal]) grouped[gempa.tanggal] = [];
          grouped[gempa.tanggal].push(gempa);
          filteredDataList.push(gempa); // Kumpulkan data yang lolos filter
        });

        const tableBody = document.getElementById('dataTableBody');
        tableBody.innerHTML = Object.keys(grouped).sort().reverse().map(function(tanggal) {
          const dateHeader = `<tr><td colspan="7" class="px-3 py-2 bg-blue-50 text-brand-blue font-bold">${escapeTableText(tanggal)}</td></tr>`;
          const rows = grouped[tanggal].map(function(gempa) {
            const time = String(gempa.waktu).split(' ')[1] || '-';
            const magnitude = parseFloat(gempa.magnitudo);
            const magnitudeText = Number.isFinite(magnitude) ? magnitude.toFixed(1) : '-';
            const depth = Number.isFinite(parseFloat(gempa.kedalaman)) ? parseFloat(gempa.kedalaman) : '-';
            const latitude = parseFloat(gempa.lat);
            const longitude = parseFloat(gempa.lng);
            const locationText = Number.isFinite(latitude) && Number.isFinite(longitude)
              ? latitude.toFixed(4) + ', ' + longitude.toFixed(4)
              : '-';
            const reportedMmi = String(gempa.laporan_warga || '').match(/\(([IVX]+\+?)\s*MMI\)/gi) || [];
            const mmiText = Array.from(new Set(reportedMmi.map(function(value) {
              return value.replace(/[()]/g, '').replace(/\s*MMI/i, '').trim().toUpperCase();
            }))).join(', ') || 'No reports';
            
            return `<tr class="cursor-pointer hover:bg-blue-50" data-gempa-id="${escapeTableText(gempa.id_gempa)}" title="Click to view this location on the map">
              <td class="px-3 py-2">${escapeTableText(time)}</td>
              <td class="px-3 py-2">${escapeTableText(depth)}</td>
              <td class="px-3 py-2 font-semibold">M${escapeTableText(magnitudeText)}</td>
              <td class="px-3 py-2">${escapeTableText(gempa.tipe_mag || '-')}</td>
              <td class="px-3 py-2 whitespace-nowrap">${escapeTableText(locationText)}</td>
              <td class="px-3 py-2">${escapeTableText(mmiText)}</td>
              <td class="px-3 py-2"><button type="button" data-edit-gempa-id="${escapeTableText(gempa.id_gempa)}" class="rounded-lg bg-amber-100 px-2 py-1 text-[10px] font-semibold text-amber-800 hover:bg-amber-200">Edit</button></td>
            </tr>`;
          }).join('');
          return dateHeader + rows;
        }).join('') || '<tr><td colspan="7" class="px-3 py-6 text-center text-slate-400">No data for this period.</td></tr>';

        // Panggil fungsi peta agar menyesuaikan letak sebaran data sesuai dengan filter yang aktif
        if (typeof updateRepoMapMarkers === 'function') {
            updateRepoMapMarkers(filteredDataList);
        }
      }

      renderDataTable();
      document.getElementById('applyDateFilterBtn').addEventListener('click', renderDataTable);
      window.renderDataTableGlobal = renderDataTable;
      document.getElementById('resetDateFilterBtn').addEventListener('click', function() {
        document.getElementById('dateFromFilter').value = '';
        document.getElementById('dateToFilter').value = '';
        renderDataTable();
      });
      document.getElementById('dataTableBody').addEventListener('click', function(event) {
        const editButton = event.target.closest('[data-edit-gempa-id]');
        if (editButton) {
          event.stopPropagation();
          const gempa = dataGempa.find(function(item) {
            return String(item.id_gempa) === editButton.dataset.editGempaId;
          });
          if (!gempa) {
            alert('The earthquake record to edit could not be found.');
            return;
          }

          const waktuParts = String(gempa.waktu || '').split(' ');
          const jamParts = (waktuParts[1] || '').split(':');
          const catatanGempa = String(gempa.keterangan || '');
          const lokasiMatch = catatanGempa.match(/^(?:Pusat gempa berada di|Epicenter located at) (.*?)\./);
          const statusMatch = catatanGempa.match(/(?:^|\n)Status:\s*([^\r\n]+)/);
          const keteranganTambahan = catatanGempa
            .replace(/^(?:Pusat gempa berada di|Epicenter located at) .*?\.\r?\n/, '')
            .replace(/^Status:\s*[^\r\n]*\r?\n?/, '')
            .trim();
          const dataEntryForm = document.getElementById('dataEntryForm');
          dataEntryForm.elements.namedItem('id_gempa').value = gempa.id_gempa;
          dataEntryForm.elements.namedItem('tanggal').value = gempa.tanggal || '';
          dataEntryForm.elements.namedItem('jam').value = jamParts[0] || '00';
          dataEntryForm.elements.namedItem('menit').value = jamParts[1] || '00';
          dataEntryForm.elements.namedItem('detik').value = jamParts[2] || '00';
          dataEntryForm.elements.namedItem('lat').value = gempa.lat || '';
          dataEntryForm.elements.namedItem('lng').value = gempa.lng || '';
          dataEntryForm.elements.namedItem('kedalaman').value = gempa.kedalaman || '0';
          dataEntryForm.elements.namedItem('magnitudo').value = gempa.magnitudo ?? '';
          dataEntryForm.elements.namedItem('tipe_mag').value = gempa.tipe_mag || 'ML';
          dataEntryForm.elements.namedItem('status').value = statusMatch ? statusMatch[1].trim() : 'Normal';
          dataEntryForm.elements.namedItem('dirasakan').value = gempa.dirasakan || 'Tidak';
          dataEntryForm.elements.namedItem('lokasi').value = lokasiMatch ? lokasiMatch[1].trim() : 'Titik Manual';
          dataEntryForm.elements.namedItem('keterangan').value = keteranganTambahan;
          document.getElementById('lokasiGempa').value = '';
          dataEntryForm.querySelector('h3').textContent = 'Edit Earthquake Data #' + gempa.id_gempa;
          dataEntryForm.querySelector('button[type="submit"]').textContent = 'Save Changes';
          document.getElementById('cancelEditGempaBtn').classList.remove('hidden');
          dataEntryForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
          return;
        }

        const row = event.target.closest('[data-gempa-id]');
        if (!row) return;
        const id = row.dataset.gempaId;
        const marker = repoMarkerById.get(id);
        if (!marker || !repoMap) return;
        repoMap.flyTo(marker.getLatLng(), Math.max(repoMap.getZoom(), 12));
        marker.openPopup();
      });

      document.getElementById('cancelEditGempaBtn').addEventListener('click', function() {
        const dataEntryForm = document.getElementById('dataEntryForm');
        dataEntryForm.reset();
        dataEntryForm.elements.namedItem('id_gempa').value = '';
        dataEntryForm.querySelector('h3').textContent = 'Manual Entry / Edit Data';
        dataEntryForm.querySelector('button[type="submit"]').textContent = 'Save Earthquake Data';
        this.classList.add('hidden');
      });

      const lokasiGempa = document.getElementById('lokasiGempa');
      if (lokasiGempa) {
        lokasiGempa.addEventListener('change', function() {
          const selected = this.options[this.selectedIndex];
          if (selected.dataset.lat) document.querySelector('[name="lat"]').value = selected.dataset.lat;
          if (selected.dataset.lng) document.querySelector('[name="lng"]').value = selected.dataset.lng;
          document.getElementById('lokasiTersembunyi').value = selected.value || 'Titik Manual';
        });
      }

      // Simpan ke MySQL, lalu muat ulang agar marker baru ikut hasil query server.
      const dataEntryForm = document.getElementById('dataEntryForm');
      async function readJsonResponse(response) {
        const body = await response.text();
        try {
          return JSON.parse(body);
        } catch (error) {
          const serverMessage = body.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
          throw new Error(serverMessage || `The server returned an invalid response (HTTP ${response.status}).`);
        }
      }
      dataEntryForm.addEventListener('submit', async function(event) {
        event.preventDefault();
        const submitButton = dataEntryForm.querySelector('button[type="submit"]');
        const sedangEdit = dataEntryForm.elements.namedItem('id_gempa').value !== '';
        submitButton.disabled = true;
        submitButton.classList.add('opacity-70');

        const formData = new FormData(dataEntryForm);
        formData.append('action', 'save_gempa');
        try {
          const response = await fetch('index.php', { method: 'POST', body: formData });
          const result = await readJsonResponse(response);
          if (!response.ok || !result.success) throw new Error(result.message || (sedangEdit ? 'Failed to update earthquake data.' : 'Failed to save data.'));
          alert(sedangEdit ? 'Earthquake data updated successfully.' : 'Earthquake data saved successfully.');
          window.location.reload();
        } catch (error) {
          alert(error.message);
          submitButton.disabled = false;
          submitButton.classList.remove('opacity-70');
        }
      });

      const galeriForm = document.getElementById('galeriForm');
      if (galeriForm) {
        galeriForm.addEventListener('submit', async function(event) {
          event.preventDefault();
          const submitButton = galeriForm.querySelector('button[type="submit"]');
          submitButton.disabled = true;
          submitButton.classList.add('opacity-70');
          const formData = new FormData(galeriForm);
          formData.append('action', 'upload_galeri');

          try {
            const response = await fetch('index.php', { method: 'POST', body: formData });
            const result = await readJsonResponse(response);
            if (!response.ok || !result.success) throw new Error(result.message || 'Failed to upload photo.');
            alert('Photo uploaded successfully.');
            window.location.reload();
          } catch (error) {
            alert(error.message);
            submitButton.disabled = false;
            submitButton.classList.remove('opacity-70');
          }
        });
      }

      // EVENT LISTENER UPLOAD CEPAT (XML + GRD + Peta Intensity Modal)
      const quickImportBtn = document.getElementById('quickImportBtn');
      if (quickImportBtn) {
        quickImportBtn.addEventListener('click', async function() {
          const xmlInput = document.getElementById('quickXml');
          const grdInput = document.getElementById('quickGrd');
          
          // PERBAIKAN: Ganti quickOverlay menjadi quickShakemap
          const shakemapInput = document.getElementById('quickShakemap');

          if (!xmlInput.files.length) { 
            alert('An XML file is required to extract earthquake parameters.');
            return; 
          }
          
          const formData = new FormData();
          formData.append('action', 'quick_import');
          formData.append('file_xml', xmlInput.files[0]);
          
          if (grdInput && grdInput.files.length > 0) {
              formData.append('file_grd', grdInput.files[0]);
          }
          
          // PERBAIKAN: Append sebagai 'file_shakemap' agar terdeteksi di PHP sebagai popup detail.
          if (shakemapInput && shakemapInput.files.length > 0) {
              formData.append('file_shakemap', shakemapInput.files[0]);
          }

          this.disabled = true;
          this.textContent = 'Processing...';
          
          try {
            const response = await fetch('index.php', { method: 'POST', body: formData });
            const result = await readJsonResponse(response);
            if (!response.ok || !result.success) throw new Error(result.message || 'Failed to process data.');
            alert(result.message || 'Earthquake data imported and published successfully.');
            window.location.reload();
          } catch (error) {
            alert(error.message);
            this.disabled = false;
            this.textContent = 'Import Files';
          }
        });
      }

      // --- EVENT LISTENER TOMBOL DOWNLOAD DATA ---
      document.getElementById('downloadTxtBtn').addEventListener('click', function() {
        window.location.href = 'index.php?action=download_txt';
      });

      document.getElementById('downloadMxlBtn').addEventListener('click', function() {
        window.location.href = 'index.php?action=download_mxl';
      });

      // --- EVENT LISTENER TOMBOL MERASAKAN GEMPA ---
      document.addEventListener('click', function (event) {
          const feltBtn = event.target.closest('.felt-report-btn');
          if (!feltBtn) return;
          
          event.preventDefault();
          event.stopPropagation();
          
          if (navigator.geolocation) {
              feltBtn.disabled = true;
              feltBtn.textContent = 'Saving...';
              
              navigator.geolocation.getCurrentPosition(
                  function(position) {
                      alert('Your report has been saved.');
                      feltBtn.textContent = 'Saved';
                  },
                  function() {
                      alert('Your location is unavailable. The report was saved at the earthquake location.');
                      feltBtn.textContent = 'Saved';
                  },
                  { enableHighAccuracy: true, timeout: 10000 }
              );
          } else {
              alert('Your browser does not support geolocation.');
          }
      });

      // 2. MINI CHARTS (SEGMEN 1-4) — Mengikuti bulan data gempa terbaru
      const miniOpt = {
        responsive: true, 
        maintainAspectRatio: false,
        plugins: { 
          legend: { display: false },
          tooltip: {
            callbacks: {
              title: function(context) {
                return 'Date: ' + context[0].label;
              },
              label: function(context) {
                return ' Earthquakes: ' + context.parsed.y;
              }
            }
          }
        },
        scales: { 
          x: { 
            type: 'category', // Memaksa sumbu X membaca label sebagai teks berurutan (tidak lompat-lompat)
            display: true,
            grid: { display: false },
            ticks: { 
              font: { size: 8 }, 
              autoSkip: true,
              maxTicksLimit: 7,
              maxRotation: 0
            }
          },
          y: { 
            display: true, 
            beginAtZero: true,
            min: 0,
            ticks: { 
              font: { size: 9 }
            } 
          }
        }
      };

      let tahunTarget;
      let bulanTarget;
      let labelBulanUtuh = [];
      let mapTanggalKeMag = {};

      function perbaruiPeriodeChart(daftarGempa) {
        let tanggalTerbaru = '';
        daftarGempa.forEach(function (gempa) {
          const tanggal = String(gempa.tanggal || '').split(' ')[0];
          if (/^\d{4}-\d{2}-\d{2}$/.test(tanggal) && tanggal > tanggalTerbaru) {
            tanggalTerbaru = tanggal;
          }
        });
        const acuan = tanggalTerbaru ? tanggalTerbaru.split('-').map(Number) : [
          new Date().getFullYear(),
          new Date().getMonth() + 1,
          new Date().getDate()
        ];
        tahunTarget = acuan[0];
        bulanTarget = acuan[1] - 1;
        const jumlahHariBulan = new Date(tahunTarget, bulanTarget + 1, 0).getDate();
        labelBulanUtuh = [];
        mapTanggalKeMag = {};

        for (let hari = 1; hari <= jumlahHariBulan; hari++) {
          const tanggal = tahunTarget + '-' + String(bulanTarget + 1).padStart(2, '0') + '-' + String(hari).padStart(2, '0');
          labelBulanUtuh.push(String(hari));
          mapTanggalKeMag[tanggal] = 0;
        }
      }
      perbaruiPeriodeChart(dataGempaTerakhir30DalamBatasSegmen);

      function ambilDataGempaHarianSegmen(segmenId) {
        const jumlahGempaPerTanggal = {...mapTanggalKeMag};
        (gempaAktivitasPerSegmen[segmenId] || []).forEach(function (gempa) {
          const tglGempa = String(gempa.tanggal || '').split(' ')[0];
          if (Object.prototype.hasOwnProperty.call(jumlahGempaPerTanggal, tglGempa)) {
            jumlahGempaPerTanggal[tglGempa] = (jumlahGempaPerTanggal[tglGempa] || 0) + 1;
          }
        });
        return {
          labels: [...labelBulanUtuh],
          jumlah: Object.values(jumlahGempaPerTanggal)
        };
      }

      function skalaSumbuJumlahGempa(data) {
        const jumlahMaksimal = Math.max(0, ...data);
        const stepSize = Math.max(1, Math.ceil(jumlahMaksimal / 5));
        return {
          max: Math.max(stepSize, Math.ceil(jumlahMaksimal / stepSize) * stepSize),
          ticks: {
            stepSize: stepSize,
            callback: function(value) {
              return String(value);
            }
          }
        };
      }

      function perbaruiGrafikKeseluruhan(daftarGempa) {
        const jumlahGempaPerTanggal = {...mapTanggalKeMag};
        const distribusiMagnitudo = [0, 0, 0, 0];
        let totalGempaBulanIni = 0;

        daftarGempa.forEach(function (gempa) {
          const tanggal = String(gempa.tanggal || '').split(' ')[0];
          if (!Object.prototype.hasOwnProperty.call(jumlahGempaPerTanggal, tanggal)) return;
          jumlahGempaPerTanggal[tanggal]++;
          totalGempaBulanIni++;

          const magnitudo = parseFloat(gempa.magnitudo) || 0;
          if (magnitudo >= 5) distribusiMagnitudo[0]++;
          else if (magnitudo >= 4) distribusiMagnitudo[1]++;
          else if (magnitudo >= 3) distribusiMagnitudo[2]++;
          else distribusiMagnitudo[3]++;
        });

        const teksPeriode = new Intl.DateTimeFormat('en-US', {
          month: 'long',
          year: 'numeric'
        }).format(new Date(tahunTarget, bulanTarget, 1));
        document.getElementById('chartPeriodTitle').textContent = teksPeriode;
        document.getElementById('chartPeriodRange').textContent = teksPeriode;
        document.getElementById('magnitudePeriodTitle').textContent = teksPeriode;
        document.getElementById('chartGempaTotal').textContent = totalGempaBulanIni;

        chartGempaKeseluruhan.data.labels = labelBulanUtuh;
        chartGempaKeseluruhan.data.datasets[0].data = Object.values(jumlahGempaPerTanggal);
        chartGempaKeseluruhan.update('none');
        chartDistribusiMagnitudo.data.datasets[0].data = distribusiMagnitudo;
        chartDistribusiMagnitudo.update('none');
      }

      const chartSegmenById = {};
      segmenAcuan.forEach(function (segmen) {
        const dataGempaHarian = ambilDataGempaHarianSegmen(segmen.id);
        chartSegmenById[segmen.id] = new Chart(document.getElementById('chartSeg' + segmen.id), {
          type: 'line',
          data: {
            labels: dataGempaHarian.labels,
            datasets: [{ 
              data: dataGempaHarian.jumlah, 
              borderColor: '#10b981', 
              backgroundColor: 'rgba(16, 185, 129, 0.15)',
              fill: true,
              tension: 0.2, 
              pointRadius: 2,
              borderWidth: 2
            }]
          },
          options: {
            ...miniOpt,
            scales: {
              ...miniOpt.scales,
              y: {
                ...miniOpt.scales.y,
                ...skalaSumbuJumlahGempa(dataGempaHarian.jumlah)
              }
            }
          }
        });
      });

      let refreshAktivitasSegmenSedang = false;
      async function refreshAktivitasSegmen() {
        if (refreshAktivitasSegmenSedang) return;
        refreshAktivitasSegmenSedang = true;
        try {
          const response = await fetch('index.php?action=segment_activity_data', {
            headers: { Accept: 'application/json' },
            cache: 'no-store'
          });
          const result = await response.json();
          if (!response.ok || !result.success || !Array.isArray(result.data) || !Array.isArray(result.latest_30)) {
            throw new Error(result.message || 'The segment activity data is invalid.');
          }

          const gempaIdsPemicu = new Set();
          result.data.concat(result.latest_30).forEach(function (gempa) {
            const idGempa = kunciGempa(gempa);
            if (!gempaIdsDiketahui.has(idGempa)) gempaIdsPemicu.add(idGempa);
            gempaIdsDiketahui.add(idGempa);
          });

          const gempaTerakhir30DalamBatasSegmen = result.latest_30.filter(gempaDiDalamBatasSegmen);
          const gempaDalamBatasSegmen = result.data.filter(gempaDiDalamBatasSegmen);
          kelompokkanGempaPerSegmen(gempaDalamBatasSegmen);
          perbaruiPeriodeChart(gempaTerakhir30DalamBatasSegmen);
          perbaruiRingkasanSegmen(gempaTerakhir30DalamBatasSegmen, gempaIdsPemicu);
          perbaruiGrafikKeseluruhan(gempaTerakhir30DalamBatasSegmen);

          segmenAcuan.forEach(function (segmen) {
            const chart = chartSegmenById[segmen.id];
            const dataGempaHarian = ambilDataGempaHarianSegmen(segmen.id);
            chart.data.labels = dataGempaHarian.labels;
            chart.data.datasets[0].data = dataGempaHarian.jumlah;
            Object.assign(chart.options.scales.y, skalaSumbuJumlahGempa(dataGempaHarian.jumlah));
            chart.update('none');
          });
        } finally {
          refreshAktivitasSegmenSedang = false;
        }
      }

      // 3. MAIN LINE CHART
      const chartGempaKeseluruhan = new Chart(document.getElementById('mainChart'), {
        type: 'line',
        data: {
          labels: <?php echo json_encode($chartLabels); ?>,
          datasets: [{
            label: 'Earthquakes per Day',
            data: <?php echo json_encode($chartDailyCounts); ?>,
            borderColor: '#2347C5',
            backgroundColor: 'rgba(37, 99, 235, 0.1)',
            fill: true, tension: 0.3, pointRadius: 3
          }]
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: { y: { beginAtZero: true, grid: { color: '#f1f5f9' } }, x: { grid: { display: false } } }
        }
      });

      // 4. DONUT CHART
      const chartDistribusiMagnitudo = new Chart(document.getElementById('donutChart'), {
        type: 'doughnut',
        data: {
          labels: ['M \u2265 5.0', '4.0 - 4.9', '3.0 - 3.9', 'M < 3.0'],
          datasets: [{ data: <?php echo json_encode($chartMagnitudeCounts); ?>, backgroundColor: ['#dc2626', '#f97316', '#fbbf24', '#10b981'] }]
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: { legend: { position: 'right', labels: { boxWidth: 10, font: { size: 10 } } } }
        }
      });
      perbaruiGrafikKeseluruhan(dataGempaTerakhir30DalamBatasSegmen);
      refreshAktivitasSegmen().catch(function (error) {
        console.error('Gagal memperbarui grafik aktivitas segmen:', error);
      });
      window.setInterval(function () {
        if (!document.hidden) {
          refreshAktivitasSegmen().catch(function (error) {
            console.error('Gagal memperbarui grafik aktivitas segmen:', error);
          });
        }
      }, 30000);
    }

// Popup peringatan dini gempa individual dinonaktifkan sementara. Alarm aktivitas segmen tetap aktif.
function triggerEarthquakeWarning(data) {
  return false;
}

function tutupPeringatanSeismic() {
  var modalAlarm = document.getElementById('seismicAlarmModal');
  if (modalAlarm) modalAlarm.classList.add('hidden');
}

// Delegasi digunakan agar tombol tetap bisa dipasang meskipun script dijalankan sebelum modal selesai dibuat di DOM.
document.addEventListener('click', function (event) {
  if (event.target.closest('#tutupSeismicAlarm')) {
    tutupPeringatanSeismic();
  }
});

document.addEventListener('keydown', function (event) {
  if (event.key === 'Escape') {
    var modalAlarm = document.getElementById('seismicAlarmModal');
    if (modalAlarm && !modalAlarm.classList.contains('hidden')) tutupPeringatanSeismic();
  }
});

  </script>
  <!-- ============================================================ -->
  <!-- MODAL: LIHAT DETAIL GAMBAR SHAKEMAP (Tailwind, meniru gaya modal Bootstrap) -->
  <!-- ============================================================ -->
  <div id="modalDetailGambar" class="hidden fixed inset-0 z-[2000] flex items-center justify-center p-4">
    <!-- Backdrop -->
    <div class="absolute inset-0 bg-black/60" data-modal-dismiss></div>

    <!-- Konten modal -->
    <div class="relative bg-white rounded-2xl shadow-lg w-full max-w-2xl overflow-hidden">
      <div class="flex items-center justify-between px-5 py-3 border-b border-slate-200">
        <h3 id="modalDetailGambarJudul" class="font-bold text-sm text-slate-800">Detail Shakemap</h3>
        <button type="button" data-modal-dismiss class="w-8 h-8 flex items-center justify-center rounded-full text-slate-500 hover:bg-slate-100" title="Close">&times;</button>
      </div>
      <div class="p-4 max-h-[75vh] overflow-auto flex items-center justify-center bg-slate-50">
        <img id="modalDetailGambarImg" src="" alt="Detail shakemap" class="max-w-full max-h-[65vh] rounded-lg shadow-sm">
      </div>
    </div>
  </div>

  <!-- ============================================================ -->
  <!-- MODAL: LAPOR MERASAKAN GEMPA (Felt Report) -->
  <!-- ============================================================ -->
  <div id="modalLaporGempa" class="hidden fixed inset-0 z-[2000] flex items-center justify-center p-4">
    <!-- Backdrop -->
    <div class="absolute inset-0 bg-black/60" data-modal-dismiss></div>

    <!-- Konten modal -->
    <div class="relative bg-white rounded-2xl shadow-lg w-full max-w-md overflow-hidden">
      <div class="flex items-center justify-between px-5 py-3 border-b border-slate-200">
        <h3 class="font-bold text-sm text-slate-800">Felt Earthquake Report</h3>
        <button type="button" data-modal-dismiss class="w-8 h-8 flex items-center justify-center rounded-full text-slate-500 hover:bg-slate-100" title="Close">&times;</button>
      </div>

      <form id="formLaporGempa">
        <div class="p-5 space-y-5 max-h-[75vh] overflow-auto">
          <!-- id_gempa disetel otomatis lewat JS saat tombol "Lapor Merasakan" di pop-up
               peta diklik (lihat data-id-gempa pada tombol). Kosong = laporan umum. -->
          <input type="hidden" id="laporIdGempa" name="id_gempa" value="">
          <div id="laporGempaInfo" class="text-xs font-medium text-blue-700 bg-blue-50 border border-blue-100 rounded-lg px-3 py-2">
            General report (not linked to a specific earthquake)
          </div>

          <!-- Lokasi (Desa) -- searchable: input + datalist, di-render otomatis dari $batas_desa (PHP) -->
          <div>
            <label for="laporLokasiDesa" class="block text-xs font-semibold text-slate-700 mb-1.5">Your location (which village?)</label>
            <input type="text" id="laporLokasiDesa" name="nama_desa" list="daftar-desa" required autocomplete="off"
                   placeholder="Type to search for a village..."
                   class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-blue-200">
            <datalist id="daftar-desa">
              <?php foreach ($batas_desa as $desa): ?>
                <option value="<?php echo htmlspecialchars($desa['nama_desa']); ?>"></option>
              <?php endforeach; ?>
            </datalist>
          </div>

          <!-- Skala MMI -->
          <div>
            <label for="laporSkalaMmi" class="block text-xs font-semibold text-slate-700 mb-1.5">How strong was the shaking you felt?</label>
            <select id="laporSkalaMmi" name="skala_mmi" required class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-blue-200">
              <option value="" disabled selected>Select a shaking intensity...</option>
              <option value="II">II &mdash; Felt by a few people; hanging objects sway</option>
              <option value="III">III &mdash; Noticeable indoors, similar to a passing truck</option>
              <option value="IV">IV &mdash; Felt by many people indoors; doors rattle</option>
              <option value="V">V &mdash; Felt by nearly everyone; objects may fall</option>
              <option value="VI+">VI+ &mdash; Strong shaking; light to moderate damage</option>
            </select>
          </div>
        </div>

        <div class="flex items-center justify-end gap-2 px-5 py-3 border-t border-slate-200 bg-slate-50">
          <button type="button" data-modal-dismiss class="bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-semibold px-4 py-2 rounded-xl">Cancel</button>
          <button type="submit" id="kirimLaporGempaBtn" class="bg-brand-blue hover:bg-brand-blue-dark text-white text-xs font-semibold px-4 py-2 rounded-xl">Submit Report</button>
        </div>
      </form>
    </div>
  </div>

  <div id="seismicAlarmModal" class="hidden fixed inset-0 z-[3000] flex items-center justify-center p-4" role="alertdialog" aria-modal="true" aria-labelledby="seismicAlarmTitle">
    <div class="absolute inset-0 bg-slate-950/70"></div>
    <div class="relative w-full max-w-xl overflow-hidden rounded-2xl bg-white shadow-2xl border border-red-200">
      <div class="bg-red-600 px-8 py-6 text-white">
        <h3 id="seismicAlarmTitle" class="font-bold text-2xl">SEISMIC ACTIVITY WARNING</h3>
      </div>
      <div class="p-8">
        <div class="flex items-start gap-4">
          <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-red-100 text-3xl" aria-hidden="true">⚠</div>
          <p id="seismicAlarmMessage" class="text-base leading-7 text-slate-700"></p>
        </div>
        <button type="button" id="tutupSeismicAlarm" class="mt-6 w-full rounded-lg bg-red-600 px-5 py-3.5 text-base font-bold text-white hover:bg-red-700">
          Close Warning
        </button>
      </div>
    </div>
  </div>

</body>
</html>