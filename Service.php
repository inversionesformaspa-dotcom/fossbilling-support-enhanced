<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * Copyright 2011-2021 BoxBilling, Inc.
 * SPDX-License-Identifier: Apache-2.0.
 */
  /**
 * Modificaciones posteriores:
 * Copyright (C) 2026 Víctor Fornés para Inversiones Forma SPA.
 * Contribuciones de DeepSeek AI, 2026.
 *
 * Las modificaciones a este archivo se distribuyen bajo los términos
 * de la GNU Affero General Public License v3.0 o posterior (AGPL-3.0-or-later).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Box\Mod\Support;

use FOSSBilling\InformationException;
use FOSSBilling\PaginationOptions;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;

class Service implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    // ============================================================
    // PERMISOS DEL MÓDULO (NUEVO 8.3)
    // ============================================================
    public function getModulePermissions(): array
    {
        return [
            'view' => [
                'type' => 'bool',
                'display_name' => __trans('View support tickets'),
                'description' => __trans('Allows the staff member to view tickets, inquiries, helpdesks, canned responses, and knowledge base articles.'),
            ],
            'manage_tickets' => [
                'type' => 'bool',
                'display_name' => __trans('Manage tickets'),
                'description' => __trans('Allows the staff member to create, update, reply to, close, and delete tickets and inquiries.'),
            ],
            'manage_helpdesk' => [
                'type' => 'bool',
                'display_name' => __trans('Manage helpdesks'),
                'description' => __trans('Allows the staff member to create, update, and delete helpdesks.'),
            ],
            'manage_canned' => [
                'type' => 'bool',
                'display_name' => __trans('Manage canned responses'),
                'description' => __trans('Allows the staff member to create, update, and delete canned responses and categories.'),
            ],
            'manage_kb' => [
                'type' => 'bool',
                'display_name' => __trans('Manage knowledge base'),
                'description' => __trans('Allows the staff member to create, update, and delete knowledge base articles and categories.'),
            ],
        ];
    }

    // ============================================================
    // INSTALACIÓN
    // ============================================================
    public function install(): void
    {
        $db = $this->di['db'];

        $db->exec("CREATE TABLE IF NOT EXISTS support_staff_ticket (
            id INT AUTO_INCREMENT PRIMARY KEY,
            support_helpdesk_id INT NOT NULL DEFAULT 0,
            subject VARCHAR(255) NOT NULL,
            status ENUM('open','on_hold','closed') DEFAULT 'open',
            created_at DATETIME DEFAULT NULL,
            updated_at DATETIME DEFAULT NULL,
            message_id VARCHAR(255) DEFAULT NULL,
            ref_header VARCHAR(500) DEFAULT NULL,
            INDEX idx_helpdesk (support_helpdesk_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS support_staff_ticket_message (
            id INT AUTO_INCREMENT PRIMARY KEY,
            support_ticket_id INT NOT NULL DEFAULT 0,
            admin_id INT NOT NULL DEFAULT 0,
            content TEXT,
            ip VARCHAR(45) DEFAULT NULL,
            created_at DATETIME DEFAULT NULL,
            updated_at DATETIME DEFAULT NULL,
            INDEX idx_ticket (support_ticket_id),
            INDEX idx_admin (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS support_staff_ticket_note (
            id INT AUTO_INCREMENT PRIMARY KEY,
            support_ticket_id INT NOT NULL DEFAULT 0,
            admin_id INT NOT NULL DEFAULT 0,
            note TEXT,
            created_at DATETIME DEFAULT NULL,
            updated_at DATETIME DEFAULT NULL,
            INDEX idx_ticket (support_ticket_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS support_whitelist (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            helpdesk_id INT NOT NULL DEFAULT 0,
            type VARCHAR(20) DEFAULT 'client',
            status VARCHAR(20) DEFAULT 'active',
            created_at DATETIME DEFAULT NULL,
            UNIQUE KEY unique_email_helpdesk (email, helpdesk_id),
            INDEX idx_helpdesk (helpdesk_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS support_blacklist (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            helpdesk_id INT DEFAULT NULL,
            reason VARCHAR(255) DEFAULT NULL,
            status VARCHAR(20) DEFAULT 'active',
            created_at DATETIME DEFAULT NULL,
            INDEX idx_helpdesk (helpdesk_id),
            INDEX idx_status (status),
            INDEX idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS support_spam_keywords (
    		id INT AUTO_INCREMENT PRIMARY KEY,
    		keyword VARCHAR(255) NOT NULL,
    		type VARCHAR(20) DEFAULT 'subject',
    		helpdesk_id INT DEFAULT NULL,
    		created_at DATETIME DEFAULT NULL,
    		INDEX idx_helpdesk (helpdesk_id),
    		INDEX idx_type (type)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $existing_columns = $db->getAll("SHOW COLUMNS FROM support_helpdesk");
        $column_names = array_column($existing_columns, 'Field');
        $new_columns = [
            'enable_email' => "INT(1) DEFAULT 0",
            'email_address' => "VARCHAR(255) DEFAULT NULL",
            'pop3_host' => "VARCHAR(255) DEFAULT NULL",
            'pop3_port' => "INT(5) DEFAULT 995",
            'pop3_encryption' => "VARCHAR(10) DEFAULT 'ssl'",
            'pop3_password' => "VARCHAR(255) DEFAULT NULL",
            'smtp_host' => "VARCHAR(255) DEFAULT NULL",
            'smtp_port' => "INT(5) DEFAULT 587",
            'smtp_encryption' => "VARCHAR(10) DEFAULT 'tls'",
            'smtp_user' => "VARCHAR(255) DEFAULT NULL",
            'smtp_pass' => "VARCHAR(255) DEFAULT NULL",
            'from_name' => "VARCHAR(255) DEFAULT NULL",
            'access_level' => "VARCHAR(20) DEFAULT 'public'",
            'allow_public_tickets' => "INT(1) DEFAULT 0",
            'allow_client_tickets' => "INT(1) DEFAULT 0",
            'allow_staff_tickets' => "INT(1) DEFAULT 0",
            'authorized_users' => "TEXT DEFAULT NULL",
            'assigned_staff' => "VARCHAR(500) DEFAULT NULL",
            'require_email_verification' => "INT(1) DEFAULT 0",
            'sieve_active' => "INT(1) DEFAULT 0",
            'sieve_updated_at' => "DATETIME DEFAULT NULL",
        ];

        $sa = new \Box\Mod\Support\Services\SpamAssassinManager();
        $sa->setDi($this->di);
        $sa->ensureColumns();

        foreach ($new_columns as $col => $definition) {
            if (!in_array($col, $column_names)) {
                $db->exec("ALTER TABLE support_helpdesk ADD COLUMN `{$col}` {$definition}");
            }
        }

        // Agregar support_helpdesk_id a support_p_ticket
        $pticket_columns = $db->getAll("SHOW COLUMNS FROM support_p_ticket");
        $pticket_col_names = array_column($pticket_columns, 'Field');
        if (!in_array('support_helpdesk_id', $pticket_col_names)) {
            $db->exec("ALTER TABLE support_p_ticket ADD COLUMN support_helpdesk_id INT DEFAULT NULL");
            $db->exec("CREATE INDEX idx_helpdesk ON support_p_ticket (support_helpdesk_id)");
        }

        // Agregar message_id y ref_header
        $this->ensureColumn($db, 'support_ticket', 'message_id', "VARCHAR(255) DEFAULT NULL");
        $this->ensureColumn($db, 'support_staff_ticket', 'message_id', "VARCHAR(255) DEFAULT NULL");
        $this->ensureColumn($db, 'support_p_ticket', 'message_id', "VARCHAR(255) DEFAULT NULL");
        $this->ensureColumn($db, 'support_ticket', 'ref_header', "VARCHAR(500) DEFAULT NULL");
        $this->ensureColumn($db, 'support_staff_ticket', 'ref_header', "VARCHAR(500) DEFAULT NULL");
        $this->ensureColumn($db, 'support_p_ticket', 'ref_header', "VARCHAR(500) DEFAULT NULL");
        $this->ensureColumn($db, 'support_helpdesk', 'ref_header', "VARCHAR(500) DEFAULT NULL");

        $this->di['logger']->info('Support module: tablas verificadas/creadas correctamente');
    }

    private function ensureColumn($db, string $table, string $column, string $definition): void
    {
        $cols = $db->getAll("SHOW COLUMNS FROM {$table}");
        $names = array_column($cols, 'Field');
        if (!in_array($column, $names)) {
            $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    // ============================================================
    // SANITIZACIÓN
    // ============================================================
    public static function sanitizeMessageContent(string $content): string
    {
        $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
        $content = strip_tags($content);
        $content = preg_replace('/\s+/', ' ', $content);
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content);
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $content = preg_replace("/\n{3,}/", "\n\n", $content);
        return trim($content);
    }

    // ============================================================
    // RUTA HOME DEL USUARIO DE CORREO
    // ============================================================
    public function getUserHomePath(string $email): ?string
    {
        $output = [];
        $returnCode = 0;
        exec("doveadm user -f home " . escapeshellarg($email) . " 2>/dev/null", $output, $returnCode);
        if ($returnCode === 0 && !empty($output[0])) {
            return rtrim($output[0], '/');
        }

        try {
            $row = $this->di['db']->getRow(
                "SELECT home FROM mailbox WHERE username = ? OR local_part = ?",
                [$email, explode('@', $email)[0]]
            );
            if ($row && !empty($row['home'])) return rtrim($row['home'], '/');
        } catch (\Exception $e) {}

        $domain = explode('@', $email)[1];
        $localPart = explode('@', $email)[0];

        if (is_dir("/home")) {
            $daUsers = glob("/home/*", GLOB_ONLYDIR);
            foreach ($daUsers as $userDir) {
                $imapPath = "{$userDir}/imap/{$domain}/{$localPart}";
                if (is_dir($imapPath)) return $imapPath;
            }
        }

        $paths = [
            "/var/qmail/mailnames/{$domain}/{$localPart}",
            "/home/{$domain}/mail/{$localPart}",
            "/home/{$domain}/imap/{$localPart}",
            "/var/vmail/{$domain}/{$localPart}",
            "/var/vmail/{$email}",
        ];
        foreach ($paths as $path) {
            if (is_dir($path)) return $path;
        }

        $config = $this->di['mod_service']('extension')->getConfig('mod_support');
        $basePath = $config['mail_base_path'] ?? '';
        if (!empty($basePath)) {
            foreach ([
                rtrim($basePath, '/') . "/{$domain}/{$localPart}",
                rtrim($basePath, '/') . "/{$email}",
            ] as $path) {
                if (is_dir($path)) return $path;
            }
        }

        return null;
    }

    // ============================================================
    // DECRYPT PASSWORD
    // ============================================================
    public function decryptPassword(string $encrypted): string
    {
        if (empty($encrypted)) return '';
        $decoded = @base64_decode($encrypted, true);
        if ($decoded === false || empty($decoded) || strlen($decoded) < 4 || preg_match('/[^\x20-\x7E]/', $decoded)) {
            return $encrypted;
        }
        return $decoded;
    }
        // ============================================================
    // STAFF ASIGNADO
    // ============================================================
    private static function getAssignedStaff(\Pimple\Container $di, int $helpdeskId): array
    {
        $helpdesk = $di['db']->findOne('SupportHelpdesk', 'id = ?', [$helpdeskId]);
        if (!$helpdesk || empty($helpdesk->assigned_staff)) return [];
        $staff_ids = array_filter(array_map('intval', explode(',', $helpdesk->assigned_staff)));
        if (empty($staff_ids)) return [];
        $placeholders = implode(',', array_fill(0, count($staff_ids), '?'));
        return $di['db']->getAll("SELECT id, email, name FROM admin WHERE id IN ({$placeholders}) AND status = 'active'", $staff_ids);
    }

    // ============================================================
    // ENVÍO DE EMAIL UNIFICADO
    // ============================================================
    public static function sendWithHelpdeskSmtp(
        \Pimple\Container $di,
        int $helpdesk_id,
        string $to_email,
        string $to_name,
        string $subject,
        string $html_body,
        ?int $to_client_id = null,
        ?string $template_code = null,
        array $template_vars = [],
        ?string $inReplyTo = null,
        ?string $references = null
    ): bool {
        try {
            $helpdesk = $di['db']->findOne('support_helpdesk', 'id = ?', [$helpdesk_id]);
            if ($helpdesk && !empty($helpdesk->enable_email) && !empty($helpdesk->smtp_host)) {
                $smtp_pass_raw = $helpdesk->smtp_pass ?? '';
                $smtp_pass = '';
                if (!empty($smtp_pass_raw)) {
                    $decoded = @base64_decode($smtp_pass_raw, true);
                    $smtp_pass = ($decoded !== false && !empty($decoded)) ? $decoded : $smtp_pass_raw;
                }
                $encryption = strtolower($helpdesk->smtp_encryption ?? 'tls');
                $scheme = ($encryption === 'ssl') ? 'smtps' : 'smtp';
                $user = rawurlencode($helpdesk->smtp_user ?? $helpdesk->email_address);
                $pass = rawurlencode($smtp_pass);
                $host = $helpdesk->smtp_host;
                $port = (int)($helpdesk->smtp_port ?? 587);
                $from_name = $helpdesk->from_name ?? $helpdesk->name ?? 'Soporte';
                $from_email = $helpdesk->email_address;

                $dsn = "{$scheme}://{$user}:{$pass}@{$host}:{$port}";
                $transport = Transport::fromDsn($dsn);
                $mailer = new Mailer($transport);
                $email = (new Email())
                    ->from(new Address($from_email, $from_name))
                    ->to(new Address($to_email, $to_name))
                    ->replyTo($from_email)
                    ->subject($subject)
                    ->text(strip_tags($html_body))
                    ->html($html_body);

                if ($inReplyTo) {
                    $email->getHeaders()->addIdHeader('In-Reply-To', $inReplyTo);
                }
                if ($references) {
                    $email->getHeaders()->addIdHeader('References', $references);
                }

                $email_domain = substr(strrchr($from_email, "@"), 1);
                $msgId = bin2hex(random_bytes(16)) . '@' . $email_domain;
                $email->getHeaders()->addIdHeader('Message-ID', $msgId);
                $mailer->send($email);

                if (!empty($template_vars['ticket_id'])) {
                    $ticketId = (int)$template_vars['ticket_id'];
                    $di['db']->exec("UPDATE support_staff_ticket SET ref_header = ? WHERE id = ?", [$msgId, $ticketId]);
                    $di['db']->exec("UPDATE support_ticket SET ref_header = ? WHERE id = ?", [$msgId, $ticketId]);
                    $di['db']->exec("UPDATE support_p_ticket SET ref_header = ? WHERE id = ?", [$msgId, $ticketId]);
                }
                return true;
            }
        } catch (\Exception $e) {
            error_log("[Support] Error SMTP helpdesk: " . $e->getMessage());
        }

        if ($template_code && ($to_client_id || $to_email)) {
            try {
                $emailService = $di['mod_service']('email');
                $email_data = array_merge($template_vars, ['code' => $template_code]);
                if ($to_client_id) $email_data['to_client'] = $to_client_id;
                else { $email_data['to'] = $to_email; $email_data['to_name'] = $to_name; }
                $emailService->sendTemplate($email_data);
                return true;
            } catch (\Exception $e) {
                error_log("[Support] Error fallback: " . $e->getMessage());
            }
        }
        return false;
    }

    // ============================================================
    // BUILDERS DE EMAIL HTML
    // ============================================================
    private static function buildTicketEmailHtml(array $ticketArr, string $reply_content, string $subject, int $ticket_id): string
    {
        $client_name = trim(($ticketArr['client']['first_name'] ?? '') . ' ' . ($ticketArr['client']['last_name'] ?? ''));
        if (empty($client_name)) $client_name = 'Cliente';
        $helpdesk_name = $ticketArr['helpdesk']['name'] ?? 'Soporte';
        $enable_email = !empty($ticketArr['helpdesk']['enable_email']);
        $content = nl2br(htmlspecialchars($reply_content));
        $reply_instruction = $enable_email
            ? "<li>Responde este correo incluyendo <strong>[Ticket #{$ticket_id}]</strong> en el asunto</li>
               <li>O accede a tu cuenta: <a href='/client/support/ticket/{$ticket_id}'>Ver ticket #{$ticket_id}</a></li>"
            : "<li>Accede a tu cuenta para responder: <a href='/client/support/ticket/{$ticket_id}'>Ver ticket #{$ticket_id}</a></li>
               <li>No respondas a este correo directamente.</li>";
        return "<!DOCTYPE html><html><head><meta charset='UTF-8'>
<style>body{font-family:Arial,sans-serif;font-size:14px;color:#333;}
.reply-box{background:#f8f9fa;border-left:4px solid #0066cc;padding:16px;margin:16px 0;}</style></head><body>
<h2>Nueva respuesta a tu ticket</h2><p>Hola {$client_name},</p>
<p>Has recibido una respuesta a tu ticket <strong>#{$ticket_id}</strong>: {$ticketArr['subject']}</p>
<div class='reply-box'>{$content}</div><ul>{$reply_instruction}</ul></body></html>";
    }

    private static function buildStaffTicketEmailHtml(array $ticketArr, string $reply_content, string $subject, int $ticket_id): string
    {
        $from_staff_name = $ticketArr['author_name'] ?? 'Staff';
        $helpdesk_name = $ticketArr['helpdesk']['name'] ?? 'Mesa de Ayuda de Staff';
        $content = nl2br(htmlspecialchars($reply_content));
        $reply_instruction = "<li>Accede al panel para responder: <a href='/admin/support/staff-ticket/{$ticket_id}'>Ver ticket #{$ticket_id}</a></li>
           <li>Por favor, no respondas a este correo directamente.</li>";
        return "<!DOCTYPE html><html><head><meta charset='UTF-8'>
<style>body{font-family:Arial,sans-serif;font-size:14px;color:#333;}
.reply-box{background:#f8f9fa;border-left:4px solid #cc6600;padding:16px;margin:16px 0;}</style></head><body>
<h2>Nueva respuesta en ticket de Staff</h2><p>Hola {$from_staff_name},</p>
<p>Has recibido una respuesta en el ticket de staff <strong>#{$ticket_id}</strong>: {$ticketArr['subject']}</p>
<div class='reply-box'>{$content}</div><ul>{$reply_instruction}</ul>
<p>Este mensaje fue enviado por {$helpdesk_name}.</p></body></html>";
    }

    // ============================================================
    // EVENTOS
    // ============================================================

    public static function onAfterClientSignUp(\Box_Event $event): void
    {
        $di = $event->getDi();
        try {
            $sieve = new \Box\Mod\Support\Services\SieveManager();
            $sieve->setDi($di);
            $sieve->updateAllClientFilters();
        } catch (\Exception $e) {
            error_log('[Support] onAfterClientSignUp: ' . $e->getMessage());
        }
    }

    public static function onAfterClientDelete(\Box_Event $event): void
    {
        $di = $event->getDi();
        try {
            $sieve = new \Box\Mod\Support\Services\SieveManager();
            $sieve->setDi($di);
            $sieve->updateAllClientFilters();
        } catch (\Exception $e) {
            error_log('[Support] onAfterClientDelete: ' . $e->getMessage());
        }
    }

    public static function onAfterClientOpenTicket(\Box_Event $event): void
    {
        $di = $event->getDi();
        $params = $event->getParameters();
        try {
            $supportService = $di['mod_service']('support');
            $emailService = $di['mod_service']('email');
            $ticketObj = $supportService->getTicketById((int) $params['id']);
            $ticketArr = $supportService->toApiArray($ticketObj, true);
            $client = $di['db']->getExistingModelById('Client', $ticketObj->client_id, 'Client not found');
            $emailService->sendTemplate([
                'to_client' => $client->id,
                'code' => 'mod_support_ticket_open',
                'ticket' => $ticketArr,
            ]);
        } catch (\Exception $exc) {
            $di['logger']->err($exc->getMessage());
        }
    }

    public static function onBeforeAdminCronRun(\Box_Event $event): bool
    {
        $di = $event->getDi();
        try {
            $gateway = new \Box\Mod\Support\Services\EmailGateway();
            $gateway->setDi($di);
            $result = $gateway->processTicketsFromPop3();
            $di['logger']->setChannel('cron')->info('Support Email Gateway: procesados ' . ($result['processed'] ?? 0) . ' emails, errores: ' . ($result['errors'] ?? 0));
        } catch (\Exception $e) {
            error_log('[Support Cron] Email Gateway error: ' . $e->getMessage());
        }
        try {
            $last_sync = $di['db']->getCell("SELECT MAX(sieve_updated_at) FROM support_helpdesk WHERE enable_email = 1");
            $minutes_since = $last_sync ? (time() - strtotime($last_sync)) / 60 : 9999;
            if ($minutes_since > 10) {
                $sieve = new \Box\Mod\Support\Services\SieveManager();
                $sieve->setDi($di);
                $synced = $sieve->syncAllSieveWithDB();
                $di['logger']->setChannel('cron')->info('Support Sieve sync: ' . $synced . ' cuentas sincronizadas');
            }
        } catch (\Exception $e) {
            error_log('[Support Cron] Sieve sync error: ' . $e->getMessage());
        }
        // Después del bloque de Sieve, agrega esto:
	try {
    	// Solo sincronizar SpamAssassin si el sistema no es Sieve
    	$filterSystem = \Box\Mod\Support\Services\SpamAssassinManager::detectFilterSystem();
    	if ($filterSystem === 'spamassassin') {
        $sa = new \Box\Mod\Support\Services\SpamAssassinManager();
        $sa->setDi($di);
        $last_sa_sync = $di['db']->getCell("SELECT MAX(spamassassin_updated_at) FROM support_helpdesk WHERE enable_email = 1 AND filter_system = 'spamassassin'");
        $sa_minutes = $last_sa_sync ? (time() - strtotime($last_sa_sync)) / 60 : 9999;
        if ($sa_minutes > 10) {
            $sa_synced = $sa->syncAllWithDB();
            $di['logger']->setChannel('cron')->info('Support SpamAssassin sync: ' . $sa_synced . ' cuentas sincronizadas');
        }
    	} else {
        $di['logger']->setChannel('cron')->info('Support: Sieve activo, omitiendo SpamAssassin');
    	}
	} catch (\Exception $e) {
    	error_log('[Support Cron] SpamAssassin sync error: ' . $e->getMessage());
	}
        return true;
    }

    public static function onAfterAdminOpenTicket(\Box_Event $event): void
    {
        $di = $event->getDi();
        $params = $event->getParameters();
        try {
            $supportService = $di['mod_service']('support');
            $identity = $di['loggedin_admin'];
            $ticketObj = $supportService->getTicketById((int) $params['id']);
            $ticketArr = $supportService->toApiArray($ticketObj, true, $identity);
            $helpdesk = $di['db']->findOne('SupportHelpdesk', 'id = ?', [$ticketObj->support_helpdesk_id]);
            $assigned_staff = self::getAssignedStaff($di, $ticketObj->support_helpdesk_id);
            foreach ($assigned_staff as $staff) {
                if ($identity instanceof \Model_Admin && $staff['id'] == $identity->id) continue;
                $emailService = $di['mod_service']('email');
                $emailService->sendTemplate([
                    'to' => $staff['email'], 'to_name' => $staff['name'],
                    'code' => 'mod_support_ticket_staff_open', 'ticket' => $ticketArr, 'helpdesk' => $helpdesk,
                ]);
            }
            $client = $di['db']->getExistingModelById('Client', $ticketObj->client_id, 'Client not found');
            if ($client) {
                $emailService = $di['mod_service']('email');
                $emailService->sendTemplate([
                    'to' => $client->email, 'to_name' => trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? '')),
                    'code' => 'mod_support_ticket_staff_open', 'ticket' => $ticketArr, 'helpdesk' => $helpdesk,
                ]);
            }
        } catch (\Exception $exc) {
            $di['logger']->err($exc->getMessage());
        }
    }

    public static function onAfterAdminCloseTicket(\Box_Event $event): void
    {
        $di = $event->getDi();
        $params = $event->getParameters();
        try {
            $supportService = $di['mod_service']('support');
            $ticketObj = $supportService->getTicketById((int) $params['id']);
            $identity = $di['loggedin_admin'];
            $ticketArr = $supportService->toApiArray($ticketObj, true, $identity);
            $client = $di['db']->getExistingModelById('Client', $ticketObj->client_id, 'Client not found');
            $client_name = trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? ''));
            $subject = "[Ticket #{$ticketObj->id}] Cerrado: " . $ticketObj->subject;
            $html_body = "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;font-size:14px;color:#333;'>
<h2>Tu ticket ha sido cerrado</h2><p>Hola {$client_name},</p>
<p>Tu ticket <strong>#{$ticketObj->id}</strong>: {$ticketObj->subject} ha sido marcado como resuelto.</p>
<p>Si necesitas continuar, responde este correo incluyendo <strong>[Ticket #{$ticketObj->id}]</strong> en el asunto.</p></body></html>";
            $helpdesk = $di['db']->findOne('SupportHelpdesk', 'id = ?', [$ticketObj->support_helpdesk_id]);
            $email_domain = substr(strrchr($helpdesk->email_address ?? 'localhost', "@"), 1) ?: 'localhost';
            $msgId = sprintf('ticket.%d.%s@%s', $ticketObj->id, bin2hex(random_bytes(8)), $email_domain);
            self::sendWithHelpdeskSmtp($di, $ticketObj->support_helpdesk_id, $client->email, $client_name, $subject, $html_body, $ticketObj->client_id, 'mod_support_ticket_staff_close', ['ticket' => $ticketArr, 'ticket_id' => $ticketObj->id], $msgId, $msgId);
        } catch (\Exception $exc) {
            $di['logger']->err($exc->getMessage());
        }
    }

    public static function onAfterAdminReplyTicket(\Box_Event $event): void
    {
        $di = $event->getDi();
        $params = $event->getParameters();
        try {
            $supportService = $di['mod_service']('support');
            $ticketObj = $supportService->getTicketById((int) $params['id']);
            $identity = $di['loggedin_admin'];
            $ticketArr = $supportService->toApiArray($ticketObj, true, $identity);
            $last_msg = $di['db']->findOne('SupportTicketMessage', 'support_ticket_id = ? ORDER BY id DESC', [$ticketObj->id]);
            $client = $di['db']->getExistingModelById('Client', $ticketObj->client_id, 'Client not found');
            $client_name = trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? ''));
            $subject = "Re: [Ticket #{$ticketObj->id}] " . $ticketObj->subject;
            $html_body = self::buildTicketEmailHtml($ticketArr, $last_msg ? $last_msg->content : '', $subject, $ticketObj->id);
            $helpdesk = $di['db']->findOne('SupportHelpdesk', 'id = ?', [$ticketObj->support_helpdesk_id]);
            $email_domain = substr(strrchr($helpdesk->email_address ?? 'localhost', "@"), 1) ?: 'localhost';
            $msgId = sprintf('ticket.%d.%s@%s', $ticketObj->id, bin2hex(random_bytes(8)), $email_domain);
            self::sendWithHelpdeskSmtp($di, $ticketObj->support_helpdesk_id, $client->email, $client_name, $subject, $html_body, $ticketObj->client_id, 'mod_support_ticket_staff_reply', ['ticket' => $ticketArr, 'last_reply' => $last_msg ? $last_msg->content : '', 'ticket_id' => $ticketObj->id, 'reply_subject' => $subject], $msgId, $msgId);
        } catch (\Exception $exc) {
            $di['logger']->err($exc->getMessage());
        }
    }
        // ============================================================
    // EVENTOS DE STAFF
    // ============================================================

    public static function onAfterAdminStaffTicketOpen(\Box_Event $event): void
    {
        error_log("[Support] onAfterAdminStaffTicketOpen: Evento para Staff Ticket #{$event->getParameters()['id']}");
    }

    public static function onAfterAdminStaffTicketReply(\Box_Event $event): void
    {
        $di = $event->getDi();
        $params = $event->getParameters();
        try {
            $supportService = $di['mod_service']('support');
            $ticketObj = $supportService->staffGetTicketById((int) $params['id']);
            $identity = $di['loggedin_admin'];
            $ticketArr = $supportService->staffToApiArray($ticketObj, true, $identity);

            $last_msg = $di['db']->getRow("SELECT * FROM support_staff_ticket_message WHERE support_ticket_id = ? ORDER BY id DESC LIMIT 1", [$ticketObj->id]);
            $subject = "Re: [Staff Ticket #{$ticketObj->id}] " . $ticketObj->subject;
            $html_body = self::buildStaffTicketEmailHtml($ticketArr, $last_msg ? $last_msg['content'] : '', $subject, $ticketObj->id);

            $first_msg = $di['db']->getRow("SELECT * FROM support_staff_ticket_message WHERE support_ticket_id = ? ORDER BY id ASC LIMIT 1", [$ticketObj->id]);

            // Notificar al autor
            if ($first_msg && $first_msg['admin_id'] != $identity->id) {
                $author = $di['db']->getRow("SELECT id, name, email FROM admin WHERE id = ?", [$first_msg['admin_id']]);
                if ($author && $author['email']) {
                    self::sendWithHelpdeskSmtp($di, $ticketObj->support_helpdesk_id, $author['email'], $author['name'], $subject, $html_body, null, 'mod_support_staff_ticket_reply', ['ticket' => $ticketArr, 'last_reply' => $last_msg ? $last_msg['content'] : '', 'ticket_id' => $ticketObj->id, 'reply_subject' => $subject]);
                }
            }

            // Notificar a staff asignado
            $assigned_staff = self::getAssignedStaff($di, $ticketObj->support_helpdesk_id);
            foreach ($assigned_staff as $staff) {
                if ($staff['id'] == $identity->id) continue;
                if ($first_msg && $staff['id'] == $first_msg['admin_id']) continue;
                self::sendWithHelpdeskSmtp($di, $ticketObj->support_helpdesk_id, $staff['email'], $staff['name'], $subject, $html_body, null, 'mod_support_staff_ticket_reply', ['ticket' => $ticketArr, 'last_reply' => $last_msg ? $last_msg['content'] : '', 'ticket_id' => $ticketObj->id, 'reply_subject' => $subject]);
            }
        } catch (\Exception $exc) {
            error_log('[Support] onAfterAdminStaffTicketReply: ' . $exc->getMessage());
        }
    }

    public static function onAfterAdminStaffTicketClose(\Box_Event $event): void
    {
        $di = $event->getDi();
        $params = $event->getParameters();
        try {
            $supportService = $di['mod_service']('support');
            $ticketObj = $supportService->staffGetTicketById((int) $params['id']);
            $identity = $di['loggedin_admin'];
            $ticketArr = $supportService->staffToApiArray($ticketObj, true, $identity);
            $subject = "[Staff Ticket #{$ticketObj->id}] Cerrado: " . $ticketObj->subject;
            $html_body = "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;font-size:14px;color:#333;'>
<h2>Ticket de Staff cerrado</h2><p>El ticket <strong>#{$ticketObj->id}</strong>: {$ticketObj->subject} ha sido cerrado por {$identity->name}.</p></body></html>";
            $assigned_staff = self::getAssignedStaff($di, $ticketObj->support_helpdesk_id);
            foreach ($assigned_staff as $staff) {
                if ($staff['id'] == $identity->id) continue;
                self::sendWithHelpdeskSmtp($di, $ticketObj->support_helpdesk_id, $staff['email'], $staff['name'], $subject, $html_body, null, 'mod_support_staff_ticket_close', ['ticket' => $ticketArr]);
            }
        } catch (\Exception $exc) {
            error_log('[Support] onAfterAdminStaffTicketClose: ' . $exc->getMessage());
        }
    }

    // ============================================================
    // EVENTOS DE PÚBLICOS
    // ============================================================

    public static function onAfterGuestPublicTicketOpen(\Box_Event $event): void
    {
        $di = $event->getDi();
        $params = $event->getParameters();
        try {
            $supportService = $di['mod_service']('support');
            $emailService = $di['mod_service']('email');
            $ticketObj = $supportService->getPublicTicketById((int) $params['id']);
            $ticketArr = $supportService->publicToApiArray($ticketObj, true);
            $emailService->sendTemplate(['to' => $ticketArr['author_email'], 'to_name' => $ticketArr['author_name'], 'code' => 'mod_support_pticket_open', 'ticket' => $ticketArr]);
        } catch (\Exception $exc) {
            error_log($exc->getMessage());
        }
    }

    public static function onAfterAdminPublicTicketOpen(\Box_Event $event): void
    {
        $di = $event->getDi();
        $params = $event->getParameters();
        try {
            $supportService = $di['mod_service']('support');
            $ticketObj = $supportService->getPublicTicketById((int) $params['id']);
            $identity = $di['loggedin_admin'];
            $ticketArr = $supportService->publicToApiArray($ticketObj, true, $identity);
            $email_domain = substr(strrchr($ticketArr['author_email'] ?? 'localhost', "@"), 1) ?: 'localhost';
            $msgId = sprintf('ticket.%d.%s@%s', $ticketObj->id, bin2hex(random_bytes(8)), $email_domain);
            self::sendWithHelpdeskSmtp($di, $ticketObj->support_helpdesk_id, $ticketArr['author_email'], $ticketArr['author_name'], "Nuevo ticket público: " . $ticketObj->subject, "<html><body><p>Un nuevo ticket público (#{$ticketObj->id}) ha sido creado.</p><p>Asunto: {$ticketObj->subject}</p></body></html>", null, 'mod_support_pticket_staff_open', ['ticket' => $ticketArr], $msgId, $msgId);
        } catch (\Exception $exc) {
            error_log($exc->getMessage());
        }
    }

    public static function onAfterAdminPublicTicketReply(\Box_Event $event): void
    {
        $di = $event->getDi();
        $params = $event->getParameters();
        try {
            $supportService = $di['mod_service']('support');
            $ticketObj = $supportService->getPublicTicketById((int) $params['id']);
            $identity = $di['loggedin_admin'];
            $ticketArr = $supportService->publicToApiArray($ticketObj, true, $identity);
            $last_msg = $di['db']->findOne('SupportPTicketMessage', 'support_p_ticket_id = ? ORDER BY id DESC', [$ticketObj->id]);
            $subject = "Re: [Ticket #{$ticketObj->id}] " . $ticketObj->subject;
            $html_body = "<html><body><p>Hola {$ticketArr['author_name']},</p><p>Hemos respondido a tu ticket público <strong>#{$ticketObj->id}</strong>.</p><div style='padding:10px;border-left:2px solid #ccc;'>{$last_msg->content}</div></body></html>";
            $email_domain = substr(strrchr($ticketArr['author_email'] ?? 'localhost', "@"), 1) ?: 'localhost';
            $msgId = sprintf('ticket.%d.%s@%s', $ticketObj->id, bin2hex(random_bytes(8)), $email_domain);
            self::sendWithHelpdeskSmtp($di, $ticketObj->support_helpdesk_id, $ticketArr['author_email'], $ticketArr['author_name'], $subject, $html_body, null, 'mod_support_pticket_staff_reply', ['ticket' => $ticketArr, 'last_reply' => $last_msg ? $last_msg->content : '', 'ticket_id' => $ticketObj->id, 'reply_subject' => $subject], $msgId, $msgId);
        } catch (\Exception $exc) {
            error_log('[Support] onAfterAdminPublicTicketReply: ' . $exc->getMessage());
        }
    }

    public static function onAfterAdminPublicTicketClose(\Box_Event $event): void
    {
        $di = $event->getDi();
        $params = $event->getParameters();
        try {
            $supportService = $di['mod_service']('support');
            $emailService = $di['mod_service']('email');
            $ticketObj = $supportService->getPublicTicketById((int) $params['id']);
            $identity = $di['loggedin_admin'];
            $ticketArr = $supportService->publicToApiArray($ticketObj, true, $identity);
            $emailService->sendTemplate(['to' => $ticketArr['author_email'], 'to_name' => $ticketArr['author_name'], 'code' => 'mod_support_pticket_staff_close', 'ticket' => $ticketArr, 'ticket_id' => $ticketObj->id]);
        } catch (\Exception $exc) {
            error_log($exc->getMessage());
        }
    }

    // ============================================================
    // MÉTODOS DE SERVICIO PRINCIPALES
    // ============================================================

    public function getTicketById(int $id): \Model_SupportTicket
    {
        return $this->di['db']->getExistingModelById('SupportTicket', $id, 'Ticket not found');
    }

    public function getPublicTicketById(int $id): \Model_SupportPTicket
    {
        return $this->di['db']->getExistingModelById('SupportPTicket', $id, 'Ticket not found');
    }

    public function getStatuses(): array
    {
        return [
            \Model_SupportTicket::OPENED => 'Open',
            \Model_SupportTicket::ONHOLD => 'On Hold',
            \Model_SupportTicket::CLOSED => 'Closed',
        ];
    }

    public function findOneByClient(\Model_Client $c, int $id): \Model_SupportTicket
    {
        $ticket = $this->di['db']->findOne('SupportTicket', 'id = :id AND client_id = :client_id', [':id' => $id, ':client_id' => $c->id]);
        if (!$ticket instanceof \Model_SupportTicket) throw new \FOSSBilling\Exception('Ticket not found');
        return $ticket;
    }

    public function getSearchQuery(array $data): array
    {
        $query = 'SELECT st.* FROM support_ticket st JOIN support_ticket_message stm ON stm.support_ticket_id = st.id LEFT JOIN client c ON st.client_id = c.id';
        $where = []; $bindings = [];

        if (!empty($data['id'])) { $where[] = 'st.id = :ticket_id'; $bindings[':ticket_id'] = $data['id']; }
        if (!empty($data['priority'])) { $where[] = 'st.priority = :priority'; $bindings[':priority'] = $data['priority']; }
        if (!empty($data['support_helpdesk_id'])) { $where[] = 'st.support_helpdesk_id = :support_helpdesk_id'; $bindings[':support_helpdesk_id'] = $data['support_helpdesk_id']; }
        if (!empty($data['status'])) { $where[] = 'st.status = :status'; $bindings[':status'] = $data['status']; }
        if (!empty($data['client_id'])) { $where[] = 'c.id = :client_id'; $bindings[':client_id'] = $data['client_id']; }
        if (!empty($data['order_id'])) { $where[] = 'st.rel_type = :rel_type AND st.rel_id = :rel_id'; $bindings[':rel_type'] = \Model_SupportTicket::REL_TYPE_ORDER; $bindings[':rel_id'] = $data['order_id']; }
        if (!empty($data['client'])) { $where[] = '(c.first_name LIKE :fn OR c.last_name LIKE :ln)'; $bindings[':fn'] = '%' . $data['client'] . '%'; $bindings[':ln'] = '%' . $data['client'] . '%'; }
        if (!empty($data['content'])) { $where[] = 'stm.content LIKE :content'; $bindings[':content'] = '%' . $data['content'] . '%'; }
        if (!empty($data['subject'])) { $where[] = 'st.subject LIKE :subject'; $bindings[':subject'] = '%' . $data['subject'] . '%'; }
        if (!empty($data['date_from'])) { $where[] = 'UNIX_TIMESTAMP(st.created_at) >= :date_from'; $bindings[':date_from'] = strtotime((string) $data['date_from']); }
        if (!empty($data['date_to'])) { $where[] = 'UNIX_TIMESTAMP(st.created_at) <= :date_to'; $bindings[':date_to'] = strtotime((string) $data['date_to']); }
        if (!empty($data['created_at'])) { $where[] = "DATE_FORMAT(st.created_at, '%Y-%m-%d') = :created_at"; $bindings[':created_at'] = date('Y-m-d', strtotime((string) $data['created_at'])); }

        $assigned = $data['assigned_helpdesk_ids'] ?? null;
        if ($assigned !== null) {
            if (empty($assigned)) { $where[] = '1 = 0'; }
            else { $phs = []; foreach ($assigned as $k => $id) { $phs[] = ":ahid_{$k}"; $bindings[":ahid_{$k}"] = $id; } $where[] = 'st.support_helpdesk_id IN (' . implode(',', $phs) . ')'; }
        }
        if (!empty($data['search'])) {
            $s = $data['search'];
            if (is_numeric($s)) { $where[] = 'st.id = :tid'; $bindings[':tid'] = $s; }
            else { $where[] = '(stm.content LIKE :sc OR st.subject LIKE :ss)'; $bindings[':sc'] = "%{$s}%"; $bindings[':ss'] = "%{$s}%"; }
        }
        if ($where) $query .= ' WHERE ' . implode(' AND ', $where);
        $query .= ' GROUP BY st.id ORDER BY st.priority ASC, st.id DESC';
        return [$query, $bindings];
    }
        // ============================================================
    // COUNTERS
    // ============================================================
    public function counter(?array $assigned_helpdesk_ids = null): array
    {
        $bindings = []; $where = [];
        if ($assigned_helpdesk_ids !== null) {
            if (empty($assigned_helpdesk_ids)) return ['total' => 0, \Model_SupportTicket::OPENED => 0, \Model_SupportTicket::ONHOLD => 0, \Model_SupportTicket::CLOSED => 0];
            $phs = implode(',', array_fill(0, count($assigned_helpdesk_ids), '?'));
            $where[] = "support_helpdesk_id IN ({$phs})"; $bindings = $assigned_helpdesk_ids;
        }
        $query = 'SELECT status, COUNT(id) as counter FROM support_ticket';
        if ($where) $query .= ' WHERE ' . implode(' AND ', $where);
        $query .= ' GROUP BY status';
        $data = $this->di['db']->getAssoc($query, $bindings);
        return [
            'total' => 0,
            \Model_SupportTicket::OPENED => (int)($data[\Model_SupportTicket::OPENED] ?? 0),
            \Model_SupportTicket::ONHOLD => (int)($data[\Model_SupportTicket::ONHOLD] ?? 0),
            \Model_SupportTicket::CLOSED => (int)($data[\Model_SupportTicket::CLOSED] ?? 0),
        ];
    }

    public function _fixCounterTotal(&$result): void { $result['total'] = $result[\Model_SupportTicket::OPENED] + $result[\Model_SupportTicket::ONHOLD] + $result[\Model_SupportTicket::CLOSED]; }

    public function getLatest(): array
    {
        return $this->di['db']->find('SupportTicket', 'ORDER BY id DESC LIMIT 10');
    }

    public function getExpired(): array
    {
        return $this->di['db']->getAll('SELECT st.* FROM support_ticket st LEFT JOIN support_helpdesk sh ON sh.id = st.support_helpdesk_id WHERE st.status = :status AND DATE_ADD(st.updated_at, INTERVAL sh.close_after HOUR) < NOW() ORDER BY st.id ASC', [':status' => \Model_SupportTicket::ONHOLD]);
    }

    public function countByStatus(string $status): int
    {
        return $this->di['db']->getCell("SELECT COUNT(id) FROM support_ticket WHERE status = :status", [':status' => $status]);
    }

    public function getActiveTicketsCountForOrder(\Model_ClientOrder $model): int
    {
        return $this->di['db']->getCell("SELECT COUNT(id) FROM support_ticket WHERE rel_id = :order_id AND rel_type = 'order' AND (status = :s1 OR status = :s2)", [':order_id' => $model->id, ':s1' => \Model_SupportTicket::OPENED, ':s2' => \Model_SupportTicket::ONHOLD]);
    }

    public function checkIfTaskAlreadyExists(\Model_Client $client, int $rel_id, string $rel_type, string $rel_task): bool
    {
        return $this->di['db']->findOne('SupportTicket', 'client_id = :cid AND rel_id = :rid AND rel_type = :rt AND rel_task = :rtk AND rel_status = :rs', [':cid' => $client->id, ':rid' => $rel_id, ':rt' => $rel_type, ':rtk' => $rel_task, ':rs' => \Model_SupportTicket::REL_STATUS_PENDING]) instanceof \Model_SupportTicket;
    }

    // ============================================================
    // TICKET ACTIONS
    // ============================================================
    public function closeTicket(\Model_SupportTicket $ticket, \Model_Admin|\Model_Client $identity): bool
    {
        $ticket->status = \Model_SupportTicket::CLOSED;
        $ticket->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($ticket);
        if ($identity instanceof \Model_Admin) $this->di['events_manager']->fire(['event' => 'onAfterAdminCloseTicket', 'params' => ['id' => $ticket->id]]);
        elseif ($identity instanceof \Model_Client) $this->di['events_manager']->fire(['event' => 'onAfterClientCloseTicket', 'params' => ['id' => $ticket->id]]);
        $this->di['logger']->info('Closed ticket "%s"', $ticket->id);
        return true;
    }

    public function autoClose(\Model_SupportTicket $model): bool
    {
        $model->status = \Model_SupportTicket::CLOSED;
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);
        return true;
    }

    public function canBeReopened(\Model_SupportTicket $model): bool
    {
        if ($model->status != \Model_SupportTicket::CLOSED) return true;
        $helpdesk = $this->di['db']->getExistingModelById('SupportHelpdesk', $model->support_helpdesk_id);
        return (bool) $helpdesk->can_reopen;
    }

    public function canBeReopenedPublic($model): bool
    {
        if ($model->status != 'closed') return true;
        $helpdesk = $this->di['db']->getExistingModelById('SupportHelpdesk', $model->support_helpdesk_id);
        return (bool) $helpdesk->can_reopen;
    }

    public function ticketReply(\Model_SupportTicket $ticket, \Model_Admin|\Model_Client $identity, string $content): int
    {
        $msg = $this->di['db']->dispense('SupportTicketMessage');
        $msg->support_ticket_id = $ticket->id;
        if ($identity instanceof \Model_Admin) $msg->admin_id = $identity->id;
        elseif ($identity instanceof \Model_Client) $msg->client_id = $identity->id;
        $msg->content = $content;
        $msg->ip = $this->di['request']->getClientIp();
        $msg->created_at = date('Y-m-d H:i:s');
        $msg->updated_at = date('Y-m-d H:i:s');
        $msgId = $this->di['db']->store($msg);
        $ticket->status = ($identity instanceof \Model_Admin) ? \Model_SupportTicket::ONHOLD : \Model_SupportTicket::OPENED;
        $ticket->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($ticket);
        if ($identity instanceof \Model_Admin) $this->di['events_manager']->fire(['event' => 'onAfterAdminReplyTicket', 'params' => ['id' => $ticket->id]]);
        elseif ($identity instanceof \Model_Client) $this->di['events_manager']->fire(['event' => 'onAfterClientReplyTicket', 'params' => ['id' => $ticket->id]]);
        return $msgId;
    }

    public function ticketCreateForClient(\Model_Client $client, \Model_SupportHelpdesk $helpdesk, array $data): int
    {
        $skip = $data['skip_before_client_open'] ?? false;
        if (!$skip) $this->di['events_manager']->fire(['event' => 'onBeforeClientOpenTicket', 'params' => ['client' => $client, 'support_helpdesk_id' => $helpdesk->id, 'subject' => $data['subject'] ?? '', 'content' => $data['content'] ?? '']]);
        $ticket = $this->di['db']->dispense('SupportTicket');
        $ticket->client_id = $client->id;
        $ticket->support_helpdesk_id = $helpdesk->id;
        $ticket->subject = $data['subject'];
        $ticket->status = 'open';
        $ticket->created_at = date('Y-m-d H:i:s');
        $ticket->updated_at = date('Y-m-d H:i:s');
        $ticketId = $this->di['db']->store($ticket);
        $this->messageCreateForTicket($ticket, $client, $data['content']);
        if (!$skip) $this->di['events_manager']->fire(['event' => 'onAfterClientOpenTicket', 'params' => ['id' => $ticketId]]);
        // Guardar Message-ID original
        $email_domain = substr(strrchr($helpdesk->email_address ?? 'localhost', "@"), 1) ?: 'localhost';
        $originalMsgId = sprintf('ticket.%d.%s@%s', $ticketId, bin2hex(random_bytes(8)), $email_domain);
        $this->di['db']->exec("UPDATE support_ticket SET message_id = ? WHERE id = ?", [$originalMsgId, $ticketId]);
        return $ticketId;
    }

    public function ticketCreateForAdmin(\Model_Client $client, \Model_SupportHelpdesk $helpdesk, array $data, \Model_Admin $identity): int
    {
        $this->di['events_manager']->fire(['event' => 'onBeforeAdminOpenTicket', 'params' => $data]);
        $ticket = $this->di['db']->dispense('SupportTicket');
        $ticket->client_id = $client->id;
        $ticket->status = $data['status'] ?? \Model_SupportTicket::ONHOLD;
        $ticket->subject = $data['subject'];
        $ticket->support_helpdesk_id = $helpdesk->id;
        $ticket->created_at = date('Y-m-d H:i:s');
        $ticket->updated_at = date('Y-m-d H:i:s');
        $ticketId = $this->di['db']->store($ticket);
        $msg = $this->di['db']->dispense('SupportTicketMessage');
        $msg->admin_id = $identity->id;
        $msg->support_ticket_id = $ticketId;
        $msg->content = $data['content'];
        $msg->ip = $this->di['request']->getClientIp();
        $msg->created_at = date('Y-m-d H:i:s');
        $msg->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($msg);
        $this->di['events_manager']->fire(['event' => 'onAfterAdminOpenTicket', 'params' => ['id' => $ticketId]]);
        $email_domain = substr(strrchr($helpdesk->email_address ?? 'localhost', "@"), 1) ?: 'localhost';
        $originalMsgId = sprintf('ticket.%d.%s@%s', $ticketId, bin2hex(random_bytes(8)), $email_domain);
        $this->di['db']->exec("UPDATE support_ticket SET message_id = ? WHERE id = ?", [$originalMsgId, $ticketId]);
        return (int) $ticketId;
    }

    public function ticketCreateForGuest(array $data): string
    {
        $data['email'] = $this->di['tools']->validateAndSanitizeEmail($data['email']);
        $this->di['events_manager']->fire(['event' => 'onBeforeGuestPublicTicketOpen', 'params' => $data]);
        $ticket = $this->di['db']->dispense('SupportPTicket');
        $ticket->hash = bin2hex(random_bytes(random_int(100, 127)));
        $ticket->author_name = $data['name'];
        $ticket->author_email = $data['email'];
        $ticket->subject = $data['subject'];
        $ticket->support_helpdesk_id = $data['support_helpdesk_id'] ?? null;
        $ticket->status = 'open';
        $ticket->created_at = date('Y-m-d H:i:s');
        $ticket->updated_at = date('Y-m-d H:i:s');
        $ticketId = $this->di['db']->store($ticket);
        $msg = $this->di['db']->dispense('SupportPTicketMessage');
        $msg->support_p_ticket_id = $ticketId;
        $msg->content = $data['message'];
        $msg->ip = $this->di['request']->getClientIp();
        $msg->created_at = date('Y-m-d H:i:s');
        $msg->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($msg);
        $this->di['events_manager']->fire(['event' => 'onAfterGuestPublicTicketOpen', 'params' => ['id' => $ticketId]]);
        $helpdesk = $this->di['db']->findOne('SupportHelpdesk', 'id = ?', [$data['support_helpdesk_id'] ?? 1]);
        $email_domain = substr(strrchr($helpdesk->email_address ?? 'localhost', "@"), 1) ?: 'localhost';
        $originalMsgId = sprintf('public.%d.%s@%s', $ticketId, bin2hex(random_bytes(8)), $email_domain);
        $this->di['db']->exec("UPDATE support_p_ticket SET message_id = ? WHERE id = ?", [$originalMsgId, $ticketId]);
        return $ticket->hash;
    }

    public function publicTicketCreate(array $data, \Model_Admin $identity): int
    {
        $data['email'] = $this->di['tools']->validateAndSanitizeEmail($data['email']);
        $this->di['events_manager']->fire(['event' => 'onBeforeAdminPublicTicketOpen', 'params' => $data]);
        $ticket = $this->di['db']->dispense('SupportPTicket');
        $ticket->hash = bin2hex(random_bytes(random_int(100, 127)));
        $ticket->author_name = $data['name'];
        $ticket->author_email = $data['email'];
        $ticket->subject = $data['subject'];
        $ticket->support_helpdesk_id = $data['support_helpdesk_id'] ?? null;
        $ticket->status = 'open';
        $ticket->created_at = date('Y-m-d H:i:s');
        $ticket->updated_at = date('Y-m-d H:i:s');
        $ticketId = $this->di['db']->store($ticket);
        $msg = $this->di['db']->dispense('SupportPTicketMessage');
        $msg->support_p_ticket_id = $ticketId;
        $msg->admin_id = $identity->id;
        $msg->content = $data['message'];
        $msg->ip = $this->di['request']->getClientIp();
        $msg->created_at = date('Y-m-d H:i:s');
        $msg->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($msg);
        $this->di['events_manager']->fire(['event' => 'onAfterAdminPublicTicketOpen', 'params' => ['id' => $ticketId]]);
        $helpdesk = $this->di['db']->findOne('SupportHelpdesk', 'id = ?', [$data['support_helpdesk_id'] ?? 1]);
        $email_domain = substr(strrchr($helpdesk->email_address ?? 'localhost', "@"), 1) ?: 'localhost';
        $originalMsgId = sprintf('public.%d.%s@%s', $ticketId, bin2hex(random_bytes(8)), $email_domain);
        $this->di['db']->exec("UPDATE support_p_ticket SET message_id = ? WHERE id = ?", [$originalMsgId, $ticketId]);
        return (int) $ticketId;
    }

    // ============================================================
    // STAFF TICKETS
    // ============================================================
    public function staffGetTicketById(int $id)
    {
        $ticket = $this->di['db']->getRow("SELECT * FROM support_staff_ticket WHERE id = ?", [$id]);
        if (!$ticket) throw new \FOSSBilling\Exception('Staff Ticket not found');
        return (object) $ticket;
    }

    public function staffGetStatuses(): array
    {
        return ['open' => 'Open', 'on_hold' => 'On Hold', 'closed' => 'Closed'];
    }

    public function ticketCreateForStaff(int $helpdesk_id, \Model_Admin $admin_identity, array $data): int
    {
        $db = $this->di['db'];
        $now = date('Y-m-d H:i:s');
        $db->exec("INSERT INTO support_staff_ticket (support_helpdesk_id, subject, status, created_at, updated_at) VALUES (?, ?, 'open', ?, ?)", [$helpdesk_id, $data['subject'], $now, $now]);
        $ticketId = (int) $db->getCell("SELECT LAST_INSERT_ID()");
        $db->exec("INSERT INTO support_staff_ticket_message (support_ticket_id, admin_id, content, ip, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)", [$ticketId, $admin_identity->id, $data['content'], $this->di['request']->getClientIp(), $now, $now]);
        $this->di['events_manager']->fire(['event' => 'onAfterAdminStaffTicketOpen', 'params' => ['id' => $ticketId]]);
        $helpdesk = $this->di['db']->findOne('SupportHelpdesk', 'id = ?', [$helpdesk_id]);
        $email_domain = substr(strrchr($helpdesk->email_address ?? 'localhost', "@"), 1) ?: 'localhost';
        $originalMsgId = sprintf('staff.%d.%s@%s', $ticketId, bin2hex(random_bytes(8)), $email_domain);
        $db->exec("UPDATE support_staff_ticket SET message_id = ? WHERE id = ?", [$originalMsgId, $ticketId]);
        return $ticketId;
    }

    public function staffTicketReply($ticket, \Model_Admin $admin_identity, string $content): int
    {
        $db = $this->di['db'];
        $now = date('Y-m-d H:i:s');
        $ticketId = is_array($ticket) ? $ticket['id'] : $ticket->id;
        $db->exec("INSERT INTO support_staff_ticket_message (support_ticket_id, admin_id, content, ip, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)", [$ticketId, $admin_identity->id, $content, $this->di['request']->getClientIp(), $now, $now]);
        $msgId = (int) $db->getCell("SELECT LAST_INSERT_ID()");
        $db->exec("UPDATE support_staff_ticket SET status = 'on_hold', updated_at = ? WHERE id = ?", [$now, $ticketId]);
        $this->di['events_manager']->fire(['event' => 'onAfterAdminStaffTicketReply', 'params' => ['id' => $ticketId]]);
        return $msgId;
    }

    public function closeStaffTicket($ticket, \Model_Admin $admin_identity): bool
    {
        $now = date('Y-m-d H:i:s');
        $ticketId = is_object($ticket) ? $ticket->id : $ticket['id'];
        $this->di['db']->exec("UPDATE support_staff_ticket SET status = 'closed', updated_at = ? WHERE id = ?", [$now, $ticketId]);
        $this->di['events_manager']->fire(['event' => 'onAfterAdminStaffTicketClose', 'params' => ['id' => $ticketId]]);
        return true;
    }

    public function staffToApiArray($model, bool $deep = true, ?\Model_Admin $identity = null): array
    {
        $db = $this->di['db'];
        if (is_array($model)) { $data = $model; }
        else {
            try { $data = $model->export(); }
            catch (\Throwable $e) { $data = (array) $model; }
        }

        $helpdesk = $db->findOne('SupportHelpdesk', 'id = ?', [$data['support_helpdesk_id']]);
        $data['helpdesk'] = $helpdesk ? $this->helpdeskToApiArray($helpdesk, $identity) : [];

        $first = $db->getRow("SELECT * FROM support_staff_ticket_message WHERE support_ticket_id = ? ORDER BY id ASC LIMIT 1", [$data['id']]);
        if ($first) {
            $author = $db->getRow("SELECT id, name, email FROM admin WHERE id = ?", [$first['admin_id']]);
            $data['first'] = ['content' => $first['content'], 'created_at' => $first['created_at'], 'author' => ['name' => $author['name'] ?? 'Staff', 'email' => $author['email'] ?? '']];
            $data['author_name'] = $author['name'] ?? 'Staff';
            $data['author_email'] = $author['email'] ?? '';
        } else {
            $data['first'] = []; $data['author_name'] = 'Staff'; $data['author_email'] = '';
        }
        $data['replies'] = (int) $db->getCell("SELECT COUNT(id) FROM support_staff_ticket_message WHERE support_ticket_id = ?", [$data['id']]);

        if ($deep) {
            $messages = $db->getAll("SELECT * FROM support_staff_ticket_message WHERE support_ticket_id = ? ORDER BY id ASC", [$data['id']]);
            $data['messages'] = [];
            foreach ($messages as $msg) {
                $author = $db->getRow("SELECT id, name, email FROM admin WHERE id = ?", [$msg['admin_id']]);
                $data['messages'][] = ['id' => $msg['id'], 'content' => $msg['content'], 'created_at' => $msg['created_at'], 'updated_at' => $msg['updated_at'], 'author' => ['name' => $author['name'] ?? 'Staff', 'email' => $author['email'] ?? '']];
            }
        }
        $notes = $db->getAll("SELECT * FROM support_staff_ticket_note WHERE support_ticket_id = ?", [$data['id']]);
        $data['notes'] = [];
        foreach ($notes as $note) {
            $author = $db->getRow("SELECT id, name, email FROM admin WHERE id = ?", [$note['admin_id']]);
            $data['notes'][] = ['id' => $note['id'], 'note' => $note['note'], 'created_at' => $note['created_at'], 'author' => ['name' => $author['name'] ?? 'Staff', 'email' => $author['email'] ?? '']];
        }
        return $data;
    }

    public function staffMessageGetRepliesCount($model): int
    {
        return (int) $this->di['db']->getCell('SELECT COUNT(id) FROM support_staff_ticket_message WHERE support_ticket_id = ?', [$model->id]);
    }

    public function staffMessageGetTicketMessages($model): array
    {
        return $this->di['db']->find('SupportStaffTicketMessage', 'support_ticket_id = ? ORDER BY id ASC', [$model->id]);
    }

    public function staffMessageGetAuthorDetails($model): array
    {
        $author = $this->di['db']->load('Admin', $model->admin_id);
        return $author ? ['name' => $author->getFullName(), 'email' => $author->email] : [];
    }

    public function staffMessageToApiArray($model): array
    {
        $data = $this->di['db']->toArray($model);
        $data['author'] = $this->staffMessageGetAuthorDetails($model);
        return $data;
    }

    public function staffNoteToApiArray($model): array
    {
        $data = $this->di['db']->toArray($model);
        $admin = $this->di['db']->load('Admin', $model->admin_id);
        $data['author'] = ['name' => $admin->getFullName(), 'email' => $admin->email];
        return $data;
    }

    public function staffGetSearchQuery(array $data): array
    {
        $query = 'SELECT st.* FROM support_staff_ticket st LEFT JOIN support_staff_ticket_message stm ON stm.support_ticket_id = st.id';
        $where = []; $bindings = [];
        if (!empty($data['id'])) { $where[] = 'st.id = :id'; $bindings[':id'] = $data['id']; }
        if (!empty($data['status'])) { $where[] = 'st.status = :status'; $bindings[':status'] = $data['status']; }
        if (!empty($data['subject'])) { $where[] = 'st.subject LIKE :subj'; $bindings[':subj'] = '%' . $data['subject'] . '%'; }
        if (!empty($data['content'])) { $where[] = 'stm.content LIKE :cont'; $bindings[':cont'] = '%' . $data['content'] . '%'; }
        if (!empty($data['support_helpdesk_id'])) { $where[] = 'st.support_helpdesk_id = :hd'; $bindings[':hd'] = $data['support_helpdesk_id']; }
        $assigned = $data['assigned_helpdesk_ids'] ?? null;
        if ($assigned !== null) {
            if (empty($assigned)) { $where[] = '1 = 0'; }
            else { $phs = []; foreach ($assigned as $k => $id) { $phs[] = ":shid_{$k}"; $bindings[":shid_{$k}"] = $id; } $where[] = 'st.support_helpdesk_id IN (' . implode(',', $phs) . ')'; }
        }
        if (!empty($data['search'])) {
            $s = $data['search'];
            if (is_numeric($s)) { $where[] = 'st.id = :sid'; $bindings[':sid'] = $s; }
            else { $where[] = '(stm.content LIKE :ssc OR st.subject LIKE :sss)'; $bindings[':ssc'] = "%{$s}%"; $bindings[':sss'] = "%{$s}%"; }
        }
        if ($where) $query .= ' WHERE ' . implode(' AND ', $where);
        $query .= ' GROUP BY st.id ORDER BY st.created_at DESC';
        return [$query, $bindings];
    }

    public function staffCounter(?array $assigned_helpdesk_ids = null): array
    {
        $bindings = []; $where = [];
        if ($assigned_helpdesk_ids !== null) {
            if (empty($assigned_helpdesk_ids)) return ['total' => 0, 'open' => 0, 'on_hold' => 0, 'closed' => 0];
            $phs = implode(',', array_fill(0, count($assigned_helpdesk_ids), '?'));
            $where[] = "support_helpdesk_id IN ({$phs})"; $bindings = $assigned_helpdesk_ids;
        }
        $query = 'SELECT status, COUNT(id) as counter FROM support_staff_ticket';
        if ($where) $query .= ' WHERE ' . implode(' AND ', $where);
        $query .= ' GROUP BY status';
        $data = $this->di['db']->getAssoc($query, $bindings);
        return [
            'total' => (int)($data['open'] ?? 0) + (int)($data['on_hold'] ?? 0) + (int)($data['closed'] ?? 0),
            'open' => (int)($data['open'] ?? 0),
            'on_hold' => (int)($data['on_hold'] ?? 0),
            'closed' => (int)($data['closed'] ?? 0),
        ];
    }
        // ============================================================
    // TO API ARRAY
    // ============================================================
    public function toApiArray(\Model_SupportTicket $model, bool $deep = true, \Model_Admin|\Model_Client|null $identity = null): array
    {
        $firstMsg = $this->di['db']->findOne('SupportTicketMessage', 'support_ticket_id = :support_ticket_id ORDER by id ASC LIMIT 1', [':support_ticket_id' => $model->id]);
        $supportHelpdesk = $this->di['db']->load('SupportHelpdesk', $model->support_helpdesk_id);

        $data = $this->ticketToApiArray($this->di['db']->toArray($model), $identity);
        $data['replies'] = $this->messageGetRepliesCount($model);
        $data['first'] = $firstMsg instanceof \Model_SupportTicketMessage ? $this->messageToApiArray($firstMsg, true, $identity) : null;
        $data['helpdesk'] = $this->helpdeskToApiArray($supportHelpdesk, $identity);
        $data['client'] = $this->getClientApiArrayForTicket($model, $identity);

        if ($deep) {
            $messages = $this->messageGetTicketMessages($model);
            foreach ($messages as $msg) {
                $data['messages'][] = $this->messageToApiArray($msg, true, $identity);
            }
        }

        if ($identity instanceof \Model_Admin) {
            $data['rel'] = $this->_getRelDetails($model);
            $data['priority'] = $model->priority;
            $notes = $this->di['db']->find('SupportTicketNote', 'support_ticket_id = :support_ticket_id', [':support_ticket_id' => $model->id]);
            foreach ($notes as $note) {
                $data['notes'][] = $this->noteToApiArray($note);
            }
        }
        return $data;
    }

    private function ticketToApiArray(array $data, \Model_Admin|\Model_Client|null $identity = null): array
    {
        if ($identity instanceof \Model_Admin) return $data;
        unset($data['support_helpdesk_id'], $data['client_id'], $data['priority'], $data['rel_type'], $data['rel_id'], $data['rel_task'], $data['rel_new_value'], $data['rel_status']);
        return $data;
    }
/**
 * Get multiple tickets in a batch for API response.
 */
public function getBatchForApi(array $ids, bool $deep = false, $identity = null): array
{
    $ids = $this->normalizeIds($ids);
    if (empty($ids)) {
        return [];
    }

    if ($deep || $identity instanceof \Model_Admin) {
        return $this->getBatchForApiWithModels($ids, $deep, $identity);
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $tickets = $this->di['db']->getAll("SELECT * FROM support_ticket WHERE id IN ($placeholders)", $ids);
    if (empty($tickets)) {
        return [];
    }

    $tickets = $this->orderRowsByIds($tickets, $ids);
    $ticketIds = array_column($tickets, 'id');
    $helpdeskIds = $this->normalizeIds(array_column($tickets, 'support_helpdesk_id'));
    $clientIds = $this->normalizeIds(array_column($tickets, 'client_id'));

    $replyCounts = [];
    if (!empty($ticketIds)) {
        $placeholders = implode(',', array_fill(0, count($ticketIds), '?'));
        $countRows = $this->di['db']->getAll(
            "SELECT support_ticket_id, COUNT(id) as counter FROM support_ticket_message WHERE support_ticket_id IN ($placeholders) GROUP BY support_ticket_id",
            $ticketIds
        );
        foreach ($countRows as $row) {
            $replyCounts[$row['support_ticket_id']] = (int) $row['counter'];
        }
    }

    $firstMessages = [];
    if (!empty($ticketIds)) {
        $placeholders = implode(',', array_fill(0, count($ticketIds), '?'));
        $rows = $this->di['db']->getAll(
            "SELECT support_ticket_id, MIN(id) as message_id FROM support_ticket_message WHERE support_ticket_id IN ($placeholders) GROUP BY support_ticket_id",
            $ticketIds
        );
        $messageIds = array_column($rows, 'message_id');
        if (!empty($messageIds)) {
            $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
            $messages = $this->di['db']->find('SupportTicketMessage', "id IN ($placeholders)", $messageIds);
            foreach ($messages as $message) {
                $firstMessages[$message->support_ticket_id] = $message;
            }
        }
    }

    $helpdesks = [];
    if (!empty($helpdeskIds)) {
        $placeholders = implode(',', array_fill(0, count($helpdeskIds), '?'));
        $helpdeskModels = $this->di['db']->find('SupportHelpdesk', "id IN ($placeholders)", $helpdeskIds);
        foreach ($helpdeskModels as $helpdesk) {
            $helpdesks[$helpdesk->id] = $helpdesk;
        }
    }

    $clients = [];
    if (!empty($clientIds)) {
        $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
        $clientModels = $this->di['db']->find('Client', "id IN ($placeholders)", $clientIds);
        foreach ($clientModels as $client) {
            $clients[$client->id] = $this->clientToTicketApiArray($client, $identity);
        }
    }

    $result = [];
    foreach ($tickets as $ticket) {
        $data = $this->ticketToApiArray($ticket, $identity);
        $data['replies'] = $replyCounts[$ticket['id']] ?? 0;
        $data['first'] = isset($firstMessages[$ticket['id']]) ? $this->messageToApiArray($firstMessages[$ticket['id']], true, $identity) : null;
        $helpdesk = $helpdesks[$ticket['support_helpdesk_id']] ?? null;
        $data['helpdesk'] = $helpdesk ? $this->helpdeskToApiArray($helpdesk, $identity) : null;
        if (!isset($clients[$ticket['client_id']])) {
            $this->di['logger']->err('Missing client for ticket ' . $ticket['id']);
            $data['client'] = [];
        } else {
            $data['client'] = $clients[$ticket['client_id']];
        }
        $result[] = $data;
    }

    return $result;
}

private function getBatchForApiWithModels(array $ids, bool $deep, $identity): array
{
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $tickets = $this->di['db']->find('SupportTicket', "id IN ($placeholders)", $ids);
    if (empty($tickets)) {
        return [];
    }

    $ticketsById = [];
    foreach ($tickets as $ticket) {
        $ticketsById[$ticket->id] = $ticket;
    }

    $result = [];
    foreach ($ids as $id) {
        if (isset($ticketsById[$id])) {
            $result[] = $this->toApiArray($ticketsById[$id], $deep, $identity);
        }
    }

    return $result;
}

private function normalizeIds(array $ids): array
{
    return array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));
}

private function orderRowsByIds(array $rows, array $ids): array
{
    $rowsById = [];
    foreach ($rows as $row) {
        $rowsById[(int) $row['id']] = $row;
    }

    $ordered = [];
    foreach ($ids as $id) {
        if (isset($rowsById[$id])) {
            $ordered[] = $rowsById[$id];
        }
    }

    return $ordered;
}
    private function _getRelDetails(\Model_SupportTicket $model): array
    {
        if (!$model->rel_type || !$model->rel_id) return [];
        $result = ['id' => $model->rel_id, 'type' => $model->rel_type, 'task' => $model->rel_task, 'new_value' => $model->rel_new_value, 'status' => $model->rel_status];
        if ($model->rel_type == \Model_SupportTicket::REL_TYPE_ORDER) {
            $client = $this->di['db']->load('Client', $model->client_id);
            $orderService = $this->di['mod_service']('order');
            $o = $orderService->findForClientById($client, $model->rel_id);
            if ($o instanceof \Model_ClientOrder) $result['order'] = $orderService->toApiArray($o, false);
        }
        return $result;
    }

    public function getClientApiArrayForTicket(\Model_SupportTicket $ticket, \Model_Admin|\Model_Client|null $identity = null): array
    {
        $client = $this->di['db']->load('Client', $ticket->client_id);
        if ($client instanceof \Model_Client) {
            return $this->clientToTicketApiArray($client, $identity);
        }
        $this->di['logger']->err('Missing client for ticket ' . $ticket->id);
        return [];
    }

    private function clientToTicketApiArray(\Model_Client $client, \Model_Admin|\Model_Client|null $identity = null): array
    {
        if ($identity instanceof \Model_Admin) {
            $clientService = $this->di['mod_service']('client');
            return $clientService->toApiArray($client, false, $identity);
        }
        return ['id' => $client->id, 'first_name' => $client->first_name, 'last_name' => $client->last_name];
    }

    // ============================================================
    // HELPDESK
    // ============================================================
    public function helpdeskGetSearchQuery(array $data): array
    {
        $query = 'SELECT * FROM support_helpdesk'; $where = []; $bindings = [];
        if (!empty($data['search'])) { $s = '%' . $data['search'] . '%'; $where[] = '(name LIKE :name OR email LIKE :email OR signature LIKE :signature)'; $bindings[':name'] = $s; $bindings[':email'] = $s; $bindings[':signature'] = $s; }
        if ($where) $query .= ' WHERE ' . implode(' AND ', $where);
        $query .= ' ORDER BY id DESC';
        return [$query, $bindings];
    }

    public function helpdeskGetPairs(): array { return $this->di['db']->getAssoc('SELECT id, name FROM support_helpdesk'); }

    public function helpdeskRm(\Model_SupportHelpdesk $model): bool
    {
        $tickets = $this->di['db']->find('SupportTicket', 'support_helpdesk_id = ?', [$model->id]);
        if (\FOSSBilling\Tools::safeCount($tickets) > 0) throw new InformationException('Cannot remove helpdesk which has tickets');
        $this->di['db']->trash($model);
        return true;
    }

    public function helpdeskToApiArray(\Model_SupportHelpdesk $model, \Model_Admin|\Model_Client|null $identity = null): array
    {
        if ($identity instanceof \Model_Admin) return $this->di['db']->toArray($model);
        return ['id' => $model->id, 'name' => $model->name, 'can_reopen' => (bool) $model->can_reopen];
    }

    // ============================================================
    // MESSAGES
    // ============================================================
    public function messageGetTicketMessages(\Model_SupportTicket $model): array
    {
        return $this->di['db']->find('SupportTicketMessage', 'support_ticket_id = ? ORDER BY id ASC', [$model->id]);
    }

    public function messageGetRepliesCount(\Model_SupportTicket $model): int
    {
        return (int) $this->di['db']->getCell('SELECT COUNT(id) FROM support_ticket_message WHERE support_ticket_id = ?', [$model->id]);
    }

    public function messageGetAuthorDetails(\Model_SupportTicketMessage $model, \Model_Admin|\Model_Client|null $identity = null): array
    {
        if ($model->admin_id) {
            $author = $this->di['db']->load('Admin', $model->admin_id);
            $role = 'admin';
        } else {
            $author = $this->di['db']->load('Client', $model->client_id);
            $role = 'client';
        }
        if (!$author) return [];
        $result = ['name' => $author->getFullName(), 'role' => $role];
        if ($identity instanceof \Model_Admin) $result['email'] = $author->email;
        return $result;
    }

    public function messageToApiArray(\Model_SupportTicketMessage $model, bool $deep = true, \Model_Admin|\Model_Client|null $identity = null): array
    {
        if ($identity instanceof \Model_Admin) $data = $this->di['db']->toArray($model);
        else $data = ['id' => $model->id, 'content' => $model->content, 'attachment' => $model->attachment, 'created_at' => $model->created_at, 'updated_at' => $model->updated_at];
        $data['author'] = $this->messageGetAuthorDetails($model, $identity);
        return $data;
    }

    public function messageCreateForTicket(\Model_SupportTicket $ticket, \Model_Admin|\Model_Client $identity, string $content): int
    {
        $msg = $this->di['db']->dispense('SupportTicketMessage');
        $msg->support_ticket_id = $ticket->id;
        if ($identity instanceof \Model_Admin) $msg->admin_id = $identity->id;
        elseif ($identity instanceof \Model_Client) $msg->client_id = $identity->id;
        else throw new \FOSSBilling\Exception('Identity is invalid');
        $msg->content = $content;
        $msg->ip = $this->di['request']->getClientIp();
        $msg->created_at = date('Y-m-d H:i:s');
        $msg->updated_at = date('Y-m-d H:i:s');
        return $this->di['db']->store($msg);
    }

    // ============================================================
    // NOTES
    // ============================================================
    public function noteGetAuthorDetails(\Model_SupportTicketNote $model): array
    {
        $admin = $this->di['db']->load('Admin', $model->admin_id);
        return ['name' => $admin->getFullName(), 'email' => $admin->email];
    }

    public function noteRm(\Model_SupportTicketNote $model): bool { $this->di['db']->trash($model); return true; }

    public function noteToApiArray(\Model_SupportTicketNote $model, bool $deep = false, \Model_Admin|\Model_Client|null $identity = null): array
    {
        $data = $this->di['db']->toArray($model);
        $data['author'] = $this->noteGetAuthorDetails($model);
        return $data;
    }

    // ============================================================
    // TICKET UPDATE
    // ============================================================
    public function ticketUpdate(\Model_SupportTicket $model, array $data): bool
    {
        $model->support_helpdesk_id = $data['support_helpdesk_id'] ?? $model->support_helpdesk_id;
        $model->status = $data['status'] ?? $model->status;
        $model->subject = $data['subject'] ?? $model->subject;
        $model->priority = $data['priority'] ?? $model->priority;
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);
        return true;
    }

    public function ticketMessageUpdate(\Model_SupportTicketMessage $model, string $content): bool
    {
        $model->content = $content;
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);
        return true;
    }

    public function ticketTaskComplete(\Model_SupportTicket $model): bool
    {
        $model->rel_status = \Model_SupportTicket::REL_STATUS_COMPLETE;
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);
        return true;
    }

    public function rm(\Model_SupportTicket $model): bool
    {
        foreach ($this->di['db']->find('SupportTicketNote', 'support_ticket_id = ?', [$model->id]) as $note) $this->di['db']->trash($note);
        foreach ($this->di['db']->find('SupportTicketMessage', 'support_ticket_id = ?', [$model->id]) as $msg) $this->di['db']->trash($msg);
        $this->di['db']->trash($model);
        return true;
    }

    public function rmByClient(\Model_Client $client): void
    {
        foreach ($this->di['db']->find('SupportTicket', 'client_id = ?', [$client->id]) as $ticket) $this->di['db']->trash($ticket);
    }

    public function helpdeskUpdate(\Model_SupportHelpdesk $model, array $data): bool
    {
        $model->name = $data['name'] ?? $model->name;
        $model->email = $data['email'] ?? $model->email;
        $model->can_reopen = $data['can_reopen'] ?? $model->can_reopen;
        $model->close_after = $data['close_after'] ?? $model->close_after;
        $model->signature = $data['signature'] ?? $model->signature;
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);
        return true;
    }

    public function helpdeskCreate(array $data): int
    {
        $model = $this->di['db']->dispense('SupportHelpdesk');
        $model->name = $data['name']; $model->email = $data['email'] ?? null;
        $model->can_reopen = $data['can_reopen'] ?? null; $model->close_after = $data['close_after'] ?? null;
        $model->signature = $data['signature'] ?? null;
        $model->created_at = date('Y-m-d H:i:s'); $model->updated_at = date('Y-m-d H:i:s');
        return $this->di['db']->store($model);
    }

    public function noteCreate(\Model_SupportTicket $ticket, \Model_Admin $identity, string $note): int
    {
        $model = $this->di['db']->dispense('SupportTicketNote');
        $model->support_ticket_id = $ticket->id; $model->admin_id = $identity->id;
        $model->note = $note;
        $model->created_at = date('Y-m-d H:i:s'); $model->updated_at = date('Y-m-d H:i:s');
        return $this->di['db']->store($model);
    }

    // ============================================================
    // CANNED RESPONSES
    // ============================================================
    private function cannedReply(\Model_SupportTicket $ticket, $cannedId): void
    {
        try {
            $cannedObj = $this->di['db']->getExistingModelById('SupportPr', $cannedId, 'Canned reply not found');
            $canned = $this->cannedToApiArray($cannedObj);
            $admin = $this->di['mod_service']('staff')->getCronAdmin();
            if (isset($canned['content']) && $admin instanceof \Model_Admin) $this->ticketReply($ticket, $admin, $canned['content']);
        } catch (\Exception $e) { error_log($e->getMessage()); }
    }

    public function cannedGetSearchQuery(array $data): array
    {
        $query = 'SELECT sp.* FROM support_pr sp LEFT JOIN support_pr_category spc ON spc.id = sp.support_pr_category_id';
        $where = []; $bindings = [];
        if (!empty($data['search'])) { $s = '%' . $data['search'] . '%'; $where[] = 'title LIKE :title OR content LIKE :content'; $bindings[':title'] = $s; $bindings[':content'] = $s; }
        if ($where) $query .= ' WHERE ' . implode(' AND ', $where);
        $query .= ' ORDER BY sp.support_pr_category_id ASC';
        return [$query, $bindings];
    }

    public function cannedGetGroupedPairs(): array
    {
        $data = $this->di['db']->getAll('SELECT sp.title as r_title, spc.title as c_title, sp.id FROM support_pr sp LEFT JOIN support_pr_category spc ON spc.id = sp.support_pr_category_id');
        $res = []; foreach ($data as $r) $res[$r['c_title']][$r['id']] = $r['r_title'];
        return $res;
    }

    public function cannedRm(\Model_SupportPr $model): bool { $this->di['db']->trash($model); return true; }

    public function cannedToApiArray(\Model_SupportPr $model): array
    {
        $result = $this->di['db']->toArray($model);
        $category = $this->di['db']->load('SupportPrCategory', $model->support_pr_category_id);
        $result['category'] = $category ? ['id' => $category->id, 'title' => $category->title] : [];
        return $result;
    }

    public function cannedCategoryGetPairs(): array { return $this->di['db']->getAssoc('SELECT id, title FROM support_pr_category'); }
    public function cannedCategoryRm(\Model_SupportPrCategory $model): bool { $this->di['db']->trash($model); return true; }
    public function cannedCategoryToApiArray(\Model_SupportPrCategory $model): array { return $this->di['db']->toArray($model); }

    public function cannedCreate(string $title, int $categoryId, ?string $content = null): int
    {
        $model = $this->di['db']->dispense('SupportPr');
        $model->support_pr_category_id = $categoryId; $model->title = $title; $model->content = $content;
        $model->created_at = date('Y-m-d H:i:s'); $model->updated_at = date('Y-m-d H:i:s');
        return $this->di['db']->store($model);
    }

    public function cannedUpdate(\Model_SupportPr $model, array $data): bool
    {
        $model->support_pr_category_id = $data['category_id'] ?? $model->support_pr_category_id;
        $model->title = $data['title'] ?? $model->title; $model->content = $data['content'] ?? $model->content;
        $model->updated_at = date('Y-m-d H:i:s'); $this->di['db']->store($model);
        return true;
    }

    public function cannedCategoryCreate(string $title): int
    {
        $model = $this->di['db']->dispense('SupportPrCategory');
        $model->title = $title; $model->created_at = date('Y-m-d H:i:s'); $model->updated_at = date('Y-m-d H:i:s');
        return $this->di['db']->store($model);
    }

    public function cannedCategoryUpdate(\Model_SupportPrCategory $model, string $title): bool
    {
        $model->title = $title; $model->updated_at = date('Y-m-d H:i:s');
        return $this->di['db']->store($model);
    }

    // ============================================================
    // KNOWLEDGE BASE
    // ============================================================
    public function kbEnabled(): bool
    {
        $config = $this->di['mod_service']('extension')->getConfig('mod_support');
        return isset($config['kb_enable']) && $config['kb_enable'] == 'on';
    }

    public function kbSearchArticles(?string $status, ?string $search, ?string $cat, PaginationOptions $pagination): array
    {
        $filter = [];
        $sql = 'SELECT * FROM support_kb_article WHERE 1';
        if ($cat) { $sql .= ' AND kb_article_category_id = :cid'; $filter[':cid'] = $cat; }
        if ($status) { $sql .= ' AND status = :status'; $filter[':status'] = $status; }
        if ($search) { $sql .= ' AND (title LIKE :q OR content LIKE :q)'; $filter[':q'] = "%$search%"; }
        $sql .= ' ORDER BY title ASC';
        return $this->di['pager']->getPaginatedResultSet($sql, $filter, $pagination);
    }

    public function kbFindActiveArticleById(int $id): ?\Model_SupportKbArticle
    {
        return $this->di['db']->findOne('SupportKbArticle', 'id = :id AND status = :status', [':id' => $id, ':status' => 'active']);
    }

    public function kbFindActiveArticleBySlug(string $slug): ?\Model_SupportKbArticle
    {
        return $this->di['db']->findOne('SupportKbArticle', 'slug = :slug AND status = :status', [':slug' => $slug, ':status' => 'active']);
    }

    public function kbFindActive(): array { return $this->di['db']->find('SupportKbArticle', 'status = :status', [':status' => 'active']); }
    public function kbHitView(\Model_SupportKbArticle $model): void { $model->views++; $this->di['db']->store($model); }
    public function kbRm(\Model_SupportKbArticle $model): void { $this->di['db']->trash($model); }

    public function kbToApiArray(\Model_SupportKbArticle $model, $deep = false, $identity = null): array
    {
        $data = ['id' => $model->id, 'slug' => $model->slug, 'title' => $model->title, 'views' => $model->views, 'created_at' => $model->created_at, 'status' => $model->status, 'updated_at' => $model->updated_at];
        $cat = $this->di['db']->getExistingModelById('SupportKbArticleCategory', $model->kb_article_category_id, 'KB category not found');
        $data['category'] = ['id' => $cat->id, 'slug' => $cat->slug, 'title' => $cat->title];
        if ($deep) $data['content'] = $model->content;
        if ($identity instanceof \Model_Admin) $data['kb_article_category_id'] = $model->kb_article_category_id;
        return $data;
    }

    public function kbCreateArticle(int $articleCategoryId, string $title, ?string $status = null, ?string $content = null): int
    {
        $model = $this->di['db']->dispense('SupportKbArticle');
        $model->kb_article_category_id = $articleCategoryId; $model->title = $title;
        $model->slug = $this->di['tools']->slug($title); $model->status = $status ?? 'draft'; $model->content = $content;
        $model->updated_at = date('Y-m-d H:i:s'); $model->created_at = date('Y-m-d H:i:s');
        return $this->di['db']->store($model);
    }

    public function kbUpdateArticle(int $id, ?int $articleCategoryId = null, ?string $title = null, ?string $slug = null, ?string $status = null, ?string $content = null, ?int $views = null): bool
    {
        $model = $this->di['db']->findOne('SupportKbArticle', 'id = ?', [$id]);
        if (!$model) throw new \FOSSBilling\Exception('Article not found');
        if ($articleCategoryId !== null) $model->kb_article_category_id = $articleCategoryId;
        if ($title !== null) $model->title = $title;
        if ($slug !== null) $model->slug = $slug;
        if ($status !== null) $model->status = $status;
        if ($content !== null) $model->content = $content;
        if ($views !== null) $model->views = $views;
        $model->updated_at = date('Y-m-d H:i:s'); $this->di['db']->store($model);
        return true;
    }

    public function kbCategoryGetSearchQuery(array $data): array
    {
        $sql = 'SELECT kac.* FROM support_kb_article_category kac LEFT JOIN support_kb_article ka ON kac.id = ka.kb_article_category_id';
        $where = []; $bindings = [];
        if (!empty($data['article_status'])) { $where[] = 'ka.status = :status'; $bindings[':status'] = $data['article_status']; }
        if (!empty($data['q'])) { $where[] = '(ka.title LIKE :q OR ka.content LIKE :q)'; $bindings[':q'] = '%' . $data['q'] . '%'; }
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' GROUP BY kac.id ORDER BY kac.title';
        return [$sql, $bindings];
    }

    public function kbCategoryFindAll(): array { return $this->di['db']->getAll('SELECT kac.*, ka.* FROM support_kb_article_category kac LEFT JOIN support_kb_article ka ON kac.id = ka.kb_article_category_id'); }
    public function kbCategoryGetPairs(): array { return $this->di['db']->getAssoc('SELECT id, title FROM support_kb_article_category'); }

    public function kbCategoryRm(\Model_SupportKbArticleCategory $model): bool
    {
        $count = $this->di['db']->getCell('SELECT COUNT(*) FROM support_kb_article WHERE kb_article_category_id = ?', [$model->id]);
        if ($count > 0) throw new InformationException('Cannot remove category which has articles');
        $this->di['db']->trash($model);
        return true;
    }

    public function kbCategoryToApiArray(\Model_SupportKbArticleCategory $model, \Model_Admin|\Model_Client|\Model_Guest|null $identity = null, ?string $query = null): array
    {
        $data = $this->di['db']->toArray($model);
        $sql = 'kb_article_category_id = :cid'; $bindings = [':cid' => $model->id];
        if (!$identity instanceof \Model_Admin) $sql .= " AND status = 'active'";
        if ($query) { $sql .= ' AND (title LIKE :q OR content LIKE :q)'; $bindings[':q'] = "%$query%"; }
        $sql .= ' ORDER BY title';
        $articles = $this->di['db']->find('SupportKbArticle', $sql, $bindings);
        foreach ($articles as $article) $data['articles'][] = $this->kbToApiArray($article, false, $identity);
        return $data;
    }

    public function kbCreateCategory(string $title, ?string $description = null): int
    {
        $model = $this->di['db']->dispense('SupportKbArticleCategory');
        $model->title = $title; $model->description = $description;
        $model->slug = $this->di['tools']->slug($title);
        $model->updated_at = date('Y-m-d H:i:s'); $model->created_at = date('Y-m-d H:i:s');
        return $this->di['db']->store($model);
    }

    public function kbUpdateCategory(\Model_SupportKbArticleCategory $model, ?string $title = null, ?string $slug = null, ?string $description = null): bool
    {
        if ($title !== null) $model->title = $title;
        if ($slug !== null) $model->slug = $slug;
        if ($description !== null) $model->description = $description;
        $model->updated_at = date('Y-m-d H:i:s'); $this->di['db']->store($model);
        return true;
    }

    public function kbFindCategoryById(int $id): \Model_SupportKbArticleCategory
    {
        return $this->di['db']->getExistingModelById('SupportKbArticleCategory', $id, 'KB category not found');
    }

    public function kbFindCategoryBySlug(string $slug): ?\Model_SupportKbArticleCategory
    {
        return $this->di['db']->findOne('SupportKbArticleCategory', 'slug = :slug', [':slug' => $slug]);
    }
        // ============================================================
    // PUBLIC TICKETS
    // ============================================================
    public function publicTicketsEnabled(): bool
    {
        $config = $this->di['mod_service']('extension')->getConfig('mod_support');
        return !(isset($config['disable_public_tickets']) && $config['disable_public_tickets']);
    }

    public function publicGetStatuses(): array
    {
        return ['open' => 'Open', 'on_hold' => 'On Hold', 'closed' => 'Closed'];
    }

    public function publicFindOneByHash(string $hash): \Model_SupportPTicket
    {
        $ticket = $this->di['db']->findOne('SupportPTicket', 'hash = :hash', [':hash' => $hash]);
        if (!$ticket) throw new \FOSSBilling\Exception('Public ticket not found');
        return $ticket;
    }

    public function publicGetSearchQuery(array $data): array
    {
        $query = 'SELECT spt.* FROM support_p_ticket spt LEFT JOIN support_p_ticket_message sptm ON spt.id = sptm.support_p_ticket_id';
        $where = []; $bindings = [];
        if (!empty($data['id'])) { $where[] = 'spt.id = :id'; $bindings[':id'] = $data['id']; }
        if (!empty($data['status'])) { $where[] = 'spt.status = :status'; $bindings[':status'] = $data['status']; }
        if (!empty($data['email'])) { $where[] = 'spt.author_email = :email'; $bindings[':email'] = $data['email']; }
        if (!empty($data['name'])) { $where[] = 'spt.author_name = :name'; $bindings[':name'] = $data['name']; }
        if (!empty($data['content'])) { $where[] = 'spt.content LIKE :content'; $bindings[':content'] = '%' . $data['content'] . '%'; }
        if (!empty($data['subject'])) { $where[] = 'spt.subject LIKE :subject'; $bindings[':subject'] = '%' . $data['subject'] . '%'; }
        $assigned = $data['assigned_helpdesk_ids'] ?? null;
        if ($assigned !== null) {
            if (empty($assigned)) { $where[] = '1 = 0'; }
            else { $phs = []; foreach ($assigned as $k => $id) { $phs[] = ":phid_{$k}"; $bindings[":phid_{$k}"] = $id; } $where[] = 'spt.support_helpdesk_id IN (' . implode(',', $phs) . ')'; }
        }
        if (!empty($data['search'])) {
            $s = $data['search'];
            if (is_numeric($s)) { $where[] = 'spt.id = :sid'; $bindings[':sid'] = $s; }
            else { $where[] = '(sptm.content LIKE :sc OR spt.subject LIKE :ss)'; $bindings[':sc'] = "%{$s}%"; $bindings[':ss'] = "%{$s}%"; }
        }
        if ($where) $query .= ' WHERE ' . implode(' AND ', $where);
        $query .= ' GROUP BY spt.id ORDER BY spt.id DESC';
        return [$query, $bindings];
    }

    public function publicCounter(?array $assigned_helpdesk_ids = null): array
    {
        $bindings = []; $where = [];
        if ($assigned_helpdesk_ids !== null) {
            if (empty($assigned_helpdesk_ids)) return ['total' => 0, 'open' => 0, 'on_hold' => 0, 'closed' => 0];
            $phs = implode(',', array_fill(0, count($assigned_helpdesk_ids), '?'));
            $where[] = "support_helpdesk_id IN ({$phs})"; $bindings = $assigned_helpdesk_ids;
        }
        $query = 'SELECT status, COUNT(id) as counter FROM support_p_ticket';
        if ($where) $query .= ' WHERE ' . implode(' AND ', $where);
        $query .= ' GROUP BY status';
        $data = $this->di['db']->getAssoc($query, $bindings);
        return [
            'total' => (int)($data['open'] ?? 0) + (int)($data['on_hold'] ?? 0) + (int)($data['closed'] ?? 0),
            'open' => (int)($data['open'] ?? 0),
            'on_hold' => (int)($data['on_hold'] ?? 0),
            'closed' => (int)($data['closed'] ?? 0),
        ];
    }

    public function publicGetLatest(): array
    {
        return $this->di['db']->find('SupportPTicket', 'ORDER BY id DESC Limit 10');
    }

    public function publicCountByStatus(string $status): int
    {
        return $this->di['db']->getCell("SELECT COUNT(id) FROM support_p_ticket WHERE status = :status", [':status' => $status]);
    }

    public function publicGetExpired(): array
    {
        return $this->di['db']->find('SupportPTicket', 'status = :status AND DATE_ADD(updated_at, INTERVAL 48 HOUR) < NOW() ORDER BY id ASC', [':status' => 'on_hold']);
    }

    public function publicCloseTicket(\Model_SupportPTicket $model, \Model_Admin|\Model_Guest $identity): bool
    {
        $model->status = 'closed';
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);
        if ($identity instanceof \Model_Admin) {
            $this->di['events_manager']->fire(['event' => 'onAfterAdminPublicTicketClose', 'params' => ['id' => $model->id]]);
            $this->di['logger']->info('Public Ticket %s was closed', $model->id);
        } elseif ($identity instanceof \Model_Guest) {
            $this->di['events_manager']->fire(['event' => 'onAfterGuestPublicTicketClose', 'params' => ['id' => $model->id]]);
            $this->di['logger']->info('"%s" closed public ticket "%s"', $model->author_email, $model->id);
        }
        return true;
    }

    public function publicAutoClose(\Model_SupportPTicket $model): bool
    {
        $model->status = 'closed';
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);
        $this->di['logger']->info('Public Ticket %s was closed', $model->id);
        return true;
    }

    public function publicRm(\Model_SupportPTicket $model): bool
    {
        foreach ($this->di['db']->find('SupportPTicketMessage', 'support_p_ticket_id = :id', [':id' => $model->id]) as $msg) $this->di['db']->trash($msg);
        $this->di['db']->trash($model);
        return true;
    }

    public function publicToApiArray(\Model_SupportPTicket $model, bool $deep = true, $identity = null): array
    {
        $data = $this->di['db']->toArray($model);
        if (!$identity instanceof \Model_Admin) unset($data['author_email']);
        $messagesArr = $this->di['db']->find('SupportPTicketMessage', 'support_p_ticket_id = :id ORDER BY id', [':id' => $model->id]);
        $data['messages'] = [];
        foreach ($messagesArr as $msg) $data['messages'][] = $this->publicMessageToApiArray($msg, true, $identity);
        $first = reset($messagesArr);
        $data['author'] = $first ? $this->publicMessageGetAuthorDetails($first, $identity) : [];
        return $data;
    }

    public function publicMessageGetAuthorDetails(\Model_SupportPTicketMessage $model, $identity = null): array
    {
        if ($model->admin_id) {
            $author = $this->di['db']->getExistingModelById('Admin', $model->admin_id);
            $result = ['name' => $author->getFullName()];
            if ($identity instanceof \Model_Admin) $result['email'] = $author->email;
            return $result;
        }
        $ticket = $this->di['db']->getExistingModelById('SupportPTicket', $model->support_p_ticket_id);
        $result = ['name' => $ticket->author_name];
        if ($identity instanceof \Model_Admin) $result['email'] = $ticket->author_email;
        return $result;
    }

    public function publicMessageToApiArray(\Model_SupportPTicketMessage $model, bool $deep = true, $identity = null): array
    {
        $data = ['id' => $model->id, 'content' => $model->content, 'created_at' => $model->created_at, 'updated_at' => $model->updated_at];
        if ($identity instanceof \Model_Admin) {
            $data['support_p_ticket_id'] = $model->support_p_ticket_id;
            $data['admin_id'] = $model->admin_id;
            $data['ip'] = $model->ip;
        }
        $data['author'] = $this->publicMessageGetAuthorDetails($model, $identity);
        return $data;
    }

    public function publicTicketUpdate(\Model_SupportPTicket $model, $data): bool
    {
        $model->subject = $data['subject'] ?? $model->subject;
        $model->status = $data['status'] ?? $model->status;
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);
        return true;
    }

    public function publicTicketReply(\Model_SupportPTicket $ticket, \Model_Admin $identity, string $content): int
    {
        $msg = $this->di['db']->dispense('SupportPTicketMessage');
        $msg->support_p_ticket_id = $ticket->id;
        $msg->admin_id = $identity->id;
        $msg->content = $content;
        $msg->ip = $this->di['request']->getClientIp();
        $msg->created_at = date('Y-m-d H:i:s');
        $msg->updated_at = date('Y-m-d H:i:s');
        $msgId = $this->di['db']->store($msg);
        $ticket->status = \Model_SupportPTicket::ONHOLD;
        $ticket->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($ticket);
        $this->di['events_manager']->fire(['event' => 'onAfterAdminPublicTicketReply', 'params' => ['id' => $ticket->id]]);
        return $msgId;
    }

    public function publicTicketReplyForGuest(\Model_SupportPTicket $ticket, string $message): string
    {
        if ($ticket->status == 'closed') {
            if ($this->canBeReopenedPublic($ticket)) $ticket->status = 'open';
            else throw new \FOSSBilling\Exception('Este ticket no puede ser reabierto.');
        }
        $msg = $this->di['db']->dispense('SupportPTicketMessage');
        $msg->support_p_ticket_id = $ticket->id;
        $msg->content = $message;
        $msg->ip = $this->di['request']->getClientIp();
        $msg->created_at = date('Y-m-d H:i:s');
        $msg->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($msg);
        $ticket->status = 'open';
        $ticket->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($ticket);
        $this->di['events_manager']->fire(['event' => 'onAfterGuestPublicTicketReply', 'params' => ['id' => $ticket->id]]);
        return $ticket->hash;
    }
}
