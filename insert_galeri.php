<?php
$koneksi = mysqli_connect("localhost", "root", "", "db_sesar_opak");

if (mysqli_connect_errno()){
    echo "Database connection failed: " . mysqli_connect_error();
    exit();
}

// 1. Buat tabelnya dulu kalau belum ada
$buat_tabel = "CREATE TABLE IF NOT EXISTS tb_galeri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_file VARCHAR(255) NOT NULL,
    deskripsi TEXT,
    tanggal_upload TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
mysqli_query($koneksi, $buat_tabel);

// 2. Kosongkan data lama agar tidak ganda
mysqli_query($koneksi, "TRUNCATE TABLE tb_galeri");

// 3. Masukkan datanya (Silakan ubah nama fotonya sesuai file milikmu)
$query1 = "INSERT INTO tb_galeri (nama_file, deskripsi) VALUES ('foto1.jpg', 'Opak Fault movement map in Yogyakarta based on GNSS observation data published in a journal by Dr. Nurrohmat Widjajanti (geodesigeodinamik.ft.ugm.ac.id)')";
$query2 = "INSERT INTO tb_galeri (nama_file, deskripsi) VALUES ('foto2.jpg', 'Opak Fault field survey (Source: https://geoparkjogja.jogjaprov.go.id/)')";
$query3 = "INSERT INTO tb_galeri (nama_file, deskripsi) VALUES ('foto3.png', 'Opak Fault field survey (Source: https://geoparkjogja.jogjaprov.go.id/)')";
$query4 = "INSERT INTO tb_galeri (nama_file, deskripsi) VALUES ('foto4.jpg', 'Illustration of the Opak Fault (Irwan Meilano/ITB via VIK KOMPAS)')";

if (mysqli_query($koneksi, $query1) && mysqli_query($koneksi, $query2)) {
    echo "<h1>Success!</h1><p>The table was created and the data was inserted.</p>";
} else {
    echo "Error: " . mysqli_error($koneksi);
}
?>