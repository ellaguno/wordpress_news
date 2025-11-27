import random
import asyncio
import os
import json
import locale
from datetime import datetime
import requests
from bs4 import BeautifulSoup
from src.utils.openai_utils import generate_news_summary
from src.utils.wordpress_utils import get_wordpress_client, publicar_en_wordpress, subir_imagen_wordpress
from src.utils.config_utils import ensure_storage_directories, save_content
from src.utils.logging_utils import get_logger
from openai import AsyncOpenAI
import re
from wordpress_xmlrpc import Client, WordPressPost
from wordpress_xmlrpc.methods import posts, media, taxonomies

logger = get_logger(__name__)

# Configurar locale para fechas en español
locale.setlocale(locale.LC_TIME, 'es_ES.UTF-8')

async def obtener_ultima_publicacion(wp_client):
    try:
        query = {
            'posts_per_page': 5,
            'post_type': 'post',
            'post_status': 'publish',
            'orderby': 'date',
            'order': 'DESC'
        }
        try:
            posts = wp_client.call(posts.GetPosts(query))
            for post in posts:
                categories = [term.name for term in post.terms_names.get('category', [])]
                if 'Noticias' in categories:
                    return post.content
        except Exception as e:
            logger.warning(f"Error al obtener posts: {e}")
        return None
    except Exception as e:
        logger.error(f"Error obteniendo última publicación: {e}")
        return None

def extraer_urls_anteriores(contenido_anterior):
    if not contenido_anterior:
        return set()
    soup = BeautifulSoup(contenido_anterior, 'html.parser')
    return {a['href'] for a in soup.find_all('a')}

def es_noticia_local(titulo, descripcion):
    palabras_locales = {
        'municipal', 'ayuntamiento', 'alcalde', 'gobernador', 'estatal',
        'local', 'ciudad', 'municipio', 'colonia', 'delegación', 'aeropuerto'
    }
    texto = (titulo + ' ' + descripcion).lower()
    return any(palabra in texto for palabra in palabras_locales)

def formatear_referencias(noticias):
    referencias_html = '<div class="referencias">\n'
    for i, noticia in enumerate(noticias, 1):
        referencias_html += f'<sup><a href="{noticia["link"]}" target="_blank"><strong>{i}</strong></a></sup> '
    referencias_html += '</div>'
    return referencias_html

async def obtener_titulares_principales():
    """
    Obtiene los titulares más importantes de los principales medios.
    """
    headers = {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
    }
    
    # Principales medios globales
    fuentes = [
        "https://news.google.com/news/rss/headlines/section/topic/WORLD?hl=es-419&gl=MX&ceid=MX:es-419",  # Mundial
        "https://news.google.com/news/rss/headlines/section/topic/NATION?hl=es-419&gl=MX&ceid=MX:es-419",  # Nacional
        "https://news.google.com/news/rss/headlines/section/topic/BREAKING?hl=es-419&gl=MX&ceid=MX:es-419"  # Breaking News
    ]
    
    titulares_importantes = []
    
    for url in fuentes:
        try:
            response = requests.get(url, headers=headers)
            response.raise_for_status()
            soup = BeautifulSoup(response.content, 'xml')
            
            # Tomar solo los 3 más importantes de cada fuente
            for item in soup.find_all('item')[:3]:
                titular = {
                    'titulo': item.title.text,
                    'fuente': item.source.text if item.source else "Google News"
                }
                titulares_importantes.append(titular)
        except Exception as e:
            logger.error(f"Error al obtener titulares de {url}: {e}")
    
    return titulares_importantes

def markdown_to_html(texto):
    """
    Convierte cualquier markdown a HTML y asegura formato correcto.
    """
    # Primero removemos markdown de títulos
    texto = re.sub(r'#+\s*(.+)', r'\1', texto)
    
    # Removemos los asteriscos de bold/italic
    texto = re.sub(r'\*\*(.+?)\*\*', r'<strong>\1</strong>', texto)
    texto = re.sub(r'\*(.+?)\*', r'<em>\1</em>', texto)
    
    # Aseguramos que todo el texto esté en párrafos
    if not texto.strip().startswith('<'):
        parrafos = texto.split('\n\n')
        texto = '\n'.join(f'<p>{p.strip()}</p>' for p in parrafos if p.strip())
    
    return texto

async def generar_resumen_general(titulares_principales, api_key, openai_config):
    """
    Genera un resumen general conciso basado en los titulares más importantes.
    """
    titulares_texto = []
    for titular in titulares_principales:
        if 'titulo' in titular and 'fuente' in titular:
            titulares_texto.append(f"- {titular['titulo']} ({titular['fuente']})")
    
    titulares_formateados = "\n".join(titulares_texto)
    
    prompt = f"""Como editor jefe, crea un resumen breve y conciso de las noticias más relevantes del día. Tieens humor y eres divertido.
    
    IMPORTANTE: 
    - USAR ESTRICTAMENTE LOS DATOS Y HECHOS TAL COMO APARECEN EN LOS TITULARES
    - USAR HTML DIRECTAMENTE (NO MARKDOWN)
    - CADA PÁRRAFO DEBE ESTAR EN TAGS <p></p>
    - USAR <strong> PARA ÉNFASIS (NO ASTERISCOS)
    
    Titulares a resumir:
    {titulares_formateados}

    Instrucciones:
    1. Usa EXCLUSIVAMENTE la información proporcionada en los titulares
    2. Mantén todas las cifras, nombres y datos exactamente como aparecen en las fuentes
    3. No agregues contexto o información que no esté en los titulares
    4. Si hay ambigüedad en algún dato, cita la fuente
    5. Extensión: 3-4 párrafos cortos en HTML
    6. Tono formal y objetivo
    7. Recuerda que Claudia Sheinbaun ya es presidenta de Mexico y Donald Trump ya es presidente de EEUU
    8. Evita mencionar que una cifra o un dato no se especifica en el resumen proporcionado

    FORMATO DE SALIDA REQUERIDO:
    <p>Primer párrafo...</p>
    <p>Segundo párrafo...</p>
    <p>Tercer párrafo...</p>
    """

    resumen = await generate_news_summary("Resumen Global", prompt, api_key, openai_config)
    return markdown_to_html(resumen)  # Convertir por si acaso viene en markdown


async def obtener_noticias_por_tema(tema):
    """
    Obtiene noticias de Google News para un tema específico.
    """
    headers = {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
    }
    url = f"https://news.google.com/rss/search?q={tema}&hl=es-419&gl=MX&ceid=MX:es-419"
    
    try:
        response = requests.get(url, headers=headers)
        response.raise_for_status()
        soup = BeautifulSoup(response.content, 'xml')
        noticias = []
        
        for item in soup.find_all('item')[:5]:  # Tomar las 5 primeras noticias
            noticia = {
                'titulo': item.title.text,
                'link': item.link.text,
                'fecha': item.pubDate.text,
                'descripcion': item.description.text if item.description else ""
            }
            noticias.append(noticia)
            
        return noticias
    except Exception as e:
        logger.error(f"Error al obtener noticias para {tema}: {e}")
        return []

async def generar_resumen_tema(tema, noticias, api_key, openai_config):
    """
    Genera un resumen para un tema específico.
    """
    noticias_texto = []
    for noticia in noticias:
        noticias_texto.append(f"TITULAR: {noticia['titulo']}\n"
                            f"DESCRIPCIÓN: {noticia['descripcion']}\n"
                            f"FUENTE: {noticia['link']}")
    
    context = "\n\n".join(noticias_texto)
    
    prompt = f"""Genera un resumen sobre {tema} basado ÚNICAMENTE en estas noticias.

    {context}

    REGLAS ESTRICTAS:
    1. Usa SOLO la información proporcionada en las noticias
    2. Mantén todos los datos cuantitativos exactamente como aparecen
    3. Si citas declaraciones, mantenlas textuales
    4. No agregues información externa ni especulaciones
    5. Si hay datos contradictorios entre fuentes, menciona ambas citando sus fuentes
    6. Tienes buen humor y eres divertido

    FORMATO REQUERIDO:
    - Usar HTML puro (NO MARKDOWN)
    - Cada párrafo debe estar en tags <p></p>
    - Usar <strong> para énfasis (NO ASTERISCOS)
    - NO usar headers (#)
    
    EJEMPLO DE FORMATO:
    <p>Primer párrafo con <strong>énfasis</strong> donde sea necesario...</p>
    <p>Segundo párrafo con más información...</p>
    """

    resumen = await generate_news_summary(tema, prompt, api_key, openai_config)
    return markdown_to_html(resumen) 

async def generar_noticias(sitio="sesolibre", config=None, num_temas=5):
    try:
        if not config:
            raise ValueError("Configuración no proporcionada")
            
        openai_config = config.get('openai', {})
        api_key = openai_config.get('api_key')
        sitio_config = config['sites'].get(sitio)
        temas_seleccionados = config.get('news_topics', [])
        
        if not sitio_config:
            raise ValueError(f"Sitio {sitio} no encontrado")

        # Create storage directories
        dirs = ensure_storage_directories()
        
        # Check local storage for previous content
        local_news_dir = os.path.join(dirs['noticias'])
        previous_urls = set()
        
        # Get URLs from last 5 local files
        try:
            for entry in sorted(os.listdir(local_news_dir))[-5:]:
                metadata_path = os.path.join(local_news_dir, entry, 'metadata.json')
                if os.path.exists(metadata_path):
                    with open(metadata_path, 'r', encoding='utf-8') as f:
                        metadata = json.load(f)
                        if 'urls' in metadata:
                            previous_urls.update(metadata['urls'])
        except Exception as e:
            logger.warning(f"Error reading local storage: {e}")

        # Get WordPress client
        client = get_wordpress_client(sitio_config)
        
        # Get previous content from WordPress
        contenido_anterior = await obtener_ultima_publicacion(client)
        if contenido_anterior:
            urls_anteriores = extraer_urls_anteriores(contenido_anterior)
            previous_urls.update(urls_anteriores)

        fecha_formato = datetime.now().strftime("%A, %d de %B de %Y").capitalize()
        titulo_wp = f"Resumen de noticias - {fecha_formato}"

        titulares_principales = await obtener_titulares_principales()
        resumen_general = await generar_resumen_general(
            titulares_principales,
            api_key,
            openai_config
        )

        contenido = "<div class='resumen-noticias'>\n"
        contenido += f"<div class='resumen-general'>\n{resumen_general}\n</div>\n\n"
        
        # Track all URLs for this run
        current_urls = []
        
        for tema in temas_seleccionados:
            noticias = await obtener_noticias_por_tema(tema['nombre'])
            
            # Filter out previously used URLs
            noticias = [n for n in noticias if n['link'] not in previous_urls]
            
            # Filter local news in international section
            if tema['nombre'].lower() == 'internacional':
                noticias = [n for n in noticias if not es_noticia_local(n['titulo'], n['descripcion'])]
            
            if noticias:
                current_urls.extend([n['link'] for n in noticias])
                resumen_tema = await generar_resumen_tema(
                    tema['nombre'],
                    noticias,
                    api_key,
                    openai_config
                )
                
                contenido += f"\n<div class='seccion-tema'>\n"
                contenido += f"<h2>{tema['nombre']}</h2>\n"
                contenido += f"<img src='{tema['imagen']}' alt='{tema['nombre']}'/>\n"
                contenido += f"{resumen_tema}\n"
                contenido += formatear_referencias(noticias)
                contenido += "<hr></div>\n"

        contenido += "</div>"

        # Save current content locally
        fecha_actual = datetime.now().strftime("%Y-%m-%d_%H-%M-%S")
        content_dir = os.path.join(dirs['noticias'], f"{fecha_actual}_noticias")
        os.makedirs(content_dir, exist_ok=True)

        with open(os.path.join(content_dir, 'content.html'), 'w', encoding='utf-8') as f:
            f.write(contenido)

        metadata = {
            'sitio': sitio,
            'fecha_generacion': fecha_actual,
            'temas_seleccionados': [t['nombre'] for t in temas_seleccionados],
            'urls': current_urls
        }
        
        with open(os.path.join(content_dir, 'metadata.json'), 'w', encoding='utf-8') as f:
            json.dump(metadata, f, ensure_ascii=False, indent=2)

        # Publish to WordPress
        post_id = publicar_en_wordpress(
            client,
            titulo_wp,
            contenido,
            categorias=['Noticias'],
            tags=[t['nombre'] for t in temas_seleccionados],
            imagen_destacada_id=7278 if sitio == "sesolibre" else None,
            estado='draft'
        )
        
        return post_id
        
    except Exception as e:
        logger.error(f"Error al generar noticias: {e}")
        raise
