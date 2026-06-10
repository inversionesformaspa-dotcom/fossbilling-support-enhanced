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
namespace Box\Mod\Support\Controller;

class Admin implements \FOSSBilling\InjectionAwareInterface
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

public function fetchNavigation(): array
{
    $nav = [
        'group' => [
            'location' => 'support',
            'index' => 500,
            'label' => __trans('Support'),
            'class' => 'support',
        ],
        'subpages' => [
            [
                'location' => 'support',
                'label' => __trans('Client Tickets'),
                'uri' => $this->di['url']->adminLink('support'),
                'index' => 100,
                'class' => '',
            ],
            [
                'location' => 'support',
                'label' => __trans('Advanced Ticket Search'),
                'uri' => $this->di['url']->adminLink('support', ['show_filter' => 1]),
                'index' => 300,
                'class' => '',
            ],
            [
                'location' => 'support',
                'label' => __trans('Canned Responses'),
                'uri' => $this->di['url']->adminLink('support/canned-responses'),
                'index' => 400,
                'class' => '',
            ],
        ],
    ];
    // Verificar qué tipos de mesas existen
    $hasClientHelpdesks = $this->di['db']->getCell("SELECT COUNT(*) FROM support_helpdesk WHERE allow_client_tickets = 1 OR access_level IN ('clients', 'hybrid')");
	// Verificar si hay tickets públicos O mesas públicas
	$hasPublicTickets = $this->di['db']->getCell("SELECT COUNT(*) FROM support_p_ticket");
	$hasPublicHelpdesks = $this->di['db']->getCell("SELECT COUNT(*) FROM support_helpdesk WHERE allow_public_tickets = 1 OR access_level IN ('public', 'hybrid')");
    $hasStaffHelpdesks = $this->di['db']->getCell("SELECT COUNT(*) FROM support_helpdesk WHERE allow_staff_tickets = 1 OR access_level = 'staff'");
    
    $admin = $this->di['session']->get('admin');
    // Menú condicional según mesa de soporte creada
    // Public Tickets - solo si hay mesas públicas o tikets publicos
    if ($hasPublicTickets > 0 || $hasPublicHelpdesks > 0) {
        $nav['subpages'][] = [ 
            'location' => 'support',
            'label' => __trans('Public Tickets'),
            'uri' => $this->di['url']->adminLink('support/public-tickets'),
            'index' => 200,
            'class' => '',
        ];
    }
    // Menú condicional según rol y si esta creada la mesa de staff
    if ($admin && isset($admin['role']) && $hasStaffHelpdesks > 0) {
        if ($admin['role'] === 'admin') {
            // Admin: ve "Staff Tickets" (todos)
            $nav['subpages'][] = [
                'location' => 'support',
                'label' => __trans('Staff Tickets'),
                'uri' => $this->di['url']->adminLink('support/staff-tickets'),
                'index' => 150,
                'class' => '',
            ];
        } elseif ($admin['role'] === 'staff') {
            // Staff: solo ve "Mis Consultas"
            $nav['subpages'][] = [
                'location' => 'support',
                'label' => __trans('Mis Consultas'),
                'uri' => $this->di['url']->adminLink('support/my-staff-tickets'),
                'index' => 150,
                'class' => '',
            ];
        }
    }

    if ($this->di['mod']('support')->getService()->kbEnabled()) {
        $nav['subpages'][] = [
            'location' => 'support',
            'index' => 500,
            'label' => __trans('Knowledge Base'),
            'uri' => $this->di['url']->adminLink('support/kb'),
            'class' => '',
        ];
    }
	// Detectar qué sistema de filtrado está disponible
	$filterSystem = \Box\Mod\Support\Services\SpamAssassinManager::detectFilterSystem();
	if ($filterSystem === 'sieve' || $filterSystem === 'spamassassin') {
	// Whitelist y Blacklist visibles si hay filtro instalado y activo
	$nav['subpages'][] = [
	    'location' => 'support',
	    'label' => __trans('Email Whitelist'),
	    'uri' => $this->di['url']->adminLink('support/whitelist'),
	    'index' => 450,
	    'class' => '',
	];
	$nav['subpages'][] = [
	    'location' => 'support',
	    'label' => __trans('Email Blacklist'),
	    'uri' => $this->di['url']->adminLink('support/blacklist'),
	    'index' => 470,
	    'class' => '',
	];
	$nav['subpages'][] = [
	    'location' => 'support',
    	    'label'    => __trans('Spam Keywords'),
    	    'uri'      => $this->di['url']->adminLink('support/spam-keywords'),
    	    'index'    => 480,
    	    'class'    => '',
	];
	}
	if ($filterSystem === 'sieve') {
	    $nav['subpages'][] = [
	        'location' => 'support',
	        'label' => __trans('Sieve Configuration'),
	        'uri' => $this->di['url']->adminLink('support/sieve'),
	        'index' => 480,
	        'class' => '',
	    ];
	} elseif ($filterSystem === 'spamassassin') {
	    $nav['subpages'][] = [
	        'location' => 'support',
	        'label' => __trans('SpamAssassin'),
	        'uri' => $this->di['url']->adminLink('support/spamassassin'),
	        'index' => 480,
	        'class' => '',
	    ];
	} else {
	    $nav['subpages'][] = [
	        'location' => 'support',
	        'label' => __trans('⚠️ Filtros de Correo'),
	        'uri' => $this->di['url']->adminLink('support/sieve'),
	        'index' => 480,
	        'class' => 'text-warning',
	    ];
	}
    return $nav;
}

    public function register(\Box_App &$app)
    {
        $app->get('/support', 'get_index', [], static::class);
        $app->get('/support/', 'get_index', [], static::class);
        $app->get('/support/index', 'get_index', [], static::class);
        $app->get('/support/ticket/:id', 'get_ticket', ['id' => '[0-9]+'], static::class);
        $app->get('/support/ticket/:id/message/:messageid', 'get_ticket', ['id' => '[0-9]+', 'messageid' => '[0-9]+'], static::class);
        $app->get('/support/staff-tickets', 'get_staff_tickets', [], static::class);
        $app->get('/support/staff-ticket/:id', 'get_staff_ticket', ['id' => '[0-9]+'], static::class);
        $app->get('/support/public-tickets', 'get_public_tickets', [], static::class);
        $app->get('/support/public-ticket/:id', 'get_public_ticket', ['id' => '[0-9]+'], static::class);
        $app->get('/support/helpdesks', 'get_helpdesks', [], static::class);
        $app->get('/support/helpdesk/:id', 'get_helpdesk', ['id' => '[0-9]+'], static::class);
        $app->get('/support/canned-responses', 'get_canned_list', [], static::class);
        $app->get('/support/canned/:id', 'get_canned', ['id' => '[0-9]+'], static::class);
        $app->get('/support/canned-category/:id', 'get_canned_cat', ['id' => '[0-9]+'], static::class);
        $app->get('/support/whitelist', 'get_whitelist', [], static::class);
        $app->get('/support/sieve', 'get_sieve_config', [], static::class);
        $app->get('/support/blacklist', 'get_blacklist', [], static::class);
        $app->get('/support/spam-keywords', 'get_spam_keywords', [], static::class);
	$app->post('/support/spam-keywords', 'post_spam_keywords', [], static::class);
        $app->get('/support/my-staff-tickets', 'get_my_staff_tickets', [], static::class);
	$app->get('/support/my-staff-ticket/:id', 'get_my_staff_ticket', ['id' => '[0-9]+'], static::class);
	$app->get('/support/spamassassin', 'get_spamassassin', [], static::class);

        if ($this->di['mod']('support')->getService()->kbEnabled()) {
            $app->get('support/kb', 'get_kb_index', [], static::class);
            $app->get('support/kb/article/:id', 'get_kb_article', [], static::class);
            $app->get('support/kb/category/:id', 'get_kb_category', [], static::class);
        }
    }

    // ============================================================
    // TICKETS DE CLIENTE
    // ============================================================

    public function get_index(\Box_App $app): string
    {
        $this->di['is_admin_logged'];
        return $app->render('mod_support_tickets');
    }

    public function get_ticket(\Box_App $app, $id, $messageid = ''): string
    {
        $api = $this->di['api_admin'];
        $ticket = $api->support_ticket_get(['id' => $id]);

        $cdm = '';
        $mod = $this->di['mod']('support');
        $config = $mod->getConfig();

        try {
            if (isset($config['delay_enable']) && $config['delay_enable'] && isset($config['delay_hours']) && $config['delay_hours'] >= 0) {
                $last_message = end($ticket['messages']);
                reset($ticket);
                $hours_passed = (round((time() - strtotime($last_message['created_at'])) / 3600) > $config['delay_hours']);
                if ($hours_passed) {
                    $delay_canned = $api->support_canned_get(['id' => $config['delay_message_id']]);
                    $cdm = $delay_canned['content'];
                }
            }
        } catch (\Exception $e) {
            error_log($e->getMessage());
        }

        return $app->render('mod_support_ticket', ['ticket' => $ticket, 'canned_delay_message' => $cdm, 'request_message' => $messageid]);
    }

    // ============================================================
    // TICKETS DE STAFF (admin)
    // ============================================================

    public function get_staff_tickets(\Box_App $app): string
    {
        $this->di['is_admin_logged'];
        return $app->render('mod_support_staff_tickets');
    }

    public function get_staff_ticket(\Box_App $app, $id): string
    {
        $api = $this->di['api_admin'];
        $ticket = $api->support_staff_ticket_get(['id' => $id]);
        return $app->render('mod_support_staff_ticket', ['ticket' => $ticket]);
    }
    
    
     	public function get_my_staff_tickets(\Box_App $app): string
	{
    	$this->di['is_admin_logged'];
    	return $app->render('mod_support_my_staff_tickets');
	}

	public function get_my_staff_ticket(\Box_App $app, $id): string
	{
    	$api = $this->di['api_admin'];
    	$ticket = $api->support_my_staff_ticket_get(['id' => $id]);
    	return $app->render('mod_support_my_staff_ticket', ['ticket' => $ticket]);
	}

    // ============================================================
    // TICKETS PÚBLICOS
    // ============================================================

    public function get_public_tickets(\Box_App $app): string
    {
        $this->di['is_admin_logged'];
        return $app->render('mod_support_public_tickets');
    }

    public function get_public_ticket(\Box_App $app, $id): string
    {
        $api = $this->di['api_admin'];
        $ticket = $api->support_public_ticket_get(['id' => $id]);
        return $app->render('mod_support_public_ticket', ['ticket' => $ticket]);
    }

    // ============================================================
    // HELPDESKS
    // ============================================================

    public function get_helpdesk(\Box_App $app, $id): string
    {
        $api = $this->di['api_admin'];
        $helpdesk = $api->support_helpdesk_get(['id' => $id]);
        return $app->render('mod_support_helpdesk', ['helpdesk' => $helpdesk]);
    }

    public function get_helpdesks(\Box_App $app): string
    {
        $this->di['is_admin_logged'];
        return $app->render('mod_support_helpdesks');
    }

    // ============================================================
    // CANNED RESPONSES
    // ============================================================

    public function get_canned_list(\Box_App $app): string
    {
        $this->di['is_admin_logged'];
        return $app->render('mod_support_canned_responses');
    }

    public function get_canned(\Box_App $app, $id): string
    {
        $api = $this->di['api_admin'];
        $c = $api->support_canned_get(['id' => $id]);
        return $app->render('mod_support_canned_response', ['response' => $c]);
    }

    public function get_canned_cat(\Box_App $app, $id): string
    {
        $api = $this->di['api_admin'];
        $c = $api->support_canned_category_get(['id' => $id]);
        return $app->render('mod_support_canned_category', ['category' => $c]);
    }

    // ============================================================
    // EMAIL GATEWAY PAGES
    // ============================================================

    public function get_whitelist(\Box_App $app): string
    {
        $this->di['is_admin_logged'];
        $api = $this->di['api_admin'];
        $data = $api->support_whitelist_get([]);
        return $app->render('mod_support_whitelist', [
            'items' => $data['items'] ?? [],
            'accounts_with_sieve' => $data['accounts_with_sieve'] ?? [],
        ]);
    }

    public function get_blacklist(\Box_App $app): string
    {
        $this->di['is_admin_logged'];
        $api = $this->di['api_admin'];
        $data = $api->support_blacklist_get([]);
        return $app->render('mod_support_blacklist', [
            'items' => $data['items'] ?? [],
            'helpdesks' => $data['helpdesks'] ?? [],
        ]);
    }

    public function get_sieve_config(\Box_App $app): string
    {
        $this->di['is_admin_logged'];
        $api = $this->di['api_admin'];
        $data = $api->support_sieve_get([]);
        return $app->render('mod_support_sieve', ['accounts' => $data['accounts'] ?? []]);
    }
    
    public function get_spamassassin(\Box_App $app): string
    {
    	$this->di['is_admin_logged'];
    	$api = $this->di['api_admin'];
    	$data = $api->support_spamassassin_get([]);
    	return $app->render('mod_support_spamassassin', [
        'available' => $data['available'] ?? false,
        'version' => $data['version'] ?? '',
        'accounts' => $data['accounts'] ?? [],
    	]);
     }
	public function get_spam_keywords(\Box_App $app): string
	{
    	$this->di['is_admin_logged'];
    	return $app->render('mod_support_spam_keywords');
	}
public function post_spam_keywords(\Box_App $app)
{
    $this->di['is_admin_logged'];

    $action = $this->di['request']->request->get('action');
    $keyword = trim($this->di['request']->request->get('keyword', ''));
    $type = $this->di['request']->request->get('type', 'subject');
    $helpdesk_id = $this->di['request']->request->get('helpdesk_id') ? (int)$this->di['request']->request->get('helpdesk_id') : null;

    $api = $this->di['api_admin'];

    if ($action === 'add') {
        $api->support_spamkw_add([
            'keyword'     => $keyword,
            'type'        => $type,
            'helpdesk_id' => $helpdesk_id,
        ]);
    } elseif ($action === 'delete') {
        $id = (int)$this->di['request']->request->get('id');
        $api->support_spamkw_delete(['id' => $id]);
    }

    $app->redirect(parse_url($this->di['url']->adminLink('support/spam-keywords'), PHP_URL_PATH));
}
// ============================================================
    // KNOWLEDGE BASE
    // ============================================================

    public function get_kb_index(\Box_App $app): string
    {
        $this->di['is_admin_logged'];
        return $app->render('mod_support_kb_index');
    }

    public function get_kb_article(\Box_App $app, $id): string
    {
        $api = $this->di['api_admin'];
        $post = $api->support_kb_article_get(['id' => $id]);
        return $app->render('mod_support_kb_article', ['post' => $post]);
    }

    public function get_kb_category(\Box_App $app, $id): string
    {
        $api = $this->di['api_admin'];
        $cat = $api->support_kb_category_get(['id' => $id]);
        return $app->render('mod_support_kb_category', ['category' => $cat]);
    }
}
