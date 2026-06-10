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
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Exception;

class EmailGateway implements InjectionAwareInterface
{
    protected $di;

    public function setDi($di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    private function debugLog($message, $prefix = ''): void
    {
        $full_message = $prefix ? "[{$prefix}] {$message}" : $message;
        if (isset($this->di['logger'])) {
            $this->di['logger']->info("[EmailGateway] " . $full_message);
        }
        error_log("[EmailGateway] " . $full_message);
    }

    // ============================================================
    // PROCESAMIENTO PRINCIPAL DE EMAILS POP3
    // ============================================================

    public function processTicketsFromPop3(): array
    {
    error_log("[EmailGateway] Iniciando processTicketsFromPop3");
        $helpdesks = $this->di['db']->find(
            'support_helpdesk',
            "enable_email = 1 AND email_address IS NOT NULL ORDER BY id ASC"
        );

        if (empty($helpdesks)) {
            return ['status' => 'skipped', 'reason' => 'No hay HelpDesks activos', 'processed' => 0, 'errors' => 0];
        }

        $total_processed = 0;
        $errors = 0;

        foreach ($helpdesks as $desk) {
            $mailbox = null;
            $delete_ids = [];

            try {
                $connection_result = $this->connectAndAuthenticate($desk);

                if (!$connection_result['success']) {
                    error_log("[EmailGateway] No se pudo conectar a {$desk->email_address}: " . $connection_result['message']);
                    continue;
                }

                $mailbox = $connection_result['connection'];
                $msg_count = $this->countMessages($mailbox);

                if ($msg_count === 0) {
                    fwrite($mailbox, "QUIT\r\n");
                    fclose($mailbox);
                    $mailbox = null;
                    continue;
                }

                for ($i = $msg_count; $i >= 1; $i--) {
                    try {
                        $msg_data = $this->getMessageData($mailbox, $i);
                        $from = $msg_data['from'] ?? '';
                        $subject = $msg_data['subject'] ?? '(Sin asunto)';
                        $body_raw = $msg_data['body'] ?? '';

                        if (method_exists('\Box\Mod\Support\Service', 'sanitizeMessageContent')) {
                            $body_raw = \Box\Mod\Support\Service::sanitizeMessageContent($body_raw);
                        } else {
                            $body_raw = strip_tags(html_entity_decode($body_raw, ENT_QUOTES, 'UTF-8'));
                            $body_raw = trim(preg_replace('/\s+/', ' ', $body_raw));
                        }
                        $subject = trim(strip_tags($subject));

                        if (empty($from)) {
                            $delete_ids[] = $i;
                            continue;
                        }

                        $result = $this->createOrUpdateTicket($subject, $body_raw, $from, (int)$desk->id, $msg_data['references'] ?? '');

                        if ($result['success']) {
                            $delete_ids[] = $i;
                            $total_processed++;
                        } else {
                            $delete_ids[] = $i;
                        }

                    } catch (\Exception $e) {
                        $errors++;
                        error_log("[EmailGateway Error] Excepción en mensaje #{$i}: " . $e->getMessage());
                    }
                }

                foreach ($delete_ids as $msg_id) {
                    fwrite($mailbox, "DELE {$msg_id}\r\n");
                    fgets($mailbox, 1024);
                }

                fwrite($mailbox, "QUIT\r\n");
                fclose($mailbox);
                $mailbox = null;

            } catch (\Exception $e) {
                $errors++;
                error_log("[EmailGateway] Error crítico en helpdesk {$desk->id}: " . $e->getMessage());
                if ($mailbox !== null) {
                    try { fwrite($mailbox, "QUIT\r\n"); fclose($mailbox); } catch (\Exception $close_e) {}
                    $mailbox = null;
                }
            }
        }

        return [
            'status' => 'completed',
            'processed' => $total_processed,
            'errors' => $errors,
            'helpdesks_checked' => count($helpdesks),
        ];
    }

    // ============================================================
    // CREAR O ACTUALIZAR TICKET
    // ============================================================

	private function createOrUpdateTicket(string $subject, string $body, string $from, int $helpdesk_id, string $references = ''): array
    {
    error_log("[EmailGateway] createOrUpdateTicket llamado: from={$from}, helpdesk={$helpdesk_id}");
        $this->debugLog("createOrUpdateTicket: from={$from}, helpdesk={$helpdesk_id}, subject={$subject}", "Gateway");

        $db = $this->di['db'];
        $supportService = $this->di['mod_service']('support');

        $helpdeskModel = $db->getExistingModelById('SupportHelpdesk', $helpdesk_id, 'Helpdesk not found');
        $access_level = strtolower(trim($helpdeskModel->access_level ?? 'public'));

        $client = null;
        $admin = null;
        $user_type = 'guest';

        $client_bean = $db->findOne('client', 'email = ? AND status = ?', [$from, 'active']);
        if ($client_bean) {
            $client = $db->getExistingModelById('Client', $client_bean->id, 'Client not found');
            $user_type = 'client';
        } else {
            $admin_bean = $db->findOne('admin', 'email = ? AND status = ?', [$from, 'active']);
            if ($admin_bean) {
                $admin = $db->getExistingModelById('Admin', $admin_bean->id, 'Admin not found');
                $user_type = ($admin->role === 'admin') ? 'admin' : 'staff';
            }
        }
    // Agregar references al subject para búsqueda por Message-ID
    $searchSubject = $subject . ' ' . $references;
        // PASO 2 — Tickets de Staff
        if ($user_type === 'admin' || $user_type === 'staff') {
            if ($access_level === 'staff' || !empty($helpdeskModel->allow_staff_tickets)) {
                if ($admin && $this->isStaffAuthorized($admin, $helpdeskModel)) {
                    $found_ticket = $this->findTicketBySubjectPattern($searchSubject, $from);
                    if ($found_ticket && $found_ticket['type'] === 'staff') {
                        $this->debugLog("Agregando respuesta a Staff Ticket #{$found_ticket['id']}", "Gateway");
                        return $this->_addMessageToStaffTicket($found_ticket['model'], $admin, $body);
                    }
                    $this->debugLog("Creando nuevo Staff Ticket", "Gateway");
                    return $this->_createStaffTicket($admin, $helpdesk_id, $subject, $body);
                } else {
                    return ['success' => false, 'message' => "Staff no autorizado para esta mesa."];
                }
            } else {
                return ['success' => false, 'message' => "Staff sin permiso en esta mesa."];
            }
        }

        // PASO 3 — Tickets de Cliente
        if ($user_type === 'client') {
            if ($access_level === 'clients' || $access_level === 'hybrid' || !empty($helpdeskModel->allow_client_tickets)) {
                if ($client) {
                    $found_ticket = $this->findTicketBySubjectPattern($searchSubject, $from);
                    if ($found_ticket && $found_ticket['type'] === 'client') {
                        return $this->_addMessageToClientTicket($found_ticket['model'], $client, $body);
                    } else {
                        return $this->_createClientTicket($client, $helpdesk_id, $subject, $body);
                    }
                }
            } else {
                return ['success' => false, 'message' => "Clientes sin permiso en esta mesa."];
            }
        }

        // PASO 4 — Tickets Públicos (Guest)
        if ($user_type === 'guest') {
    	// Verificar si los tickets públicos están desactivados en configuración
   	 $config = $this->di['mod_service']('extension')->getConfig('mod_support');
    	if (!empty($config['disable_public_tickets'])) {
        	$this->debugLog("Tickets públicos desactivados en configuración", "Gateway");
        	return ['success' => false, 'message' => "Tickets públicos desactivados."];
    	}
    	if ($access_level === 'public' || $access_level === 'hybrid' || !empty($helpdeskModel->allow_public_tickets)) {
                $existing_open = $db->findOne(
                    'SupportPTicket',
                    'author_email = ? AND subject = ? ORDER BY id DESC LIMIT 1',
                    [$from, $subject]
                );

                if ($existing_open) {
                    $this->debugLog("Ticket público existente encontrado #{$existing_open->id} — agregando respuesta", "Gateway");
                    return $this->_addMessageToPublicTicket($existing_open, $from, $body);
                }

                $found_ticket = $this->findTicketBySubjectPattern($searchSubject, $from);
                if ($found_ticket && $found_ticket['type'] === 'public') {
                    return $this->_addMessageToPublicTicket($found_ticket['model'], $from, $body);
                }

                return $this->_createPublicTicket($from, $from, $helpdesk_id, $subject, $body);
            } else {
                return ['success' => false, 'message' => "Invitados sin permiso en esta mesa."];
            }
        }

        return ['success' => false, 'message' => "Remitente no autorizado."];
    }

    // ============================================================
    // MÉTODOS DE CONEXIÓN POP3
    // ============================================================

private function connectAndAuthenticate($desk): array
{
    $email_parts = explode('@', $desk->email_address);
    if (count($email_parts) < 2) {
        return ['success' => false, 'message' => 'Formato de email inválido'];
    }

    $pop3_user = $desk->email_address;
    $pop3_domain = !empty($desk->pop3_host) ? $desk->pop3_host : $email_parts[1];
    $port = intval($desk->pop3_port ?? 995);
    $encryption = strtolower($desk->pop3_encryption ?? 'ssl');

    // Siempre usar tcp:// y luego habilitar crypto si es necesario
    $host_string = "tcp://{$pop3_domain}:{$port}";
    $errno = 0; $errstr = '';
    $resource = @stream_socket_client($host_string, $errno, $errstr, 15);

    if (!$resource) {
        return ['success' => false, 'message' => "Conexión fallida: {$errstr} ({$errno})"];
    }

    // Habilitar TLS/SSL después de conectar
    if (in_array($encryption, ['ssl', 'tls'])) {
        $crypto_result = @stream_socket_enable_crypto($resource, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if (!$crypto_result) {
            fclose($resource);
            return ['success' => false, 'message' => 'Error al habilitar cifrado TLS'];
        }
    }

    $banner = fgets($resource, 1024);
    if (strpos($banner, '+OK') === false) {
        fclose($resource);
        return ['success' => false, 'message' => "Greeting inválido: {$banner}"];
    }

    fwrite($resource, "USER {$pop3_user}\r\n");
    $user_response = fgets($resource, 1024);
    if (strpos($user_response, '+OK') === false) {
        fwrite($resource, "QUIT\r\n");
        fclose($resource);
        return ['success' => false, 'message' => "Usuario rechazado: {$user_response}"];
    }

    $pop3_password = $this->di['mod_service']('support')->decryptPassword($desk->pop3_password);
    fwrite($resource, "PASS {$pop3_password}\r\n");
    $pass_response = fgets($resource, 1024);
    if (strpos($pass_response, '+OK') === false) {
        fwrite($resource, "QUIT\r\n");
        fclose($resource);
        return ['success' => false, 'message' => "Contraseña incorrecta: {$pass_response}"];
    }

    return ['success' => true, 'connection' => $resource];
}
    private function countMessages($mailbox): int { fwrite($mailbox, "STAT\r\n"); $response = fgets($mailbox, 1024); if (preg_match('/(\d+)/', $response, $matches)) return (int)$matches[1]; return 0; }

private function getMessageData($mailbox, $msg_id): array
{
    fwrite($mailbox, "RETR {$msg_id}\r\n");
    $first_line = fgets($mailbox, 1024);
    if (strpos($first_line, '+OK') === false) return ['from' => '', 'subject' => '', 'body' => ''];

    $raw_lines = [];
    while (($line = fgets($mailbox, 4096)) !== false) {
        $trimmed = rtrim($line, "\r\n");
        if ($trimmed === '.') break;
        if (substr($trimmed, 0, 2) === '..') $trimmed = substr($trimmed, 1);
        $raw_lines[] = $trimmed;
    }

    $headers = []; $body_lines = []; $in_body = false; $current_header = '';
    foreach ($raw_lines as $line) {
        if (!$in_body) {
            if ($line === '') { if ($current_header !== '') $headers[] = $current_header; $in_body = true; continue; }
            if (($line[0] === ' ' || $line[0] === "\t") && $current_header !== '') $current_header .= ' ' . trim($line);
            else { if ($current_header !== '') $headers[] = $current_header; $current_header = $line; }
        } else { $body_lines[] = $line; }
    }

    $from = ''; $subject = '';
    foreach ($headers as $header) {
        if (stripos($header, 'From:') === 0) {
            $value = trim(substr($header, 5));
            if (preg_match('/<([^>]+@[^>]+)>/', $value, $m)) $from = trim($m[1]);
            elseif (filter_var(trim($value), FILTER_VALIDATE_EMAIL)) $from = trim($value);
            elseif (preg_match('/([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})/', $value, $m)) $from = $m[1];
        }
        if (stripos($header, 'Subject:') === 0) $subject = mb_decode_mimeheader(trim(substr($header, 8)));
    }

    $body_raw = implode("\n", $body_lines);
    $encoding = ''; $boundary = '';
    foreach ($headers as $header) {
        if (stripos($header, 'Content-Transfer-Encoding:') === 0) $encoding = strtolower(trim(substr($header, 26)));
        if (stripos($header, 'Content-Type:') === 0) {
            if (preg_match('/boundary="?([^";]+)"?/i', $header, $bm)) $boundary = trim($bm[1]);
        }
    }

    // Extraer cuerpo con soporte de charset
    $charset = 'UTF-8';
    foreach ($headers as $header) {
        if (stripos($header, 'Content-Type:') === 0 && preg_match('/charset="?([^";\s]+)"?/i', $header, $cm)) {
            $charset = strtoupper(trim($cm[1]));
        }
    }

    $body = $body_raw;
    if (!empty($boundary)) {
        $parts = explode('--' . $boundary, $body_raw);
        $html_fallback = '';
        foreach ($parts as $part) {
            $part = ltrim($part, "\r\n");
            if (empty($part) || $part === '--') continue;
            
            $part_break = strpos($part, "\r\n\r\n");
            if ($part_break === false) $part_break = strpos($part, "\n\n");
            if ($part_break === false) continue;
            
            $part_headers = substr($part, 0, $part_break);
            $part_body = substr($part, $part_break + 2);
            
            $part_encoding = '';
            if (preg_match('/Content-Transfer-Encoding:\s*([^\r\n]+)/i', $part_headers, $em)) {
                $part_encoding = strtolower(trim($em[1]));
            }
            
            $part_charset = $charset;
            if (preg_match('/charset="?([^";\s]+)"?/i', $part_headers, $pcm)) {
                $part_charset = strtoupper(trim($pcm[1]));
            }
            
            $decoded = $part_body;
            if ($part_encoding === 'base64') {
                $decoded = base64_decode(str_replace(["\r", "\n"], '', $part_body));
            } elseif ($part_encoding === 'quoted-printable') {
                $decoded = quoted_printable_decode($part_body);
            }
            
            if ($part_charset !== 'UTF-8' && $part_charset !== 'UTF8') {
                $decoded = @mb_convert_encoding($decoded, 'UTF-8', $part_charset);
            }
            
            if (preg_match('/Content-Type:\s*text\/plain/i', $part_headers)) {
                $clean = trim(strip_tags($decoded));
                if (!empty($clean) && strlen($clean) > 10) {
                    $body = $clean;
                    break;
                }
            } elseif (preg_match('/Content-Type:\s*text\/html/i', $part_headers)) {
                $html_fallback = trim(strip_tags(html_entity_decode($decoded, ENT_QUOTES, 'UTF-8')));
            }
        }
        if (empty($body) || strlen($body) < 10) {
            $body = !empty($html_fallback) ? $html_fallback : $body_raw;
        }
    } elseif ($encoding === 'base64') {
        $body = base64_decode(str_replace(["\r", "\n"], '', $body_raw));
        if ($charset !== 'UTF-8' && $charset !== 'UTF8') {
            $body = @mb_convert_encoding($body, 'UTF-8', $charset);
        }
    } elseif ($encoding === 'quoted-printable') {
        $body = quoted_printable_decode($body_raw);
        if ($charset !== 'UTF-8' && $charset !== 'UTF8') {
            $body = @mb_convert_encoding($body, 'UTF-8', $charset);
        }
    }
    
    // Eliminar texto citado (respuesta de Gmail/Outlook)
    $body = preg_replace('/^>.*$/m', '', $body);
    $body = trim($body);
    // Extraer References/In-Reply-To de los headers
	$references = '';
	foreach ($headers as $header) {
    	if (stripos($header, 'References:') === 0) {
        $references .= ' ' . trim(substr($header, 11));
    	}
    	if (stripos($header, 'In-Reply-To:') === 0) {
        $references .= ' ' . trim(substr($header, 12));
    	}
	}
return ['from' => $from,'subject' => $subject ?: '(Sin asunto)','body' => $body, 'references' => trim($references),];
	}
    // ============================================================
    // AUTORIZACIÓN
    // ============================================================
    private function isEmailInList(string $email, ?string $list_str): bool { if (empty($list_str)) return false; return in_array($email, array_map('trim', explode(',', $list_str))); }
    private function isStaffAuthorized(\Model_Admin $admin, \Model_SupportHelpdesk $helpdesk): bool { if ($this->isEmailInList($admin->email, $helpdesk->authorized_users)) return true; return in_array($admin->id, array_map('intval', explode(',', $helpdesk->assigned_staff ?? ''))); }

    // ============================================================
    // BÚSQUEDA DE TICKET POR PATRÓN
    // ============================================================
private function findTicketBySubjectPattern(string $subject, string $from_email): ?array
{
    $db = $this->di['db'];
    
    error_log("[DEBUG findTicket] Buscando en: " . $subject);
    
    // ⭐ NUEVO: Buscar por ref_header guardado en References/In-Reply-To
    if (preg_match_all('/<([^>]+@[^>]+)>/', $subject, $msgMatches)) {
        error_log("[DEBUG findTicket] Referencias encontradas: " . json_encode($msgMatches[1]));
        foreach ($msgMatches[1] as $refId) {
            error_log("[DEBUG findTicket] Buscando ref_header: " . $refId);
            
            // Buscar en staff tickets
            $staffTicket = $db->getRow("SELECT * FROM support_staff_ticket WHERE ref_header = ?", [$refId]);
            if ($staffTicket) {
                error_log("[DEBUG findTicket] ENCONTRADO staff ticket #{$staffTicket['id']} por ref_header");
                $admin = $db->findOne('admin', 'email = ? AND status = ?', [$from_email, 'active']);
                if ($admin) {
                    return ['type' => 'staff', 'id' => $staffTicket['id'], 'model' => $staffTicket];
                }
            }
            
            // Buscar en client tickets
            $ticket = $db->findOne('SupportTicket', 'ref_header = ?', [$refId]);
            if ($ticket) {
                error_log("[DEBUG findTicket] ENCONTRADO client ticket #{$ticket->id} por ref_header");
                $client = $db->findOne('client', 'email = ? AND status = ?', [$from_email, 'active']);
                if ($client && $ticket->client_id == $client->id) {
                    return ['type' => 'client', 'id' => $ticket->id, 'model' => $ticket];
                }
            }
            
            // Buscar en public tickets
            $publicTicket = $db->findOne('SupportPTicket', 'ref_header = ?', [$refId]);
            if ($publicTicket) {
                error_log("[DEBUG findTicket] ENCONTRADO public ticket #{$publicTicket->id} por ref_header");
                return ['type' => 'public', 'id' => $publicTicket->id, 'model' => $publicTicket];
            }
        }
    }

    // ⭐ NUEVO: Buscar por message_id original (respaldo cuando Gmail rompe referencias)
    // Recorremos de nuevo las referencias para buscar por message_id original del ticket
    if (preg_match_all('/<([^>]+@[^>]+)>/', $subject, $msgMatches)) {
        foreach ($msgMatches[1] as $refId) {
            // Buscar en staff tickets por message_id original
            $staffTicket = $db->getRow("SELECT * FROM support_staff_ticket WHERE message_id = ?", [$refId]);
            if ($staffTicket) {
                error_log("[DEBUG findTicket] ENCONTRADO staff ticket #{$staffTicket['id']} por message_id original");
                $admin = $db->findOne('admin', 'email = ? AND status = ?', [$from_email, 'active']);
                if ($admin) {
                    return ['type' => 'staff', 'id' => $staffTicket['id'], 'model' => $staffTicket];
                }
            }
            
            // Buscar en client tickets por message_id original
            $ticket = $db->findOne('SupportTicket', 'message_id = ?', [$refId]);
            if ($ticket) {
                error_log("[DEBUG findTicket] ENCONTRADO client ticket #{$ticket->id} por message_id original");
                $client = $db->findOne('client', 'email = ? AND status = ?', [$from_email, 'active']);
                if ($client && $ticket->client_id == $client->id) {
                    return ['type' => 'client', 'id' => $ticket->id, 'model' => $ticket];
                }
            }
            
            // Buscar en public tickets por message_id original
            $publicTicket = $db->findOne('SupportPTicket', 'message_id = ?', [$refId]);
            if ($publicTicket) {
                error_log("[DEBUG findTicket] ENCONTRADO public ticket #{$publicTicket->id} por message_id original");
                return ['type' => 'public', 'id' => $publicTicket->id, 'model' => $publicTicket];
            }
        }
    }    
    // Búsqueda por [Ticket #ID] en asunto (clientes)
    if (preg_match('/\[Ticket #(\d+)\]/', $subject, $matches)) {
        $ticket_id = (int)$matches[1];
        $client = $db->findOne('client', 'email = ? AND status = ?', [$from_email, 'active']);
        if ($client) {
            $ticket = $db->findOne('SupportTicket', 'id = ? AND client_id = ?', [$ticket_id, $client->id]);
            if ($ticket) return ['type' => 'client', 'id' => $ticket->id, 'model' => $ticket];
        }
    }
    
    // Búsqueda por [Staff Ticket #ID] en asunto
    if (preg_match('/\[Staff Ticket #(\d+)\]/', $subject, $matches)) {
        $ticket_id = (int)$matches[1];
        $admin_bean = $db->findOne('admin', 'email = ? AND status = ?', [$from_email, 'active']);
        if ($admin_bean) {
            $count = $db->getCell("SELECT COUNT(*) FROM support_staff_ticket_message WHERE support_ticket_id = ? AND admin_id = ?", [$ticket_id, $admin_bean->id]);
            if ($count > 0) {
                $ticket = $db->getRow("SELECT * FROM support_staff_ticket WHERE id = ?", [$ticket_id]);
                if ($ticket) return ['type' => 'staff', 'id' => $ticket['id'], 'model' => $ticket];
            }
        }
    }
    
    // Búsqueda por [Ticket #ID] en asunto (públicos)
    if (preg_match('/\[Ticket #(\d+)\]/', $subject, $matches)) {
        $ticket_id = (int)$matches[1];
        $ticket = $db->findOne('SupportPTicket', 'id = ? AND author_email = ?', [$ticket_id, $from_email]);
        if ($ticket) return ['type' => 'public', 'id' => $ticket->id, 'model' => $ticket];
    }
    
    return null;
}
    // ============================================================
    // CREACIÓN Y RESPUESTA DE TICKETS
    // ============================================================
    private function _createStaffTicket(\Model_Admin $admin, int $helpdesk_id, string $subject, string $body): array { $s = $this->di['mod_service']('support'); try { $id = $s->ticketCreateForStaff($helpdesk_id, $admin, ['subject' => $subject, 'content' => $body]); return ['success' => true, 'message' => "Staff Ticket creado.", 'ticket_id' => $id, 'type' => 'staff']; } catch (\Exception $e) { return ['success' => false, 'message' => "Error: " . $e->getMessage()]; } }
    private function _createClientTicket(\Model_Client $client, int $helpdesk_id, string $subject, string $body): array { $s = $this->di['mod_service']('support'); try { $h = $this->di['db']->getExistingModelById('SupportHelpdesk', $helpdesk_id); $id = $s->ticketCreateForClient($client, $h, ['subject' => $subject, 'content' => $body, 'skip_before_client_open' => true]); return ['success' => true, 'ticket_id' => $id, 'type' => 'client']; } catch (\Exception $e) { return ['success' => false, 'message' => "Error: " . $e->getMessage()]; } }
    private function _addMessageToClientTicket(\Model_SupportTicket $ticket, \Model_Client $client, string $body): array { $s = $this->di['mod_service']('support'); try { $s->ticketReply($ticket, $client, $body); return ['success' => true, 'ticket_id' => $ticket->id, 'type' => 'client']; } catch (\Exception $e) { return ['success' => false, 'message' => "Error: " . $e->getMessage()]; } }
    private function _createPublicTicket(string $author_name, string $author_email, int $helpdesk_id, string $subject, string $body): array { $s = $this->di['mod_service']('support'); try { $id = $s->ticketCreateForGuest(['name' => $author_name, 'email' => $author_email, 'subject' => $subject, 'message' => $body, 'support_helpdesk_id' => $helpdesk_id]); return ['success' => true, 'ticket_id' => $id, 'type' => 'public']; } catch (\Exception $e) { return ['success' => false, 'message' => "Error: " . $e->getMessage()]; } }

    private function _addMessageToPublicTicket(\Model_SupportPTicket $ticket, string $author_email, string $body): array
    {
        $supportService = $this->di['mod_service']('support');
        try {
            $cleanBody = preg_replace('/^>.*$/m', '', $body);
            $cleanBody = trim(preg_replace("/\n{3,}/", "\n\n", $cleanBody));
            if (empty($cleanBody)) { $cleanBody = $body; }
            $supportService->publicTicketReplyForGuest($ticket, $cleanBody);
            return ['success' => true, 'message' => "Respuesta añadida.", 'ticket_id' => $ticket->id, 'type' => 'public'];
        } catch (\Exception $e) { return ['success' => false, 'message' => "Error: " . $e->getMessage()]; }
    }

    private function _addMessageToStaffTicket($ticket, \Model_Admin $admin, string $body): array
    {
        try {
            $db = $this->di['db']; $now = date('Y-m-d H:i:s');
            $ticketId = is_object($ticket) ? $ticket->id : (is_array($ticket) ? $ticket['id'] : $ticket);
            $cleanBody = preg_replace('/^>.*$/m', '', $body);
            $cleanBody = trim(preg_replace("/\n{3,}/", "\n\n", $cleanBody));
            if (empty($cleanBody)) { $cleanBody = $body; }
            $db->exec("INSERT INTO support_staff_ticket_message (support_ticket_id, admin_id, content, ip, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)", [$ticketId, $admin->id, $cleanBody, $this->di['request']->getClientIp() ?? '127.0.0.1', $now, $now]);
            $currentStatus = is_object($ticket) ? $ticket->status : (is_array($ticket) ? $ticket['status'] : 'closed');
            $db->exec("UPDATE support_staff_ticket SET status = 'open', updated_at = ? WHERE id = ?", [$now, $ticketId]);
            return ['success' => true, 'message' => "Respuesta añadida.", 'ticket_id' => $ticketId, 'type' => 'staff'];
        } catch (\Exception $e) { return ['success' => false, 'message' => "Error: " . $e->getMessage()]; }
    }

    // ============================================================
    // TEST DE CONEXIÓN
    // ============================================================
	public function getSmtpConfig(int $helpdesk_id): array
	{ 
	    $d = $this->di['db']->findOne('support_helpdesk', "id = ? AND enable_email = 1", [$helpdesk_id]); 
	    if (!$d || !$d->smtp_host) return [];
	    $supportService = $this->di['mod_service']('support');
	    return [
	        'host' => $d->smtp_host, 'port' => $d->smtp_port ?? 587,
	        'encryption' => $d->smtp_encryption ?? 'tls',
	        'username' => $d->smtp_user ?? $d->email_address,
	        'password' => $supportService->decryptPassword($d->smtp_pass),
	        'from_email' => $d->email_address,
	        'from_name' => $d->from_name ?? $d->name ?? 'Soporte'
	    ]; 
	}

	public function testConnection(int $helpdesk_id): array
	{
	ini_set('display_errors', '1');
error_reporting(E_ALL);
	    try {
	        $desk = $this->di['db']->findOne('support_helpdesk', "id = ?", [$helpdesk_id]);
	        if (!$desk || !$desk->email_address) return ['success' => false, 'message' => 'Credenciales incompletas'];
        
	        $supportService = $this->di['mod_service']('support');
	        $pop3_password = $supportService->decryptPassword($desk->pop3_password);
	        $smtp_password = $supportService->decryptPassword($desk->smtp_pass);
	        $host = !empty($desk->pop3_host) ? $desk->pop3_host : explode('@', $desk->email_address)[1];
	        $port = intval($desk->pop3_port ?? 995);
	        $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
	        $mailbox = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
	        if (!$mailbox) return ['success' => false, 'message' => "Error POP3: {$errstr} ({$errno})"];
	        if (strpos(fgets($mailbox, 1024), '+OK') === false) { fclose($mailbox); return ['success' => false, 'message' => 'Greeting inválido']; }
	        fwrite($mailbox, "USER {$desk->email_address}\r\n");
	        if (strpos(fgets($mailbox, 1024), '+OK') === false) { fwrite($mailbox, "QUIT\r\n"); fclose($mailbox); return ['success' => false, 'message' => 'Usuario rechazado']; }
	        fwrite($mailbox, "PASS {$pop3_password}\r\n");
	        if (strpos(fgets($mailbox, 1024), '+OK') === false) { fwrite($mailbox, "QUIT\r\n"); fclose($mailbox); return ['success' => false, 'message' => 'Contraseña incorrecta']; }
	        fwrite($mailbox, "STAT\r\n"); preg_match('/(\d+)/', fgets($mailbox, 1024), $m); $msg_count = isset($m[1]) ? (int)$m[1] : 0;
	        fclose($mailbox);
        
	        $scheme = ($desk->smtp_encryption ?? 'tls') === 'ssl' ? 'smtps' : 'smtp';
	        $dsn = "{$scheme}://" . rawurlencode($desk->smtp_user ?? $desk->email_address) . ":" . rawurlencode($smtp_password) . "@{$desk->smtp_host}:" . ($desk->smtp_port ?? 587);
	        (new Mailer(Transport::fromDsn($dsn)))->send((new Email())->from($desk->email_address)->to($desk->email_address)->subject('Prueba')->text('OK'));
        
	        return ['success' => true, 'message' => "POP3 OK ({$msg_count} msgs), SMTP OK"];
	    } catch (\Exception $e) { return ['success' => false, 'message' => 'Error: ' . $e->getMessage()]; }
	}
}
