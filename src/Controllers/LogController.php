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

        // Resolved errors are hidden by default so multiple admins don't
        // re-triage the same handled error (#365); ?resolved=1 shows them
        $showResolved = !empty($_GET['resolved']);
        if (!$showResolved) {
            $where .= ' AND sl.resolved_at IS NULL';
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
            SELECT sl.*, a.name as agent_name, u.username as resolved_by_name
            FROM server_log sl
            LEFT JOIN agents a ON a.id = sl.agent_id
            LEFT JOIN users u ON u.id = sl.resolved_by
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
            'showResolved' => $showResolved,
        ]);
    }

    /**
     * Mark an error entry as resolved (or unresolved) so it drops off the
     * dashboard and default log view (#365). Admin only — resolution is a
     * global state shared by every admin doing backup checks.
     */
    public function resolve(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $entry = $this->db->fetchOne("SELECT id, level, resolved_at FROM server_log WHERE id = ?", [$id]);
        if (!$entry || $entry['level'] !== 'error') {
            $this->flash('danger', 'Log entry not found (only errors can be resolved).');
            $this->redirect('/log');
        }

        if (!empty($_POST['unresolve'])) {
            $this->db->update('server_log', ['resolved_at' => null, 'resolved_by' => null], 'id = ?', [$id]);
            $this->flash('success', 'Error marked as unresolved.');
        } else {
            $this->db->update('server_log', [
                'resolved_at' => date('Y-m-d H:i:s'),
                'resolved_by' => $_SESSION['user_id'] ?? null,
            ], 'id = ?', [$id]);
            $this->flash('success', 'Error marked as resolved.');
        }
        $this->redirect($this->safeReturnPath());
    }

    /**
     * Resolve every currently-unresolved error in one click.
     */
    public function resolveAll(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $this->db->query(
            "UPDATE server_log SET resolved_at = NOW(), resolved_by = ? WHERE level = 'error' AND resolved_at IS NULL",
            [$_SESSION['user_id'] ?? null]
        );
        $this->flash('success', 'All errors marked as resolved.');
        $this->redirect($this->safeReturnPath());
    }

    /** Only same-site paths — a POSTed return URL must not become an open redirect. */
    private function safeReturnPath(): string
    {
        $r = $_POST['return'] ?? '';
        return (is_string($r) && str_starts_with($r, '/') && !str_starts_with($r, '//')) ? $r : '/log';
    }
}
