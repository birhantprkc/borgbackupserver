<?php

namespace BBS\Core;

class App
{
    private \AltoRouter $router;

    public function __construct()
    {
        Config::load();

        // Set gc_maxlifetime to 30 days so Ubuntu's sessionclean cron
        // doesn't delete session files before our app-level timeout
        ini_set('session.gc_maxlifetime', 30 * 86400);

        session_set_cookie_params([
            'lifetime' => 30 * 86400,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
        $this->router = new \AltoRouter();
    }

    public function run(): void
    {
        $this->registerRoutes();

        $match = $this->router->match();

        if ($match) {
            [$controller, $method] = explode('@', $match['target']);
            $controller = "BBS\\Controllers\\{$controller}";
            $instance = new $controller();
            call_user_func_array([$instance, $method], $match['params']);
        } else {
            http_response_code(404);
            echo '404 Not Found';
        }
    }

    private function registerRoutes(): void
    {
        // Auth
        $this->router->map('GET', '/login', 'AuthController@loginForm');
        $this->router->map('POST', '/login', 'AuthController@login');
        $this->router->map('GET', '/login/2fa', 'AuthController@twoFactorForm');
        $this->router->map('POST', '/login/2fa', 'AuthController@twoFactorVerify');
        $this->router->map('GET', '/logout', 'AuthController@logout');
        $this->router->map('GET', '/forgot-password', 'AuthController@forgotPasswordForm');
        $this->router->map('POST', '/forgot-password', 'AuthController@forgotPassword');
        $this->router->map('GET', '/reset-password/[:token]', 'AuthController@resetPasswordForm');
        $this->router->map('POST', '/reset-password', 'AuthController@resetPassword');

        // Dashboard
        $this->router->map('GET', '/', 'DashboardController@index');
        $this->router->map('GET', '/dashboard', 'DashboardController@index');
        $this->router->map('GET', '/dashboard/json', 'DashboardController@apiJson');

        // Clients (Agents)
        $this->router->map('GET', '/clients', 'ClientController@index');
        $this->router->map('GET', '/clients/add', 'ClientController@add');
        $this->router->map('POST', '/clients/add', 'ClientController@store');
        $this->router->map('GET', '/clients/[i:id]', 'ClientController@detail');
        $this->router->map('GET', '/clients/[i:id]/json', 'ClientController@detailJson');
        $this->router->map('GET', '/clients/[i:id]/repos', 'ClientController@repos');
        $this->router->map('GET', '/clients/[i:id]/schedules', 'ClientController@schedules');
        $this->router->map('GET', '/clients/[i:id]/restore', 'ClientController@restore');
        $this->router->map('POST', '/clients/[i:id]/edit', 'ClientController@update');
        $this->router->map('POST', '/clients/[i:id]/delete', 'ClientController@delete');
        $this->router->map('POST', '/clients/[i:id]/update-borg', 'ClientController@updateBorg');
        $this->router->map('POST', '/clients/[i:id]/update-agent', 'ClientController@updateAgent');

        // Plugins
        $this->router->map('POST', '/clients/[i:id]/plugins', 'PluginController@updateAgentPlugins');

        // Plugin Configs (named configurations)
        $this->router->map('POST', '/clients/[i:id]/plugin-configs', 'PluginConfigController@store');
        $this->router->map('POST', '/clients/[i:id]/plugin-configs/[i:configId]/edit', 'PluginConfigController@update');
        $this->router->map('POST', '/clients/[i:id]/plugin-configs/[i:configId]/delete', 'PluginConfigController@delete');
        $this->router->map('POST', '/clients/[i:id]/plugin-configs/[i:configId]/test', 'PluginConfigController@test');
        $this->router->map('GET', '/clients/[i:id]/plugin-configs/[i:configId]/test-status', 'PluginConfigController@testStatus');

        // Repositories
        $this->router->map('POST', '/repositories/create', 'RepositoryController@store');
        $this->router->map('POST', '/repositories/[i:id]/delete', 'RepositoryController@delete');

        // Backup Plans
        $this->router->map('POST', '/plans/create', 'BackupPlanController@store');
        $this->router->map('POST', '/plans/[i:id]/edit', 'BackupPlanController@update');
        $this->router->map('POST', '/plans/[i:id]/delete', 'BackupPlanController@delete');
        $this->router->map('POST', '/plans/[i:id]/trigger', 'BackupPlanController@trigger');

        // Schedules
        $this->router->map('POST', '/schedules/[i:id]/toggle', 'ScheduleController@toggle');
        $this->router->map('POST', '/schedules/[i:id]/delete', 'ScheduleController@delete');

        // Queue
        $this->router->map('GET', '/queue', 'QueueController@index');
        $this->router->map('GET', '/queue/json', 'QueueController@indexJson');
        $this->router->map('GET', '/queue/[i:id]', 'QueueController@detail');
        $this->router->map('GET', '/queue/[i:id]/json', 'QueueController@detailJson');
        $this->router->map('POST', '/queue/[i:id]/cancel', 'QueueController@cancel');
        $this->router->map('POST', '/queue/[i:id]/retry', 'QueueController@retry');

        // Notifications
        $this->router->map('GET', '/notifications', 'NotificationController@index');
        $this->router->map('POST', '/notifications/[i:id]/read', 'NotificationController@markRead');
        $this->router->map('POST', '/notifications/read-all', 'NotificationController@markAllRead');

        // Log
        $this->router->map('GET', '/log', 'LogController@index');

        // Settings
        $this->router->map('GET', '/settings', 'SettingsController@index');
        $this->router->map('POST', '/settings', 'SettingsController@update');
        $this->router->map('POST', '/settings/templates/add', 'SettingsController@addTemplate');
        $this->router->map('POST', '/settings/templates/[i:id]/edit', 'SettingsController@editTemplate');
        $this->router->map('POST', '/settings/templates/[i:id]/delete', 'SettingsController@deleteTemplate');
        $this->router->map('POST', '/settings/test-smtp', 'SettingsController@testSmtp');
        $this->router->map('POST', '/settings/check-update', 'SettingsController@checkUpdate');
        $this->router->map('POST', '/settings/upgrade', 'SettingsController@upgrade');
        $this->router->map('POST', '/settings/sync', 'SettingsController@sync');
        $this->router->map('POST', '/settings/upgrade-agents', 'SettingsController@upgradeAgents');
        $this->router->map('GET', '/api/agent-updates', 'SettingsController@agentUpdatesJson');
        $this->router->map('GET', '/api/templates/[i:id]', 'SettingsController@templateJson');

        // Users (admin)
        $this->router->map('GET', '/users', 'UserController@index');
        $this->router->map('POST', '/users/add', 'UserController@store');
        $this->router->map('POST', '/users/[i:id]/edit', 'UserController@update');
        $this->router->map('POST', '/users/[i:id]/delete', 'UserController@delete');

        // Profile
        $this->router->map('GET', '/profile', 'ProfileController@index');
        $this->router->map('POST', '/profile', 'ProfileController@update');
        $this->router->map('POST', '/profile/detect-timezone', 'ProfileController@detectTimezone');
        $this->router->map('POST', '/profile/2fa/setup', 'ProfileController@twoFactorSetup');
        $this->router->map('POST', '/profile/2fa/enable', 'ProfileController@twoFactorEnable');
        $this->router->map('POST', '/profile/2fa/disable', 'ProfileController@twoFactorDisable');
        $this->router->map('POST', '/profile/2fa/regenerate-codes', 'ProfileController@twoFactorRegenerateCodes');

        // Toasts (global live notifications)
        $this->router->map('GET', '/api/toasts', 'DashboardController@toasts');

        // Agent API
        $this->router->map('POST', '/api/agent/register', 'Api\\AgentApiController@register');
        $this->router->map('GET', '/api/agent/tasks', 'Api\\AgentApiController@tasks');
        $this->router->map('POST', '/api/agent/progress', 'Api\\AgentApiController@progress');
        $this->router->map('POST', '/api/agent/status', 'Api\\AgentApiController@status');
        $this->router->map('POST', '/api/agent/heartbeat', 'Api\\AgentApiController@heartbeat');
        $this->router->map('POST', '/api/agent/info', 'Api\\AgentApiController@info');
        $this->router->map('POST', '/api/agent/catalog', 'Api\\AgentApiController@catalog');
        $this->router->map('GET', '/api/agent/ssh-key', 'Api\\AgentApiController@sshKey');
        $this->router->map('GET', '/api/agent/download', 'Api\\AgentApiController@downloadFile');
        $this->router->map('GET', '/get-agent', 'Api\\AgentApiController@getAgent');

        // Catalog & Restore (client-facing)
        $this->router->map('GET', '/clients/[i:id]/catalog/[i:archive_id]', 'ClientController@catalog');
        $this->router->map('GET', '/clients/[i:id]/catalog/[i:archive_id]/tree', 'ClientController@catalogTree');
        $this->router->map('GET', '/clients/[i:id]/catalog/search-all', 'ClientController@catalogSearchAll');
        $this->router->map('POST', '/clients/[i:id]/restore', 'ClientController@restoreSubmit');
        $this->router->map('POST', '/clients/[i:id]/restore-mysql', 'ClientController@restoreMysqlSubmit');
        $this->router->map('POST', '/clients/[i:id]/restore-pg', 'ClientController@restorePgSubmit');
        $this->router->map('GET', '/clients/[i:id]/archive/[i:archive_id]/databases', 'ClientController@archiveDatabases');
        $this->router->map('POST', '/clients/[i:id]/download', 'ClientController@download');
    }
}
