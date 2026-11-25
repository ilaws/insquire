<?php

/**
 * Normalize a model-generated writing review against the draft it describes.
 *
 * Returns null when the response is structurally unusable. Individual issues
 * that cannot be applied safely are dropped rather than shown to the learner.
 */
function sanitizeWritingReview(mixed $review, string $draft): ?array
{
    if (
        !is_array($review) ||
        !isset($review['summary'], $review['strengths'], $review['issues']) ||
        !is_string($review['summary']) ||
        !is_array($review['strengths']) ||
        !is_array($review['issues']) ||
        count($review['issues']) > 6
    ) {
        return null;
    }

    $strengths = [];
    foreach ($review['strengths'] as $strength) {
        if (!is_string($strength) || trim($strength) === '') {
            continue;
        }
        $strengths[] = mb_substr(trim($strength), 0, 280);
    }
    if (count($strengths) > 2) {
        return null;
    }

    $allowedCategories = ['Grammar', 'Punctuation', 'Spelling', 'Clarity', 'Word choice', 'Academic tone'];
    $allowedPriorities = ['high', 'medium', 'low'];
    $issues = [];
    $seenExcerpts = [];
    foreach ($review['issues'] as $issue) {
        if (!is_array($issue)) {
            continue;
        }
        $excerpt = $issue['excerpt'] ?? null;
        $category = $issue['category'] ?? null;
        $priority = $issue['priority'] ?? null;
        $suggestion = $issue['suggestion'] ?? null;
        $explanation = $issue['explanation'] ?? null;
        if (
            !is_string($excerpt) ||
            $excerpt === '' ||
            mb_strlen($excerpt) > 160 ||
            !str_contains($draft, $excerpt) ||
            in_array($excerpt, $seenExcerpts, true) ||
            !is_string($category) ||
            !in_array($category, $allowedCategories, true) ||
            !is_string($priority) ||
            !in_array($priority, $allowedPriorities, true) ||
            !is_string($suggestion) ||
            trim($suggestion) === '' ||
            !is_string($explanation) ||
            trim($explanation) === ''
        ) {
            continue;
        }
        $seenExcerpts[] = $excerpt;
        $issues[] = [
            'category' => $category,
            'priority' => $priority,
            'excerpt' => $excerpt,
            'suggestion' => mb_substr(trim($suggestion), 0, 280),
            'explanation' => mb_substr(trim($explanation), 0, 360)
        ];
    }

    return [
        'summary' => mb_substr(trim($review['summary']), 0, 360),
        'strengths' => $strengths,
        'issues' => $issues
    ];
}
