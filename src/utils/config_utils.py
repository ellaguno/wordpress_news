import os
import json
from datetime import datetime
from src.utils.logging_utils import get_logger
from pathlib import Path  # Añadimos esta importación

logger = get_logger(__name__)

def load_config(config_path=None):
    """
    Carga la configuración desde archivos JSON.
    
    :param config_path: Ruta al archivo de configuración. 
                       Si no se proporciona, usa la ubicación predeterminada.
    :return: Diccionario con la configuración cargada
    """
    # Si config_path es un string, convertirlo a Path
    if isinstance(config_path, str):
        config_path = Path(config_path)
    
    # Si no se proporciona ruta, usar ubicación predeterminada
    if config_path is None:
        config_path = Path(__file__).parent.parent.parent / 'config' / 'wordpress_config.json'
    
    try:
        with open(config_path, 'r') as config_file:
            config = json.load(config_file)
        logger.info(f"Configuración cargada exitosamente desde {config_path}")
        
        return config
    
    except FileNotFoundError as e:
        logger.error(f"Error: Archivo de configuración no encontrado: {e.filename}")
        raise
    except json.JSONDecodeError as e:
        logger.error(f"Error: Problema al decodificar JSON en {e.doc}: {e}")
        raise
    except Exception as e:
        logger.error(f"Error inesperado al cargar la configuración: {e}")
        raise

def ensure_storage_directories():
    """Asegura que existan los directorios de almacenamiento."""
    base_dir = os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(__file__))), 'storage')
    dirs = {
        'articulos': os.path.join(base_dir, 'articulos'),
        'noticias': os.path.join(base_dir, 'noticias'),
        'imagenes': os.path.join(base_dir, 'imagenes')
    }
    
    for dir_path in dirs.values():
        os.makedirs(dir_path, exist_ok=True)
        logger.info(f"Directorio asegurado: {dir_path}")
    
    return dirs

def save_content(content_type, titulo, contenido, imagen_path=None, metadata=None):
    """
    Guarda el contenido generado localmente.
    """
    dirs = ensure_storage_directories()
    fecha = datetime.now().strftime("%Y-%m-%d_%H-%M-%S")
    safe_titulo = "".join(c for c in titulo if c.isalnum() or c in (' ', '-', '_')).rstrip()
    safe_titulo = safe_titulo[:50]  # Limitar longitud del título
    
    content_dir = os.path.join(dirs[content_type], f"{fecha}_{safe_titulo}")
    os.makedirs(content_dir, exist_ok=True)
    logger.info(f"Directorio de contenido creado: {content_dir}")
    
    # Guardar contenido
    content_path = os.path.join(content_dir, 'contenido.html')
    with open(content_path, 'w', encoding='utf-8') as f:
        f.write(contenido)
    logger.info(f"Contenido guardado en: {content_path}")
    
    # Guardar metadatos
    if metadata:
        metadata['titulo'] = titulo
        metadata['fecha_creacion'] = fecha
        metadata['imagen_path'] = imagen_path
        metadata_path = os.path.join(content_dir, 'metadata.json')
        with open(metadata_path, 'w', encoding='utf-8') as f:
            json.dump(metadata, f, ensure_ascii=False, indent=2)
        logger.info(f"Metadatos guardados en: {metadata_path}")
    
    return content_dir
