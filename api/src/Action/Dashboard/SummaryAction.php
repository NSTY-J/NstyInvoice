<?php

declare(strict_types=1);

namespace MyInvoice\Action\Dashboard;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Agregace pro Dashboard:
 *  - KPI: letošní obrat per měna, YoY change, počet vystavených, průměrná doba úhrady
 *  - Po splatnosti: tabulka faktur s due_date < today, status issued/sent
 *  - Nezaplacené (před splatností)
 *  - Top klienti YTD
 *  - Obrat po měsících (12 měsíců současný + minulý rok)
 *
 * Storno (cancelled) a interní cancellation se z obratu vyřazují.
 */
final class SummaryAction
{
    public function __construct(private readonly Connection $db) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $pdo = $this->db->pdo();
        $today = new \DateTimeImmutable('today');
        $year = (int) $today->format('Y');
        $prevYear = $year - 1;
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);

        return Json::ok($response, [
            'kpi'                    => $this->kpi($pdo, $year, $prevYear, $sid),
            'overdue'                => $this->overdue($pdo, $sid),
            'unpaid_upcoming'        => $this->unpaidUpcoming($pdo, $sid),
            'top_clients_ytd'        => $this->topClients($pdo, $year, $sid),
            'top_clients_prev_year'  => $this->topClients($pdo, $prevYear, $sid),
            'revenue_by_month'       => $this->revenueByMonth($pdo, $year, $prevYear, $sid),
            'today'                  => $today->format('Y-m-d'),
            'year'                   => $year,
            'prev_year'              => $prevYear,
        ]);
    }

    private function kpi(\PDO $pdo, int $year, int $prevYear, int $sid): array
    {
        // Obrat per měna pro YTD (letošní vs. minulý rok)
        // Záměrně počítáme i NEZAPLACENÉ faktury, pokud jsou vystavené (status: issued / sent / paid).
        // Dobropisy (credit_note) sem NEZAHRNUJEME — zobrazují se separátně, aby neuměle nesnižovaly obrat.
        $sql = "SELECT cur.code AS currency, YEAR(COALESCE(i.tax_date, i.issue_date)) AS y, SUM(i.total_with_vat) AS total
                  FROM invoices i
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND YEAR(COALESCE(i.tax_date, i.issue_date)) IN (?, ?)
                   AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                   AND i.invoice_type = 'invoice'
                 GROUP BY cur.code, y";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid, $year, $prevYear]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $perCurrency = [];
        foreach ($rows as $r) {
            $cur = $r['currency'];
            if (!isset($perCurrency[$cur])) {
                $perCurrency[$cur] = [
                    'currency'   => $cur,
                    'this_year'  => 0.0,
                    'prev_year'  => 0.0,
                    'change_pct' => null,
                ];
            }
            $perCurrency[$cur][(int) $r['y'] === $year ? 'this_year' : 'prev_year'] = (float) $r['total'];
        }
        foreach ($perCurrency as &$pc) {
            if ($pc['prev_year'] > 0) {
                $pc['change_pct'] = round((($pc['this_year'] - $pc['prev_year']) / $pc['prev_year']) * 100, 1);
            }
            $pc['this_year'] = round($pc['this_year'], 2);
            $pc['prev_year'] = round($pc['prev_year'], 2);
        }
        unset($pc);

        // Počet vystavených YTD
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM invoices
              WHERE supplier_id = ?
                AND YEAR(COALESCE(tax_date, issue_date)) = ?
                AND status NOT IN ('draft', 'cancelled')
                AND invoice_type IN ('invoice', 'credit_note', 'proforma')"
        );
        $stmt->execute([$sid, $year]);
        $issuedCount = (int) $stmt->fetchColumn();

        // Po splatnosti — počet a celkem k úhradě
        $stmt = $pdo->prepare(
            "SELECT cur.code AS currency, COUNT(*) AS cnt, SUM(i.amount_to_pay) AS total
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.status IN ('issued','sent','reminded') AND i.due_date <= CURDATE()
                AND i.invoice_type IN ('invoice','credit_note')
              GROUP BY cur.code"
        );
        $stmt->execute([$sid]);
        $overdue = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $overduePerCurrency = array_map(fn (array $r) => [
            'currency' => $r['currency'],
            'count'    => (int) $r['cnt'],
            'total'    => round((float) $r['total'], 2),
        ], $overdue);
        $overdueTotalCount = array_sum(array_column($overduePerCurrency, 'count'));

        // Průměrná doba úhrady (paid_at - issue_date) ve dnech, pro letošní zaplacené
        $stmt = $pdo->prepare(
            "SELECT AVG(DATEDIFF(paid_at, issue_date)) FROM invoices
              WHERE supplier_id = ? AND status = 'paid' AND paid_at IS NOT NULL
                AND YEAR(COALESCE(tax_date, issue_date)) = ?"
        );
        $stmt->execute([$sid, $year]);
        $avgPaymentDays = $stmt->fetchColumn();
        $avgPaymentDays = $avgPaymentDays !== null && $avgPaymentDays !== false
            ? round((float) $avgPaymentDays, 1)
            : null;

        // Stav faktur YTD (počet) — pro fallback chart když není prev year
        $stmt = $pdo->prepare(
            "SELECT status, COUNT(*) AS cnt
               FROM invoices
              WHERE supplier_id = ?
                AND YEAR(COALESCE(tax_date, issue_date)) = ?
                AND invoice_type = 'invoice'
              GROUP BY status"
        );
        $stmt->execute([$sid, $year]);
        $statusCounts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $statusCounts[$r['status']] = (int) $r['cnt'];
        }

        return [
            'per_currency'        => array_values($perCurrency),
            'issued_count_ytd'    => $issuedCount,
            'overdue_count'       => $overdueTotalCount,
            'overdue_per_currency'=> $overduePerCurrency,
            'avg_payment_days'    => $avgPaymentDays,
            'status_counts_ytd'   => $statusCounts,
        ];
    }

    private function overdue(\PDO $pdo, int $sid): array
    {
        $sql = "SELECT i.id, i.varsymbol, i.invoice_type, i.client_id, cur.code AS currency,
                       i.issue_date, i.due_date, i.amount_to_pay, i.status,
                       c.company_name AS client_company_name,
                       DATEDIFF(CURDATE(), i.due_date) AS days_overdue
                  FROM invoices i
                  JOIN clients c ON c.id = i.client_id
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND i.status IN ('issued','sent','reminded')
                   AND i.due_date <= CURDATE()
                   AND i.invoice_type IN ('invoice','credit_note')
                 ORDER BY i.due_date ASC
                 LIMIT 20";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn (array $r) => $this->castListItem($r), $rows);
    }

    private function unpaidUpcoming(\PDO $pdo, int $sid): array
    {
        $sql = "SELECT i.id, i.varsymbol, i.invoice_type, i.client_id, cur.code AS currency,
                       i.issue_date, i.due_date, i.amount_to_pay, i.status,
                       c.company_name AS client_company_name
                  FROM invoices i
                  JOIN clients c ON c.id = i.client_id
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND i.status IN ('issued','sent','reminded')
                   AND i.due_date >= CURDATE()
                   AND i.invoice_type IN ('invoice','credit_note')
                 ORDER BY i.due_date ASC
                 LIMIT 20";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn (array $r) => $this->castListItem($r), $rows);
    }

    private function topClients(\PDO $pdo, int $year, int $sid): array
    {
        $sql = "SELECT c.id, c.company_name, cur.code AS currency,
                       SUM(i.total_with_vat) AS total,
                       COUNT(*) AS invoice_count
                  FROM invoices i
                  JOIN clients c ON c.id = i.client_id
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                   AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                   AND i.invoice_type = 'invoice'
                 GROUP BY c.id, c.company_name, cur.code
                 ORDER BY total DESC
                 LIMIT 10";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid, $year]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn (array $r) => [
            'client_id'     => (int) $r['id'],
            'company_name'  => $r['company_name'],
            'currency'      => $r['currency'],
            'total'         => round((float) $r['total'], 2),
            'invoice_count' => (int) $r['invoice_count'],
        ], $rows);
    }

    /**
     * Obrat per měsíc (1-12) pro letošní + minulý rok, per měna.
     * Output: { 'CZK': { '2026': [m1..m12], '2025': [m1..m12] }, 'EUR': {...} }
     */
    private function revenueByMonth(\PDO $pdo, int $year, int $prevYear, int $sid): array
    {
        // Obrat po měsících — totéž pravidlo jako u kpi(): vystavené invoice bez dobropisů.
        $sql = "SELECT cur.code AS currency,
                       YEAR(COALESCE(i.tax_date, i.issue_date)) AS y,
                       MONTH(COALESCE(i.tax_date, i.issue_date)) AS m,
                       SUM(i.total_with_vat) AS total
                  FROM invoices i
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND YEAR(COALESCE(i.tax_date, i.issue_date)) IN (?, ?)
                   AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                   AND i.invoice_type = 'invoice'
                 GROUP BY cur.code, y, m";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid, $year, $prevYear]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $r) {
            $cur = $r['currency'];
            $y = (string) $r['y'];
            $m = (int) $r['m'];
            if (!isset($out[$cur])) {
                $out[$cur] = [
                    (string) $year     => array_fill(1, 12, 0.0),
                    (string) $prevYear => array_fill(1, 12, 0.0),
                ];
            }
            $out[$cur][$y][$m] = round((float) $r['total'], 2);
        }

        // Reformat to use 0-indexed arrays for frontend Chart.js
        $formatted = [];
        foreach ($out as $cur => $years) {
            $formatted[] = [
                'currency' => $cur,
                'this_year' => array_values($years[(string) $year]),
                'prev_year' => array_values($years[(string) $prevYear]),
            ];
        }
        return $formatted;
    }

    private function castListItem(array $r): array
    {
        return [
            'id'                  => (int) $r['id'],
            'varsymbol'           => $r['varsymbol'],
            'invoice_type'        => $r['invoice_type'],
            'client_id'           => (int) $r['client_id'],
            'client_company_name' => $r['client_company_name'],
            'currency'            => $r['currency'],
            'issue_date'          => $r['issue_date'],
            'due_date'            => $r['due_date'],
            'amount_to_pay'       => (float) $r['amount_to_pay'],
            'status'              => $r['status'],
            'days_overdue'        => isset($r['days_overdue']) ? (int) $r['days_overdue'] : null,
        ];
    }
}
