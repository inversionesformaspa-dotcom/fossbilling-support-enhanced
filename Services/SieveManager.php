<?php
declare(strict_types=1);
/**
 * Copyright (C) 2026 Víctor Fornés para Inversiones Forma SPA.
 * Contribuciones de DeepSeek AI, 2026.
 *
 * Las modificaciones a este archivo se distribuyen bajo los términos
 * de la GNU Affero General Public License v3.0 o posterior (AGPL-3.0-or-later).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace Box\Mod\Support\Services;

use FOSSBilling\InjectionAwareInterface;
use function explode;
use function implode;
use function strtolower;
use function strpos;
use function count;
use function error_log;
use function filter_var;
use function is_array;

/**
 * SieveManager — gestiona filtros Sieve via protocolo ManageSieve (puerto 4190)
 * No requiere acceso directo al filesystem ni sudo.
 * Usa las credenciales de cada cuenta de correo almacenadas en support_helpdesk.
 */
class SieveManager implements InjectionAwareInterface
{
    private const SIEVE_HOST = '127.0.0.1';
    private const SIEVE_PORT = 4190;
    private const SCRIPT_NAME = 'fossbilling_support';

    protected $di = null;

    public function setDi($di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }
	/**
	 * Verificar si Sieve/ManageSieve está disponible en el sistema
	 */
	public function isAvailable(): bool
	{
	    // Intentar conectar al puerto ManageSieve
	    $socket = @\fsockopen(self::SIEVE_HOST, self::SIEVE_PORT, $errno, $errstr, 5);
	    if ($socket) {
	        \fclose($socket);
	        return true;
	    }
	    return false;
	}
    // ================================================================
    // MÉTODO PRINCIPAL — Guardar script Sieve via ManageSieve
    // ================================================================

    /**
     * Conectar a ManageSieve y guardar/activar un script
     * Usa las credenciales de la cuenta de correo del helpdesk
     */
    private function saveSieveScript(string $email, string $password, string $script_content): bool
    {
        $socket = null;

        try {
            // Cargar autoloader del módulo si existe
            $autoloader = __DIR__ . '/../vendor/autoload.php';
            if (\file_exists($autoloader)) {
                require_once $autoloader;
            }

            // Verificar si la librería arnonerba/managesieve-php está disponible
            if (\class_exists('\ArnonErba\ManageSieve\Client')) {
                return $this->saveSieveScriptViaLibrary($email, $password, $script_content);
            }

            // Fallback: implementación directa via socket (sin librería externa)
            return $this->saveSieveScriptViaSocket($email, $password, $script_content);

        } catch (\Exception $e) {
            error_log("[SieveManager] Error en saveSieveScript para {$email}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Implementación usando la librería arnonerba/managesieve-php
     */
    private function saveSieveScriptViaLibrary(string $email, string $password, string $script_content): bool
    {
        $client = new \ArnonErba\ManageSieve\Client(self::SIEVE_HOST, self::SIEVE_PORT);

        $client->connect();
        $client->login($email, $password);
        $client->putScript(self::SCRIPT_NAME, $script_content);
        $client->setActive(self::SCRIPT_NAME);

        error_log("[SieveManager] Script guardado via librería para {$email}");
        return true;
    }

    /**
     * Implementación directa via socket — no requiere librería externa
     * Implementa el protocolo ManageSieve (RFC 5804) básico
     */
    private function saveSieveScriptViaSocket(string $email, string $password, string $script_content): bool
    {
        $errno  = 0;
        $errstr = '';

        // Conectar al servidor ManageSieve
        $socket = @\fsockopen(self::SIEVE_HOST, self::SIEVE_PORT, $errno, $errstr, 10);

        if (!$socket) {
            throw new \Exception("No se pudo conectar a ManageSieve " . self::SIEVE_HOST . ":" . self::SIEVE_PORT . " — {$errstr} ({$errno})");
        }

        \stream_set_timeout($socket, 10);

        // Leer greeting del servidor
        $greeting = $this->sieveReadResponse($socket);
        error_log("[SieveManager Socket] Greeting: " . \substr($greeting, 0, 100));

        // Autenticar con PLAIN
        $auth_string = \base64_encode("\0{$email}\0{$password}");
        $this->sieveWrite($socket, "AUTHENTICATE \"PLAIN\" \"{$auth_string}\"");
        $auth_response = $this->sieveReadResponse($socket);

        if (\stripos($auth_response, 'NO') === 0 || \stripos($auth_response, 'BYE') === 0) {
            \fclose($socket);
            throw new \Exception("Autenticación ManageSieve rechazada para {$email}: {$auth_response}");
        }

        error_log("[SieveManager Socket] Autenticado: {$email}");

        // Subir el script
        $script_len = \strlen($script_content);
        $this->sieveWrite($socket, "PUTSCRIPT \"" . self::SCRIPT_NAME . "\" {{$script_len}+}");
        $this->sieveWrite($socket, $script_content);
        $put_response = $this->sieveReadResponse($socket);

        if (\stripos($put_response, 'NO') === 0) {
            \fclose($socket);
            throw new \Exception("Error subiendo script Sieve: {$put_response}");
        }

        // Activar el script
        $this->sieveWrite($socket, "SETACTIVE \"" . self::SCRIPT_NAME . "\"");
        $active_response = $this->sieveReadResponse($socket);

        if (\stripos($active_response, 'NO') === 0) {
            \fclose($socket);
            throw new \Exception("Error activando script Sieve: {$active_response}");
        }

        // Cerrar conexión limpia
        $this->sieveWrite($socket, "LOGOUT");
        \fclose($socket);

        error_log("[SieveManager Socket] Script activado correctamente para {$email}");
        return true;
    }

    /**
     * Escribir línea al socket ManageSieve
     */
    private function sieveWrite($socket, string $line): void
    {
        \fwrite($socket, $line . "\r\n");
    }

    /**
     * Leer respuesta completa del servidor ManageSieve
     */
    private function sieveReadResponse($socket): string
    {
        $response = '';
        while (!\feof($socket)) {
            $line = \fgets($socket, 1024);
            if ($line === false) break;
            $response .= $line;
            // El protocolo ManageSieve termina respuestas con OK, NO o BYE
            $trimmed = \trim($line);
            if (\preg_match('/^(OK|NO|BYE)(\s|$)/i', $trimmed)) {
                break;
            }
        }
        return $response;
    }

    // ================================================================
    // MÉTODOS PÚBLICOS
    // ================================================================

    /**
     * Actualizar script Sieve para un helpdesk específico
     * Genera el contenido según la whitelist y lo sube via ManageSieve
     */
    public function updateSieveFile(int $helpdesk_id): bool
    {
        try {
            $helpdesk = $this->getHostFromId($helpdesk_id);

            if (!$helpdesk) {
                error_log("[SieveManager] HelpDesk #{$helpdesk_id} no encontrado");
                return false;
            }

            if (empty($helpdesk->email_address)) {
                error_log("[SieveManager] HelpDesk #{$helpdesk_id} sin email_address");
                return false;
            }

            // Obtener contraseña POP3 (es la misma cuenta de correo)
		// AHORA:
		$supportService = $this->di['mod_service']('support');
		$password = $supportService->decryptPassword($helpdesk->pop3_password ?? '');

            if (empty($password)) {
                error_log("[SieveManager] HelpDesk #{$helpdesk_id} sin contraseña configurada");
                return false;
            }

            // Generar contenido del script
            $script_content = $this->generateSieveContent($helpdesk_id);

            // Subir via ManageSieve
            $result = $this->saveSieveScript($helpdesk->email_address, $password, $script_content);

            if ($result) {
                // Actualizar estado en BD
                $this->di['db']->exec(
                    "UPDATE support_helpdesk SET sieve_active = 1, sieve_updated_at = NOW() WHERE id = ?",
                    [$helpdesk_id]
                );
                error_log("[SieveManager] Sieve actualizado: {$helpdesk->email_address}");
                return true;
            }

            return false;

        } catch (\Exception $e) {
            error_log("[SieveManager] updateSieveFile #{$helpdesk_id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Reiniciar filtros básicos para todas las cuentas
     */
    public function resetBasicFilters(): bool
    {  
        $success_count = 0;
        $helpdesks     = $this->getHostsFromDB();

        if (empty($helpdesks)) {
            error_log("[SieveManager Reset] No hay helpdesks con email habilitado");
            return false;
        }

        foreach ($helpdesks as $desk) {
            try {
                if ($this->updateSieveFile((int)$desk->id)) {
                    $success_count++;
                    error_log("[SieveManager Reset] OK: {$desk->email_address}");
                }
            } catch (\Exception $e) {
                error_log("[SieveManager Reset] Error en {$desk->email_address}: " . $e->getMessage());
            }
        }

        error_log("[SieveManager Reset] Completado: {$success_count}/" . count($helpdesks) . " cuentas");
        return $success_count > 0;
    }

    /**
     * Añadir email a whitelist y actualizar Sieve automáticamente
     */
    public function addToWhitelist(string $email, int $helpdesk_id, string $type = 'client'): bool
    {
        try {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \Exception('Email inválido: ' . $email);
            }

            $helpdesk = $this->getHostFromId($helpdesk_id);
            if (!$helpdesk) {
                throw new \Exception("HelpDesk #{$helpdesk_id} no encontrado");
            }

            // Verificar si ya existe
            $existing = $this->di['db']->findOne(
                'support_whitelist',
                'email = ? AND helpdesk_id = ?',
                [$email, $helpdesk_id]
            );

            if ($existing) {
                // Reactivar si estaba inactivo
                $this->di['db']->exec(
                    "UPDATE support_whitelist SET status = 'active' WHERE email = ? AND helpdesk_id = ?",
                    [$email, $helpdesk_id]
                );
            } else {
                // Insertar nuevo
                $this->di['db']->exec(
                    "INSERT INTO support_whitelist (email, helpdesk_id, type, status, created_at)
                    VALUES (?, ?, ?, 'active', NOW())",
                    [$email, $helpdesk_id, $type]
                );
            }

            error_log("[SieveManager] Whitelist añadido: {$email} -> HelpDesk #{$helpdesk_id}");

            // Actualizar Sieve automáticamente
            $this->updateSieveFile($helpdesk_id);

            return true;

        } catch (\Exception $e) {
            error_log("[SieveManager] addToWhitelist: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Eliminar email de whitelist y actualizar Sieve automáticamente
     */
    public function removeFromWhitelist(string $email, int $helpdesk_id): bool
    {
        try {
            $this->di['db']->exec(
                "DELETE FROM support_whitelist WHERE email = ? AND helpdesk_id = ?",
                [$email, $helpdesk_id]
            );

            error_log("[SieveManager] Whitelist eliminado: {$email} <- HelpDesk #{$helpdesk_id}");

            $this->updateSieveFile($helpdesk_id);

            return true;

        } catch (\Exception $e) {
            error_log("[SieveManager] removeFromWhitelist: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtener whitelists formateadas para la vista admin
     */
    public function getWhitelistsForView(): array
    {
        $raw_whitelist = $this->getWhitelistFromDB();
        $items = [];

        foreach ($raw_whitelist as $helpdesk_id => $entries) {
            $helpdesk      = $this->getHostFromId((int)$helpdesk_id);
            $account_email = $helpdesk ? $helpdesk->email_address : 'Desconocido';

            foreach ($entries as $entry) {
                $items[] = [
                    'email'         => $entry['email'],
                    'account_id'    => $helpdesk_id,
                    'account_email' => $account_email,
                    'helpdesk_id'   => (int)$helpdesk_id,
                    'type'          => $entry['type'] ?? 'client',
                    'created_at'    => $entry['created_at'],
                ];
            }
        }

        return $items;
    }

    /**
     * Obtener cuentas con información Sieve para la vista admin
     * Lee el estado desde BD — sin acceso al filesystem
     */
    public function getAccountsWithSieve(): array
    {
    $helpdesks = $this->getHostsFromDB();
    error_log("[SieveManager] getAccountsWithSieve: helpdesks count = " . count($helpdesks));
    
    $accounts = [];
        foreach ($helpdesks as $desk) {
        $account_id = $this->getAccountFromHelpdesk((int)$desk->id);
        error_log("[SieveManager] desk #{$desk->id} email={$desk->email_address} account_id={$account_id}");
        
        if (!$account_id) {
            continue;
        }
        $accounts[(int)$desk->id] = [
            'helpdesk_id'   => (int)$desk->id,
            'helpdesk_name' => $desk->name,
            'account_id'    => $account_id,
            'email'         => $desk->email_address,
            'has_sieve'     => !empty($desk->sieve_active),
            'updated_at'    => $desk->sieve_updated_at ?? 'Nunca',
        ];
        }
    error_log("[SieveManager] accounts final count = " . count($accounts));
    return $accounts;
    }

    /**
     * Sincronizar todos los scripts Sieve con la whitelist actual en BD
     */
    public function syncAllSieveWithDB(): int
    {
        $success_count = 0;
        $helpdesks     = $this->getHostsFromDB();

        foreach ($helpdesks as $desk) {
            if ($this->updateSieveFile((int)$desk->id)) {
                $success_count++;
            }
        }

        error_log("[SieveManager] Sync completado: {$success_count}/" . count($helpdesks));
        return $success_count;
    }

    // ================================================================
    // MÉTODOS PRIVADOS / AUXILIARES
    // ================================================================

    private function getHostFromId(int $helpdesk_id): ?object
    {
        if (!$this->di || !$this->di['db']) {
            return null;
        }
        return $this->di['db']->findOne('support_helpdesk', "id = ?", [$helpdesk_id]);
    }

    public function getHostsFromDB(): array
    {
        if (!$this->di || !$this->di['db']) {
            throw new \Exception('DI container no configurado');
        }
        return $this->di['db']->find('support_helpdesk', "enable_email = 1 ORDER BY id ASC");
    }

    public function getAccountFromHelpdesk(int $helpdesk_id): ?string
    {
        $helpdesk = $this->getHostFromId($helpdesk_id);
        if (!$helpdesk || empty($helpdesk->email_address)) {
            return null;
        }
        $parts = explode('@', $helpdesk->email_address);
        return !empty($parts[0]) ? $parts[0] : null;
    }

    /**
     * Actualizar los filtros Sieve de todas las mesas tipo "clients"
     * cuando se agrega o elimina un cliente
     */
    public function updateAllClientFilters(): int
    {
    $helpdesks = $this->getHostsFromDB();
    $count = 0;
    
    foreach ($helpdesks as $desk) {
        $access = strtolower(trim($desk->access_level ?? 'public'));
        if ($access === 'clients' || $access === 'hybrid') {
            if ($this->updateSieveFile((int)$desk->id)) {
                $count++;
                }
            }
    	}
    
        error_log("[SieveManager] Filtros de clientes actualizados: {$count} mesas");
        return $count;
        }

    public function getWhitelistFromDB(): array
    {
        if (!$this->di || !$this->di['db']) {
            throw new \Exception('DI container no configurado');
        }

        $results = $this->di['db']->getAll(
            "SELECT email, helpdesk_id, type, status, created_at
             FROM support_whitelist
             WHERE status = 'active'
             ORDER BY helpdesk_id, email"
        );

        $whitelist = [];
        foreach ($results as $row) {
            $whitelist[$row['helpdesk_id']][] = [
                'email'      => $row['email'],
                'type'       => $row['type'],
                'status'     => $row['status'],
                'created_at' => $row['created_at'],
            ];
        }

        return $whitelist;
    }
private function getSpamKeywords(?int $helpdesk_id = null): array
{
    $sql = "SELECT keyword, type FROM support_spam_keywords WHERE ";
    $bindings = [];
    if ($helpdesk_id) {
        $sql .= "helpdesk_id = ? OR helpdesk_id IS NULL";
        $bindings[] = $helpdesk_id;
    } else {
        $sql .= "helpdesk_id IS NULL";
    }
    return $this->di['db']->getAll($sql, $bindings);
}
    /**
     * Generar contenido del script Sieve según whitelist y tipo de helpdesk
     */
    private function generateSieveContent(int $helpdesk_id): string
    {
        $helpdesk = $this->getHostFromId($helpdesk_id);
 
        if (!$helpdesk) {
            return "require [\"fileinto\", \"mailbox\"];\nkeep;\n";
        }
 
        $access = strtolower(trim($helpdesk->access_level ?? 'public'));
 
        // ============================================================
        // INICIO DEL SCRIPT
        // ============================================================
        $content  = "require [\"fileinto\", \"mailbox\"];\n\n";
        $content .= "/* Generado por FOSSBilling Support Module */\n";
        $content .= "/* Mesa: {$helpdesk->name} | Acceso: {$access} */\n\n";
 
        // ============================================================
        // BLOQUE 1 — Filtros anti-spam (siempre activos, todas las mesas)
        // ============================================================
        $content .= "/* === FILTROS ANTI-SPAM === */\n";
        $content .= "if header :contains \"Subject\" \"Acuse de recibo\" { discard; }\n";
        $content .= "if header :contains \"Subject\" \"Delivery Status Notification\" { discard; }\n";
        $content .= "if header :contains \"Subject\" \"Out of Office\" { discard; }\n";
        $content .= "if header :contains \"Subject\" \"Respuesta Automática\" { discard; }\n";
        $content .= "if header :contains \"Subject\" \"Auto-Reply\" { discard; }\n";
        $content .= "if header :contains \"From\" \"Mailer Daemon\" { discard; }\n";
        $content .= "if header :contains \"From\" \"Mail Delivery Subsystem\" { discard; }\n";
        $content .= "if header :contains \"From\" \"postmaster@\" { discard; }\n";
        $content .= "if header :contains \"From\" \"noreply@\" { discard; }\n";
        $content .= "if header :contains \"From\" \"no-reply@\" { discard; }\n\n";
 
 	// Filtros anti-spam personalizados (desde BD)
	$keywords = $this->getSpamKeywords($helpdesk_id);
	foreach ($keywords as $kw) {
    		$word = trim($kw['keyword']);
    		$type = $kw['type'];
    		if (empty($word)) continue;
    		if ($type === 'subject') {
        		$content .= "if header :contains \"Subject\" \"{$word}\" { discard; }\n";
    		} elseif ($type === 'from') {
        		$content .= "if header :contains \"From\" \"{$word}\" { discard; }\n";
    		} elseif ($type === 'body') {
        		$content .= "if body :contains \"{$word}\" { discard; }\n";
    		} elseif ($type === 'header') {
        		$content .= "if header :contains \"{$word}\" { discard; }\n";
    		}
	} 
        // ============================================================
        // BLOQUE 2 — Lista negra (siempre antes de whitelist)
        // Aplica emails bloqueados globales + específicos de esta mesa
        // ============================================================
        try {
            $blacklist = $this->di['db']->getAll(
                "SELECT DISTINCT email FROM support_blacklist 
                 WHERE status = 'active' 
                 AND (helpdesk_id = ? OR helpdesk_id IS NULL)
                 ORDER BY email",
                [$helpdesk_id]
            );
 
            if (!empty($blacklist)) {
                $content .= "/* === LISTA NEGRA === */\n";
                foreach ($blacklist as $blocked) {
    			$email = trim($blocked['email'] ?? '');
    			if (empty($email)) continue;
			    if (strpos($email, '*') !== false) {
        			// Bloquear todo el dominio
        			$content .= "if address :matches \"From\" \"{$email}\" { discard; }\n";
    				} elseif (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        			$content .= "if address :is \"From\" \"{$email}\" { discard; }\n";
    				}
		}
                $content .= "\n";
                error_log("[SieveManager] #{$helpdesk_id} blacklist: " . count($blacklist) . " emails bloqueados");
            }
        } catch (\Exception $e) {
            // Si la tabla no existe aún, continuar sin blacklist
            error_log("[SieveManager] #{$helpdesk_id} blacklist no disponible: " . $e->getMessage());
        }
 
        // ============================================================
        // BLOQUE 3 — Whitelist manual (aplica a TODAS las mesas)
        // Emails agregados manualmente siempre tienen prioridad
        // ============================================================
        try {
            $whitelist     = $this->getWhitelistFromDB();
            $manual_emails = $whitelist[$helpdesk_id] ?? [];
 
            if (!empty($manual_emails)) {
                $content .= "/* === WHITELIST MANUAL === */\n";
                foreach ($manual_emails as $entry) {
                    $email = trim($entry['email'] ?? '');
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $content .= "if address :is \"From\" \"{$email}\" { keep; }\n";
                    }
                }
                $content .= "\n";
                error_log("[SieveManager] #{$helpdesk_id} whitelist manual: " . count($manual_emails) . " emails");
            }
        } catch (\Exception $e) {
            error_log("[SieveManager] #{$helpdesk_id} whitelist error: " . $e->getMessage());
        }
 
        // ============================================================
        // BLOQUE 3.5 — Usuarios Autorizados en Configuración de Mesa
        // (aplica a todas las mesas que tengan usuarios autorizados)
        // ============================================================
        if (!empty($helpdesk->authorized_users)) {
            $authorized_users_emails = explode(',', $helpdesk->authorized_users);
            $authorized_users_emails = array_map('trim', $authorized_users_emails);
            $content .= "/* === USUARIOS AUTORIZADOS (CONFIG MESA) === */\n";
            foreach ($authorized_users_emails as $email) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $content .= "if address :is \"From\" \"{$email}\" { keep; }\n";
                }
            }
            $content .= "\n";
            error_log("[SieveManager] #{$helpdesk_id} authorized_users: " . count($authorized_users_emails) . " emails de usuarios autorizados");
        }

        // ============================================================
        // BLOQUE 3.6 — Staff Asignado a la Mesa (independiente del access_level)
        // Estos emails siempre deben ser 'keep' si están asignados a la mesa.
        // ============================================================
        if (!empty($helpdesk->assigned_staff)) {
            $staff_ids = array_filter(array_map('intval', explode(',', $helpdesk->assigned_staff)));
            if (!empty($staff_ids)) {
                $placeholders = implode(',', array_fill(0, count($staff_ids), '?'));
                $staff_list = $this->di['db']->getAll("SELECT email FROM admin WHERE id IN ({$placeholders}) AND status = 'active' AND email IS NOT NULL", $staff_ids);
                if (!empty($staff_list)) {
                    $content .= "/* === STAFF ASIGNADO (CONFIG MESA) === */\n";
                    foreach ($staff_list as $staff) {
                        $email = trim($staff['email']);
                        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $content .= "if address :is \"From\" \"{$email}\" { keep; }\n";
                        }
                    }
                    $content .= "\n";
                    error_log("[SieveManager] #{$helpdesk_id} assigned_staff: " . count($staff_list) . " miembros");
                }
            }
        }

        // ============================================================
        // BLOQUE 4 — Lógica según tipo de acceso
        // ============================================================
        error_log("[SieveManager] #{$helpdesk_id} access_level: '{$access}'");
 
        // MESA PÚBLICA — aceptar todo lo que no fue descartado arriba
        if ($access === 'public') {
            $content .= "/* === MESA PÚBLICA — aceptar todo === */\n";
            $content .= "keep;\n";
            return $content;
        }
 
        // MESA HÍBRIDA — clientes registrados + público general
        if ($access === 'hybrid') {
            // Agregar clientes registrados explícitamente (para completitud)
            try {
                $clients = $this->di['db']->getAll(
                    "SELECT email FROM client 
                     WHERE status = 'active' 
                     AND email IS NOT NULL AND email != '' 
                     ORDER BY email"
                );
                if (!empty($clients)) {
                    $content .= "/* === CLIENTES REGISTRADOS (híbrido) === */\n";
                    foreach ($clients as $client) {
                        $email = trim($client['email']);
                        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $content .= "if address :is \"From\" \"{$email}\" { keep; }\n";
                        }
                    }
                    $content .= "\n";
                }
            } catch (\Exception $e) {
                error_log("[SieveManager] #{$helpdesk_id} hybrid clients error: " . $e->getMessage());
            }
            $content .= "/* === MESA HÍBRIDA — aceptar resto === */\n";
            $content .= "keep;\n";
            return $content;
        }
 
        // MESA SOLO CLIENTES — solo emails de clientes activos en FOSSBilling
        if ($access === 'clients') {
            try {
                $clients = $this->di['db']->getAll(
                    "SELECT email FROM client 
                     WHERE status = 'active' 
                     AND email IS NOT NULL AND email != '' 
                     ORDER BY email"
                );
                error_log("[SieveManager] #{$helpdesk_id} clientes activos: " . count($clients));
 
                if (!empty($clients)) {
                    $content .= "/* === SOLO CLIENTES REGISTRADOS === */\n";
                    foreach ($clients as $client) {
                        $email = trim($client['email']);
                        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $content .= "if address :is \"From\" \"{$email}\" { keep; }\n";
                        }
                    }
                    $content .= "\n";
                } else {
                    $content .= "/* Sin clientes registrados */\n";
                }
            } catch (\Exception $e) {
                error_log("[SieveManager] #{$helpdesk_id} clients error: " . $e->getMessage());
            }
 
            $content .= "/* === Todo lo demás se descarta === */\n";
            $content .= "discard;\n";
            return $content;
        }
 
        // MESA SOLO STAFF — staff asignado + whitelist manual
        if ($access === 'staff') {
            try {
                if (!empty($helpdesk->assigned_staff)) {
                    $staff_ids = array_filter(
                        array_map('intval', explode(',', $helpdesk->assigned_staff))
                    );
 
                    if (!empty($staff_ids)) {
                        $placeholders = implode(',', array_fill(0, count($staff_ids), '?'));
                        $staff_list   = $this->di['db']->getAll(
                            "SELECT email FROM admin 
                             WHERE id IN ({$placeholders}) 
                             AND status = 'active' 
                             AND email IS NOT NULL",
                            $staff_ids
                        );
 
                        if (!empty($staff_list)) {
                            $content .= "/* === STAFF ASIGNADO === */\n";
                            foreach ($staff_list as $staff) {
                                $email = trim($staff['email']);
                                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                    $content .= "if address :is \"From\" \"{$email}\" { keep; }\n";
                                }
                            }
                            $content .= "\n";
                            error_log("[SieveManager] #{$helpdesk_id} staff asignado: " . count($staff_list) . " miembros");
                        }
                    }
                }
            } catch (\Exception $e) {
                error_log("[SieveManager] #{$helpdesk_id} staff error: " . $e->getMessage());
            }
 
            $content .= "/* === Todo lo demás se descarta === */\n";
            $content .= "discard;\n";
            return $content;
        }
 
        // Fallback — si access_level tiene un valor desconocido, aceptar todo
        error_log("[SieveManager] #{$helpdesk_id} access_level desconocido '{$access}' — usando keep");
        $content .= "/* Access level desconocido — aceptar todo */\n";
        $content .= "keep;\n";
 
        return $content;
    }
}
