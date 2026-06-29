<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenAiCommandInterpreter
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $openaiApiKey
    ) {
    }

    public function interpret(string $text): string
    {
        $text = trim($text);
        $normalizedText = mb_strtolower($text);

        if (str_starts_with($text, '/')) {
            return $text;
        }

        // Prueba directa para confirmar que este archivo se está usando.
        if (
            str_starts_with($normalizedText, 'acuérdate de ') ||
            str_starts_with($normalizedText, 'acuerdate de ')
        ) {
            $task = preg_replace('/^acuérdate de |^acuerdate de /iu', '', $text);
            return '/tarea ' . trim($task);
        }

        if (
            str_starts_with($normalizedText, 'recuérdame ') ||
            str_starts_with($normalizedText, 'recuerdame ')
        ) {
            $task = preg_replace('/^recuérdame |^recuerdame /iu', '', $text);
            return '/tarea ' . trim($task);
        }

        $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/responses', [
            'verify_peer' => false,
            'verify_host' => false,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->openaiApiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => 'gpt-4.1-mini',
                'temperature' => 0,
                'instructions' => "Eres un convertidor de mensajes naturales a comandos internos para un bot llamado Marcenio Assistant.

Devuelve SOLO uno de estos comandos:

/tarea texto de la tarea
/tareas
/hecha ID
/borrar-tarea ID
/nota texto de la nota
/notas
/borrar-nota ID
/ayuda

Reglas:
- Si el usuario dice que se acuerde de algo, que le recuerde algo, que tiene que hacer algo, que apunte algo o que añada algo pendiente, devuelve /tarea.
- Si el usuario quiere guardar una idea, pensamiento o información, devuelve /nota.
- Si pregunta por tareas, pendientes o cosas que tiene que hacer, devuelve /tareas.
- Si pregunta por notas o ideas guardadas, devuelve /notas.
- Si quiere marcar una tarea como hecha y da un número, devuelve /hecha ID.
- Si quiere borrar una tarea y da un número, devuelve /borrar-tarea ID.
- Si quiere borrar una nota y da un número, devuelve /borrar-nota ID.
- Solo devuelve /ayuda si no hay ninguna intención clara.

Ejemplos:
acuérdate de comprar café mañana => /tarea comprar café mañana
recuérdame llamar al dentista => /tarea llamar al dentista
tengo que revisar el proyecto => /tarea revisar el proyecto
guarda una idea para conectar WhatsApp => /nota conectar WhatsApp
qué tareas tengo pendientes => /tareas
enséñame mis notas => /notas
marca como hecha la tarea 3 => /hecha 3
borra la tarea 4 => /borrar-tarea 4
elimina la nota 2 => /borrar-nota 2",
                'input' => $text,
            ],
        ]);

        $data = $response->toArray(false);

        return trim($data['output'][0]['content'][0]['text'] ?? '/ayuda');
    }
}