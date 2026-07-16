<?php
/**
 * core/PushService.php
 *
 * Servicio de Web Push Notifications usando VAPID (RFC 8292).
 * Requiere: minishlink/web-push (instalado vía Composer).
 *
 * Uso rápido:
 *   PushService::notify($user_id, 'Pago validado', 'S/ 1,200 validados', $url, 'pago_validado');
 *
 * La tabla push_subscriptions se crea automáticamente en la primera llamada.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

class PushService
{
    private static bool $_tableReady = false;

    private static function ensureTable(): void
    {
        if (self::$_tableReady) return;
        try {
            Database::get()->exec("
                CREATE TABLE IF NOT EXISTS push_subscriptions (
                    id         INT AUTO_INCREMENT PRIMARY KEY,
                    user_id    INT          NOT NULL,
                    endpoint   TEXT         NOT NULL,
                    p256dh     VARCHAR(512) NOT NULL,
                    auth       VARCHAR(255) NOT NULL,
                    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_ps_user (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            self::$_tableReady = true;
        } catch (\Throwable $e) {
            error_log('[PushService] ensureTable error: ' . $e->getMessage());
        }
    }

    private static function auth(): array
    {
        return [
            'VAPID' => [
                'subject'    => APP_URL,
                'publicKey'  => VAPID_PUBLIC_KEY,
                'privateKey' => VAPID_PRIVATE_KEY,
            ],
        ];
    }

    /**
     * Envía una notificación push a todos los dispositivos suscritos de un usuario.
     *
     * @param int    $userId  ID del usuario destinatario
     * @param string $title   Título de la notificación
     * @param string $body    Cuerpo del mensaje
     * @param string $url     URL a abrir al tocar la notificación (opcional)
     * @param string $tag     Tag para agrupar/reemplazar notificaciones similares (opcional)
     */
    public static function notify(int $userId, string $title, string $body, string $url = '', string $tag = ''): void
    {
        if (!VAPID_PUBLIC_KEY || !VAPID_PRIVATE_KEY) {
            error_log('[PushService] VAPID keys not configured');
            return;
        }

        self::ensureTable();
        $pdo = Database::get();

        $stmt = $pdo->prepare("SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($rows)) {
            // No es un error — el usuario simplemente no ha suscrito notificaciones aún
            error_log("[PushService] user_id={$userId} no tiene suscripciones push registradas");
            return;
        }

        // En iOS, el OS añade "from [short_name]" entre el título y el cuerpo de forma automática.
        // Para que la empresa sea el texto más prominente, usamos la parte después de " — " como título
        // (nombre de empresa) y la parte anterior (evento) como primera línea del cuerpo.
        // Formato esperado del $title: "Evento — Nombre Empresa"
        $titleParts = explode(' — ', $title, 2);
        if (isset($titleParts[1])) {
            $notifTitle = trim($titleParts[1]); // Empresa → título visible en negrita
            $notifSub   = trim($titleParts[0]); // Evento  → primera línea del cuerpo
        } else {
            $notifTitle = trim($titleParts[0]); // Sin empresa: el evento va al título
            $notifSub   = '';
        }

        // Si el body empieza con el nombre de la empresa, eliminarlo para evitar duplicado
        $notifBody = $body;
        $empresaNombre = isset($titleParts[1]) ? trim($titleParts[1]) : '';
        if ($empresaNombre !== '' && mb_stripos($notifBody, $empresaNombre) === 0) {
            $notifBody = ltrim(mb_substr($notifBody, mb_strlen($empresaNombre)));
            $notifBody = ltrim($notifBody, " \t—-");
            if ($notifBody !== '') {
                $notifBody = mb_strtoupper(mb_substr($notifBody, 0, 1)) . mb_substr($notifBody, 1);
            }
        }

        $payload = json_encode([
            'title'    => $notifTitle ?: 'GGI Sistema',
            'subtitle' => $notifSub,
            'body'     => $notifBody,
            'url'      => $url ?: APP_URL . '/modules/dashboard/index.php',
            'icon'     => APP_URL . '/assets/icons/icon-192.png',
            'badge'    => APP_URL . '/assets/icons/icon-192.png',
            'tag'      => $tag ?: ('ggi-' . substr(md5($title . $body), 0, 8)),
        ], JSON_UNESCAPED_UNICODE);

        try {
            $webPush = new WebPush(self::auth(), ['TTL' => 3600 * 24]);

            $endpointToId = [];
            foreach ($rows as $row) {
                $endpointToId[$row['endpoint']] = $row['id'];
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $row['endpoint'],
                        'keys'     => ['p256dh' => $row['p256dh'], 'auth' => $row['auth']],
                    ]),
                    $payload
                );
            }

            foreach ($webPush->flush() as $report) {
                $ep = (string)$report->getRequest()->getUri();
                if ($report->isSubscriptionExpired()) {
                    error_log("[PushService] Suscripción expirada para user_id={$userId}, eliminando");
                    if (isset($endpointToId[$ep])) {
                        $pdo->prepare("DELETE FROM push_subscriptions WHERE id = ?")
                            ->execute([$endpointToId[$ep]]);
                    }
                } elseif (!$report->isSuccess()) {
                    $code = $report->getResponse() ? $report->getResponse()->getStatusCode() : 'N/A';
                    error_log("[PushService] Push fallido para user_id={$userId}: HTTP {$code} — {$report->getReason()}");
                }
            }
        } catch (\Throwable $e) {
            error_log('[PushService] notify() excepción: ' . $e->getMessage() . ' en ' . basename($e->getFile()) . ':' . $e->getLine());
        }
    }

    /**
     * Registra (o actualiza) una suscripción push para el usuario actual.
     * Idempotente: si el endpoint ya existe para ese usuario, no crea duplicado.
     */
    public static function subscribe(int $userId, string $endpoint, string $p256dh, string $auth): bool
    {
        if (!VAPID_PUBLIC_KEY) return false;
        self::ensureTable();
        try {
            $pdo = Database::get();

            // Eliminar suscripciones del mismo endpoint pertenecientes a otro usuario
            $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint = ? AND user_id != ?")
                ->execute([$endpoint, $userId]);

            // Verificar si ya existe para este usuario+endpoint exacto
            $existing = $pdo->prepare("SELECT id FROM push_subscriptions WHERE user_id = ? AND endpoint = ?");
            $existing->execute([$userId, $endpoint]);

            if (!$existing->fetch()) {
                $pdo->prepare("
                    INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth)
                    VALUES (?, ?, ?, ?)
                ")->execute([$userId, $endpoint, $p256dh, $auth]);
                error_log("[PushService] Nueva suscripción registrada para user_id={$userId}");
            }
            return true;
        } catch (\Throwable $e) {
            error_log('[PushService] subscribe() excepción: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Elimina una suscripción (cuando el usuario desactiva notificaciones).
     */
    public static function unsubscribe(int $userId, string $endpoint): void
    {
        self::ensureTable();
        try {
            Database::get()->prepare("DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?")
                ->execute([$userId, $endpoint]);
        } catch (\Throwable $e) {
            error_log('[PushService] unsubscribe() excepción: ' . $e->getMessage());
        }
    }

    /**
     * Devuelve la clave pública VAPID para el frontend (applicationServerKey).
     */
    public static function publicKey(): string
    {
        return VAPID_PUBLIC_KEY;
    }
}
