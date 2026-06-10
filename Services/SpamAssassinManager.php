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

class SpamAssassinManager implements InjectionAwareInterface
{
    protected $di = null;

    public function setDi($di): void { $this->di = $di; }
    public function getDi(): ?\Pimple\Container { return $this->di; }

    // ================================================================
    // DETECCIÓN
    // ================================================================

    public function isAvailable(): bool
	{
    		if (!function_exists('exec')) {
        		return false;
    		}
    		$output = []; $returnCode = 0;
    		exec('which spamassassin 2>/dev/null', $output, $returnCode);
    		if ($returnCode !== 0) exec('which spamc 2>/dev/null', $output, $returnCode);
    		return $returnCode === 0;
	}

    public function getVersion(): string
	{
    		if (!function_exists('exec')) {
        	return 'Desconocida (exec desactivado)';
    		}
    		$output = [];
    		exec('spamassassin --version 2>/dev/null | head -1', $output);
    		return $output[0] ?? 'Desconocida';
	}

	public static function detectFilterSystem(): string
	{
	    // Sieve no necesita exec (usa socket)
	    $sieve = new SieveManager();
	    if ($sieve->isAvailable()) {
	        return 'sieve';
	    }
	    // SpamAssassin solo si exec está disponible
	    if (function_exists('exec')) {
	        $sa = new self();
	        if ($sa->isAvailable()) {
	            return 'spamassassin';
	        }
	    }
	    return 'none';
	}

    // ================================================================
    // GESTIÓN DE REGLAS
    // ================================================================

    public function updateRules(int $helpdesk_id): bool
    {
        if (!function_exists('exec')) {
        error_log("[SpamAssassin] exec() desactivada, no se pueden actualizar reglas");
        return false;
    	}
        try {
            $helpdesk = $this->getHostFromId($helpdesk_id);
            if (!$helpdesk || empty($helpdesk->email_address)) return false;

            $email = $helpdesk->email_address;
            $configDir = $this->getUserConfigDir($email);
            
            if (!is_dir($configDir)) {
                @mkdir($configDir, 0750, true);
            }

            $config = $this->generateUserPrefs($helpdesk_id);
            $configFile = $configDir . '/user_prefs';
            
            file_put_contents($configFile, $config);

            $this->di['db']->exec(
                "UPDATE support_helpdesk SET spamassassin_active = 1, spamassassin_updated_at = NOW() WHERE id = ?",
                [$helpdesk_id]
            );

            error_log("[SpamAssassin] Reglas actualizadas para {$email}");
            return true;
        } catch (\Exception $e) {
            error_log("[SpamAssassin] Error updateRules #{$helpdesk_id}: " . $e->getMessage());
            return false;
        }
    }

    public function syncAllWithDB(): int
    {
        $success = 0;
        $helpdesks = $this->getHostsFromDB();
        foreach ($helpdesks as $desk) {
            if ($this->updateRules((int)$desk->id)) $success++;
        }
        error_log("[SpamAssassin] Sync completado: {$success}/" . count($helpdesks));
        return $success;
    }

    public function resetBasicFilters(): bool
    {
        return $this->syncAllWithDB() > 0;
    }

    // ================================================================
    // INFORMACIÓN PARA VISTAS
    // ================================================================

    public function getAccountsWithSpamAssassin(): array
    {
    	if (!function_exists('exec')) {
    	// Añadir un campo extra en el resultado para que el template muestre un mensaje
    	$result['exec_available'] = false;
    	$result['notice'] = 'La función exec() está desactivada. No podemos verificar si existe SpamAssassin ni su version.';
	}
        $helpdesks = $this->getHostsFromDB();
        $accounts = [];
        foreach ($helpdesks as $desk) {
            $accounts[(int)$desk->id] = [
                'helpdesk_id' => (int)$desk->id,
                'helpdesk_name' => $desk->name,
                'email' => $desk->email_address,
                'has_spamassassin' => !empty($desk->spamassassin_active),
                'updated_at' => $desk->spamassassin_updated_at ?? 'Nunca',
            ];
        }
        return $accounts;
    }

    // ================================================================
    // MÉTODOS PRIVADOS
    // ================================================================

    private function getHostFromId(int $helpdesk_id): ?object
    {
        if (!$this->di || !$this->di['db']) return null;
        return $this->di['db']->findOne('support_helpdesk', "id = ?", [$helpdesk_id]);
    }

    private function getHostsFromDB(): array
    {
        return $this->di['db']->find('support_helpdesk', "enable_email = 1 ORDER BY id ASC");
    }

    private function getUserConfigDir(string $email): string
{
    // Usar el método centralizado del Service (doveadm + detección de panel + ruta configurable)
    $supportService = $this->di['mod_service']('support');
    $homePath = $supportService->getUserHomePath($email);
    
    if ($homePath) {
        $spamDir = $homePath . '/spamassassin';
        if (!is_dir($spamDir)) {
            @mkdir($spamDir, 0700, true);
        }
        return $spamDir;
    }
    
    // Solo si todo falla, usar la ruta antigua
    error_log("[SpamAssassin] No se encontró ruta para {$email}, usando fallback /var/vmail/");
    return "/var/vmail/{$email}/spamassassin";
}

    private function generateUserPrefs(int $helpdesk_id): string
    {
        $helpdesk = $this->getHostFromId($helpdesk_id);
        if (!$helpdesk) return '';

        $access = strtolower(trim($helpdesk->access_level ?? 'public'));
        
        $config = "# FOSSBilling Support - SpamAssassin Rules\n";
        $config .= "# Helpdesk: {$helpdesk->name} | Access: {$access}\n";
        $config .= "# Generated: " . date('Y-m-d H:i:s') . "\n\n";
        $config .= "required_score 5.0\n\n";

        // Whitelist manual
        $whitelist = $this->di['db']->getAll(
            "SELECT email FROM support_whitelist WHERE helpdesk_id = ? AND status = 'active' ORDER BY email",
            [$helpdesk_id]
        );
        if (!empty($whitelist)) {
            $config .= "# === WHITELIST MANUAL ===\n";
            foreach ($whitelist as $entry) {
                $email = trim($entry['email'] ?? '');
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $config .= "whitelist_from {$email}\n";
                }
            }
            $config .= "\n";
        }

        // Staff asignado
        if (!empty($helpdesk->assigned_staff)) {
            $staff_ids = array_filter(array_map('intval', explode(',', $helpdesk->assigned_staff)));
            if (!empty($staff_ids)) {
                $placeholders = implode(',', array_fill(0, count($staff_ids), '?'));
                $staff_list = $this->di['db']->getAll(
                    "SELECT email FROM admin WHERE id IN ({$placeholders}) AND status = 'active' AND email IS NOT NULL",
                    $staff_ids
                );
                if (!empty($staff_list)) {
                    $config .= "# === STAFF ASIGNADO ===\n";
                    foreach ($staff_list as $staff) {
                        $config .= "whitelist_from {$staff['email']}\n";
                    }
                    $config .= "\n";
                }
            }
        }

        // Blacklist
        try {
            $blacklist = $this->di['db']->getAll(
                "SELECT DISTINCT email FROM support_blacklist 
                 WHERE status = 'active' AND (helpdesk_id = ? OR helpdesk_id IS NULL) ORDER BY email",
                [$helpdesk_id]
            );
            if (!empty($blacklist)) {
                $config .= "# === LISTA NEGRA ===\n";
                foreach ($blacklist as $blocked) {
    			$email = trim($blocked['email'] ?? '');
    			if (empty($email)) continue;
    			if (strpos($email, '*') !== false) {
        		// Bloquear todo el dominio con expresión regular
        		$config .= "blacklist_from " . str_replace('*', '.*', $email) . "\n";
    			} elseif (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        		$config .= "blacklist_from {$email}\n";
    			}
		}
                $config .= "\n";
            }
        } catch (\Exception $e) {
            error_log("[SpamAssassin] Blacklist error: " . $e->getMessage());
        }

        // Clientes registrados (solo para mesas clients)
        if ($access === 'clients' || $access === 'hybrid') {
            $clients = $this->di['db']->getAll(
                "SELECT email FROM client WHERE status = 'active' AND email IS NOT NULL AND email != '' ORDER BY email"
            );
            if (!empty($clients)) {
                $config .= "# === CLIENTES REGISTRADOS ===\n";
                foreach ($clients as $client) {
                    $config .= "whitelist_from {$client['email']}\n";
                }
                $config .= "\n";
            }
        }

        // Reglas anti-spam básicas
        $config .= "# === ANTI-SPAM BÁSICO ===\n";
        $config .= "header AUTO_REPLY Subject =~ /(out of office|auto.?reply|respuesta.?automática|acuse de recibo|delivery status)/i\n";
        $config .= "describe AUTO_REPLY Respuesta automática\n";
        $config .= "score AUTO_REPLY 50.0\n\n";
        $config .= "header MAILER_DAEMON From =~ /(mailer.?daemon|mail delivery|postmaster@|noreply@|no-reply@)/i\n";
        $config .= "describe MAILER_DAEMON Mensaje del sistema\n";
        $config .= "score MAILER_DAEMON 50.0\n";
	// Palabras clave anti-spam personalizadas
	$keywords = $this->di['db']->getAll(
    	"SELECT keyword, type FROM support_spam_keywords WHERE helpdesk_id = ? OR helpdesk_id IS NULL",[$helpdesk_id]);
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
        // Si es solo clientes, rechazar no clientes
        if ($access === 'clients') {
            $config .= "\n# === RECHAZAR NO CLIENTES ===\n";
            $config .= "header NON_CLIENT From =~ /.*/\n";
            $config .= "describe NON_CLIENT Remitente no es cliente\n";
            $config .= "score NON_CLIENT 100.0\n";
        }

        return $config;
    }

    public function ensureColumns(): void
    {
        $columns = $this->di['db']->getAll("SHOW COLUMNS FROM support_helpdesk");
        $names = array_column($columns, 'Field');
        if (!in_array('spamassassin_active', $names)) {
            $this->di['db']->exec("ALTER TABLE support_helpdesk ADD COLUMN spamassassin_active INT(1) DEFAULT 0");
        }
        if (!in_array('spamassassin_updated_at', $names)) {
            $this->di['db']->exec("ALTER TABLE support_helpdesk ADD COLUMN spamassassin_updated_at DATETIME DEFAULT NULL");
        }
        if (!in_array('filter_system', $names)) {
            $this->di['db']->exec("ALTER TABLE support_helpdesk ADD COLUMN filter_system VARCHAR(20) DEFAULT 'sieve'");
        }
    }
}
