<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * Copyright 2011-2021 BoxBilling, Inc.
 * SPDX-License-Identifier: Apache-2.0.
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
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
 
namespace Box\Mod\Support\Api;

use FOSSBilling\PaginationOptions;
use FOSSBilling\Validation\Api\RequiredParams;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

class Admin extends \Api_Abstract
{
    // ============================================================
    // INSTALACIÓN DE TABLAS
    // ============================================================
    public function check_and_install_tables($data)
    {
        $db = $this->di['db'];
        $required_tables = ['support_staff_ticket', 'support_staff_ticket_message', 'support_staff_ticket_note', 'support_whitelist', 'support_blacklist'];
        $missing = [];
        foreach ($required_tables as $table) {
            $exists = $db->getCell("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?", [$table]);
            if (!$exists) $missing[] = $table;
        }
        $required_columns = ['enable_email', 'email_address', 'pop3_host', 'access_level', 'assigned_staff'];
        $existing_columns = $db->getAll("SHOW COLUMNS FROM support_helpdesk");
        $column_names = array_column($existing_columns, 'Field');
        $missing_columns = array_diff($required_columns, $column_names);
        $pticket_columns = $db->getAll("SHOW COLUMNS FROM support_p_ticket");
        $pticket_col_names = array_column($pticket_columns, 'Field');
        $missing_pticket = !in_array('support_helpdesk_id', $pticket_col_names);
        return ['needs_install' => !empty($missing) || !empty($missing_columns) || $missing_pticket, 'missing_tables' => $missing, 'missing_columns' => $missing_columns, 'missing_pticket_column' => $missing_pticket];
    }

    public function install_support_tables($data)
    {
        if (empty($data['confirm']) || $data['confirm'] !== 'YES_I_HAVE_BACKUP') {
            throw new \FOSSBilling\Exception('Debe confirmar que tiene copia de seguridad.');
        }
        try {
            $this->getService()->install();
            return ['success' => true, 'message' => 'Tablas instaladas correctamente.'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    // ============================================================
    // MÉTODOS UNIFICADOS DE LISTAS Y CONTADORES
    // ============================================================
    private function _getTicketList(string $table, string $serviceMethod, array $data): array
    {
        $data = $this->_applyHelpdeskFilter($data);
        switch ($table) {
            case 'support_ticket': [$sql, $bindings] = $this->getService()->getSearchQuery($data); break;
            case 'support_p_ticket': [$sql, $bindings] = $this->getService()->publicGetSearchQuery($data); break;
            case 'support_staff_ticket': [$sql, $bindings] = $this->getService()->staffGetSearchQuery($data); break;
            default: return ['list' => [], 'total' => 0, 'pages' => 1];
        }
        $pager = $this->di['pager']->getPaginatedResultSet($sql, $bindings, PaginationOptions::fromArray($data));
        foreach ($pager['list'] as $key => $ticketArr) {
            if ($table === 'support_staff_ticket') {
                $ticket = $this->di['db']->getRow("SELECT * FROM support_staff_ticket WHERE id = ?", [$ticketArr['id']]);
                if ($ticket) $pager['list'][$key] = $this->getService()->$serviceMethod($ticket, true, $this->getIdentity());
            } else {
                $modelName = $table === 'support_ticket' ? 'SupportTicket' : 'SupportPTicket';
                $ticket = $this->di['db']->getExistingModelById($modelName, $ticketArr['id'], 'Ticket not found');
                $pager['list'][$key] = $this->getService()->$serviceMethod($ticket, true, $this->getIdentity());
            }
        }
        return $pager;
    }

    private function _getTicketCounters(string $table, array $data): array
    {
        $identity = $this->getIdentity();
        if ($identity instanceof \Model_Admin && $identity->role === 'admin') $assigned_ids = null;
        else $assigned_ids = $this->_getAssignedHelpdeskIds();
        switch ($table) {
            case 'support_ticket': return $this->getService()->counter($assigned_ids);
            case 'support_p_ticket': return $this->getService()->publicCounter($assigned_ids);
            case 'support_staff_ticket': return $this->getService()->staffCounter($assigned_ids);
        }
        return ['total' => 0];
    }

    // ============================================================
    // TICKETS DE CLIENTE
    // ============================================================
    public function ticket_get_list(array $data): array
    {
        return $this->_getTicketList('support_ticket', 'toApiArray', $data);
    }

    #[RequiredParams(['id' => 'Ticket ID is missing'])]
    public function ticket_get(array $data): array
    {
        $this->_checkAdminTicketAccess((int) $data['id']);
        $model = $this->di['db']->getExistingModelById('SupportTicket', $data['id'], 'Ticket not found');
        return $this->getService()->toApiArray($model, true, $this->getIdentity());
    }

    #[RequiredParams(['id' => 'Ticket ID is missing'])]
    public function ticket_update(array $data): bool
    {
        $model = $this->di['db']->getExistingModelById('SupportTicket', $data['id'], 'Ticket not found');
        return $this->getService()->ticketUpdate($model, $data);
    }

    #[RequiredParams(['id' => 'Ticket message ID is missing', 'content' => 'Ticket message content is missing'])]
    public function ticket_message_update(array $data): bool
    {
        $this->_checkAdminTicketAccessByMessageId((int) $data['id']);
        $model = $this->di['db']->getExistingModelById('SupportTicketMessage', $data['id'], 'Ticket message not found');
        return $this->getService()->ticketMessageUpdate($model, $data['content']);
    }

    #[RequiredParams(['id' => 'Ticket ID is missing'])]
    public function ticket_delete(array $data): bool
    {
        $this->_checkAdminTicketAccess((int) $data['id']);
        $model = $this->di['db']->getExistingModelById('SupportTicket', $data['id'], 'Ticket not found');
        return $this->getService()->rm($model);
    }

    #[RequiredParams(['id' => 'Ticket ID is missing', 'content' => 'Ticket message content is missing'])]
    public function ticket_reply(array $data): int
    {
        $this->_checkAdminTicketAccess((int) $data['id']);
        $data['content'] = \FOSSBilling\Tools::sanitizeContent($data['content'], true);
        $ticket = $this->di['db']->getExistingModelById('SupportTicket', $data['id'], 'Ticket not found');
        return $this->getService()->ticketReply($ticket, $this->getIdentity(), $data['content']);
    }

    #[RequiredParams(['id' => 'Ticket ID is missing'])]
    public function ticket_close(array $data): bool
    {
        $this->_checkAdminTicketAccess((int) $data['id']);
        $ticket = $this->di['db']->getExistingModelById('SupportTicket', $data['id'], 'Ticket not found');
        if ($ticket->status == \Model_SupportTicket::CLOSED) return true;
        return $this->getService()->closeTicket($ticket, $this->getIdentity());
    }

    #[RequiredParams(['client_id' => 'Client ID is missing', 'content' => 'Ticket content required', 'subject' => 'Ticket subject required', 'support_helpdesk_id' => 'Ticket support_helpdesk_id is required'])]
    public function ticket_create(array $data): int
    {
        $this->_checkAdminHelpdeskAccess((int) $data['support_helpdesk_id']);
        $data['content'] = \FOSSBilling\Tools::sanitizeContent($data['content'], true);
        $client = $this->di['db']->getExistingModelById('Client', $data['client_id'], 'Client not found');
        $helpdesk = $this->di['db']->getExistingModelById('SupportHelpdesk', $data['support_helpdesk_id'], 'Helpdesk invalid');
        return $this->getService()->ticketCreateForAdmin($client, $helpdesk, $data, $this->getIdentity());
    }

    public function batch_ticket_auto_close($data): bool
    {
        foreach ($this->getService()->getExpired() as $ticketArr) {
            $ticketModel = $this->di['db']->getExistingModelById('SupportTicket', $ticketArr['id'], 'Ticket not found');
            if (!$this->getService()->autoClose($ticketModel)) {
                $this->di['logger']->info('Ticket %s was not closed', $ticketModel->id);
            }
        }
        return true;
    }

    public function batch_public_ticket_auto_close($data): bool
    {
        foreach ($this->getService()->publicGetExpired() as $model) {
            if (!$this->getService()->publicAutoClose($model)) {
                $this->di['logger']->info('Public Ticket %s was not closed', $model->id);
            }
        }
        return true;
    }

    public function ticket_get_statuses(array $data): array
    {
        if (isset($data['titles'])) return $this->getService()->getStatuses();
        return $this->_getTicketCounters('support_ticket', $data);
    }

    // ============================================================
    // TICKETS PÚBLICOS
    // ============================================================
    public function public_ticket_get_list(array $data): array
    {
        return $this->_getTicketList('support_p_ticket', 'publicToApiArray', $data);
    }

    #[RequiredParams(['name' => 'Name is required', 'email' => 'Email is required', 'subject' => 'Subject is required', 'message' => 'Message is required'])]
    public function public_ticket_create(array $data): int
    {
        return $this->getService()->publicTicketCreate($data, $this->getIdentity());
    }

    #[RequiredParams(['id' => 'Ticket ID is missing'])]
    public function public_ticket_get(array $data): array
    {
        $this->_checkAdminPublicTicketAccess((int) $data['id']);
        $model = $this->di['db']->getExistingModelById('SupportPTicket', $data['id'], 'Ticket not found');
        return $this->getService()->publicToApiArray($model, true, $this->getIdentity());
    }

    #[RequiredParams(['id' => 'Ticket ID is missing'])]
    public function public_ticket_delete(array $data): bool
    {
        $this->_checkAdminPublicTicketAccess((int) $data['id']);
        $model = $this->di['db']->getExistingModelById('SupportPTicket', $data['id'], 'Ticket not found');
        return $this->getService()->publicRm($model);
    }

    #[RequiredParams(['id' => 'Ticket ID is missing'])]
    public function public_ticket_close(array $data): bool
    {
        $ticket = $this->di['db']->getExistingModelById('SupportPTicket', $data['id'], 'Ticket not found');
        return $this->getService()->publicCloseTicket($ticket, $this->getIdentity());
    }

    #[RequiredParams(['id' => 'Ticket ID is missing'])]
    public function public_ticket_update(array $data): bool
    {
        $model = $this->di['db']->getExistingModelById('SupportPTicket', $data['id'], 'Ticket not found');
        return $this->getService()->publicTicketUpdate($model, $data);
    }

    #[RequiredParams(['id' => 'Ticket ID is missing', 'content' => 'Ticket content required'])]
    public function public_ticket_reply(array $data): int
    {
        $ticket = $this->di['db']->getExistingModelById('SupportPTicket', $data['id'], 'Ticket not found');
        return $this->getService()->publicTicketReply($ticket, $this->getIdentity(), $data['content']);
    }

    public function public_ticket_get_statuses(array $data): array
    {
        if (isset($data['titles'])) return $this->getService()->publicGetStatuses();
        return $this->_getTicketCounters('support_p_ticket', $data);
    }

    // ============================================================
    // TICKETS DE STAFF
    // ============================================================
    public function staff_ticket_get_list(array $data): array
    {
        return $this->_getTicketList('support_staff_ticket', 'staffToApiArray', $data);
    }

    #[RequiredParams(['id' => 'Staff Ticket id is missing'])]
    public function staff_ticket_get(array $data): array
    {
        $id = (int) preg_replace('/[^0-9]/', '', (string) $data['id']);
        $row = $this->di['db']->getRow("SELECT * FROM support_staff_ticket WHERE id = ?", [$id]);
        if (!$row) throw new \FOSSBilling\Exception('Staff Ticket not found');
        $identity = $this->getIdentity();
        if ($identity->role !== 'admin') $this->_checkAdminHelpdeskAccess((int)$row['support_helpdesk_id']);
        return $this->getService()->staffToApiArray($row, true, $this->getIdentity());
    }

    #[RequiredParams(['id' => 'Staff Ticket id is missing', 'content' => 'Content is missing'])]
    public function staff_ticket_reply(array $data)
    {
        $id = (int) preg_replace('/[^0-9]/', '', (string) $data['id']);
        $data['content'] = \FOSSBilling\Tools::sanitizeContent($data['content'], true);
        $ticket = $this->di['db']->getRow("SELECT * FROM support_staff_ticket WHERE id = ?", [$id]);
        if (!$ticket) throw new \FOSSBilling\Exception('Staff Ticket not found');
        $identity = $this->getIdentity();
        if ($identity->role !== 'admin') $this->_checkAdminHelpdeskAccess((int)$ticket['support_helpdesk_id']);

        $db = $this->di['db'];
        $now = date('Y-m-d H:i:s');
        $db->exec("INSERT INTO support_staff_ticket_message (support_ticket_id, admin_id, content, ip, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)", [$id, $identity->id, $data['content'], $this->di['request']->getClientIp(), $now, $now]);
        $db->exec("UPDATE support_staff_ticket SET status = 'on_hold', updated_at = ? WHERE id = ?", [$now, $id]);

        $first_msg = $db->getRow("SELECT * FROM support_staff_ticket_message WHERE support_ticket_id = ? ORDER BY id ASC LIMIT 1", [$id]);
        if ($first_msg && $first_msg['admin_id'] != $identity->id) {
            $author = $db->getRow("SELECT id, name, email FROM admin WHERE id = ?", [$first_msg['admin_id']]);
            if ($author && $author['email']) {
                $subject = "Re: [Staff Ticket #{$id}] " . $ticket['subject'];
                $html_body = "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;font-size:14px;color:#333;'>
                <h2>Respuesta a tu consulta</h2><p>Han respondido a tu ticket <strong>#{$id}</strong>: {$ticket['subject']}</p><hr>
                <div style='padding:15px;border-left:4px solid #cc6600;background:#f9f9f9;margin:10px 0;'>" . nl2br(htmlspecialchars($data['content'])) . "</div><hr>
                <p style='font-size:12px;color:#888;'><strong>Para responder:</strong> Contesta este correo incluyendo <strong>[Staff Ticket #{$id}]</strong> en el asunto.<br><strong>Escribe tu mensaje al inicio del correo.</strong></p></body></html>";
                $hd = $db->getRow("SELECT email_address FROM support_helpdesk WHERE id = ?", [(int)$ticket['support_helpdesk_id']]);
                $helpdesk_email = $hd['email_address'] ?? 'localhost';
                $email_domain = substr(strrchr($helpdesk_email, "@"), 1) ?: 'localhost';
                $newMsgId = sprintf('ticket.%d.%s@%s', $id, bin2hex(random_bytes(8)), $email_domain);
                $this->di['db']->exec("UPDATE support_staff_ticket SET ref_header = ? WHERE id = ?", [$newMsgId, $id]);
                $originalMsgId = $ticket['message_id'] ?? $newMsgId;
                \Box\Mod\Support\Service::sendWithHelpdeskSmtp($this->di, (int)$ticket['support_helpdesk_id'], $author['email'], $author['name'], $subject, $html_body, null, null, [], $newMsgId, $originalMsgId);
            }
        }
        return true;
    }

    #[RequiredParams(['id' => 'Staff Ticket id is missing'])]
    public function staff_ticket_close(array $data): bool
    {
        $id = (int) preg_replace('/[^0-9]/', '', (string) $data['id']);
        $ticket = $this->di['db']->getRow("SELECT * FROM support_staff_ticket WHERE id = ?", [$id]);
        if (!$ticket) throw new \FOSSBilling\Exception('Staff Ticket not found');
        if ($ticket['status'] == 'closed') return true;
        $identity = $this->getIdentity();
        if ($identity->role !== 'admin') $this->_checkAdminHelpdeskAccess((int)$ticket['support_helpdesk_id']);

        $now = date('Y-m-d H:i:s');
        $this->di['db']->exec("UPDATE support_staff_ticket SET status = 'closed', updated_at = ? WHERE id = ?", [$now, $id]);

        $first_msg = $this->di['db']->getRow("SELECT * FROM support_staff_ticket_message WHERE support_ticket_id = ? ORDER BY id ASC LIMIT 1", [$id]);
        if ($first_msg) {
            $author = $this->di['db']->getRow("SELECT id, name, email FROM admin WHERE id = ?", [$first_msg['admin_id']]);
            if ($author && $author['email']) {
                $last_msg = $this->di['db']->getRow("SELECT * FROM support_staff_ticket_message WHERE support_ticket_id = ? ORDER BY id DESC LIMIT 1", [$id]);
                $subject = "[Staff Ticket #{$id}] Cerrado: " . $ticket['subject'];
                $html_body = "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;font-size:14px;color:#333;'>
                <h2>Ticket cerrado</h2><p>Tu ticket <strong>#{$id}</strong>: {$ticket['subject']} ha sido <strong>cerrado</strong>.</p><hr>
                <div style='padding:15px;border-left:4px solid #cc6600;background:#f9f9f9;margin:10px 0;'>" . nl2br(htmlspecialchars($last_msg['content'] ?? '')) . "</div><hr>
                <p style='font-size:12px;color:#888;'><strong>Para reabrir:</strong> Responde este correo incluyendo <strong>[Staff Ticket #{$id}]</strong> en el asunto.<br><strong>Escribe tu mensaje al inicio del correo.</strong></p></body></html>";
                $hd = $this->di['db']->getRow("SELECT email_address FROM support_helpdesk WHERE id = ?", [(int)$ticket['support_helpdesk_id']]);
                $helpdesk_email = $hd['email_address'] ?? 'localhost';
                $email_domain = substr(strrchr($helpdesk_email, "@"), 1) ?: 'localhost';
                $newMsgId = sprintf('ticket.%d.%s@%s', $id, bin2hex(random_bytes(8)), $email_domain);
                $this->di['db']->exec("UPDATE support_staff_ticket SET ref_header = ? WHERE id = ?", [$newMsgId, $id]);
                $originalMsgId = $ticket['message_id'] ?? $newMsgId;
                \Box\Mod\Support\Service::sendWithHelpdeskSmtp($this->di, (int)$ticket['support_helpdesk_id'], $author['email'], $author['name'], $subject, $html_body, null, null, [], $newMsgId, $originalMsgId);
            }
        }
        return true;
    }

    #[RequiredParams(['support_helpdesk_id' => 'Helpdesk ID is required', 'subject' => 'Subject is required', 'content' => 'Content is required'])]
    public function staff_ticket_create(array $data): int
    {
        $this->_checkAdminHelpdeskAccess((int) $data['support_helpdesk_id']);
        $data['content'] = \FOSSBilling\Tools::sanitizeContent($data['content'], true);
        return $this->getService()->ticketCreateForStaff((int) $data['support_helpdesk_id'], $this->getIdentity(), $data);
    }

    public function staff_ticket_get_statuses(array $data): array
    {
        if (isset($data['titles'])) return $this->getService()->staffGetStatuses();
        return $this->_getTicketCounters('support_staff_ticket', $data);
    }

    // ============================================================
    // MY STAFF TICKETS (Mis Consultas)
    // ============================================================
    public function my_staff_ticket_get_list($data)
    {
        $identity = $this->getIdentity();
        $data['admin_id'] = $identity->id;
        $sql = 'SELECT st.* FROM support_staff_ticket st JOIN support_staff_ticket_message stm ON stm.support_ticket_id = st.id WHERE stm.admin_id = :admin_id';
        $bindings = [':admin_id' => $data['admin_id']];
        if (!empty($data['status'])) { $sql .= ' AND st.status = :status'; $bindings[':status'] = $data['status']; }
        $sql .= ' GROUP BY st.id ORDER BY st.created_at DESC';
        $pager = $this->di['pager']->getPaginatedResultSet($sql, $bindings, PaginationOptions::fromArray($data));
        foreach ($pager['list'] as $key => $ticketArr) {
            $ticket = $this->di['db']->getRow("SELECT * FROM support_staff_ticket WHERE id = ?", [$ticketArr['id']]);
            if ($ticket) $pager['list'][$key] = $this->getService()->staffToApiArray($ticket, true, $this->getIdentity());
        }
        return $pager;
    }

    #[RequiredParams(['id' => 'Staff Ticket id required'])]
    public function my_staff_ticket_get(array $data): array
    {
        $id = (int) preg_replace('/[^0-9]/', '', (string) $data['id']);
        $ticket = $this->di['db']->getRow("SELECT * FROM support_staff_ticket WHERE id = ?", [$id]);
        if (!$ticket) throw new \FOSSBilling\Exception('Staff Ticket not found');
        return $this->getService()->staffToApiArray($ticket, true, $this->getIdentity());
    }

    #[RequiredParams(['id' => 'Staff Ticket id is missing', 'content' => 'Content is missing'])]
    public function my_staff_ticket_reply(array $data): bool
    {
        $id = (int) preg_replace('/[^0-9]/', '', (string) $data['id']);
        $data['content'] = \FOSSBilling\Tools::sanitizeContent($data['content'], true);
        $ticket = $this->di['db']->getRow("SELECT * FROM support_staff_ticket WHERE id = ?", [$id]);
        if (!$ticket) throw new \FOSSBilling\Exception('Staff Ticket not found');
        $db = $this->di['db']; $now = date('Y-m-d H:i:s');
        $db->exec("INSERT INTO support_staff_ticket_message (support_ticket_id, admin_id, content, ip, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)", [$id, $this->getIdentity()->id, $data['content'], $this->di['request']->getClientIp(), $now, $now]);
        $db->exec("UPDATE support_staff_ticket SET status = 'open', updated_at = ? WHERE id = ?", [$now, $id]);
        return true;
    }

    #[RequiredParams(['id' => 'Staff Ticket id is missing'])]
    public function my_staff_ticket_close(array $data): bool
    {
        $id = (int) preg_replace('/[^0-9]/', '', (string) $data['id']);
        $now = date('Y-m-d H:i:s');
        $this->di['db']->exec("UPDATE support_staff_ticket SET status = 'closed', updated_at = ? WHERE id = ?", [$now, $id]);
        return true;
    }

    #[RequiredParams(['id' => 'Ticket id is missing'])]
    public function staff_ticket_delete(array $data): bool
    {
        $id = (int) preg_replace('/[^0-9]/', '', (string) $data['id']);
        $this->di['db']->exec("DELETE FROM support_staff_ticket_message WHERE support_ticket_id = ?", [$id]);
        $this->di['db']->exec("DELETE FROM support_staff_ticket_note WHERE support_ticket_id = ?", [$id]);
        $this->di['db']->exec("DELETE FROM support_staff_ticket WHERE id = ?", [$id]);
        return true;
    }

    #[RequiredParams(['ids' => 'IDs not passed'])]
    public function batch_delete_staff($data): bool
    {
        foreach ($data['ids'] as $id) { $this->staff_ticket_delete(['id' => $id]); }
        return true;
    }

    // ============================================================
    // HELPDESK
    // ============================================================
    public function helpdesk_get_list(array $data): array
    {
        [$sql, $bindings] = $this->getService()->helpdeskGetSearchQuery($data);
        return $this->di['pager']->getPaginatedResultSet($sql, $bindings, PaginationOptions::fromArray($data));
    }

    public function helpdesk_get_pairs($data): array
    {
        if (isset($data['exclude_staff']) && $data['exclude_staff']) {
            return $this->di['db']->getAssoc("SELECT id, name FROM support_helpdesk WHERE (allow_staff_tickets = 0 OR allow_staff_tickets IS NULL) ORDER BY name");
        }
        return $this->getService()->helpdeskGetPairs();
    }

    #[RequiredParams(['id' => 'Help desk ID is missing'])]
    public function helpdesk_get(array $data): array
    {
        $model = $this->di['db']->getExistingModelById('support_helpdesk', $data['id'], 'Help desk not found');
        $staff_list = $this->di['db']->find('admin', "status IN ('active', '') ORDER BY name ASC");
        $admin_staff_pairs = [];
        foreach ($staff_list as $staff) {
            $admin_staff_pairs[$staff->id] = ['name' => $staff->name, 'email' => $staff->email, 'role' => $staff->role, 'is_admin' => $staff->role === 'admin'];
        }
        $allow_staff = !empty($model->allow_staff_tickets);
        $allow_public = !empty($model->allow_public_tickets);
        $allow_client = !empty($model->allow_client_tickets);
        if ($allow_staff && !$allow_public && !$allow_client) $current_access = 'staff';
        elseif ($allow_client && !$allow_public && !$allow_staff) $current_access = 'clients';
        elseif ($allow_public && !$allow_client && !$allow_staff) $current_access = 'public';
        else $current_access = 'hybrid';
        return [
            'id' => (int)$model->id, 'name' => $model->name ?? '', 'email' => $model->email ?? '',
            'signature' => $model->signature ?? '', 'close_after' => $model->close_after ?? '',
            'can_reopen' => !empty($model->can_reopen), 'enable_email' => !empty($model->enable_email) ? 1 : 0,
            'email_address' => $model->email_address ?? '', 'pop3_host' => $model->pop3_host ?? '',
            'pop3_port' => !empty($model->pop3_port) ? intval($model->pop3_port) : 995,
            'pop3_encryption' => $model->pop3_encryption ?? 'ssl',
            'smtp_host' => $model->smtp_host ?? '', 'smtp_port' => !empty($model->smtp_port) ? intval($model->smtp_port) : 465,
            'smtp_encryption' => $model->smtp_encryption ?? 'ssl', 'smtp_user' => $model->smtp_user ?? '',
            'from_name' => $model->from_name ?? '', 'access_level' => $current_access,
            'allow_public_tickets' => $allow_public ? 1 : 0, 'allow_client_tickets' => $allow_client ? 1 : 0,
            'allow_staff_tickets' => $allow_staff ? 1 : 0, 'authorized_users' => $model->authorized_users ?? '',
            'require_email_verification' => !empty($model->require_email_verification) ? 1 : 0,
            'assigned_staff' => $model->assigned_staff ?? '', 'admin_staff_pairs' => $admin_staff_pairs, 'available_clients' => [],
        ];
    }

    #[RequiredParams(['id' => 'Help desk ID is missing'])]
    public function helpdesk_update(array $data): array
    {
        $model = $this->di['db']->getExistingModelById('support_helpdesk', $data['id'], 'Help desk not found');
        $encrypted_pop3_pass = !empty($data['pop3_password']) ? base64_encode($data['pop3_password']) : null;
        $encrypted_smtp_pass = !empty($data['smtp_pass']) ? base64_encode($data['smtp_pass']) : null;
        $assigned_staff_ids = isset($data['assigned_staff']) && is_array($data['assigned_staff']) ? implode(',', array_map('intval', $data['assigned_staff'])) : '';
        $access_level = !empty($data['access_level']) ? $data['access_level'] : 'public';
        switch ($access_level) {
            case 'public': $allow_public = 1; $allow_client = 0; $allow_staff = 0; break;
            case 'clients': $allow_public = 0; $allow_client = 1; $allow_staff = 0; break;
            case 'staff': $allow_public = 0; $allow_client = 0; $allow_staff = 1; break;
            default: $allow_public = 1; $allow_client = 1; $allow_staff = 0; break;
        }
        $authorized_emails = isset($data['staff_select']) && is_array($data['staff_select']) ? implode(',', array_map('trim', $data['staff_select'])) : (!empty($data['authorized_users']) ? $data['authorized_users'] : '');
        $this->di['db']->exec(
            "UPDATE support_helpdesk SET name = COALESCE(:name, name), email = COALESCE(:email, email), close_after = COALESCE(:close_after, close_after), can_reopen = COALESCE(:can_reopen, can_reopen), signature = COALESCE(:signature, signature), enable_email = COALESCE(:enable_email, 0), email_address = :email_address, pop3_host = :pop3_host, pop3_port = :pop3_port, pop3_encryption = :pop3_encryption, pop3_password = :pop3_password, smtp_host = :smtp_host, smtp_port = :smtp_port, smtp_encryption = :smtp_encryption, smtp_user = :smtp_user, smtp_pass = :smtp_pass, from_name = :from_name, access_level = :access_level, allow_public_tickets = :allow_public, allow_client_tickets = :allow_client, allow_staff_tickets = :allow_staff, authorized_users = :authorized_value, assigned_staff = :assigned_value, require_email_verification = :require_verify, updated_at = NOW() WHERE id = :id",
            [':id' => (int)$data['id'], ':name' => !empty($data['name']) ? $data['name'] : null, ':email' => !empty($data['email']) ? $data['email'] : null, ':close_after' => !empty($data['close_after']) ? intval($data['close_after']) : null, ':can_reopen' => isset($data['can_reopen']) ? 1 : 0, ':signature' => !empty($data['signature']) ? $data['signature'] : null, ':enable_email' => isset($data['enable_email']) && $data['enable_email'] == 1 ? 1 : 0, ':email_address' => !empty($data['email_address']) ? $data['email_address'] : null, ':pop3_host' => !empty($data['pop3_host']) ? $data['pop3_host'] : null, ':pop3_port' => !empty($data['pop3_port']) ? intval($data['pop3_port']) : 995, ':pop3_encryption' => !empty($data['pop3_encryption']) ? $data['pop3_encryption'] : 'ssl', ':pop3_password' => $encrypted_pop3_pass, ':smtp_host' => !empty($data['smtp_host']) ? $data['smtp_host'] : null, ':smtp_port' => !empty($data['smtp_port']) ? intval($data['smtp_port']) : 465, ':smtp_encryption' => !empty($data['smtp_encryption']) ? $data['smtp_encryption'] : 'ssl', ':smtp_user' => !empty($data['smtp_user']) ? $data['smtp_user'] : null, ':smtp_pass' => $encrypted_smtp_pass, ':from_name' => !empty($data['from_name']) ? $data['from_name'] : null, ':allow_public' => (int)$allow_public, ':allow_client' => (int)$allow_client, ':allow_staff' => (int)$allow_staff, ':authorized_value' => !empty($authorized_emails) ? $authorized_emails : null, ':assigned_value' => !empty($assigned_staff_ids) ? $assigned_staff_ids : null, ':require_verify' => isset($data['require_email_verification']) ? 1 : 0, ':access_level' => $access_level]
        );
        if (!empty($data['enable_email']) || !empty($model->enable_email)) {
            try {
                $sieve = new \Box\Mod\Support\Services\SieveManager();
                $sieve->setDi($this->di);
                $sieve->updateSieveFile((int)$data['id']);
            } catch (\Exception $e) {
                error_log('[Support] Error actualizando Sieve en helpdesk_update: ' . $e->getMessage());
            }
        }
        return ['success' => true, 'message' => 'Configuración actualizada correctamente'];
    }

    #[RequiredParams(['name' => 'Help desk title is missing'])]
    public function helpdesk_create(array $data): int
    {
        return $this->getService()->helpdeskCreate($data);
    }

    #[RequiredParams(['id' => 'Help desk ID is missing'])]
    public function helpdesk_delete(array $data): bool
    {
        $model = $this->di['db']->getExistingModelById('SupportHelpdesk', $data['id'], 'Help desk not found');
        return $this->getService()->helpdeskRm($model);
    }

    // ============================================================
    // CONVERTIR TICKET PÚBLICO A CLIENTE
    // ============================================================
    public function public_ticket_convert_to_client($data)
    {
        $required = ['id' => 'Ticket id is missing'];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);
        $ticket = $this->di['db']->getExistingModelById('SupportPTicket', $data['id'], 'Public Ticket not found');
        $client = $this->di['db']->findOne('Client', 'email = ?', [$ticket->author_email]);
        if (!$client) {
            $client = $this->di['db']->dispense('Client');
            $client->email = $ticket->author_email; $client->first_name = $ticket->author_name;
            $client->status = 'active'; $client->created_at = date('Y-m-d H:i:s'); $client->updated_at = date('Y-m-d H:i:s');
            $clientId = $this->di['db']->store($client);
            try {
                $emailService = $this->di['mod_service']('email');
                $emailService->sendTemplate(['to_client' => $clientId, 'code' => 'mod_client_signup']);
            } catch (\Exception $e) { error_log('[Support] Error enviando correo de bienvenida: ' . $e->getMessage()); }
        } else { $clientId = $client->id; }
        $helpdeskId = !empty($data['helpdesk_id']) ? (int)$data['helpdesk_id'] : ($ticket->support_helpdesk_id ?? 1);
        $messages = $this->di['db']->find('SupportPTicketMessage', 'support_p_ticket_id = ? ORDER BY id ASC', [$ticket->id]);
        $newTicket = $this->di['db']->dispense('SupportTicket');
        $newTicket->client_id = $clientId; $newTicket->support_helpdesk_id = $helpdeskId;
        $newTicket->subject = $ticket->subject; $newTicket->status = $ticket->status;
        $newTicket->created_at = $ticket->created_at; $newTicket->updated_at = date('Y-m-d H:i:s');
        $newTicketId = $this->di['db']->store($newTicket);
        foreach ($messages as $msg) {
            $newMsg = $this->di['db']->dispense('SupportTicketMessage');
            $newMsg->support_ticket_id = $newTicketId; $newMsg->client_id = $clientId;
            $newMsg->content = $msg->content; $newMsg->created_at = $msg->created_at; $newMsg->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($newMsg);
        }
        $this->getService()->publicRm($ticket);
        try {
            $sieve = new \Box\Mod\Support\Services\SieveManager();
            $sieve->setDi($this->di);
            $sieve->addToWhitelist($ticket->author_email, $helpdeskId, 'client');
        } catch (\Exception $e) { error_log('[Support] Error agregando a whitelist: ' . $e->getMessage()); }
        return ['success' => true, 'message' => 'Cliente creado y ticket convertido', 'client_id' => $clientId, 'ticket_id' => $newTicketId];
    }

    // ============================================================
    // EMAIL GATEWAY TEST
    // ============================================================
    public function email_gateway_test($data = [])
    {
        $required = ['id' => 'HelpDesk ID requerido', 'email_address' => 'Correo electrónico requerido', 'pop3_password' => 'Contraseña POP3 requerida'];
        foreach ($required as $field => $message) { if (!isset($data[$field]) || trim($data[$field]) === '') return ['success' => false, 'message' => $message]; }
        try {
            $helpdesk_id = intval($data['id']);
            if ($helpdesk_id <= 0) return ['success' => false, 'message' => 'ID de HelpDesk inválido'];
            $desk = $this->di['db']->findOne('support_helpdesk', "id = ?", [$helpdesk_id]);
            if (!$desk || !$desk->email_address) return ['success' => false, 'message' => 'HelpDesk no encontrado'];
            $supportService = $this->di['mod_service']('support');
            $email_address = !empty($data['email_address']) ? $data['email_address'] : $desk->email_address;
            $pop3_password_db = $supportService->decryptPassword($desk->pop3_password ?? '');
            if ($pop3_password_db === false || empty($pop3_password_db)) $pop3_password_db = $desk->pop3_password;
            $pop3_password = isset($data['pop3_password']) && !empty($data['pop3_password']) ? $data['pop3_password'] : $pop3_password_db;
            $pop3_host = !empty($data['pop3_host']) ? $data['pop3_host'] : ($desk->pop3_host ?? 'mail.hostelacion.com');
            $port = !empty($data['pop3_port']) ? intval($data['pop3_port']) : ($desk->pop3_port ?? 995);
            ini_set('default_socket_timeout', 15);
            $connection = @stream_socket_client("tcp://{$pop3_host}:{$port}", $errno, $errstr, 15);
            if (!$connection) return ['success' => false, 'message' => 'Conexión fallida', 'details' => "{$errstr} ({$errno})"];
            $encryption = !empty($data['pop3_encryption']) ? $data['pop3_encryption'] : ($desk->pop3_encryption ?? 'ssl');
            if (in_array(strtolower($encryption), ['ssl', 'tls'])) stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if (strpos(fgets($connection, 1024), '+OK') === false) { fclose($connection); return ['success' => false, 'message' => 'Greeting inválido']; }
            fwrite($connection, "USER {$email_address}\r\n");
            if (strpos(fgets($connection, 1024), '+OK') === false) { fwrite($connection, "QUIT\r\n"); fclose($connection); return ['success' => false, 'message' => 'Usuario rechazado']; }
            fwrite($connection, "PASS {$pop3_password}\r\n");
            if (strpos(fgets($connection, 1024), '+OK') === false) { fwrite($connection, "QUIT\r\n"); fclose($connection); return ['success' => false, 'message' => 'Contraseña incorrecta']; }
            fwrite($connection, "STAT\r\n"); preg_match('/(\d+)/', fgets($connection, 1024), $matches);
            $msg_count = isset($matches[1]) ? (int)$matches[1] : 0;
            fwrite($connection, "QUIT\r\n"); fclose($connection);
            $smtp_status = 'Skipped'; $smtp_sent = false;
            if (!empty($data['smtp_pass'])) {
                try {
                    $smtp_password_db = $supportService->decryptPassword($desk->smtp_pass ?? '');
                    $smtp_password = isset($data['smtp_pass']) && !empty($data['smtp_pass']) ? $data['smtp_pass'] : $smtp_password_db;
                    $smtp_host = !empty($data['smtp_host']) ? $data['smtp_host'] : $desk->smtp_host;
                    $smtp_port = !empty($data['smtp_port']) ? intval($data['smtp_port']) : ($desk->smtp_port ?? 587);
                    $scheme = (($data['smtp_encryption'] ?? $desk->smtp_encryption ?? 'tls') === 'ssl') ? 'smtps' : 'smtp';
                    $dsn = "{$scheme}://" . rawurlencode($data['smtp_user'] ?? $desk->email_address) . ":" . rawurlencode($smtp_password) . "@{$smtp_host}:{$smtp_port}";
                    (new Mailer(Transport::fromDsn($dsn)))->send((new Email())->from($email_address)->to(!empty($data['test_email']) ? $data['test_email'] : $email_address)->subject('Prueba')->text('OK'));
                    $smtp_status = 'OK'; $smtp_sent = !empty($data['test_email']);
                } catch (\Exception $e) { $smtp_status = 'FAIL: ' . $e->getMessage(); }
            }
            return ['success' => true, 'message' => "✅ Conexión exitosa", 'details' => "POP3: OK ({$msg_count} msgs) | SMTP: {$smtp_status}", 'messages_in_inbox' => $msg_count, 'smtp_status' => $smtp_status, 'smtp_sent' => $smtp_sent];
        } catch (\Exception $e) { return ['success' => false, 'message' => 'Error interno', 'details' => $e->getMessage()]; }
    }

    // ============================================================
    // WHITELIST / BLACKLIST
    // ============================================================
    public function whitelist_get($data) { $s = new \Box\Mod\Support\Services\SieveManager(); $s->setDi($this->di); $items = []; foreach ($s->getWhitelistsForView() as $i) $items[] = ['email' => $i['email'] ?? '', 'account_id' => $i['account_id'] ?? '', 'account_email' => $i['account_email'] ?? '', 'type' => $i['type'] ?? 'client', 'created_at' => $i['created_at'] ?? '']; return ['items' => $items, 'accounts_with_sieve' => $s->getAccountsWithSieve()]; }

    public function whitelist_add($data)
    {
        $required = ['email' => 'Email is missing', 'helpdesk_id' => 'Helpdesk ID is missing'];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        $sieve_manager = new \Box\Mod\Support\Services\SieveManager();
        $sieve_manager->setDi($this->di);

        $email = filter_var(trim($data['email']), FILTER_SANITIZE_EMAIL);
        $helpdesk_id = (int)$data['helpdesk_id'];
        $type = trim($data['type'] ?? 'client');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \FOSSBilling\Exception('Invalid email address');
        }

        $helpdesk = $this->di['db']->findOne('support_helpdesk', "id = ?", [$helpdesk_id]);
        if (!$helpdesk) {
            throw new \FOSSBilling\Exception('Helpdesk not found');
        }

        $result = $sieve_manager->addToWhitelist($email, $helpdesk_id, $type);

        // Obtener filter_system
        $filter_system = $helpdesk->filter_system ?? 'sieve';

        if (function_exists('exec') && $filter_system === 'spamassassin') {
            try {
                $spamassassin = new \Box\Mod\Support\Services\SpamAssassinManager();
                $spamassassin->setDi($this->di);
                $spamassassin->updateRules($helpdesk_id);
            } catch (\Exception $e) {
                error_log("[Support] SpamAssassin whitelist_add error: " . $e->getMessage());
            }
        }

        return $result;
    }

    public function whitelist_remove($data)
    {
        $required = ['email' => 'Email is missing', 'account_id' => 'Account ID is missing'];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        $sieve_manager = new \Box\Mod\Support\Services\SieveManager();
        $sieve_manager->setDi($this->di);

        $helpdesk_id = (int) trim($data['account_id']);
        $result = $sieve_manager->removeFromWhitelist(trim($data['email']), $helpdesk_id);

        // Obtener filter_system
        $filter_system = $this->di['db']->getCell("SELECT filter_system FROM support_helpdesk WHERE id = ?", [$helpdesk_id]) ?? 'sieve';

        if (function_exists('exec') && $filter_system === 'spamassassin') {
            try {
                $spamassassin = new \Box\Mod\Support\Services\SpamAssassinManager();
                $spamassassin->setDi($this->di);
                $spamassassin->updateRules($helpdesk_id);
            } catch (\Exception $e) {
                error_log("[Support] SpamAssassin whitelist_remove error: " . $e->getMessage());
            }
        }

        return $result;
    }

    public function blacklist_get($data)
    {
        $items = $this->di['db']->getAll("SELECT b.id, b.email, b.helpdesk_id, b.reason, b.status, b.created_at, h.name as helpdesk_name FROM support_blacklist b LEFT JOIN support_helpdesk h ON h.id = b.helpdesk_id WHERE b.status = 'active' ORDER BY b.created_at DESC");
        $helpdesks = $this->di['db']->getAll("SELECT id, name, email_address FROM support_helpdesk WHERE enable_email = 1 ORDER BY name ASC");
        return ['items' => $items ?? [], 'helpdesks' => $helpdesks ?? []];
    }

    public function blacklist_add($data)
    {
        $required = ['email' => 'Email is missing'];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        $email = trim($data['email']);
        $hid = !empty($data['helpdesk_id']) ? (int)$data['helpdesk_id'] : null;
        $reason = trim($data['reason'] ?? '');

        // Permitir patrón con asterisco para dominio completo
        if (strpos($email, '*') !== false) {
            if (!preg_match('/^\*@[\w\.\-]+\.[a-zA-Z]{2,}$/', $email)) {
                throw new \FOSSBilling\Exception('Formato inválido. Use *@dominio.ejemplo');
            }
        } else {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \FOSSBilling\Exception('Invalid email address');
            }
        }

        $existing = $this->di['db']->findOne('support_blacklist', 'email = ? AND (helpdesk_id = ? OR (helpdesk_id IS NULL AND ? IS NULL))', [$email, $hid, $hid]);
        if ($existing) {
            $this->di['db']->exec("UPDATE support_blacklist SET status = 'active', reason = ? WHERE email = ? AND (helpdesk_id = ? OR helpdesk_id IS NULL)", [$reason ?: null, $email, $hid]);
        } else {
            $this->di['db']->exec("INSERT INTO support_blacklist (email, helpdesk_id, reason, status, created_at) VALUES (?, ?, ?, 'active', NOW())", [$email, $hid, $reason ?: null]);
        }

        // Actualizar Sieve
        $sieve = new \Box\Mod\Support\Services\SieveManager();
        $sieve->setDi($this->di);
        $hid ? $sieve->updateSieveFile($hid) : $sieve->syncAllSieveWithDB();

        // Obtener filter_system
        if ($hid) {
            $filter_system = $this->di['db']->getCell("SELECT filter_system FROM support_helpdesk WHERE id = ?", [$hid]) ?? 'sieve';
        } else {
            $filter_system = \Box\Mod\Support\Services\SpamAssassinManager::detectFilterSystem();
        }

        // Actualizar SpamAssassin solo si exec() está disponible y el sistema es spamassassin
        if (function_exists('exec') && $filter_system === 'spamassassin') {
            try {
                $sa = new \Box\Mod\Support\Services\SpamAssassinManager();
                $sa->setDi($this->di);
                $hid ? $sa->updateRules($hid) : $sa->syncAllWithDB();
            } catch (\Exception $e) {
                error_log("[Support] SpamAssassin update error: " . $e->getMessage());
            }
        }

        return true;
    }

    public function blacklist_remove($data)
    {
        $required = ['id' => 'ID is missing'];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        $item = $this->di['db']->findOne('support_blacklist', 'id = ?', [(int)$data['id']]);
        if (!$item) throw new \FOSSBilling\Exception('Entry not found');

        $helpdesk_id = $item->helpdesk_id ? (int)$item->helpdesk_id : null;
        $this->di['db']->exec("DELETE FROM support_blacklist WHERE id = ?", [(int)$data['id']]);

        $sieve = new \Box\Mod\Support\Services\SieveManager();
        $sieve->setDi($this->di);
        if ($helpdesk_id) {
            $sieve->updateSieveFile($helpdesk_id);
        } else {
            $sieve->syncAllSieveWithDB();
        }

        // Obtener filter_system
        if ($helpdesk_id) {
            $filter_system = $this->di['db']->getCell("SELECT filter_system FROM support_helpdesk WHERE id = ?", [$helpdesk_id]) ?? 'sieve';
        } else {
            $filter_system = \Box\Mod\Support\Services\SpamAssassinManager::detectFilterSystem();
        }

        if (function_exists('exec') && $filter_system === 'spamassassin') {
            try {
                $spamassassin = new \Box\Mod\Support\Services\SpamAssassinManager();
                $spamassassin->setDi($this->di);
                if ($helpdesk_id) {
                    $spamassassin->updateRules($helpdesk_id);
                } else {
                    $spamassassin->syncAllWithDB();
                }
            } catch (\Exception $e) {
                error_log("[Support] SpamAssassin update error: " . $e->getMessage());
            }
        }

        return true;
    }

    // ============================================================
    // SIEVE
    // ============================================================
    public function sieve_get($data) { $s = new \Box\Mod\Support\Services\SieveManager(); $s->setDi($this->di); return ['accounts' => $s->getAccountsWithSieve()]; }
    public function sieve_reset($data) { $s = new \Box\Mod\Support\Services\SieveManager(); $s->setDi($this->di); return $s->resetBasicFilters(); }

    // ============================================================
    // SPAMASSASSIN
    // ============================================================
    public function spamassassin_get($data) { $sa = new \Box\Mod\Support\Services\SpamAssassinManager(); $sa->setDi($this->di); return ['available' => $sa->isAvailable(), 'version' => $sa->getVersion(), 'accounts' => $sa->getAccountsWithSpamAssassin()]; }
    public function spamassassin_reset($data) { $sa = new \Box\Mod\Support\Services\SpamAssassinManager(); $sa->setDi($this->di); return $sa->syncAllWithDB(); }
    public function filter_system_detect($data) { $sys = \Box\Mod\Support\Services\SpamAssassinManager::detectFilterSystem(); return ['detected' => $sys, 'sieve_available' => $sys === 'sieve', 'spamassassin_available' => $sys === 'spamassassin']; }
    public function helpdesk_set_filter_system($data)
    {
        $required = ['id' => 'Helpdesk ID is missing', 'filter_system' => 'Filter system is required'];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);
        if (!in_array($data['filter_system'], ['sieve', 'spamassassin', 'none'])) throw new \FOSSBilling\Exception('Invalid filter system');
        $this->di['db']->exec("UPDATE support_helpdesk SET filter_system = ? WHERE id = ?", [$data['filter_system'], (int)$data['id']]);
        return true;
    }

    // ============================================================
    // CANNED RESPONSES, NOTES, KB
    // ============================================================
    public function canned_get_list(array $data): array { [$sql, $bindings] = $this->getService()->cannedGetSearchQuery($data); $pager = $this->di['pager']->getPaginatedResultSet($sql, $bindings, PaginationOptions::fromArray($data)); foreach ($pager['list'] as $k => $v) { $staff = $this->di['db']->getExistingModelById('SupportPr', $v['id'], 'Canned response not found'); $pager['list'][$k] = $this->getService()->cannedToApiArray($staff); } return $pager; }
    public function canned_pairs(): array { $res = $this->di['db']->getAssoc('SELECT id, title FROM support_pr_category'); $list = []; foreach ($res as $id => $title) $list[$title] = $this->di['db']->getAssoc('SELECT id, title FROM support_pr WHERE support_pr_category_id = :id', ['id' => $id]); return $list; }
    public function canned_get($data) { $required = ['id' => 'Canned reply id is missing']; $this->di['validator']->checkRequiredParamsForArray($required, $data); return $this->getService()->cannedToApiArray($this->di['db']->getExistingModelById('SupportPr', $data['id'], 'Canned reply not found')); }
    public function canned_delete($data) { $required = ['id' => 'Canned reply id is missing']; $this->di['validator']->checkRequiredParamsForArray($required, $data); return $this->getService()->cannedRm($this->di['db']->getExistingModelById('SupportPr', $data['id'], 'Canned reply not found')); }
    public function canned_create($data) { $required = ['title' => 'Canned reply title is missing', 'category_id' => 'Canned reply category id is missing']; $this->di['validator']->checkRequiredParamsForArray($required, $data); return $this->getService()->cannedCreate($data['title'], $data['category_id'], $data['content'] ?? null); }
    public function canned_update($data) { $required = ['id' => 'Canned reply id is missing']; $this->di['validator']->checkRequiredParamsForArray($required, $data); return $this->getService()->cannedUpdate($this->di['db']->getExistingModelById('SupportPr', $data['id'], 'Canned reply not found'), $data); }
    public function canned_category_pairs($data) { return $this->di['db']->getAssoc('SELECT id, title FROM support_pr_category WHERE 1'); }
    public function canned_category_get($data) { $required = ['id' => 'Canned category id is missing']; $this->di['validator']->checkRequiredParamsForArray($required, $data); return $this->getService()->cannedCategoryToApiArray($this->di['db']->getExistingModelById('SupportPrCategory', $data['id'], 'Canned category not found')); }
    public function canned_category_update($data) { $required = ['id' => 'Canned category id is missing']; $this->di['validator']->checkRequiredParamsForArray($required, $data); return $this->getService()->cannedCategoryUpdate($this->di['db']->getExistingModelById('SupportPrCategory', $data['id'], 'Canned category not found'), $data['title'] ?? ''); }
    public function canned_category_delete($data) { $required = ['id' => 'Canned category id is missing']; $this->di['validator']->checkRequiredParamsForArray($required, $data); return $this->getService()->cannedCategoryRm($this->di['db']->getExistingModelById('SupportPrCategory', $data['id'], 'Canned category not found')); }
    public function canned_category_create($data) { $required = ['title' => 'Canned category title is missing']; $this->di['validator']->checkRequiredParamsForArray($required, $data); return $this->getService()->cannedCategoryCreate($data['title']); }
    public function note_create($data) { $required = ['ticket_id' => 'ticket_id is missing', 'note' => 'Note is missing']; $this->di['validator']->checkRequiredParamsForArray($required, $data); return $this->getService()->noteCreate($this->di['db']->getExistingModelById('SupportTicket', $data['ticket_id'], 'Ticket not found'), $this->getIdentity(), $data['note']); }
    public function note_delete($data) { $required = ['id' => 'Note id is missing']; $this->di['validator']->checkRequiredParamsForArray($required, $data); return $this->getService()->noteRm($this->di['db']->getExistingModelById('SupportTicketNote', $data['id'], 'Note not found')); }
    public function task_complete($data) { $required = ['id' => 'Ticket id is missing']; $this->di['validator']->checkRequiredParamsForArray($required, $data); return $this->getService()->ticketTaskComplete($this->di['db']->getExistingModelById('SupportTicket', $data['id'], 'Ticket not found')); }
    public function batch_delete($data) { $required = ['ids' => 'IDs not passed']; $this->di['validator']->checkRequiredParamsForArray($required, $data); foreach ($data['ids'] as $id) $this->ticket_delete(['id' => $id]); return true; }
    public function batch_delete_public($data) { $required = ['ids' => 'IDs not passed']; $this->di['validator']->checkRequiredParamsForArray($required, $data); foreach ($data['ids'] as $id) $this->public_ticket_delete(['id' => $id]); return true; }
    public function kb_article_get_list(array $data): array { $pager = $this->getService()->kbSearchArticles($data['status'] ?? null, $data['search'] ?? null, $data['cat'] ?? $data['kb_article_category_id'] ?? null, PaginationOptions::fromArray($data)); foreach ($pager['list'] as $k => $v) { $a = $this->di['db']->getExistingModelById('SupportKbArticle', $v['id'], 'KB Article not found'); $pager['list'][$k] = $this->getService()->kbToApiArray($a); } return $pager; }
    public function kb_article_get($data) { $required = ['id' => 'Article id not passed']; $this->di['validator']->checkRequiredParamsForArray($required, $data); $m = $this->di['db']->findOne('SupportKbArticle', 'id = ?', [$data['id']]); if (!$m instanceof \Model_SupportKbArticle) throw new \FOSSBilling\InformationException('Article not found'); return $this->getService()->kbToApiArray($m, true, $this->getIdentity()); }
    public function kb_article_create($data) { $required = ['kb_article_category_id' => 'Article category id not passed', 'title' => 'Article title not passed']; $this->di['validator']->checkRequiredParamsForArray($required, $data); return $this->getService()->kbCreateArticle($data['kb_article_category_id'], $data['title'], $data['status'] ?? \Model_SupportKbArticle::DRAFT, $data['content'] ?? null); }
    public function kb_article_update($data) { $required = ['id' => 'Article ID not passed']; $this->di['validator']->checkRequiredParamsForArray($required, $data); return $this->getService()->kbUpdateArticle($data['id'], $data['kb_article_category_id'] ?? null, $data['title'] ?? null, $data['slug'] ?? null, $data['status'] ?? null, $data['content'] ?? null, $data['views'] ?? null); }
    public function kb_article_delete($data) { $required = ['id' => 'Article ID not passed']; $this->di['validator']->checkRequiredParamsForArray($required, $data); $m = $this->di['db']->findOne('SupportKbArticle', 'id = ?', [$data['id']]); if (!$m instanceof \Model_SupportKbArticle) throw new \FOSSBilling\InformationException('Article not found'); $this->getService()->kbRm($m); return true; }
    public function kb_category_get_list(array $data): array { [$sql, $bindings] = $this->getService()->kbCategoryGetSearchQuery($data); $pager = $this->di['pager']->getPaginatedResultSet($sql, $bindings, PaginationOptions::fromArray($data)); foreach ($pager['list'] as $k => $v) { $c = $this->di['db']->getExistingModelById('SupportKbArticleCategory', $v['id'], 'KB Article not found'); $pager['list'][$k] = $this->getService()->kbCategoryToApiArray($c, $this->getIdentity()); } return $pager; }
    public function kb_category_get($data) { $required = ['id' => 'Category ID not passed']; $this->di['validator']->checkRequiredParamsForArray($required, $data); $m = $this->di['db']->findOne('SupportKbArticleCategory', 'id = ?', [$data['id']]); if (!$m instanceof \Model_SupportKbArticleCategory) throw new \FOSSBilling\InformationException('Article Category not found'); return $this->getService()->kbCategoryToApiArray($m); }
    public function kb_category_create($data) { $required = ['title' => 'Category title not passed']; $this->di['validator']->checkRequiredParamsForArray($required, $data); return $this->getService()->kbCreateCategory($data['title'], $data['description'] ?? null); }
    public function kb_category_update($data) { $required = ['id' => 'Category ID not passed']; $this->di['validator']->checkRequiredParamsForArray($required, $data); $m = $this->di['db']->findOne('SupportKbArticleCategory', 'id = ?', [$data['id']]); if (!$m instanceof \Model_SupportKbArticleCategory) throw new \FOSSBilling\InformationException('Article Category not found'); return $this->getService()->kbUpdateCategory($m, $data['title'] ?? null, $data['slug'] ?? null, $data['description'] ?? null); }
    public function kb_category_delete($data) { $required = ['id' => 'Category ID not passed']; $this->di['validator']->checkRequiredParamsForArray($required, $data); $m = $this->di['db']->findOne('SupportKbArticleCategory', 'id = ?', [$data['id']]); if (!$m instanceof \Model_SupportKbArticleCategory) throw new \FOSSBilling\InformationException('Category not found'); return $this->getService()->kbCategoryRm($m); }
    public function kb_category_get_pairs($data) { return $this->getService()->kbCategoryGetPairs(); }

public function spamkw_get_list($data)
{
    $helpdesk_id = !empty($data['helpdesk_id']) ? (int)$data['helpdesk_id'] : null;
    $sql = "SELECT * FROM support_spam_keywords WHERE ";
    $bindings = [];
    if ($helpdesk_id) {
        $sql .= "helpdesk_id = ? OR helpdesk_id IS NULL";
        $bindings[] = $helpdesk_id;
    } else {
        $sql .= "helpdesk_id IS NULL";
    }
    $sql .= " ORDER BY created_at DESC";
    return $this->di['db']->getAll($sql, $bindings);
}

public function spamkw_add($data)
{
    $keyword = trim($data['keyword'] ?? '');
    if (empty($keyword)) throw new \FOSSBilling\Exception('Keyword is missing');
    $type = $data['type'] ?? 'subject';
    if (!in_array($type, ['subject', 'from', 'body', 'header'])) throw new \FOSSBilling\Exception('Invalid type');
    $helpdesk_id = !empty($data['helpdesk_id']) ? (int)$data['helpdesk_id'] : null;

    $this->di['db']->exec(
        "INSERT INTO support_spam_keywords (keyword, type, helpdesk_id, created_at) VALUES (?, ?, ?, NOW())",
        [$keyword, $type, $helpdesk_id]
    );

    // Actualizar Sieve
    $sieve = new \Box\Mod\Support\Services\SieveManager();
    $sieve->setDi($this->di);
    if ($helpdesk_id) {
        $sieve->updateSieveFile($helpdesk_id);
    } else {
        $sieve->syncAllSieveWithDB();
    }

    // Actualizar SpamAssassin si procede
    if (function_exists('exec')) {
        $filter_system = $helpdesk_id
            ? $this->di['db']->getCell("SELECT filter_system FROM support_helpdesk WHERE id = ?", [$helpdesk_id]) ?? 'sieve'
            : \Box\Mod\Support\Services\SpamAssassinManager::detectFilterSystem();
        if ($filter_system === 'spamassassin') {
            try {
                $sa = new \Box\Mod\Support\Services\SpamAssassinManager();
                $sa->setDi($this->di);
                $helpdesk_id ? $sa->updateRules($helpdesk_id) : $sa->syncAllWithDB();
            } catch (\Exception $e) {}
        }
    }

    return true;
}

public function spamkw_delete($data)
{
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) throw new \FOSSBilling\Exception('ID is missing');

    $item = $this->di['db']->findOne('support_spam_keywords', 'id = ?', [$id]);
    if (!$item) throw new \FOSSBilling\Exception('Keyword not found');

    $helpdesk_id = $item->helpdesk_id ? (int)$item->helpdesk_id : null;
    $this->di['db']->exec("DELETE FROM support_spam_keywords WHERE id = ?", [$id]);

    // Actualizar Sieve
    $sieve = new \Box\Mod\Support\Services\SieveManager();
    $sieve->setDi($this->di);
    if ($helpdesk_id) {
        $sieve->updateSieveFile($helpdesk_id);
    } else {
        $sieve->syncAllSieveWithDB();
    }

    // Actualizar SpamAssassin si procede
    if (function_exists('exec')) {
        $filter_system = $helpdesk_id
            ? $this->di['db']->getCell("SELECT filter_system FROM support_helpdesk WHERE id = ?", [$helpdesk_id]) ?? 'sieve'
            : \Box\Mod\Support\Services\SpamAssassinManager::detectFilterSystem();
        if ($filter_system === 'spamassassin') {
            try {
                $sa = new \Box\Mod\Support\Services\SpamAssassinManager();
                $sa->setDi($this->di);
                $helpdesk_id ? $sa->updateRules($helpdesk_id) : $sa->syncAllWithDB();
            } catch (\Exception $e) {}
        }
    }

    return true;
}
    // ============================================================
    // MÉTODOS PRIVADOS DE PERMISOS
    // ============================================================
    private function _applyHelpdeskFilter(array $data): array
    {
        $identity = $this->getIdentity();
        if ($identity instanceof \Model_Admin && $identity->role !== 'admin') {
            $assigned = $this->_getAssignedHelpdeskIds();
            if ($assigned !== null) $data['assigned_helpdesk_ids'] = $assigned;
        }
        return $data;
    }

    private function _getAssignedHelpdeskIds(): array
    {
        $identity = $this->getIdentity();
        if (!$identity instanceof \Model_Admin) return [0];
        if ($identity->role === 'admin') return [];
        $admin_id = $identity->id;
        $helpdesk_ids = [];
        $assignedHelpdesks = $this->di['db']->find('SupportHelpdesk', "FIND_IN_SET(:admin_id, assigned_staff) AND access_level != 'staff'", [':admin_id' => $admin_id]);
        foreach ($assignedHelpdesks as $helpdesk) $helpdesk_ids[] = $helpdesk->id;
        $unique = array_unique($helpdesk_ids);
        return empty($unique) ? [0] : $unique;
    }

    private function _checkAdminHelpdeskAccess(int $helpdesk_id): void
    {
        $identity = $this->getIdentity();
        if ($identity->role === 'admin') return;
        if (!in_array($helpdesk_id, $this->_getAssignedHelpdeskIds())) throw new \FOSSBilling\Exception('No está autorizado para acceder a esta mesa de ayuda.');
    }

    private function _checkAdminTicketAccess(int $ticket_id): void
    {
        $identity = $this->getIdentity();
        if ($identity->role === 'admin') return;
        $ticket = $this->di['db']->getExistingModelById('SupportTicket', $ticket_id, 'Ticket not found');
        $this->_checkAdminHelpdeskAccess($ticket->support_helpdesk_id);
    }

    private function _checkAdminTicketAccessByMessageId(int $message_id): void
    {
        $message = $this->di['db']->getExistingModelById('SupportTicketMessage', $message_id, 'Ticket message not found');
        $this->_checkAdminTicketAccess((int) $message->support_ticket_id);
    }

    private function _checkAdminPublicTicketAccess(int $public_ticket_id): void
    {
        $identity = $this->getIdentity();
        if ($identity->role === 'admin') return;
        $ticket = $this->di['db']->getExistingModelById('SupportPTicket', $public_ticket_id, 'Public Ticket not found');
        $this->_checkAdminHelpdeskAccess($ticket->support_helpdesk_id);
    }

    private function _checkAdminStaffTicketAccess(int $staff_ticket_id): void
    {
        $identity = $this->getIdentity();
        if ($identity->role === 'admin') return;
        $id = (int) trim(str_replace(['"', "'"], '', (string) $staff_ticket_id));
        $ticket = $this->di['db']->findOne('SupportStaffTicket', 'id = ?', [$id]);
        if (!$ticket) throw new \FOSSBilling\Exception('Staff Ticket not found');
        $this->_checkAdminHelpdeskAccess($ticket->support_helpdesk_id);
    }
}
