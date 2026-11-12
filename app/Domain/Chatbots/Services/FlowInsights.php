<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Services;

use App\Domain\Chatbots\Engine\FlowGraph;
use App\Domain\Chatbots\Models\ChatbotEvent;
use App\Domain\Chatbots\Models\ChatbotFlow;
use App\Domain\Chatbots\Models\ChatbotFlowVersion;
use App\Domain\Chatbots\Models\ChatbotSession;
use Illuminate\Support\Carbon;

/**
 * Flow insights from the real execution log (chatbot_events, chatbot_sessions), aware of versions.
 *
 * A step's numbers are only added up across versions in which that step was the same: same type,
 * same settings, same paths (FlowGraph::nodeHashes). Changing a step's question starts its count
 * afresh; moving it on the canvas or renaming it does not. Scopes:
 *   current — the published version, each step counted over every version where it was identical
 *   N       — one version only
 *   all     — everything by step id (the earlier behaviour; can mix different steps that share an id)
 */
final class FlowInsights
{
    /**
     * @return array<string, mixed>
     */
    public function report(ChatbotFlow $flow, string $scope, Carbon $since): array
    {
        $versions = ChatbotFlowVersion::query()->where('flow_id', $flow->id)->orderBy('version')->get(['version', 'graph', 'node_hashes', 'created_at']);
        $hashes = $versions->mapWithKeys(fn (ChatbotFlowVersion $v) => [$v->version => $v->node_hashes ?? FlowGraph::nodeHashes($v->graph)])->all();
        $target = match (true) {
            $scope === 'all' => null,
            ctype_digit($scope) => (int) $scope,
            default => $flow->published_version,
        };
        if ($target !== null && ! isset($hashes[$target])) {
            $target = null;
            $scope = 'all';
        }
        $graph = FlowGraph::from($target !== null ? $versions->firstWhere('version', $target)?->graph : ($flow->publishedGraph()->graph ?? $flow->draft));

        // Which versions count for each step of the target version.
        /** @var array<string, list<int>> $counted */
        $counted = [];
        if ($target !== null) {
            foreach ($hashes[$target] as $nodeId => $hash) {
                $counted[$nodeId] = ctype_digit($scope) ? [$target] : array_keys(array_filter($hashes, fn (array $h) => ($h[$nodeId] ?? null) === $hash));
            }
        }

        $rows = ChatbotEvent::query()->where('flow_id', $flow->id)->where('created_at', '>=', $since)
            ->when(ctype_digit($scope), fn ($q) => $q->where('flow_version', $target))
            ->selectRaw('flow_version, node_id, kind, detail, count(*) AS total, count(DISTINCT session_id) AS sessions')
            ->groupBy('flow_version', 'node_id', 'kind', 'detail')->toBase()->get();
        $nodes = [];
        $outcomes = [];
        foreach ($rows as $r) {
            if (in_array($r->kind, ChatbotEvent::LOG_KINDS, true)) {
                continue; // the run's transcript (Runs & Activity), not funnel numbers
            }
            $id = (string) $r->node_id;
            if ($target !== null && ! in_array((int) $r->flow_version, $counted[$id] ?? [], true)) {
                continue; // a different step under the same id, or a step that no longer exists
            }
            $n = $nodes[$id] ?? ['entries' => 0, 'clicks' => [], 'abandoned' => 0, 'handoffs' => 0, 'completed' => 0, 'errors' => 0, 'no_match' => 0, 'counted_versions' => $counted[$id] ?? null];
            $total = (int) $r->total;
            switch ($r->kind) {
                case 'enter':
                    $n['entries'] += (int) $r->sessions;
                    break;
                case 'click':
                    $n['clicks'][(string) $r->detail] = ($n['clicks'][(string) $r->detail] ?? 0) + $total;
                    break;
                case 'abandon':
                    $n['abandoned'] += $total;
                    break;
                case 'handoff':
                    $n['handoffs'] += $total;
                    break;
                case 'complete':
                    $n['completed'] += $total;
                    break;
                default:
                    $r->detail === 'no_match' ? $n['no_match'] += $total : $n['errors'] += $total;
            }
            $nodes[$id] = $n;
            if (in_array($r->kind, ['handoff', 'complete'], true) && $r->detail !== null && $r->detail !== '') {
                $outcomes[(string) $r->detail] = ($outcomes[(string) $r->detail] ?? 0) + (int) $r->total;
            }
        }

        // Runs: the target version's (or all of them).
        $sessions = ChatbotSession::query()->where('flow_id', $flow->id)->where('created_at', '>=', $since)
            ->when($target !== null, fn ($q) => $q->where('flow_version', $target));
        $byStatus = (clone $sessions)->selectRaw('status, count(*) AS total')->groupBy('status')->toBase()->pluck('total', 'status');
        $total = (int) $byStatus->sum();
        $finished = (int) ($byStatus['completed'] ?? 0) + (int) ($byStatus['handed_off'] ?? 0);
        $avg = (clone $sessions)->whereIn('status', ['completed', 'handed_off'])->whereNotNull('ended_at')
            ->selectRaw('avg(extract(epoch from (ended_at - created_at))) AS seconds')->toBase()->value('seconds');
        $daily = (clone $sessions)
            ->selectRaw("to_char(created_at, 'YYYY-MM-DD') AS day, count(*) AS started, count(*) FILTER (WHERE status IN ('completed', 'handed_off')) AS finished")
            ->groupBy('day')->orderBy('day')->toBase()->get();
        $perVersion = ChatbotSession::query()->where('flow_id', $flow->id)->where('created_at', '>=', $since)
            ->selectRaw('flow_version, count(*) AS total')->groupBy('flow_version')->toBase()->pluck('total', 'flow_version');

        $dropoff = collect($nodes)->filter(fn ($n) => $n['abandoned'] > 0)->sortByDesc('abandoned')->take(8)
            ->map(fn ($n, $id) => ['node_id' => (string) $id, 'title' => $graph->node((string) $id)['title'] ?? (string) $id, 'abandoned' => $n['abandoned'], 'entries' => $n['entries']])->values();

        arsort($outcomes);

        return [
            'scope' => $target !== null ? (ctype_digit($scope) ? 'version' : 'current') : 'all',
            'version' => $target,
            'versions' => $versions->map(fn (ChatbotFlowVersion $v) => [
                'version' => $v->version, 'published_at' => $v->created_at?->toIso8601String(), 'sessions' => (int) ($perVersion[$v->version] ?? 0),
                // Steps of the target version that changed in this version (so their counts start here).
                'changed_steps' => $target !== null ? count(array_filter($hashes[$target], fn ($h, $id) => ($hashes[$v->version][$id] ?? null) !== $h, ARRAY_FILTER_USE_BOTH)) : null,
            ])->values(),
            'sessions' => [
                'started' => $total,
                'running' => (int) (($byStatus['active'] ?? 0) + ($byStatus['waiting'] ?? 0)),
                'completed' => (int) ($byStatus['completed'] ?? 0),
                'handed_off' => (int) ($byStatus['handed_off'] ?? 0),
                'abandoned' => (int) ($byStatus['abandoned'] ?? 0),
                'stopped' => (int) ($byStatus['stopped'] ?? 0),
                'completion_rate' => $total > 0 ? round(100 * $finished / $total, 1) : null,
                'handoff_rate' => $total > 0 ? round(100 * (int) ($byStatus['handed_off'] ?? 0) / $total, 1) : null,
                'avg_seconds' => $avg !== null ? (int) round((float) $avg) : null,
            ],
            'daily' => $daily->map(fn ($d) => ['day' => $d->day, 'started' => (int) $d->started, 'finished' => (int) $d->finished])->values(),
            'dropoff' => $dropoff,
            'outcomes' => collect($outcomes)->map(fn ($count, $label) => ['label' => (string) $label, 'count' => $count])->values(),
            'nodes' => $nodes,
            'graph' => $graph,
        ];
    }
}
