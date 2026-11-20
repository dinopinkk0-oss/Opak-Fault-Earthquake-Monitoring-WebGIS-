import os
os.environ.pop('PROJ_LIB', None)
os.environ.pop('PROJ_DATA', None)
import sys
import json
from pathlib import Path

import geopandas as gpd
import matplotlib
matplotlib.use('Agg')
import matplotlib.colors as mcolors
import matplotlib.pyplot as plt
import numpy as np
import rasterio
from rasterio.warp import transform as transform_coordinates
from affine import Affine
from rasterio.features import rasterize
from rasterio.mask import mask
from scipy.ndimage import distance_transform_edt, zoom

FAKTOR_SKALA = 5
MMI_MAKSIMUM_MASUK_AKAL = 12.0
GEOJSON_DLH = Path(__file__).resolve().parent.parent / 'Batas DLH se-Indonesia.geojson'


def sample_mmi_at_villages(src, valid_mask, data, villages):
    if not villages:
        return []

    longitudes = [float(village['lng']) for village in villages]
    latitudes = [float(village['lat']) for village in villages]
    target_crs = src.crs or 'EPSG:4326'
    x_coordinates, y_coordinates = transform_coordinates(
        'EPSG:4326', target_crs, longitudes, latitudes
    )

    sampled = []
    for village, x, y in zip(villages, x_coordinates, y_coordinates):
        try:
            row, column = src.index(x, y)
        except (ValueError, OverflowError):
            row, column = -1, -1

        mmi = None
        if (0 <= row < src.height and 0 <= column < src.width and
                valid_mask[row, column]):
            mmi = round(float(data[row, column]), 1)
        sampled.append({'nama': village['nama'], 'mmi': mmi})

    return sampled


def convert_grd_to_png(input_grd, output_png, output_bounds_json,
                       desa_centroids_json=None, output_mmi_json=None):
    try:
        villages = []
        if desa_centroids_json:
            with open(desa_centroids_json, 'r') as f:
                villages = json.load(f)

        if not GEOJSON_DLH.is_file():
            raise FileNotFoundError('GeoJSON batas DLH tidak ditemukan: {}'.format(GEOJSON_DLH))

        gdf = gpd.read_file(GEOJSON_DLH)
        if gdf.empty:
            raise ValueError('GeoJSON batas DLH tidak memiliki geometri.')
        if gdf.crs is None:
            raise ValueError('GeoJSON batas DLH tidak memiliki CRS yang terdefinisi.')

        with rasterio.open(input_grd) as src:
            target_crs = src.crs or 'EPSG:4326'
            if gdf.crs != target_crs:
                gdf = gdf.to_crs(target_crs)

            out_image, out_transform = mask(src, gdf.geometry, crop=False, filled=False)
            data = np.ma.array(out_image[0], dtype=np.float32).filled(np.nan)
            bounds = src.bounds
            land_mask_asli = ~np.ma.getmaskarray(out_image[0]) & np.isfinite(data)
            desa_mmi = sample_mmi_at_villages(src, valid_mask, data, villages)

        valid_mask = (
            land_mask_asli &
            (data > 2.0) &
            (data <= MMI_MAKSIMUM_MASUK_AKAL)
        )
        if not np.any(valid_mask):
            raise ValueError('Tidak ada area MMI di atas 2.0 di dalam batas DLH.')

        data_bersih = np.where(valid_mask, data, np.nan)
        data_halus = zoom(np.nan_to_num(data_bersih, nan=0.0), FAKTOR_SKALA, order=1)
        land_mask_halus = zoom(land_mask_asli.astype(float), FAKTOR_SKALA, order=1) >= 0.5
        transform_halus = out_transform * Affine.scale(1 / FAKTOR_SKALA, 1 / FAKTOR_SKALA)
        batas_daratan_halus = rasterize(
            ((geom, 1) for geom in gdf.geometry if geom is not None),
            out_shape=data_halus.shape,
            transform=transform_halus,
            fill=0,
            dtype='uint8',
        ).astype(bool)
        data_halus_bersih = np.where(
            batas_daratan_halus & land_mask_halus & (data_halus > 2.0),
            data_halus,
            np.nan,
        )

        mmi_levels = [2.75, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0, 10.0]
        mmi_colors = [
            '#5ccaf5', '#89f6ff', '#7afda4', '#efff0b',
            '#ffca09', '#ff9b04', '#f71701', '#d40000'
        ]
        norm = mcolors.Normalize(vmin=2.75, vmax=10.0)
        custom_cmap = mcolors.LinearSegmentedColormap.from_list(
            'mmi_cmap', list(zip(norm(mmi_levels), mmi_colors))
        )
        rgba_img = custom_cmap(norm(data_halus_bersih))

        max_opacity = 0.65
        alpha_channel = np.full(data_halus_bersih.shape, max_opacity)
        fade_mask = data_halus_bersih < 3.5
        rasio = (data_halus_bersih[fade_mask] - 2.0) / (3.5 - 2.0)
        alpha_channel[fade_mask] = rasio * max_opacity
        alpha_channel[np.isnan(data_halus_bersih)] = 0.0
        fade_tepi = np.clip(distance_transform_edt(batas_daratan_halus) / 8.0, 0.0, 1.0)
        alpha_channel *= fade_tepi
        rgba_img[:, :, 3] = alpha_channel

        plt.imsave(output_png, rgba_img, format='png', origin='upper')

        bounds_payload = {"bounds": [[bounds.bottom, bounds.left], [bounds.top, bounds.right]]}
        with open(output_bounds_json, 'w') as f:
            json.dump(bounds_payload, f)
        if output_mmi_json:
            with open(output_mmi_json, 'w') as f:
                json.dump({'desa_mmi': desa_mmi}, f)

        print("Sukses mengonversi {} ke {}".format(input_grd, output_png))

    except Exception as e:
        print("Error: {}".format(e))
        sys.exit(1)


if __name__ == "__main__":
    if len(sys.argv) not in (4, 6):
        print("Penggunaan: python convert_grd.py <input.grd> <output.png> <output_bounds.json> [<desa_centroids.json> <output_mmi.json>]")
        sys.exit(1)

    input_file = sys.argv[1]
    output_png_file = sys.argv[2]
    output_bounds_file = sys.argv[3]
    desa_centroids_file = sys.argv[4] if len(sys.argv) == 6 else None
    output_mmi_file = sys.argv[5] if len(sys.argv) == 6 else None

    convert_grd_to_png(
        input_file, output_png_file, output_bounds_file,
        desa_centroids_file, output_mmi_file
    )
