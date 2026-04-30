<?php

namespace BBS\Controllers;

use BBS\Core\Controller;

class LogController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();

        $level = $_GET['level'] ?? '';
        $clientId = !empty($_GET['client']) ? (int) $_GET['client'] : 0;
        // Time-window filter (used by the dashboard "Errors (24h)" tile so
        // the linked page only shows the same 24h window the count counted —
        // before this, the tile said "12 errors in 24h" and the linked page
        // showed every error ever, including weeks old (#232).
        $hours = isset($_GET['hours']) ? max(0, (int) $_GET['hours']) : 0;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        // Filter logs by accessible agents
        [$agentWhere, $agentParams] = $this->getAgentWhereClause('a');

        $where = "(sl.agent_id IS NULL OR {$agentWhere})";
        $params = $agentParams;

        if ($level && in_array($level, ['info', 'warning', 'error'])) {
            $where .= ' AND sl.level = ?';
            $params[] = $level;
        }

        if ($clientId > 0) {
            $where .= ' AND sl.agent_id = ?';
            $params[] = $clientId;
        }

        if ($hours > 0) {
            $where .= ' AND sl.created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)';
            $params[] = $hours;
        }

        // Get agents list for the client filter dropdown
        [$agentListWhere, $agentListParams] = $this->getAgentWhereClause('a');
        $agents = $this->db->fetchAll("
            SELECT a.id, a.name FROM agents a WHERE {$agentListWhere} ORDER BY a.name
        ", $agentListParams);

        // Get total count for pagination
        $countRow = $this->db->fetchOne("
            SELECT COUNT(*) as cnt
            FROM server_log sl
            LEFT JOIN agents a ON a.id = sl.agent_id
            WHERE {$where}
        ", $params);
        $total = (int) ($countRow['cnt'] ?? 0);
        $pages = max(1, (int) ceil($total / $perPage));

        $logs = $this->db->fetchAll("
            SELECT sl.*, a.name as agent_name
            FROM server_log sl
            LEFT JOIN agents a ON a.id = sl.agent_id
            WHERE {$where}
            ORDER BY sl.created_at DESC
            LIMIT {$perPage} OFFSET {$offset}
        ", $params);

        $this->view('log/index', [
            'pageTitle' => 'Log',
            'logs' => $logs,
            'agents' => $agents,
            'currentLevel' => $level,
            'currentClient' => $clientId,
            'currentHours' => $hours,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
    }
}
