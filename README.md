# Marcenio Assistant

Marcenio Assistant es un bot de Telegram construido con Symfony. Su objetivo actual es ayudar a guardar y consultar tareas y notas desde mensajes de Telegram, aceptando tanto comandos explícitos como algunas frases naturales en español.

El proyecto está en una fase sencilla y directa: recibe actualizaciones de Telegram, interpreta el texto del usuario, ejecuta la acción correspondiente sobre la base de datos y responde por Telegram.

## Funcionalidades principales

- Recibir mensajes desde Telegram mediante webhook.
- Guardar tareas con `/tarea comprar pan`.
- Listar tareas con `/tareas`.
- Marcar tareas como hechas con `/hecha 1`.
- Borrar tareas con `/borrar-tarea 1`.
- Guardar notas con `/nota idea para el proyecto`.
- Listar notas con `/notas`.
- Borrar notas con `/borrar-nota 1`.
- Separar tareas y notas por `chat.id` de Telegram.
- Interpretar frases naturales simples con reglas locales, por ejemplo `acuérdate de comprar café`.
- Usar OpenAI como intérprete de respaldo para convertir lenguaje natural en comandos internos.
- Enviar mensajes de prueba y consultar actualizaciones de Telegram desde rutas auxiliares.

## Arquitectura actual

El flujo principal es:

```text
Telegram -> TelegramWebhookController -> intérpretes -> BotCommandHandler -> Doctrine -> TelegramService -> Telegram
```

### TelegramWebhookController

`src/Controller/TelegramWebhookController.php` expone `POST /telegram/webhook`.

Responsabilidades actuales:

- Leer el JSON recibido desde Telegram.
- Ignorar actualizaciones que no contengan un mensaje.
- Obtener `chat.id` y el texto del mensaje.
- Responder cuando el mensaje no sea texto.
- Ejecutar el comando especial `/debug-ia`.
- Pasar el texto primero por `AiCommandInterpreter`.
- Usar `OpenAiCommandInterpreter` como respaldo si el intérprete local no transforma el mensaje.
- Enviar el comando interpretado y el `chat.id` a `BotCommandHandler`.
- Enviar la respuesta final por Telegram usando `TelegramService`.

### BotCommandHandler

`src/Service/BotCommandHandler.php` recibe comandos internos como strings y devuelve el texto de respuesta para el usuario.

Actualmente concentra tres responsabilidades:

- Parsear comandos como `/tarea`, `/hecha`, `/nota` o `/borrar-nota`.
- Ejecutar operaciones de persistencia con Doctrine.
- Construir los mensajes de respuesta en español.

### AiCommandInterpreter

`src/Service/AiCommandInterpreter.php` es un intérprete local basado en reglas. No llama a ningún servicio externo.

Convierte frases naturales conocidas en comandos internos. Por ejemplo:

```text
mis tareas -> /tareas
marca como hecha la tarea 3 -> /hecha 3
acuérdate de comprar café -> /tarea comprar café
anota idea para el proyecto -> /nota idea para el proyecto
```

Si no reconoce la intención, devuelve el texto original.

### OpenAiCommandInterpreter

`src/Service/OpenAiCommandInterpreter.php` usa la API de OpenAI como respaldo para interpretar mensajes naturales más flexibles.

Envía el texto a la API de Responses y espera recibir únicamente uno de los comandos internos soportados:

```text
/tarea texto
/tareas
/hecha ID
/borrar-tarea ID
/nota texto
/notas
/borrar-nota ID
/ayuda
```

### TelegramService

`src/Service/TelegramService.php` encapsula llamadas HTTP a la API de Telegram.

Métodos actuales:

- `sendMessage()`: envía un mensaje a un chat.
- `getUpdates()`: consulta actualizaciones pendientes mediante polling.

### Entidades Task y Note

`src/Entity/Task.php` representa una tarea:

- `id`
- `title`
- `telegramChatId`
- `isDone`

`src/Entity/Note.php` representa una nota:

- `id`
- `content`
- `telegramChatId`

Las tareas y notas se asocian al `chat.id` de Telegram. Cada chat solo lista, completa o borra sus propios registros.

## Requisitos

- PHP 8.4 o superior.
- Composer.
- Symfony CLI, recomendado para desarrollo local.
- Una base de datos compatible con Doctrine.
- Una cuenta/bot de Telegram creado con BotFather.
- Una clave de API de OpenAI si se quiere activar la interpretación con OpenAI.
- ngrok u otra herramienta de túnel HTTPS para recibir webhooks en local.

## Instalación

Instala dependencias:

```bash
composer install
```

Crea el archivo local de variables de entorno:

```bash
cp .env .env.local
```

En Windows PowerShell:

```powershell
Copy-Item .env .env.local
```

Edita `.env.local` con tus valores reales. No pongas secretos reales en `.env`, porque ese archivo puede formar parte del repositorio.

## Variables de entorno

Variables usadas por el proyecto:

```dotenv
APP_ENV=dev
APP_SECRET=change-me
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data.db"
TELEGRAM_BOT_TOKEN="123456:telegram-token"
TELEGRAM_CHAT_ID="123456789"
OPENAI_API_KEY="sk-..."
```

Notas:

- `TELEGRAM_BOT_TOKEN` es el token del bot entregado por BotFather.
- `TELEGRAM_CHAT_ID` se usa para rutas de prueba como `/test-telegram`.
- `OPENAI_API_KEY` permite usar `OpenAiCommandInterpreter`.
- `DATABASE_URL` define la conexión de Doctrine. Para desarrollo local, SQLite en `var/data.db` es una opción simple.
- `.env.local` ya está ignorado por Git.

## Base de datos

Si usas SQLite local:

```dotenv
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data.db"
```

Ejecuta las migraciones:

```bash
php bin/console doctrine:migrations:migrate
```

Para revisar el estado:

```bash
php bin/console doctrine:migrations:status
```

## Ejecutar Symfony localmente

Con Symfony CLI:

```bash
symfony server:start
```

Normalmente la aplicación quedará disponible en:

```text
https://127.0.0.1:8000
```

También puedes usar el servidor embebido de PHP:

```bash
php -S 127.0.0.1:8000 -t public
```

Rutas útiles:

- `POST /telegram/webhook`: webhook principal de Telegram.
- `GET /telegram/check`: consulta mensajes con `getUpdates()`.
- `GET /test-telegram`: envía un mensaje de prueba al `TELEGRAM_CHAT_ID`.
- `GET /home`: página básica de Symfony.

## Ejecutar ngrok

Telegram necesita una URL pública HTTPS para enviar webhooks. En desarrollo local puedes usar ngrok.

Si Symfony está corriendo en el puerto `8000`:

```bash
ngrok http 8000
```

ngrok mostrará una URL pública parecida a:

```text
https://abc123.ngrok-free.app
```

La URL completa del webhook será:

```text
https://abc123.ngrok-free.app/telegram/webhook
```

Cada vez que reinicies ngrok en el plan gratuito, la URL puede cambiar. Si cambia, hay que volver a registrar el webhook en Telegram.

## Registrar el webhook de Telegram

Sustituye los valores por tu token y URL pública:

```bash
curl "https://api.telegram.org/bot<TU_TELEGRAM_BOT_TOKEN>/setWebhook?url=https://abc123.ngrok-free.app/telegram/webhook"
```

Para comprobar el webhook registrado:

```bash
curl "https://api.telegram.org/bot<TU_TELEGRAM_BOT_TOKEN>/getWebhookInfo"
```

Para eliminar el webhook:

```bash
curl "https://api.telegram.org/bot<TU_TELEGRAM_BOT_TOKEN>/deleteWebhook"
```

Si usas PowerShell, `curl` puede apuntar a `Invoke-WebRequest`. Esta forma evita ambigüedades:

```powershell
Invoke-RestMethod "https://api.telegram.org/bot<TU_TELEGRAM_BOT_TOKEN>/getWebhookInfo"
```

## Configurar OpenAI

1. Crea una clave de API desde tu cuenta de OpenAI.
2. Guárdala en `.env.local`:

```dotenv
OPENAI_API_KEY="sk-..."
```

3. Reinicia el servidor Symfony si ya estaba arrancado.
4. Prueba desde Telegram con una frase natural:

```text
acuérdate de comprar café mañana
```

También puedes probar el modo de depuración del bot:

```text
/debug-ia acuérdate de comprar café mañana
```

Ese comando consulta OpenAI y devuelve cómo interpreta el texto.

## Seguridad y secretos

- No subas `.env.local` al repositorio.
- No pegues tokens reales en issues, commits, chats o capturas.
- No escribas `TELEGRAM_BOT_TOKEN` ni `OPENAI_API_KEY` directamente en código PHP.
- Si expones un token por error, revócalo y genera uno nuevo.
- Usa variables de entorno reales en producción, no archivos compartidos manualmente.
- Revisa `git status` antes de hacer commit.
- Revisa `git diff` para confirmar que no aparece ningún secreto.
- Mantén `var/`, bases de datos locales y archivos `.db` fuera de Git.

Comandos útiles antes de publicar cambios:

```bash
git status
git diff
```

## Pruebas

El proyecto contiene tests de PHPUnit para los intérpretes y servicios principales.

Para ejecutar la suite:

```bash
php bin/phpunit
```

## Notas de mantenimiento

- El controlador del webhook concentra bastante orquestación. Una mejora segura sería mover el procesamiento de updates a un servicio de aplicación.
- `BotCommandHandler` mezcla parsing, persistencia y formato de respuestas. Puede separarse gradualmente en servicios de tareas, notas y formateadores.
- Las respuestas de OpenAI deberían validarse de forma estricta antes de ejecutarse como comandos internos.
- Para producción, conviene revisar la configuración HTTP y TLS de las llamadas externas.
