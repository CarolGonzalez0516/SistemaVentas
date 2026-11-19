# BRYSA: sistema web de ventas e inventario con sugerencia de reposición asistida por IA

[![CI](https://github.com/Ghost-sm/SistemaVentas/actions/workflows/test.yml/badge.svg)](https://github.com/Ghost-sm/SistemaVentas/actions/workflows/test.yml)

> **Tablero Kanban del proyecto (GitHub Projects):** https://github.com/users/CarolGonzalez0516/projects/1
> Columnas Por hacer, En progreso, En revisión y Hecho, con los issues priorizados (MoSCoW) y etiquetados por sesión. Está en la cuenta de una integrante porque este repositorio es de una cuenta personal y GitHub solo vincula aquí tableros de la misma cuenta.

Sistema web y API REST para la tienda de abarrotes **BRYSA**. Registra ventas de forma automatica (venta, detalle, inventario, kardex y caja en una sola transacción) y, a partir del historial de ventas, calcula cuántas unidades reponer de cada producto. Un modelo de lenguaje (opcional) redacta la explicación de la sugerencia. El sistema apoya la decisión del propietario: **no crea compras ni modifica existencias por su cuenta**.

## Caso de uso del MVP

Registrar una venta (por la interfaz web o por la API) y consultar la sugerencia de reposición de un producto con base en las ventas registradas.

## Estado de las funcionalidades

| Funcionalidad | Estado |
|---|---|
| Registro de venta atómico por la interfaz web (venta, detalle, inventario, kardex y caja) | Implementado |
| Registro de venta por API REST con validación de stock e idempotencia | Implementado |
| Autenticación por token (Sanctum) y permisos por rol (Spatie) | Implementado |
| Sugerencia de reposición con promedio móvil y regla del dominio | Implementado |
| Explicación con LLM detrás de un puerto intercambiable, con respaldo sin IA | Implementado |
| Documentación de la API (OpenAPI 3 y colección Postman) | Implementado |
| Contenedorización con Docker Compose | Implementado |
| Pronóstico Holt-Winters en servicio Python/FastAPI | Futuro |
| Productos complementarios, operaciones inusuales y asistente en lenguaje natural | Futuro |
| Fiados, cuentas por cobrar y abonos | Futuro |
| Aprobar la sugerencia y crear la orden de compra; anulaciones y devoluciones por API | Futuro |

## Cómo ejecutar el proyecto

### Opción A: Laragon (Windows)

Requisitos: **PHP 8.4 o superior** (con las extensiones `zip`, `mbstring`, `openssl`, `pdo_mysql`, `fileinfo`, `gd` e `intl`), Composer, MySQL 8 y Git.

1. Clonar el repositorio y entrar a la carpeta:

   ```bash
   git clone https://github.com/Ghost-sm/SistemaVentas.git
   cd SistemaVentas
   ```

2. Instalar las dependencias y crear la configuración:

   ```bash
   composer install
   copy .env.example .env
   php artisan key:generate
   ```

3. Crear una base de datos vacía (por ejemplo `brysa`) y configurarla en `.env`:

   ```
   DB_DATABASE=brysa
   DB_USERNAME=root
   DB_PASSWORD=
   ```

4. Crear las tablas y cargar los datos básicos:

   ```bash
   php artisan migrate --seed
   ```

5. Iniciar el servidor y abrir <http://localhost:8000>:

   ```bash
   php artisan serve
   ```

   Si el proyecto está en `C:\laragon\www`, Laragon también lo publica en `http://SistemaVentas.test`.

**Usuario de demostración:** `admin@gmail.com` / `12345678`. Es solo para la demo y debe cambiarse en cualquier uso real.

### Opción B: Docker Compose

Requisito: Docker Desktop.

```bash
cp .env.docker.example .env      # editar las contraseñas de la base de datos
docker compose up --build
```

Aplicación en <http://localhost:8000>. Al arrancar, el contenedor espera a MySQL, ejecuta las migraciones y carga los datos básicos. Para detener todo: `docker compose down` (con `-v` también borra los datos).

### Pruebas

```bash
php artisan test
```

Las pruebas usan una base de datos MySQL aparte (la misma que en producción, porque algunas migraciones no funcionan en SQLite). Hay 24 pruebas:

| Tipo | Archivo | Qué comprueba |
|---|---|---|
| Unitaria | `tests/Unit/MetodoPagoEnumTest.php` | Los métodos de pago aceptados (EFECTIVO y TARJETA) |
| Unitaria | `tests/Unit/KardexTest.php` | El costo total del kardex (saldo por costo unitario) |
| Unitaria | `tests/Unit/StoreVentaRequestTest.php` | Una venta sin productos no pasa la validación |
| Integración (HTTP) | `tests/Feature/AutenticacionTest.php` | Inicio de sesión, usuario inactivo, invitado y permisos por rol (HU-01) |
| Integración (HTTP) | `tests/Feature/RegistroVentaTest.php` | Registro de venta: sin caja abierta, datos inválidos y venta válida que baja el stock y escribe kardex y caja (HU-03) |
| Integración (HTTP) | `tests/Feature/CategoriaControllerTest.php` | CRUD de categorías |
| Integración (HTTP) | `tests/Feature/ReposicionIaTest.php` | Función de IA: acceso con token, cálculo del promedio móvil, uso del LLM simulado con `Http::fake()` sin enviar datos de clientes y respaldo por reglas si la API falla |

El flujo de GitHub Actions (`.github/workflows/test.yml`) las ejecuta con PHP 8.4 y MySQL 8 en cada push y en cada pull request a `main`. El estado aparece en la insignia CI al inicio de este archivo.

## Función de IA: sugerencia de reposición

| Ruta | Autenticación |
|---|---|
| `GET /api/ia/reposicion?dias=28` | Token de Sanctum (`Authorization: Bearer <token>`) |
| `GET /admin/ia/reposicion` | Sesión iniciada en la web |

1. `ReposicionService` calcula por producto el promedio diario de unidades vendidas en la ventana, los días de cobertura del stock y la cantidad sugerida para cubrir `IA_DIAS_COBERTURA` días.
2. `ExplicadorReposicion` envía al modelo de lenguaje solo productos y cifras de inventario (nunca datos de clientes) y devuelve la explicación. Si no hay llave o la API falla, responde con una explicación por reglas (`fuente_explicacion: "reglas"`).
3. No se crean órdenes de compra ni se modifica el inventario: el propietario aprueba.

Para obtener un token de prueba: `php artisan tinker` y luego `User::first()->createToken('demo')->plainTextToken`.

## Configuración (variables de entorno)

| Variable | Para qué sirve | Valor por defecto |
|---|---|---|
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Conexión a MySQL | Ver `.env.example` |
| `IA_LLM_DRIVER` | `none` (plantilla, sin IA) o `anthropic` (LLM) | `none` |
| `ANTHROPIC_API_KEY` | Clave del proveedor de lenguaje (solo si `IA_LLM_DRIVER=anthropic`) | vacía |
| `ANTHROPIC_MODEL`, `IA_LLM_TIMEOUT` | Modelo y tiempo máximo de espera | configurables |
| `IA_VENTANA_DIAS` | Días de historial del promedio móvil | `28` |
| `IA_DIAS_COBERTURA`, `IA_DIAS_ALERTA` | Días que debe cubrir la reposición y umbral de urgencia | `14`, `5` |

El archivo `.env` está en `.gitignore`: ninguna clave queda en el repositorio. Solo se versionan las plantillas sin credenciales.
