# AGENT TEAM - LeanWeave WordPress Plugin

---

## REGLAS DE AUTONOMIA

El equipo opera de forma autonoma. El usuario NO debe ser interrumpido
salvo excepciones muy especificas.

**El agente principal NUNCA consulta al usuario para:**
- Crear, editar o eliminar archivos
- Decisiones de estructura de carpetas o nombres de archivos
- Eleccion entre dos implementaciones tecnicas equivalentes
- Redaccion de READMEs, comentarios o documentacion
- Escribir tests o casos de prueba
- Cualquier cosa que pueda resolver con el contexto disponible

**Solo interrumpir al usuario si:**
- Una decision cambia el scope del MVP
- Ambiguedad con impacto opuesto sin forma de inferir la correcta
- Se necesita credencial o acceso externo no proporcionado
- Bloqueo tecnico sin salida sin input externo

**Al terminar cada sprint:**

Completado: [lista de lo que se hizo]
Decisiones autonomas tomadas: [lista con breve justificacion]
Requiere tu revision: [solo si aplica]
Siguiente paso sugerido: [que vendria despues]

---

## VISION DEL PLUGIN

**LeanWeave** es un plugin de WordPress lean, API-first, disenado para
sitios con 15,000+ posts (creciendo ~700/semana). Automatiza la insercion
de links internos y de afiliados sin impactar el performance del sitio
bajo ninguna circunstancia.

**Principio absoluto:** El plugin nunca puede ser la causa de que
un sitio cargue mas lento. Si hay duda entre una implementacion
mas capaz pero mas pesada vs una mas simple y liviana, siempre
gana la liviana.

### Escala real del sitio (ecosistemastartup.com)
- Posts actuales: 15,000+
- Crecimiento: ~700 posts/semana (~100/dia)
- Actores (CPT): 500+ entidades -> 500+ reglas de linking
- Glosario (CPT): 500+ terminos -> 500+ reglas de linking
- Total reglas base: 1,000+ antes de afiliados
- Proyeccion a 12 meses: ~50,000 posts

### Diferenciadores frente a plugins existentes
El equipo debe estudiar el estado del arte antes de disenar cualquier
decision tecnica. Los plugins de referencia (Link Whisper, Yoast SEO,
Rank Math, Internal Link Juicer, Internal Links Manager) tienen
problemas conocidos de performance que este plugin debe resolver.
LeanWeave debe ser objetivamente mejor en velocidad y eficiencia,
no solo diferente.

### Casos de uso concretos (ecosistemastartup.com)
- Keywords alineadas con el glosario del sitio -> link al termino
- Keywords alineadas con la DB de empresas, VCs, actores del ecosistema
- Keywords de productos/servicios de afiliados -> link con tracking
- Glosario y entidades como destino de links internos de autoridad

---

## FASE 0: INVESTIGACION DEL ESTADO DEL ARTE

**Esta fase es obligatoria antes de cualquier decision tecnica.**
El Research Agent lidera esta fase y entrega un reporte que el
equipo completo debe leer antes de continuar.

El Research Agent debe investigar y documentar:

### Plugins a analizar
- **Link Whisper** - lider del mercado, modelo de IA para sugerencias
- **Yoast SEO** - internal linking suggestions integradas
- **Rank Math** - link suggestions + schema
- **Internal Link Juicer** - referencia directa del usuario
- **Internal Links Manager** - alternativa popular
- **SEOKEY** - enfoque en performance SEO
- Cualquier otro plugin relevante publicado en wordpress.org
  con mas de 10,000 instalaciones activas

### Para cada plugin documentar
- Mecanismo de insercion: sincrono o async?
- Estrategia de matching: regex, IA, base de datos?
- Impacto en performance: afecta TTFB, LCP, FID?
- Como maneja sitios con 15,000+ posts
- Problemas reportados en resenas de wordpress.org
- Limitaciones conocidas de su API (si tienen)
- Modelo de negocio: gratuito, freemium, premium

### Output del Research Agent
```
REPORTE: Estado del Arte - Internal Linking Plugins

1. Tabla comparativa de todos los plugins analizados
2. Problemas de performance documentados con evidencia
3. Patrones de implementacion que funcionan bien
4. Antipatrones a evitar
5. Oportunidades: que no hace ninguno bien
6. Recomendacion tecnica: que estrategia de insercion
   (on-save async, on-render, hybrid) tiene el mejor
   balance performance/eficacia segun la evidencia
7. Decision fundamentada sobre el timing de insercion
   de links (no se decide antes del reporte)
```

**El Estratega no aprueba ningun diseno tecnico hasta que
este reporte este completo y revisado por todo el equipo.**

---

## ENTORNO DE DESARROLLO Y TESTING

### Docker Environment
```yaml
# docker-compose.yml
services:
  wordpress:
    image: wordpress:latest
    ports: ["8080:80"]
    environment:
      WORDPRESS_DB_HOST: db
      WORDPRESS_DB_NAME: leanweave_test
      WORDPRESS_DB_USER: wp
      WORDPRESS_DB_PASSWORD: wp
    volumes:
      - ./plugin:/var/www/html/wp-content/plugins/leanweave
      - ./data/uploads:/var/www/html/wp-content/uploads

  db:
    image: mysql:8.0
    environment:
      MYSQL_DATABASE: leanweave_test
      MYSQL_USER: wp
      MYSQL_PASSWORD: wp
      MYSQL_ROOT_PASSWORD: root
    volumes:
      - ./data/mysql:/var/lib/mysql
      - ./data/seed:/docker-entrypoint-initdb.d

  phpmyadmin:
    image: phpmyadmin
    ports: ["8081:80"]
    environment:
      PMA_HOST: db

  wp-cli:
    image: wordpress:cli
    volumes:
      - ./plugin:/var/www/html/wp-content/plugins/leanweave
    depends_on: [wordpress, db]
```

### Estrategia de datos de prueba

**Datos reales:** El usuario puede exportar contenido de
ecosistemastartup.com (XML de WordPress) para importarlo
en el entorno Docker.

**Datos sinteticos de respaldo:** Script WP-CLI que genere:
- 15,000 posts con contenido en espanol sobre tecnologia,
  startups, IA y emprendimiento
- 500 terminos de glosario (CPT)
- 500 actores/empresas/VCs (CPT)
- 100 reglas de keywords de afiliados
- Simulacion de carga semanal de 700 posts
```bash
wp leanweave seed --posts=15000
                  --actors=500
                  --glossary=500
                  --affiliates=100
                  --growth-simulation=700
```

### Suite de performance testing
```
Metricas de servidor:
- TTFB (Time To First Byte) - debe ser identico con/sin plugin
- Tiempo de respuesta de save_post - debe ser < 50ms adicionales
- Memoria PHP en save_post - debe ser < 2MB adicionales
- Queries de DB en page load - debe ser 0 queries adicionales

Metricas de procesamiento:
- Throughput del job: posts procesados por minuto
- Throughput sostenido: > 70 posts/hora (para 100 posts/dia)
- Tiempo promedio por post con 1,000 reglas activas: < 500ms
- Bulk reprocess: 15,000 posts en < 4 horas
- Memory footprint del job: < 32MB por ejecucion

Herramientas:
- Query Monitor (plugin WP) para profiling de DB
- Blackfire o XDebug para profiling de PHP
- Apache Bench (ab) para carga en endpoints API
- wp --debug para analisis de hooks
```

---

## ARQUITECTURA TECNICA

### Stack
- **PHP:** 8.1+ con `declare(strict_types=1)` en cada archivo
- **DB:** Custom tables en MySQL (no post meta para reglas)
- **Queue:** Action Scheduler (battle-tested, usado por WooCommerce)
  - Concurrencia: hasta 3 jobs de LinkProcessorJob en paralelo
  - Lotes de 100 posts por job
  - Prioridad: posts nuevos antes que reprocesamiento bulk
- **API:** WordPress REST API bajo `/wp-json/leanweave/v1/`
- **Docs:** `openapi.yaml` en la raiz del repo (source of truth)
- **Testing:** PHPUnit + WP_Mock para unit tests
- **Cache:**
  - Si Redis/Memcached disponible -> object cache para reglas
  - Si no -> cache particionada por rule_type
  - Reglas de actores y glosario cacheadas separadas de afiliados
    (distintos TTLs y patrones de invalidacion)

### Decision de timing (a determinar por Research Agent)

**Opcion A - On-save async (recomendacion inicial)**
```
save_post -> encolar job -> Action Scheduler procesa en background
```
Pro: cero impacto en page load, cero impacto en save
Con: links no aparecen inmediatamente al publicar

**Opcion B - On-render con cache**
```
page load -> verificar cache -> si miss: procesar y cachear -> servir
```
Pro: links siempre actualizados
Con: primer render puede ser lento, riesgo de impacto en TTFB

**Opcion C - Hybrid**
```
save_post -> procesar en background -> cachear resultado procesado
page load -> servir desde cache siempre
```
Pro: balance entre frescura y performance
Con: mayor complejidad

**El Performance Agent valida empiricamente la opcion elegida
en el entorno Docker con 15,000 posts antes de que se considere
aprobada.**

---

## SCHEMA DE BASE DE DATOS
```sql
CREATE TABLE {prefix}lw_rules (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rule_type     ENUM('internal','affiliate','entity') NOT NULL,
  keyword       VARCHAR(255) NOT NULL,
  target_url    TEXT NOT NULL,
  entity_type   VARCHAR(100),
  entity_id     BIGINT UNSIGNED,
  priority      TINYINT DEFAULT 10,
  max_per_post  TINYINT DEFAULT 1,
  case_sensitive TINYINT(1) DEFAULT 0,
  is_active     TINYINT(1) DEFAULT 1,
  nofollow      TINYINT(1) DEFAULT 0,
  sponsored     TINYINT(1) DEFAULT 0,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_keyword (keyword),
  INDEX idx_type (rule_type),
  INDEX idx_active (is_active)
);

CREATE TABLE {prefix}lw_applied_links (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id     BIGINT UNSIGNED NOT NULL,
  rule_id     BIGINT UNSIGNED NOT NULL,
  keyword     VARCHAR(255) NOT NULL,
  target_url  TEXT NOT NULL,
  applied_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_post (post_id),
  INDEX idx_rule (rule_id)
);

CREATE TABLE {prefix}lw_queue (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id      BIGINT UNSIGNED NOT NULL UNIQUE,
  status       ENUM('pending','processing','done','failed') DEFAULT 'pending',
  triggered_by VARCHAR(50) DEFAULT 'save_post',
  attempts     TINYINT DEFAULT 0,
  scheduled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  error_log    TEXT NULL,
  INDEX idx_status (status),
  INDEX idx_post (post_id)
);

CREATE TABLE {prefix}lw_exclusions (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type       ENUM('post','url','keyword','post_type') NOT NULL,
  value      TEXT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE {prefix}lw_performance_log (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_type    VARCHAR(100) NOT NULL,
  post_id       BIGINT UNSIGNED,
  duration_ms   INT UNSIGNED,
  memory_kb     INT UNSIGNED,
  rules_checked SMALLINT UNSIGNED,
  links_applied SMALLINT UNSIGNED,
  logged_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_event (event_type),
  INDEX idx_date (logged_at)
);
```

---

## API REST

Base: `/wp-json/leanweave/v1/`
Auth: WordPress Application Passwords
Docs: `openapi.yaml` - se actualiza ANTES de implementar cada endpoint
```
Rules:
GET    /rules
POST   /rules
GET    /rules/{id}
PUT    /rules/{id}
DELETE /rules/{id}
PATCH  /rules/{id}/toggle
POST   /rules/import

Queue:
GET    /queue
POST   /queue/bulk
POST   /queue/retry
DELETE /queue/clear-done
GET    /queue/{post_id}

Applied:
GET    /applied?post_id=
GET    /applied?rule_id=
GET    /applied/stats

Exclusions:
GET    /exclusions
POST   /exclusions
DELETE /exclusions/{id}

Performance:
GET    /performance/summary
GET    /performance/log

Health:
GET    /health
```

---

## ESTRUCTURA DE CARPETAS
```
leanweave/
├── leanweave.php
├── uninstall.php
├── openapi.yaml
├── README.md
├── CHANGELOG.md
├── docker-compose.yml
├── docker/
│   ├── seed/
│   │   └── seed.sql
│   └── scripts/
│       └── generate-content.sh
├── src/
│   ├── Plugin.php
│   ├── Installer.php
│   ├── Api/
│   │   ├── RestController.php
│   │   ├── RulesController.php
│   │   ├── QueueController.php
│   │   ├── AppliedController.php
│   │   ├── ExclusionsController.php
│   │   ├── PerformanceController.php
│   │   └── HealthController.php
│   ├── Engine/
│   │   ├── RuleMatcherEngine.php
│   │   ├── ContentParser.php
│   │   └── LinkBuilder.php
│   ├── Jobs/
│   │   └── LinkProcessorJob.php
│   ├── Repositories/
│   │   ├── RulesRepository.php
│   │   ├── QueueRepository.php
│   │   ├── AppliedLinksRepository.php
│   │   ├── ExclusionsRepository.php
│   │   └── PerformanceRepository.php
│   ├── Cache/
│   │   └── RulesCache.php
│   └── Admin/
│       └── AdminPage.php
├── tests/
│   ├── Unit/
│   │   ├── RuleMatcherEngineTest.php
│   │   └── ContentParserTest.php
│   ├── Integration/
│   │   └── ApiEndpointsTest.php
│   └── Performance/
│       └── BulkProcessingTest.php
└── bin/
    └── benchmark.sh
```

---

## ESTANDARES PARA PUBLICACION EN WORDPRESS.ORG

- **License:** GPLv2 or later
- **Text domain:** `leanweave` en todas las funciones de i18n
- **Escape output:** `esc_html__()`, `esc_attr__()` en toda la UI
- **Nonces:** en todas las acciones de admin
- **Prefix:** todas las funciones globales prefijadas con `leanweave_`
- **No** llamadas directas a archivos PHP (`if !defined ABSPATH`)
- **Readme.txt** en formato wordpress.org
- **Assets:** banner 1544x500, icono 256x256, screenshots
- **Tags:** internal links, seo, link building, affiliate, performance

---

## EQUIPO DE AGENTES

### 1. ESTRATEGA
- Mantiene el principio absoluto: nunca impactar el performance
- Lidera la decision de timing basandose en el reporte del Research Agent
- Aprueba cada release

### 2. RESEARCH AGENT
- Investiga todos los plugins del estado del arte
- Documenta problemas de performance con evidencia
- Entrega el reporte antes de cualquier decision tecnica

### 3. SEO ENGINEER
- Define el algoritmo de matching con justificacion SEO
- Reglas de cuantos links internos por post son optimos
- Estrategia para glosario, empresas, VCs
- Afiliados: `rel="sponsored nofollow"` siempre

### 4. PERFORMANCE AGENT
- Configura y mantiene el entorno Docker
- Define las metricas de benchmark antes del desarrollo
- Ejecuta benchmarks en cada milestone
- Tiene poder de veto si alguna implementacion degrada metricas

Metricas de aprobacion minimas:
```
TTFB con plugin activo vs inactivo: diferencia < 5ms
save_post overhead: < 50ms adicionales
Queries adicionales en frontend: 0
Bulk 15,000 posts: < 4 horas
Throughput sostenido: > 70 posts/hora
Engine con 1,000 reglas: < 500ms por post
Memory footprint del job: < 32MB por ejecucion
```

### 5. ARQUITECTO / DEV
- Implementa siguiendo estandares de wordpress.org desde el dia 1
- `openapi.yaml` antes de cada endpoint
- Docker funcional desde el dia 1
- PHPUnit + WP_Mock para tests unitarios

### 6. QA
- Auth, capability check, sanitizacion en cada endpoint
- Engine: no linkear en `<a>`, `<h1>`-`<h6>`, `<code>`, `<pre>`
- Performance: save_post < 50ms, 0 queries en frontend
- Estandares wordpress.org cumplidos al 100%

---

## FLUJO DE ARRANQUE (orden estricto)
```
FASE 0 - INVESTIGACION
1. Research Agent -> Reporte estado del arte
2. Performance Agent -> Define metricas + configura Docker
3. SEO Engineer -> Lee reporte y define algoritmo de matching
4. Estratega -> Decide estrategia de timing con evidencia

FASE 1 - FUNDACION
5. Arquitecto -> Schema de DB + openapi.yaml base + Docker funcional
6. QA -> Valida schema y contrato
7. Arquitecto -> Plugin bootstrap + Installer + Action Scheduler setup

FASE 2 - ENGINE CORE
8. Arquitecto -> RuleMatcherEngine + ContentParser + LinkBuilder
9. QA -> Tests unitarios del Engine
10. Performance Agent -> Benchmark del Engine con 1,000 reglas

FASE 3 - QUEUE Y PROCESSING
11. Arquitecto -> LinkProcessorJob + integracion completa con queue
12. Performance Agent -> Benchmark: save_post + bulk 15,000 posts
13. QA -> Validacion: no bloqueo en save, reintentos, error logging

FASE 4 - API Y ADMIN
14. Arquitecto -> API REST completa
15. QA -> Validacion endpoints
16. Arquitecto -> Admin UI minima + seed script WP-CLI

FASE 5 - PUBLICACION
17. SEO Engineer -> readme.txt optimizado + screenshots + tags
18. Arquitecto -> README.md + CHANGELOG.md + assets
19. Performance Agent -> Benchmark final
20. QA -> Checklist completo wordpress.org
21. Estratega -> Aprueba y notifica al usuario
```

---

## DEFINITION OF DONE DEL MVP

- [ ] Reporte del estado del arte completado y aprobado
- [ ] Docker levanta con un comando (`docker compose up`)
- [ ] Seed de 15,000 posts disponible y funcional
- [ ] Guardar un post: overhead < 50ms, 0 queries en frontend
- [ ] Engine: no linkea en headings, code, links existentes
- [ ] Afiliados: `rel="sponsored nofollow"` automatico
- [ ] Bulk 15,000 posts: completa en < 4 horas
- [ ] Throughput sostenido: > 70 posts/hora
- [ ] Engine con 1,000 reglas: < 500ms por post
- [ ] API REST documentada al 100% en openapi.yaml
- [ ] Benchmarks superan las metricas minimas definidas
- [ ] Estandares wordpress.org cumplidos al 100%
- [ ] readme.txt optimizado para SEO en el directorio
- [ ] README.md con guia completa para agentes externos
- [ ] CHANGELOG.md con version 0.1.0
