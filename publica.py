#!/usr/bin/env python3
import argparse
import asyncio
import os
import sys
from src.utils.config_utils import load_config
import random

async def main():
    parser = argparse.ArgumentParser(description="Publicador de contenido WordPress")
    
    # Grupos mutuamente excluyentes
    grupo = parser.add_mutually_exclusive_group(required=True)
    grupo.add_argument("--articulo", action="store_true", 
                       help="Generar y publicar un artículo")
    grupo.add_argument("--noticias", action="store_true", 
                       help="Generar y publicar un resumen de noticias")
    
    # Argumentos opcionales
    parser.add_argument("--tema", type=str, 
                        help="Tema específico para el artículo")
    parser.add_argument("--sitio", type=str, default="pruebas",
                        help="Sitio de WordPress para publicar")
    
    args = parser.parse_args()

    try:
        config = load_config()
    except Exception as e:
        print(f"Error al cargar la configuración: {e}")
        sys.exit(1)

    try:
        if args.sitio not in config.get('sites', {}):
            print(f"Error: El sitio {args.sitio} no está configurado.")
            sys.exit(1)

        openai_config = config.get('openai', {})
        if not openai_config.get('api_key'):
            print("Error: API Key de OpenAI no configurada.")
            sys.exit(1)

        if args.articulo:
            from src.crea_articulo import generar_articulo
            
            if not args.tema:
                article_topics = config.get('article_topics', [])
                if not article_topics:
                    print("Error: No hay temas de artículos configurados.")
                    sys.exit(1)
                args.tema = random.choice(article_topics)
            
            resultado = await generar_articulo(
                tema=args.tema, 
                sitio=args.sitio, 
                config=config
            )

        elif args.noticias:
            from src.crea_noticias import generar_noticias
            resultado = await generar_noticias(
                sitio=args.sitio, 
                config=config
            )

        if resultado:
            print(f"Publicación exitosa. ID del post: {resultado}")
        else:
            print("No se pudo generar la publicación.")

    except ImportError as e:
        print(f"Error al importar módulo: {e}")
    except Exception as e:
        print(f"Error inesperado: {e}")

if __name__ == "__main__":
    asyncio.run(main())