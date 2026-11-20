"""
interpolasi_idw.py

Membuat shakemap "komunitas" dari titik-titik laporan warga (hasil fitur
"Lapor Merasakan" di WebGIS), memakai metode IDW (Inverse Distance Weighting).

IDW dipilih (bukan cubic/kriging) karena titik-titiknya biasanya SEDIKIT
(cuma sejumlah desa yang sempat melapor untuk satu gempa, sering < 10 titik)
dan rawan noise antar-laporan warga -- cubic gampang overshoot di kondisi
begini, sementara kriging butuh cukup titik untuk menghitung variogram yang
stabil. IDW jauh lebih aman dipakai walau datanya cuma 3 titik.

Argumen CLI:
    python interpolasi_idw.py <input_titik.json> <output.png> <output_bounds.json>

Format input_titik.json: array of objects, masing-masing:
    {"lon": 110.401, "lat": -7.912, "mmi": 4.0}
(File ini dibuat oleh PHP dari gabungan tb_laporan_masyarakat + tb_batas_desa,
bukan file yang diunggah user secara langsung -- makanya formatnya JSON polos,
tidak perlu logika sniffing kolom/pemisah seperti convert_grd.py/interpolasi_xyz.py.)
"""
import os
os.environ.pop('PROJ_LIB', None)
os.environ.pop('PROJ_DATA', None)
import sys
import json
import numpy as np
import matplotlib
matplotlib.use('Agg')  # backend non-GUI, wajib dijalankan lewat PHP exec() di server
import matplotlib.pyplot as plt
from matplotlib.colors import ListedColormap, BoundaryNorm

# Sama persis dengan interpolasi_xyz.py, supaya shakemap komunitas dan shakemap
# resmi (dari .grd) terlihat konsisten satu sama lain di peta.
WARNA_MMI = [
    '#fefefe', '#e6e7e8', '#d2d3d5', '#91d8f7', '#00afef', '#fff688',
    '#fff212', '#ffcc29', '#faa954', '#f5874f', '#ed3237', '#e13338',
]
BATAS_LEVEL_MMI = np.arange(0.5, 13.5, 1.0)

RESOLUSI_GRID = 300
ALPHA_KONTUR = 0.55        # sedikit lebih transparan dari shakemap resmi (0.65) --
                           # ini data crowdsourced, bukan hasil resmi, jadi divisualkan
                           # lebih "ringan" biar keduanya tetap bisa dibedakan sekilas
TEBAL_GARIS_KONTUR = 1.2
MARGIN_DERAJAT = 0.03      # ~3 km. Titik laporan warga cuma beberapa desa yang jaraknya
                           # bisa berjauhan -- tanpa margin, kotak bounds jadi terlalu
                           # pas-pasan/sempit dan area terluar antar-desa tidak tergambar.
POWER_IDW = 2.0            # pangkat pembobotan jarak, 2 adalah nilai standar IDW


def idw_interpolasi(lon_titik, lat_titik, nilai_titik, grid_lon, grid_lat, power=POWER_IDW):
    """
    IDW manual (tanpa dependency tambahan): nilai tiap sel grid = rata-rata
    terbobot dari semua titik data, bobot = 1 / jarak^power. Kalau grid persis
    berimpit dengan salah satu titik data, nilai titik itu dipakai apa adanya
    (menghindari pembagian dengan nol).
    """
    hasil = np.zeros(grid_lon.shape, dtype=np.float64)
    total_bobot = np.zeros(grid_lon.shape, dtype=np.float64)
    tepat_di_titik = np.zeros(grid_lon.shape, dtype=bool)

    for lon_i, lat_i, nilai_i in zip(lon_titik, lat_titik, nilai_titik):
        jarak = np.sqrt((grid_lon - lon_i) ** 2 + (grid_lat - lat_i) ** 2)
        berimpit = jarak < 1e-9
        if np.any(berimpit):
            hasil[berimpit] = nilai_i
            tepat_di_titik |= berimpit

        aman = ~berimpit
        bobot = np.zeros_like(jarak)
        bobot[aman] = 1.0 / (jarak[aman] ** power)
        hasil[aman & ~tepat_di_titik] += nilai_i * bobot[aman & ~tepat_di_titik]
        total_bobot[aman & ~tepat_di_titik] += bobot[aman & ~tepat_di_titik]

    sel_dihitung = ~tepat_di_titik
    with np.errstate(invalid='ignore', divide='ignore'):
        hasil[sel_dihitung] = hasil[sel_dihitung] / total_bobot[sel_dihitung]

    return hasil


def simpan_png_kosong(output_png, output_bounds_json, south, west, north, east):
    fig = plt.figure(figsize=(RESOLUSI_GRID / 100, RESOLUSI_GRID / 100), dpi=100)
    fig.patch.set_alpha(0)
    plt.savefig(output_png, transparent=True, dpi=100)
    plt.close(fig)
    with open(output_bounds_json, 'w') as f:
        json.dump({"bounds": [[south, west], [north, east]]}, f)


def proses_idw_ke_png(input_json, output_png, output_bounds_json):
    try:
        with open(input_json, 'r') as f:
            titik_data = json.load(f)

        if not isinstance(titik_data, list) or len(titik_data) < 3:
            raise ValueError('Minimal 3 titik laporan dibutuhkan untuk membuat shakemap komunitas.')

        lon_titik = np.array([float(t['lon']) for t in titik_data])
        lat_titik = np.array([float(t['lat']) for t in titik_data])
        mmi_titik = np.array([float(t['mmi']) for t in titik_data])

        min_lon, max_lon = lon_titik.min(), lon_titik.max()
        min_lat, max_lat = lat_titik.min(), lat_titik.max()

        west, east = min_lon - MARGIN_DERAJAT, max_lon + MARGIN_DERAJAT
        south, north = min_lat - MARGIN_DERAJAT, max_lat + MARGIN_DERAJAT

        if west == east or south == north:
            # Semua titik segaris/setitik persis -> tidak bisa dibuat grid 2D
            simpan_png_kosong(output_png, output_bounds_json, south, west, north, east)
            sys.exit(0)

        grid_lon_1d = np.linspace(west, east, RESOLUSI_GRID)
        grid_lat_1d = np.linspace(south, north, RESOLUSI_GRID)
        grid_lon, grid_lat = np.meshgrid(grid_lon_1d, grid_lat_1d)

        grid_mmi = idw_interpolasi(lon_titik, lat_titik, mmi_titik, grid_lon, grid_lat)

        cmap_mmi = ListedColormap(WARNA_MMI)
        norm_mmi = BoundaryNorm(BATAS_LEVEL_MMI, cmap_mmi.N)

        fig = plt.figure(figsize=(RESOLUSI_GRID / 100, RESOLUSI_GRID / 100), dpi=100)
        fig.patch.set_alpha(0)
        ax = fig.add_axes([0, 0, 1, 1])
        ax.set_xlim(west, east)
        ax.set_ylim(south, north)
        ax.axis('off')

        # Lapisan warna gradasi MMI (IDW mengisi seluruh grid, tidak ada area NaN
        # seperti cubic/kriging -- makanya di sini TIDAK ada area transparan di
        # dalam bounds, cuma di luar bounds yang otomatis transparan)
        ax.contourf(
            grid_lon, grid_lat, grid_mmi,
            levels=BATAS_LEVEL_MMI, cmap=cmap_mmi, norm=norm_mmi,
            alpha=ALPHA_KONTUR, extend='neither'
        )

        # Garis kontur PUTUS-PUTUS (bukan solid seperti shakemap resmi) -- penanda
        # visual bahwa ini hasil crowdsourced/komunitas, bukan data resmi BMKG.
        try:
            ax.contour(
                grid_lon, grid_lat, grid_mmi,
                levels=BATAS_LEVEL_MMI, colors='black',
                linewidths=TEBAL_GARIS_KONTUR, linestyles='dashed'
            )
        except Exception:
            pass

        # Titik-titik desa pelapor asli digambar sebagai penanda kecil, supaya jelas
        # ini interpolasi dari laporan diskrit, bukan raster kontinu seperti .grd
        ax.scatter(lon_titik, lat_titik, s=14, color='black', zorder=5)

        plt.savefig(
            output_png,
            transparent=True,
            bbox_inches='tight',
            pad_inches=0,
            dpi=100
        )
        plt.close(fig)

        with open(output_bounds_json, 'w') as f:
            json.dump({"bounds": [[south, west], [north, east]]}, f)

        print("Sukses membuat shakemap komunitas dari {} titik laporan.".format(len(titik_data)))

    except Exception as e:
        print("Error: {}".format(e))
        sys.exit(1)


if __name__ == "__main__":
    if len(sys.argv) < 4:
        print("Penggunaan: python interpolasi_idw.py <input_titik.json> <output.png> <output_bounds.json>")
        sys.exit(1)

    proses_idw_ke_png(sys.argv[1], sys.argv[2], sys.argv[3])
