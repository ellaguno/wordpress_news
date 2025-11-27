# Proyecto: Content Processor
# Script: Utilidades de OpenAI
# Autor: Eduardo Llaguno Velasco

import asyncio
import json
import os
import re
import shutil
from contextlib import contextmanager
from functools import wraps
from bs4 import BeautifulSoup
from src.utils.logging_utils import get_logger
from contextlib import asynccontextmanager
from openai import AsyncOpenAI
import markdown2 

logger = get_logger(__name__)

@asynccontextmanager
async def openai_client_context(api_key):
    """Contexto asíncrono seguro para el cliente OpenAI"""
    client = None
    try:
        client = AsyncOpenAI(api_key=api_key)
        yield client
    finally:
        if client:
            await client.close()

logger = get_logger(__name__)


async def retry_with_backoff(func, *args, config, max_retries=None, **kwargs):
    """Manejo unificado de reintentos con backoff exponencial"""
    retries = max_retries or config['openai'].get('max_retries', 3)
    initial_delay = config['openai'].get('initial_retry_delay', 1)
    max_delay = config['openai'].get('max_retry_delay', 60)

    for attempt in range(retries):
        try:
            return await func(*args, **kwargs)
        except Exception as e:
            if attempt == retries - 1:
                logger.error(f"Error después de {retries} intentos: {e}")
                raise
            delay = min(initial_delay * (2 ** attempt), max_delay)
            logger.warning(f"Intento {attempt + 1} falló. Reintentando en {delay} segundos...")
            await asyncio.sleep(delay)


def clean_html(html_content):
    """Limpia y formatea contenido HTML."""
    try:
        soup = BeautifulSoup(html_content, 'html.parser')
        for tag in soup(['meta', 'title']):
            tag.decompose()
        
        cleaned_html = re.sub(r'\n\s*\n', '\n', str(soup), flags=re.MULTILINE)
        cleaned_html = re.sub(r'(<br\s*/?>\s*){2,}', '<br>', cleaned_html)
        
        if not cleaned_html.strip().startswith('<p>'):
            cleaned_html = f'<p>{cleaned_html}</p>'
        
        return cleaned_html.strip()
    except Exception as e:
        logger.error(f"Error al limpiar HTML: {e}")
        return f"<p>{html_content}</p>"

async def generate_title(tema, api_key, config=None):
    """Genera título usando el contexto del cliente"""
    config = config or {}  # Asegurar que config no sea None
    
    async with AsyncOpenAI(api_key=api_key) as client:
        try:
            prompt = f"Genera un título atractivo y conciso para un artículo sobre {tema}"
            response = await client.chat.completions.create(
                model=config.get('model', 'gpt-4o'),
                messages=[
                    {"role": "system", "content": "Eres un experto en crear títulos atractivos."},
                    {"role": "user", "content": prompt}
                ],
                max_tokens=30,
                temperature=0.7
            )
            return response.choices[0].message.content.strip().replace('"', '').replace("'", "")
        except Exception as e:
            print(f"Error en generate_title: {type(e)}, {str(e)}")
            raise

async def generate_content(tema, api_key, config=None):
    """Genera contenido usando el contexto del cliente"""
    if not config:
        raise ValueError("No se proporcionó configuración")
    
    # Verificar estructura de configuración
    # NOTA: Ahora config ya debería ser el openai_config
    if 'model' not in config:
        raise ValueError("Modelo no especificado en la configuración de OpenAI")

    # Obtener configuración de contenido
    content_settings = config.get('content_settings', {}).get('article', {})
    min_words = content_settings.get('min_words', 1200)
    max_words = content_settings.get('max_words', 1500)
    min_sections = content_settings.get('min_sections', 4)
    reading_time = content_settings.get('reading_time_minutes', {})
    min_time = reading_time.get('min', 6)
    max_time = reading_time.get('max', 10)

    # Obtener parámetros de generación
    gen_params = config.get('generation_params', {}).get('content', {})

    try:
        async with AsyncOpenAI(api_key=api_key) as client:
            prompt = f"""Escribe un artículo detallado sobre {tema} que cumpla con estos requisitos:
            - Longitud: entre {min_words} y {max_words} palabras
            - Tiempo de lectura: {min_time}-{max_time} minutos
            - Mínimo {min_sections} secciones
            
            Instrucciones de formato:
            1. Usa HTML directo (no Markdown)
            2. Estructura el contenido así:
                - Un párrafo introductorio
                - Secciones con <h2> para títulos
                - Párrafos con <p>
                - Énfasis con <strong> para puntos clave
                - Enlaces con <a href="url">texto</a>
            3. No escapes los caracteres HTML
            4. No incluyas bloques de código
            """
            
            response = await client.chat.completions.create(
                model=config['model'],  # Cambiado aquí
                messages=[
                    {"role": "system", "content": "Eres un escritor experto que genera contenido detallado y bien estructurado en HTML."},
                    {"role": "user", "content": prompt}
                ],
                temperature=gen_params.get('temperature', 0.3),
                presence_penalty=gen_params.get('presence_penalty', 0.1),
                frequency_penalty=gen_params.get('frequency_penalty', 0.1),
                max_tokens=gen_params.get('max_tokens', 4000)
            )
            
            content = response.choices[0].message.content.strip()
            
            # Limpiar cualquier residuo de formato no deseado
            content = content.replace('```html', '')
            content = content.replace('```', '')
            content = content.replace('<!DOCTYPE html>', '')
            content = content.replace('<html>', '')
            content = content.replace('</html>', '')
            
            # Asegurar que el contenido comience con un párrafo
            if not content.strip().startswith('<'):
                content = f'<p>{content}</p>'
            
            return content
                
    except Exception as e:
        logger.error(f"Error al generar contenido con OpenAI: {e}")
        raise

async def generate_image_dalle(tema, api_key, config=None):
    """Genera una imagen usando DALL-E con la configuración especificada"""
    # Asegurar que config no sea None
    config = config or {}

    async with AsyncOpenAI(api_key=api_key) as client:
        try:
            prompt = f"Una imagen creativa y atractiva relacionada con {tema}, estilo obra de arte con estilo acuarela"
            response = await client.images.generate(
                prompt=prompt,
                model=config.get('image_model', 'dall-e-3'),
                n=1,
                size=config.get('dalle_size', '1024x1024'),
                quality=config.get('dalle_quality', 'standard')
            )
            
            logger.info("Imagen DALL-E generada exitosamente")
            return response.data[0].url
        except Exception as e:
            print(f"Error detallado en generate_image_dalle: {type(e)}, {str(e)}")
            raise

async def generate_news_summary(tema, prompt, api_key, config=None):
    """Genera un resumen de noticias."""
    if not config:
        raise ValueError("Configuración no proporcionada")

    try:
        async with AsyncOpenAI(api_key=api_key) as client:
            # Obtener el modelo directamente del config
            model = config['model']
            
            response = await client.chat.completions.create(
                model=model,  # Usar el modelo exacto del config
                messages=[
                    {"role": "system", "content": "Eres un experto en resumir noticias de manera objetiva y concisa."},
                    {"role": "user", "content": prompt}
                ],
                max_tokens=config.get('generation_params', {}).get('content', {}).get('max_tokens', 1500),
                temperature=config.get('generation_params', {}).get('content', {}).get('temperature', 0.5)
            )
            
            if response and response.choices:
                return response.choices[0].message.content.strip()
            else:
                logger.error("No se recibió una respuesta válida de OpenAI")
                return f"No se pudo generar un resumen para {tema}."
                
    except Exception as e:
        logger.error(f"Error al generar resumen para {tema}: {str(e)}")
        logger.error(f"Modelo configurado: {config.get('model', 'no especificado')}")
        return f"No se pudo generar un resumen para {tema} debido a un error técnico."

# Al final del archivo, agrega:
__all__ = [
    'generate_title', 
    'generate_content', 
    'generate_image_dalle', 
    'generate_news_summary',
    'analyze_with_chatgpt'
]
