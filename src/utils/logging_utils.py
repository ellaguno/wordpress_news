import logging

def setup_logging(level=logging.INFO):
    """
    Configura el logging para todo el proyecto.
    
    :param level: Nivel de logging (por defecto: logging.INFO)
    """
    logging.basicConfig(
        level=level,
        format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
        datefmt='%Y-%m-%d %H:%M:%S'
    )

def get_logger(name):
    """
    Obtiene un logger configurado para el módulo especificado.
    
    :param name: Nombre del módulo (generalmente __name__)
    :return: Logger configurado
    """
    return logging.getLogger(name)