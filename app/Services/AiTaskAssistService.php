<?php

namespace App\Services;

use App\Models\AiTaskDraft;
use App\Models\CaseMessage;

/**
 * Local equivalent of imrandevbd/laravel-ai-hub for roofing task & exception assist.
 * Rule + heuristic engine ships offline; swap provider via AI Hub module.
 */
class AiTaskAssistService
{
    public function __construct(
        protected ModuleManager $modules,
        protected AuditLogger $audit,
    ) {}

    public function classify(CaseMessage $message): CaseMessage
    {
        if (! $this->modules->enabled('ai_task_assist')) {
            $message->update(['status' => 'pending_approval']);

            return $message->fresh();
        }

        $text = strtolower(($message->subject ?? '').' '.$message->body);
        $category = $this->detectCategory($text);
        $priority = $this->detectPriority($text, $category);
        $score = $this->score($text, $category, $priority);
        $slaHours = $this->slaHours($priority, $category);
        $tags = $this->tags($text, $category);
        $summary = sprintf(
            'AI case assist: %s message scored %d/100 as %s priority (%s). Suggested SLA %dh.',
            $message->channel,
            $score,
            $priority,
            $category,
            $slaHours
        );

        $response = [
            'category' => $category,
            'priority' => $priority,
            'score' => $score,
            'tags' => $tags,
            'summary' => $summary,
            'sla_hours' => $slaHours,
            'title' => $this->suggestedTitle($message, $category, $priority),
        ];

        AiTaskDraft::query()->create([
            'case_message_id' => $message->id,
            'provider' => $this->modules->enabled('ai_hub') ? 'ai-hub-local' : 'local-rules',
            'model' => 'roofing-case-v1',
            'suggested_title' => $response['title'],
            'suggested_description' => $summary."\n\n".$message->body,
            'suggested_category' => $category,
            'suggested_priority' => $priority,
            'suggested_sla_hours' => $slaHours,
            'response' => $response,
            'confidence' => min(95, 50 + (int) floor($score / 3)),
            'status' => 'pending_approval',
        ]);

        $message->update([
            'ai_category' => $category,
            'ai_priority' => $priority,
            'ai_score' => $score,
            'ai_summary' => $summary,
            'ai_tags' => $tags,
            'suggested_sla_hours' => $slaHours,
            'status' => $this->modules->enabled('human_approval_workflow') ? 'pending_approval' : 'classified',
            'classified_at' => now(),
        ]);

        $this->audit->log('message.classified', CaseMessage::class, $message->id, null, $response);

        return $message->fresh();
    }

    protected function detectCategory(string $text): string
    {
        $map = [
            'emergency_leak' => ['leak', 'leaking', 'water coming in', 'active drip', 'ceiling wet', 'emergency', 'storm leak'],
            'storm_damage' => ['storm', 'hail', 'wind damage', 'missing shingles', 'tree on roof', 'hurricane'],
            'parts_delay' => ['parts', 'backorder', 'waiting on part', 'shingles on order', 'flashing delay', 'delay'],
            'callback' => ['callback', 'come back', 'still leaking', 'not fixed', 'return visit'],
            'dispatch' => ['dispatch', 'assign tech', 'send crew', 'schedule crew', 'on the way'],
            'safety_hold' => ['safety', 'fall hazard', 'electrical hazard', 'lockout', 'osha', 'ladder'],
            'billing_exception' => ['invoice', 'billing', 'charge dispute', 'payment', 'insurance claim', 'warranty claim'],
            'maintenance' => ['maintenance', 'inspection', 'gutter clean', 'tune-up', 'preventive'],
            'reroof' => ['reroof', 'tear-off', 'full replacement', 'new roof', 're-roof'],
            'ops_internal' => ['ops note', 'internal', 'crew short', 'truck down'],
        ];

        foreach ($map as $category => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    return $category;
                }
            }
        }

        return 'general_roofing';
    }

    protected function detectPriority(string $text, string $category): string
    {
        if (in_array($category, ['emergency_leak', 'storm_damage', 'safety_hold'], true)
            || str_contains($text, 'asap')
            || str_contains($text, 'urgent')
            || str_contains($text, 'escalate')) {
            return 'urgent';
        }

        if (in_array($category, ['callback', 'parts_delay', 'dispatch', 'billing_exception'], true)) {
            return 'high';
        }

        if (str_contains($text, 'today') || str_contains($text, 'this afternoon')) {
            return 'high';
        }

        return 'normal';
    }

    protected function slaHours(string $priority, string $category): int
    {
        if ($category === 'safety_hold') {
            return 2;
        }

        return match ($priority) {
            'urgent' => 4,
            'high' => 8,
            'low' => 72,
            default => 24,
        };
    }

    protected function score(string $text, string $category, string $priority): int
    {
        $score = 45;

        if (strlen($text) > 80) {
            $score += 10;
        }

        if (preg_match('/\d{3}[-.\s]?\d{3}[-.\s]?\d{4}/', $text)) {
            $score += 8;
        }

        $score += match ($priority) {
            'urgent' => 25,
            'high' => 15,
            default => 5,
        };

        $score += match ($category) {
            'emergency_leak', 'storm_damage', 'safety_hold' => 12,
            'callback', 'parts_delay', 'dispatch' => 8,
            default => 3,
        };

        return max(10, min(99, $score));
    }

    protected function tags(string $text, string $category): array
    {
        $tags = [$category];

        if (str_contains($text, 'commercial')) {
            $tags[] = 'commercial';
        }
        if (str_contains($text, 'residential') || str_contains($text, 'home')) {
            $tags[] = 'residential';
        }
        if (str_contains($text, 'sla') || str_contains($text, 'overdue')) {
            $tags[] = 'sla_risk';
        }
        if (str_contains($text, 'warranty') || str_contains($text, 'insurance')) {
            $tags[] = 'insurance';
        }

        return array_values(array_unique($tags));
    }

    protected function suggestedTitle(CaseMessage $message, string $category, string $priority): string
    {
        $label = str_replace('_', ' ', $category);

        return ucfirst($priority).' '.$label.' — '.($message->from_name ?: 'Roofing case');
    }
}
