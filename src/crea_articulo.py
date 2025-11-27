import random
import asyncio
import os
import json
import tempfile
from datetime import datetime
from src.utils.openai_utils import generate_title, generate_content, generate_image_dalle
from src.utils.wordpress_utils import get_wordpress_client, publicar_en_wordpress, subir_imagen_wordpress
from src.utils.image_utils import descargar_imagen, aplicar_sello_a_imagen
from src.utils.config_utils import ensure_storage_directories, save_content
from src.utils.logging_utils import get_logger
from openai import AsyncOpenAI

logger = get_logger(__name__)

async def generar_articulo(tema=None, sitio="pruebas", config=None):
    # Verificar configuración de OpenAI
    openai_config = config.get('openai', {})
    
    # Verificar API key
    api_key = openai_config.get('api_key')
    if not api_key:
        raise ValueError("No se encontró la API key de OpenAI")
    
    # Si no se proporciona tema, seleccionar uno aleatorio
    if not tema:
        tema = random.choice(config.get('article_topics', []))

    try:
        # Primero generar el título
        titulo = await generate_title(tema, api_key, openai_config)
        print(f"Título generado: {titulo}")
        
        #print("DEBUG - Estructura de config recibida:", json.dumps(config, indent=2))

        # Luego generar el contenido
        try:
            contenido = await generate_content(tema, api_key, openai_config)
            print(f"Contenido generado. Longitud: {len(contenido)}")
        except Exception as e:
            print("Error al llamar generate_content")
            print("openai_config:", json.dumps(openai_config, indent=2))
            raise

        # Luego generar el contenido
        contenido = await generate_content(tema, api_key, openai_config)
        print(f"Contenido generado. Longitud: {len(contenido)}")
        
        # Después generar la imagen
        imagen_url = await generate_image_dalle(titulo, api_key, openai_config)
        print(f"Imagen generada: {imagen_url}")
        
        # Descargar y procesar la imagen
        imagen_bytes = await descargar_imagen(imagen_url)
        
        # Guardar imagen original
        dirs = ensure_storage_directories()
        fecha = datetime.now().strftime("%Y-%m-%d_%H-%M-%S")
        imagen_original_path = os.path.join(dirs['imagenes'], f"{fecha}_original.png")
        with open(imagen_original_path, 'wb') as f:
            f.write(imagen_bytes)
        
        # Aplicar sello si está configurado
        if config.get('image_settings', {}).get('watermark', {}).get('enabled'):
            sello_path = config['image_settings']['watermark']['path']
            imagen_con_sello_path = os.path.join(dirs['imagenes'], f"{fecha}_con_sello.png")
            aplicar_sello_a_imagen(imagen_original_path, sello_path, imagen_con_sello_path)
            with open(imagen_con_sello_path, 'rb') as f:
                imagen_bytes = f.read()
        
        # Guardar contenido localmente
        metadata = {
            'tema': tema,
            'sitio': sitio,
            'imagen_original': imagen_original_path,
            'imagen_con_sello': imagen_con_sello_path if config.get('image_settings', {}).get('watermark', {}).get('enabled') else None
        }
        content_dir = save_content('articulos', titulo, contenido, imagen_con_sello_path, metadata)
        print(f"Contenido guardado localmente en: {content_dir}")
        
        # Continuar con la publicación en WordPress
        sitio_config = config['sites'][sitio]
        wp_client = get_wordpress_client(sitio_config)
        
        imagen_wp_url, imagen_wp_id = subir_imagen_wordpress(wp_client, imagen_bytes, titulo)
        print(f"Imagen subida a WordPress: {imagen_wp_url}")
        
        contenido_html = f'<img src="{imagen_wp_url}" alt="{titulo}">\n{contenido}'
        
        # Generar tags relevantes
        palabras_clave = set()  # Usamos set para evitar duplicados
        palabras_titulo = [palabra.strip() for palabra in titulo.split() 
                        if len(palabra) > 3 and palabra.lower() not in 
                        ['para', 'como', 'desde', 'entre', 'esto', 'esta', 'estos', 'estas']]
        palabras_clave.update(palabras_titulo[:3])
        palabras_clave.add(tema)
        tags = list(palabras_clave)

        # Publicar en WordPress
        post_id = publicar_en_wordpress(
            wp_client, 
            titulo, 
            contenido_html, 
            categorias=[tema],
            tags=tags,
            imagen_destacada_id=imagen_wp_id
        )
        
        # Actualizar metadatos con información de WordPress
        metadata['wordpress_post_id'] = post_id
        metadata['wordpress_image_url'] = imagen_wp_url
        metadata['tags'] = tags
        metadata['categorias'] = [tema]
        
        with open(os.path.join(content_dir, 'metadata.json'), 'w', encoding='utf-8') as f:
            json.dump(metadata, f, ensure_ascii=False, indent=2)
        
        return post_id
        
    except Exception as e:
        print(f"Error detallado: {type(e)}")
        print(f"Detalles del error: {str(e)}")
        raise