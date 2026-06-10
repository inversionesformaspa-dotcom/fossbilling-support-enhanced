<?php

/**
 * Copyright 2022-2025 FOSSBilling
 * Copyright 2011-2021 BoxBilling, Inc.
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

/**
 * Client support tickets management.
 */

namespace Box\Mod\Support\Api;

class Client extends \Api_Abstract
{
    /**
     * Get client tickets list.
     *
     * @optional string status - filter tickets by status
     * @optional string date_from - show tickets created since this day. Can be any string parsable by strtotime()
     * @optional string date_to - show tickets created until this day. Can be any string parsable by strtotime()
     *
     * @return array
     */
    public function ticket_get_list($data)
    {
        $identity = $this->getIdentity();
        $data['client_id'] = $identity->id;

        [$sql, $bindings] = $this->getService()->getSearchQuery($data);
        $per_page = $data['per_page'] ?? $this->di['pager']->getDefaultPerPage();
        $pager = $this->di['pager']->getPaginatedResultSet($sql, $bindings, $per_page);
        foreach ($pager['list'] as $key => $ticketArr) {
            $ticket = $this->di['db']->getExistingModelById('SupportTicket', $ticketArr['id'], 'Ticket not found');
            $pager['list'][$key] = $this->getService()->toApiArray($ticket, true, $this->getIdentity());
        }

        return $pager;
    }

    /**
     * Return ticket full details.
     *
     * @return array
     */
    public function ticket_get($data)
    {
        $required = [
            'id' => 'Ticket id required',
        ];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        $ticket = $this->getService()->findOneByClient($this->getIdentity(), $data['id']);

        return $this->getService()->toApiArray($ticket);
    }

    /**
     * Return pairs for support helpdesk. Can be used to populate select box.
     *
     * @return array
     */
    public function helpdesk_get_pairs()
    {
        return $this->getService()->helpdeskGetPairs();
    }

    /**
     * Method to create open new ticket. Tickets can have tasks assigned to them
     * via optional parameters.
     *
     * @optional int $rel_type - Ticket relation type
     * @optional int $rel_id - Ticket relation id
     * @optional int $rel_task - Ticket task codename
     * @optional int $rel_new_value - Task can have new value assigned.
     *
     * @return int $id - ticket id
     */
    public function ticket_create($data)
    {
        $required = [
            'content' => 'Ticket content required',
            'subject' => 'Ticket subject required',
            'support_helpdesk_id' => 'Ticket support_helpdesk_id required',
        ];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        $data['content'] = preg_replace('/javascript:\/\/|\%0(d|a)/i', '', $data['content']);

        $helpdesk = $this->di['db']->getExistingModelById('SupportHelpdesk', $data['support_helpdesk_id'], 'Helpdesk invalid');

        $client = $this->getIdentity();

        return $this->getService()->ticketCreateForClient($client, $helpdesk, $data);
    }

    /**
     * Add new conversation message to ticket. Ticket will be reopened if closed.
     *
     * @return bool
     */
    public function ticket_reply($data)
    {
        $required = [
            'id' => 'Ticket ID required',
            'content' => 'Ticket content required',
        ];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        $data['content'] = preg_replace('/javascript:\/\/|\%0(d|a)/i', '', $data['content']);

        $client = $this->getIdentity();

        $bindings = [
            ':id' => $data['id'],
            ':client_id' => $client->id,
        ];
        $ticket = $this->di['db']->findOne('SupportTicket', 'id = :id AND client_id = :client_id', $bindings);

        if (!$ticket instanceof \Model_SupportTicket) {
            throw new \FOSSBilling\InformationException('Ticket not found');
        }

        if (!$this->getService()->canBeReopened($ticket)) {
            throw new \FOSSBilling\InformationException('Ticket cannot be reopened.');
        }

        $result = $this->getService()->ticketReply($ticket, $client, $data['content']);

        return ($result > 0) ? true : false;
    }

    /**
     * Close ticket.
     *
     * @return bool
     */
    public function ticket_close($data)
    {
        $required = [
            'id' => 'Ticket ID required',
        ];
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        $client = $this->getIdentity();

        $ticket = $this->getService()->findOneByClient($client, $data['id']);

        return $this->getService()->closeTicket($ticket, $this->getIdentity());
    }
public function get_status($data)
{
    $orderId = $data['order_id'] ?? null;
    if (!$orderId) {
        throw new \FOSSBilling\Exception('Falta order_id.');
    }

    // Obtener el estado guardado localmente por si falla la API
    $localStatus = $this->di['db']->getCell('SELECT status FROM service_contabo WHERE order_id = ?', [$orderId]);
    
    $instanceId = $this->di['db']->getCell('SELECT instance_id FROM service_contabo WHERE order_id = ?', [$orderId]);
    if (!$instanceId) {
        return ['status' => $localStatus ?? 'unknown'];
    }

    // Intentar obtener el estado real del simulador
    try {
        $config = $this->getModuleConfig();
        $token = $this->obtenerTokenContabo($config);
        if ($token) {
            $apiBaseUrl = rtrim($config['api_base_url'] ?? 'http://127.0.0.1:5550', '/');
            $ch = curl_init("{$apiBaseUrl}/v1/compute/instances/{$instanceId}");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
            $response = curl_exec($ch);
            curl_close($ch);
            $apiData = json_decode($response, true);
            $instance = $apiData['data'][0] ?? null;
            if ($instance) {
                // Actualizar el estado en la BD local para mantenerlo sincronizado
                $this->di['db']->exec('UPDATE service_contabo SET status = ?, updated_at = NOW() WHERE order_id = ?', 
                    [$instance['status'], $orderId]);
                return ['status' => $instance['status']];
            }
        }
    } catch (\Exception $e) {
        // Si falla, devolvemos el estado local
    }

    return ['status' => $localStatus ?? 'unknown'];
}
public function stop($data)
{
    return $this->ejecutarAccion($data, 'stop');
}
public function start($data)
{
    return $this->ejecutarAccion($data, 'start');
}
private function ejecutarAccion($data, string $action): array
{
    $orderId = $data['order_id'] ?? null;
    if (!$orderId) {
        throw new \FOSSBilling\Exception('Falta order_id.');
    }

    $instanceId = $this->di['db']->getCell('SELECT instance_id FROM service_contabo WHERE order_id = ?', [$orderId]);
    if (!$instanceId) {
        throw new \FOSSBilling\Exception('No se encontró el VPS asociado.');
    }

    $meta = $this->di['db']->findOne('ExtensionMeta', 'extension = ? AND meta_key = ?', ['servicecontabo', 'config']);
    $config = [];
    if ($meta) {
        $decrypted = $this->di['crypt']->decrypt($meta->meta_value);
        $wrapper = json_decode($decrypted, true);
        if (is_array($wrapper)) {
            $current = $wrapper;
            $depth = 4;
            while ($depth > 0 && isset($current['config']) && !isset($current['client_id'])) {
                $current = $current['config'];
                $depth--;
            }
            $config = is_array($current) && isset($current['client_id']) ? $current : [];
        }
    }

    $token = $this->obtenerTokenContabo($config);
    if (!$token) {
        throw new \FOSSBilling\Exception('Error al autenticar con Contabo.');
    }

    $apiBaseUrl = rtrim($config['api_base_url'] ?? 'http://127.0.0.1:5550', '/');
    $url = "{$apiBaseUrl}/v1/compute/instances/{$instanceId}/actions/{$action}";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $token",
        "X-Request-ID: " . uniqid()
    ]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 && $httpCode !== 202) {
        throw new \FOSSBilling\Exception("Error al ejecutar la acción '{$action}' (HTTP {$httpCode}).");
    }

    $mensajes = [
        'stop'  => 'VPS detenido correctamente.',
        'start' => 'VPS iniciado correctamente.',
    ];
// Actualizar estado en la BD local
$nuevoEstado = $action === 'start' ? 'running' : 'stopped';
$this->di['db']->exec('UPDATE service_contabo SET status = ?, updated_at = NOW() WHERE order_id = ?', [$nuevoEstado, $orderId]);
    return [
        'success' => true,
        'message' => $mensajes[$action] ?? 'Acción ejecutada.'
    ];
}
private function getModuleConfig(): array
{
    $meta = $this->di['db']->findOne('ExtensionMeta', 'extension = ? AND meta_key = ?', ['servicecontabo', 'config']);
    $config = [];
    if ($meta) {
        $decrypted = $this->di['crypt']->decrypt($meta->meta_value);
        $wrapper = json_decode($decrypted, true);
        if (is_array($wrapper)) {
            $current = $wrapper;
            $depth = 4;
            while ($depth > 0 && isset($current['config']) && !isset($current['client_id'])) {
                $current = $current['config'];
                $depth--;
            }
            $config = is_array($current) && isset($current['client_id']) ? $current : [];
        }
    }
    return $config;
}
}
