import os
import json  # Añadimos esta importación
import logging
from wordpress_xmlrpc import Client, WordPressPost
from wordpress_xmlrpc.methods import posts, media, taxonomies
import xmlrpc.client
from wordpress_xmlrpc.compat import xmlrpc_client
from src.utils.logging_utils import get_logger
from typing import List  # Añadimos esta importación

logger = get_logger(__name__)

def get_wordpress_client(site_config):
    """
    Crea y devuelve un cliente de WordPress.
    """
    try:
        client = Client(site_config['url'], site_config['username'], site_config['password'])
        return client
    except Exception as e:
        logger.error(f"Error al conectar con WordPress: {e}")
        return None

def upload_to_wordpress(client, file_path):
    """
    Sube un archivo a WordPress y devuelve la URL y el ID del archivo subido.
    """
    try:
        with open(file_path, 'rb') as img:
            data = {
                'name': os.path.basename(file_path),
                'type': 'image/jpeg',  # Ajusta esto según el tipo de archivo
                'bits': xmlrpc_client.Binary(img.read())
            }

        response = client.call(media.UploadFile(data))
        return response['url'], response['id']
    except Exception as e:
        logger.error(f"Error al subir archivo a WordPress: {e}")
        raise

def subir_imagen_wordpress(wp_client, imagen, nombre):
    """
    Sube una imagen a WordPress.
    """
    logger.info(f"Subiendo imagen a WordPress: {nombre}")
    datos = {
        'name': f'{nombre}.png',
        'type': 'image/png',
        'bits': imagen
    }

    try:
        respuesta = wp_client.call(media.UploadFile(datos))
        logger.info("Imagen subida exitosamente a WordPress")
        return respuesta['url'], respuesta['id']
    except Exception as e:
        logger.error(f"Error al subir la imagen: {e}")
        raise

def publicar_en_wordpress(client, titulo, contenido, categorias=None, tags=None, imagen_destacada_id=None, estado='draft'):
    """
    Publica un post en WordPress.
    
    Args:
        client: Cliente WordPress
        titulo: Título del post
        contenido: Contenido HTML del post
        categorias: Lista de categorías o categoría única
        tags: Lista de etiquetas o etiqueta única
        imagen_destacada_id: ID de la imagen destacada
        estado: Estado del post ('draft' o 'publish')
    """
    try:
        post = WordPressPost()
        post.title = titulo.strip()
        post.content = contenido
        post.post_status = estado
        
        terms_names = {}
        
        # Procesar categorías
        if categorias:
            if isinstance(categorias, str):
                categorias = [categorias]
            terms_names['category'] = categorias
        else:
            terms_names['category'] = ['Sin categoría']
            
        # Procesar tags
        if tags:
            if isinstance(tags, str):
                tags = [tags]
            # Limpiar y filtrar tags
            tags = [tag.strip() for tag in tags if tag.strip()]
            if tags:
                terms_names['post_tag'] = tags

        # Asignar términos al post
        post.terms_names = terms_names
        logger.info(f"Asignando términos: {terms_names}")

        if imagen_destacada_id:
            post.thumbnail = imagen_destacada_id

        # Publicar post
        post_id = client.call(posts.NewPost(post))
        logger.info(f"Post creado exitosamente con ID: {post_id}")
        
        return post_id
            
    except Exception as e:
        logger.error(f"Error al publicar en WordPress: {e}")
        raise
    
# Asegúrate de que todas las funciones que necesitas exportar estén definidas aquí