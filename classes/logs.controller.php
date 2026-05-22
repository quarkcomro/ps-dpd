<?php

/** **/

if (!defined('_PS_VERSION_'))
    exit;

class DpdGeopostLogsController extends DpdGeopostController
{
    const FILENAME = 'logs.controller';
    const LOGS_PER_PAGE = 20;

    public function getLogsPage()
    {
        require_once(_DPDGEOPOST_CLASSES_DIR_ . 'apiDebugLogger.php');

        DpdGeopostApiDebugLogger::prune();
        $debugLogDates = DpdGeopostApiDebugLogger::listAvailableDates();
        $selectedDebugLogDate = Tools::getValue('debug_log_date');
        if (!$selectedDebugLogDate && !empty($debugLogDates)) {
            $selectedDebugLogDate = $debugLogDates[0];
        }
        if (!in_array($selectedDebugLogDate, $debugLogDates, true)) {
            $selectedDebugLogDate = !empty($debugLogDates) ? $debugLogDates[0] : '';
        }
        $allEntries = $selectedDebugLogDate ? $this->parseDebugLogEntries(DpdGeopostApiDebugLogger::readByDate($selectedDebugLogDate)) : array();

        $totalLogEntries = count($allEntries);
        $totalLogPages = $totalLogEntries > 0 ? (int) ceil($totalLogEntries / self::LOGS_PER_PAGE) : 0;
        $currentLogPage = max(1, (int) Tools::getValue('log_page', 1));
        if ($totalLogPages > 0 && $currentLogPage > $totalLogPages) {
            $currentLogPage = $totalLogPages;
        }

        $offset = ($currentLogPage - 1) * self::LOGS_PER_PAGE;
        $pageEntries = $totalLogEntries > 0 ? array_slice($allEntries, $offset, self::LOGS_PER_PAGE) : array();
        $showingFrom = $totalLogEntries > 0 ? $offset + 1 : 0;
        $showingTo = $totalLogEntries > 0 ? min($offset + self::LOGS_PER_PAGE, $totalLogEntries) : 0;

        $logPageBaseUrl = $this->module_instance->module_url . '&menu=logs';
        if ($selectedDebugLogDate !== '') {
            $logPageBaseUrl .= '&debug_log_date=' . urlencode($selectedDebugLogDate);
        }

        $this->context->smarty->assign(array(
            'debugLogBaseUrl' => $this->module_instance->module_url . '&menu=logs',
            'debugLogDates' => $debugLogDates,
            'selectedDebugLogDate' => $selectedDebugLogDate,
            'debugLogEntries' => $pageEntries,
            'logPageBaseUrl' => $logPageBaseUrl,
            'currentLogPage' => $currentLogPage,
            'totalLogPages' => $totalLogPages,
            'totalLogEntries' => $totalLogEntries,
            'logsShowingFrom' => $showingFrom,
            'logsShowingTo' => $showingTo,
            'logPaginationItems' => $this->buildPaginationItems($currentLogPage, $totalLogPages),
        ));

        return $this->context->smarty->fetch(_DPDGEOPOST_TPL_DIR_ . 'admin/logs.tpl');
    }

    private function buildPaginationItems($currentPage, $totalPages)
    {
        if ($totalPages <= 1) {
            return array();
        }

        $range = 2;
        $shown = array();
        $shown[1] = true;
        $shown[$totalPages] = true;
        for ($i = $currentPage - $range; $i <= $currentPage + $range; $i++) {
            if ($i >= 1 && $i <= $totalPages) {
                $shown[$i] = true;
            }
        }
        ksort($shown);
        $pages = array_keys($shown);

        $items = array();
        $prev = 0;
        foreach ($pages as $page) {
            if ($page - $prev > 1) {
                $items[] = array('type' => 'ellipsis');
            }
            $items[] = array(
                'type' => 'page',
                'number' => $page,
                'is_current' => $page === $currentPage,
            );
            $prev = $page;
        }
        return $items;
    }

    private function parseDebugLogEntries($content)
    {
        $content = trim((string) $content);
        if ($content === '') {
            return array();
        }

        $entries = array();
        $legacyChunk = array();
        $lines = preg_split('/\R/', $content);
        if (!is_array($lines)) {
            return array();
        }

        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            if ($trimmedLine === '') {
                continue;
            }

            if (preg_match('/^-{20,}$/', $trimmedLine)) {
                if (!empty($legacyChunk)) {
                    $entries[] = $this->buildDebugLogEntryFromChunk(implode(PHP_EOL, $legacyChunk));
                    $legacyChunk = array();
                }
                continue;
            }

            $decoded = json_decode($trimmedLine, true);
            if (is_array($decoded)) {
                $entries[] = $this->buildDebugLogEntryFromDecoded($decoded, $trimmedLine);
                continue;
            }

            $legacyChunk[] = $line;
        }

        if (!empty($legacyChunk)) {
            $entries[] = $this->buildDebugLogEntryFromChunk(implode(PHP_EOL, $legacyChunk));
        }

        return array_reverse($entries);
    }

    private function buildDebugLogEntryFromChunk($chunk)
    {
        $chunk = trim((string) $chunk);
        if ($chunk === '') {
            return $this->buildUnparsedEntry('');
        }

        $decoded = json_decode($chunk, true);
        if (!is_array($decoded)) {
            return $this->buildUnparsedEntry($chunk);
        }

        return $this->buildDebugLogEntryFromDecoded($decoded, $chunk);
    }

    private function buildDebugLogEntryFromDecoded(array $decoded, $fallbackDetails = '')
    {
        $loggedAt = !empty($decoded['logged_at']) ? (string) $decoded['logged_at'] : '';
        $method = !empty($decoded['method']) ? (string) $decoded['method'] : '';
        $httpCode = isset($decoded['response']['http_code']) && $decoded['response']['http_code'] !== '' ? (string) $decoded['response']['http_code'] : '';
        $responseTimeMs = isset($decoded['timing']['response_time_ms']) ? (string) $decoded['timing']['response_time_ms'] : '';
        $event = !empty($decoded['event']) ? (string) $decoded['event'] : '';

        $date = '';
        if ($loggedAt !== '') {
            $ts = strtotime($loggedAt);
            $date = $ts !== false ? date('Y-m-d H:i:s', $ts) : $loggedAt;
        }

        if ($event === '') {
            $type = $this->l('API');
        } else {
            $friendly = preg_replace('/^payload_cache_/', 'cache ', $event);
            $type = ucfirst(trim(str_replace('_', ' ', $friendly)));
        }

        $details = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($details === false) {
            $details = (string) $fallbackDetails;
        }

        $httpDisplay = $httpCode !== '' ? $httpCode : '—';
        $durationDisplay = $responseTimeMs !== '' ? $responseTimeMs . ' ms' : '—';

        return array(
            'date' => $date !== '' ? $date : '—',
            'type' => $type,
            'method' => $method !== '' ? $method : '—',
            'http_code' => $httpDisplay,
            'duration' => $durationDisplay,
            'summary' => sprintf('%s | %s | %s | HTTP %s | %s', $date, $type, $method, $httpDisplay, $durationDisplay),
            'details' => $details,
        );
    }

    private function buildUnparsedEntry($rawChunk)
    {
        return array(
            'date' => '—',
            'type' => $this->l('Unparsed'),
            'method' => '—',
            'http_code' => '—',
            'duration' => '—',
            'summary' => $this->l('Unparsed log entry'),
            'details' => (string) $rawChunk,
        );
    }
}
