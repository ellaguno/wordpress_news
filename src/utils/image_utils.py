# Proyecto: Content Processor
# Script: Utilerías Imagenes
# Autor: Eduardo Llaguno Velasco
# Versión: 4.80
# Fecha: 2024-09-30

import requests
import logging
from io import BytesIO
from PIL import Image
import cairosvg
import tempfile
import os
import io
import aiohttp
from src.utils.logging_utils import get_logger

logger = get_logger(__name__)

async def descargar_imagen(url):
    """
    Descarga una imagen desde una URL de forma asíncrona.
    """
    logger.info(f"Descargando imagen desde: {url}")
    try:
        async with aiohttp.ClientSession() as session:
            async with session.get(url) as response:
                response.raise_for_status()
                return await response.read()
    except Exception as e:
        logger.error(f"Error al descargar imagen: {e}")
        raise

def guardar_imagen(imagen_bytes, nombre):
    """
    Guarda una imagen en el disco.
    """
    logger.info(f"Guardando imagen como: {nombre}")
    try:
        img = Image.open(io.BytesIO(imagen_bytes))
        img.save(nombre, 'PNG')
        logger.info("Imagen guardada exitosamente")
    except Exception as e:
        logger.error(f"Error al guardar imagen: {e}")
        raise

def aplicar_sello_a_imagen(ruta_imagen, ruta_sello, ruta_salida):
    """
    Aplica un sello (marca de agua) a una imagen.
    """
    logger.info(f"Aplicando sello a la imagen: {ruta_imagen}")
    try:
        if not os.path.exists(ruta_sello):
            raise FileNotFoundError(f"El archivo de sello no se encuentra en: {ruta_sello}")
        
        with tempfile.NamedTemporaryFile(suffix='.png', delete=False) as temp_png:
            cairosvg.svg2png(url=ruta_sello, write_to=temp_png.name)
            temp_png_path = temp_png.name

        imagen_base = Image.open(ruta_imagen).convert("RGBA")
        sello = Image.open(temp_png_path).convert("RGBA")
        
        sello_height = int(imagen_base.height * 0.1)
        sello_width = int(sello.width * (sello_height / sello.height))
        sello_redimensionado = sello.resize((sello_width, sello_height))
        
        posicion = (imagen_base.width - sello_redimensionado.width, imagen_base.height - sello_redimensionado.height)
        
        imagen_final = Image.new("RGBA", imagen_base.size, (0, 0, 0, 0))
        imagen_final.paste(imagen_base, (0, 0))
        imagen_final.paste(sello_redimensionado, posicion, sello_redimensionado)
        
        imagen_final.save(ruta_salida, "PNG")
        
        os.unlink(temp_png_path)
        
        logger.info(f"Sello aplicado exitosamente. Imagen guardada como: {ruta_salida}")
        return ruta_salida
    except Exception as e:
        logger.error(f"Error al aplicar sello a la imagen: {e}")
        raise

async def obtener_imagen_pixabay(tema, api_key):
    """
    Obtiene una imagen de Pixabay basada en un tema de forma asíncrona.
    """
    logger.info(f"Obteniendo imagen de Pixabay para el tema: {tema}")
    palabras_clave = '+'.join(tema.split()[:2])
    url = f"https://pixabay.com/api/?key={api_key}&q={palabras_clave}&image_type=photo&lang=es"
    try:
        async with aiohttp.ClientSession() as session:
            async with session.get(url) as response:
                response.raise_for_status()
                datos = await response.json()
                if datos['hits']:
                    return datos['hits'][0]['webformatURL']
        logger.warning(f"No se encontraron imágenes en Pixabay para: {palabras_clave}")
    except Exception as e:
        logger.error(f"Error al obtener imagen de Pixabay: {e}")
        raise
    return None

# Asegúrate de que todas las funciones que necesitas exportar estén definidas aquí
