# LeanAutoLinks

> Plugin de WordPress lean y API-first para automatizar enlaces internos en sitios de alto volumen.

![WordPress Plugin Version](https://img.shields.io/badge/WordPress-Plugin_v0.4.2-blue?logo=wordpress)
![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)
![License: GPLv2](https://img.shields.io/badge/License-GPLv2-green.svg)

**[English](README.md)** | Espanol

## Por que existe este plugin

El enlazado interno a escala es una pesadilla manual. Sitios con 15,000+ posts y cientos de reglas de enlazado no pueden depender de plugins que escanean contenido en cada carga de pagina o bloquean el editor al guardar. Las soluciones existentes (Link Whisper, Internal Link Juicer, Rank Math) degradan el rendimiento a medida que crece la cantidad de reglas, y ninguna expone una API adecuada para automatizacion.

LeanAutoLinks fue construido para un sitio que publica 100 posts por dia con 1,000+ reglas de enlazado. Procesa links en segundo plano, los sirve desde cache, agrega cero consultas adicionales al frontend, y expone cada operacion a traves de una API REST disenada para agentes de IA y pipelines de contenido.

## Caracteristicas

- **Procesamiento en segundo plano** -- los links se construyen de forma asincrona via Action Scheduler, sin bloquear guardados ni cargas de pagina.
- **Cero overhead en frontend** -- el contenido procesado se sirve desde cache con 0 consultas adicionales a la base de datos.
- **Matching con soporte Unicode** -- maneja caracteres acentuados, espanol, portugues y otros diacriticos de forma nativa.
- **Seguridad de contenido** -- nunca inyecta links dentro de headings (`h1`-`h6`), `pre`, `code`, o tags de anchor existentes.
- **Cumplimiento de afiliados** -- los links de afiliados reciben automaticamente `rel="sponsored nofollow"`.
- **17 endpoints REST API** -- CRUD completo para reglas, gestion de cola, links aplicados, exclusiones, logs de rendimiento y health checks.
- **Cache de 3 capas** -- object cache (Redis/Memcached), transients particionados por tipo de regla, y cache de contenido pre-construido.
- **UI de administracion** -- interfaz de 5 pestanas para gestionar reglas, monitorear la cola, revisar links aplicados, rastrear rendimiento y configurar exclusiones.
- **Panel Gutenberg** -- panel lateral nativo en el editor de bloques para ver y gestionar keywords directamente desde el editor.
- **Widget de dashboard** -- resumen rapido de reglas, links aplicados, cola y rendimiento en el escritorio de WordPress.
- **Comandos WP-CLI** -- seed de datos, reprocesamiento masivo, gestion de cache y benchmarks desde la linea de comandos.
- **Sistema de exclusiones** -- excluir por post, URL, keyword o tipo de post.
- **Importacion masiva** -- cargar reglas desde CSV o JSON via la API.

## Inicio rapido

### Instalacion

1. Sube la carpeta `leanautolinks` a `/wp-content/plugins/`.
2. Activa el plugin desde el panel de administracion de WordPress.
3. El plugin crea sus tablas en la base de datos automaticamente al activarse.

### Crea tu primera regla

**Desde la UI de admin:** Ve a LeanAutoLinks > Rules > Add New.

**Desde el editor de posts:** Usa el panel lateral "LeanAutoLinks Keywords" para agregar keywords que apunten al post actual.

**Via la API:**

```bash
curl -X POST https://your-site.com/wp-json/leanautolinks/v1/rules \
  -H "Content-Type: application/json" \
  -u "admin:TU_APP_PASSWORD" \
  -d '{
    "rule_type": "internal",
    "keyword": "inteligencia artificial",
    "target_url": "/glosario/inteligencia-artificial/",
    "max_per_post": 1
  }'
```

Los posts nuevos y actualizados se procesan automaticamente en segundo plano. Para reprocesar contenido existente en bulk:

```bash
curl -X POST https://your-site.com/wp-json/leanautolinks/v1/queue/bulk \
  -H "Content-Type: application/json" \
  -u "admin:TU_APP_PASSWORD" \
  -d '{"post_type": "post", "limit": 15000}'
```

## API REST

URL base: `/wp-json/leanautolinks/v1/`

Autenticacion: WordPress Application Passwords (recomendado) o cualquier plugin de autenticacion que soporte la REST API.

Especificacion completa: ver `openapi.yaml` en la raiz del repositorio.

### Endpoints principales

| Metodo | Endpoint | Descripcion |
|---|---|---|
| `GET` | `/rules` | Listar todas las reglas (filtrable por tipo, estado) |
| `POST` | `/rules` | Crear una nueva regla de enlazado |
| `PUT` | `/rules/{id}` | Actualizar una regla |
| `PATCH` | `/rules/{id}/toggle` | Activar o desactivar una regla |
| `POST` | `/rules/import` | Importacion masiva de reglas (CSV/JSON) |
| `POST` | `/queue/bulk` | Encolar posts para procesamiento en segundo plano |
| `POST` | `/queue/retry` | Reintentar items fallidos de la cola |
| `GET` | `/queue` | Listar estado de la cola |
| `GET` | `/applied?post_id={id}` | Obtener links aplicados a un post especifico |
| `GET` | `/applied/stats` | Estadisticas agregadas de enlazado |
| `GET` | `/exclusions` | Listar todas las exclusiones |
| `POST` | `/exclusions` | Agregar una exclusion |
| `GET` | `/performance/summary` | Resumen de metricas de rendimiento |
| `GET` | `/health` | Salud del plugin, profundidad de cola, estado del cache |

## Rendimiento

Benchmarked con datos reales de produccion de un sitio con 25,394 posts y 687 reglas activas.

| Metrica | Objetivo | Resultado |
|---|---|---|
| Overhead de `save_post` | < 50 ms | **1.2 ms** |
| Latencia del engine (p50) | < 500 ms | **58 ms** |
| Latencia del engine (p95) | -- | **142 ms** |
| Reprocesamiento masivo 15K posts | < 4 horas | **17 minutos** |
| Throughput | > 70 posts/hr | **52,000 posts/hr** |
| Consultas DB adicionales en frontend | 0 | **0** |

El plugin agrega cero consultas a la base de datos en cargas de paginas del frontend. Toda la inyeccion de links se resuelve en tiempo de procesamiento y se sirve desde cache.

## Arquitectura

LeanAutoLinks usa una estrategia **hibrida asincrona**:

```
Hook save_post
  |
  v
Encolar post_id (< 2ms, no bloqueante)
  |
  v
Action Scheduler toma el job en segundo plano
  |
  v
RuleMatcherEngine escanea contenido contra reglas activas
  |-- ContentParser: extrae nodos de texto seguros (omite h1-h6, pre, code, a)
  |-- LinkBuilder: construye links con atributos rel correctos
  |
  v
Contenido procesado almacenado en cache
  |
  v
Frontend sirve contenido cacheado (0 queries extra)
```

### Capas de cache

1. **Object cache** (Redis/Memcached) -- usado cuando esta disponible para conjuntos de reglas y contenido procesado.
2. **Transients particionados** -- las reglas se cachean por tipo (internal, affiliate, entity) con TTLs e invalidacion independientes.
3. **Cache de contenido** -- HTML pre-construido con links ya inyectados, indexado por post ID y hash de version de reglas.

## Integracion con agentes de IA

LeanAutoLinks esta disenado como infraestructura para agentes de IA y pipelines de contenido automatizados. Cada operacion esta disponible a traves de la API REST con respuestas JSON predecibles, facilitando la integracion con workflows basados en LLMs, pipelines CI/CD y scripts de automatizacion personalizados.

## Requisitos

- **PHP**: 8.1 o superior
- **WordPress**: 6.0 o superior
- **Action Scheduler**: 3.0 o superior (incluido con WooCommerce, o instalable standalone)
- **MySQL**: 8.0 o superior (o MariaDB 10.4+)
- **Recomendado**: Redis o Memcached para rendimiento optimo de cache

## Comandos WP-CLI

### Procesar todo de inmediato (sin esperar al cron)

Para configuracion inicial o migraciones en sitios grandes, usa `process-now` para procesar todo inmediatamente:

```bash
# Procesar todos los posts pendientes
wp leanautolinks process-now

# Con batches mas grandes para mayor velocidad
wp leanautolinks process-now --batch-size=100
```

Esto corre como proceso PHP CLI -- no afecta el rendimiento del sitio y no tiene timeout. En un sitio con 25,000 posts, toma ~30 minutos con `--batch-size=100`.

### Otros comandos

```bash
# Encolar todos los posts para reprocesamiento
wp leanautolinks bulk-reprocess

# Ver estadisticas de la cola
wp leanautolinks queue-stats

# Gestion de cache
wp leanautolinks cache flush
wp leanautolinks cache stats
wp leanautolinks cache warm

# Seed de datos de prueba (solo desarrollo)
wp leanautolinks seed --posts=15000 --actors=500 --glossary=500 --affiliates=100
```

## Cron del sistema (Recomendado para produccion)

Por defecto, LeanAutoLinks usa WP-Cron para procesamiento en segundo plano. Funciona bien en sitios con trafico regular.

Sin embargo, WP-Cron depende de visitas al sitio. En sitios con cache agresivo (Cloudflare, Varnish) o poco trafico, la cola puede estancarse. Hosts administrados como WP Engine, Kinsta y Pantheon manejan esto automaticamente.

Para servidores propios:

```php
// wp-config.php
define('DISABLE_WP_CRON', true);
```

```bash
# Cron del sistema (cada minuto)
* * * * * cd /ruta/a/wordpress && wp cron event run --due-now > /dev/null 2>&1
```

## Contribuir

Las contribuciones son bienvenidas. Antes de enviar un pull request:

1. Asegurate de que todo el codigo siga `declare(strict_types=1)` y los estandares de codificacion de WordPress.
2. Ejecuta la suite de tests: `composer test`
3. Verifica que los benchmarks de rendimiento pasen: sin regresion en overhead de `save_post`, latencia del engine o uso de memoria.
4. Actualiza `openapi.yaml` si modificas algun endpoint de la API.
5. Agrega una entrada en el changelog bajo `[Unreleased]` en `CHANGELOG.md`.

## Patrocinio

Si este plugin te resulta util, considera patrocinar su desarrollo a traves de [GitHub Sponsors](https://github.com/sponsors/ctala). Tu apoyo nos ayuda a mantener el plugin actualizado, agregar nuevas funcionalidades y ofrecer soporte.

## Licencia

GPLv2 o posterior. Ver [LICENSE](LICENSE) para el texto completo.
