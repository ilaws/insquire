<?php

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/config/database.php';

requirePersonalAccount();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require dirname(__DIR__) . '/includes/personal-assignment-chat.php';
}

$userId = currentUserId();
$taskId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$taskStmt = $pdo->prepare(
    'SELECT id, title, details, due_at, status
     FROM personal_tasks
     WHERE id = ? AND user_id = ?
     LIMIT 1'
);
$taskStmt->execute([$taskId, $userId]);
$task = $taskStmt->fetch(PDO::FETCH_ASSOC);

if (!$task) {
    http_response_code(404);
    $title = 'Assignment not found';
    require_once dirname(__DIR__) . '/includes/header.php';
    echo '<section class="card"><h1>Assignment not found</h1><p>This assignment may have been deleted or may belong to another account.</p><a class="button" href="personal-planner.php">Back to assignments</a></section>';
    require_once dirname(__DIR__) . '/includes/footer.php';
    exit;
}

$writingStmt = $pdo->prepare(
    'SELECT draft, review_json, reviewed_draft_hash, reviewed_at
     FROM personal_task_writing
     WHERE task_id = ?
     LIMIT 1'
);
$writingStmt->execute([(int) $task['id']]);
$writing = $writingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
$draft = (string) ($writing['draft'] ?? '');
$review = null;
$reviewIsCurrent = false;
if ($writing && is_string($writing['review_json']) && $writing['review_json'] !== '') {
    $decodedReview = json_decode($writing['review_json'], true);
    if (is_array($decodedReview)) {
        $review = $decodedReview;
        $reviewIsCurrent = hash_equals(
            (string) ($writing['reviewed_draft_hash'] ?? ''),
            hash('sha256', $draft)
        );
    }
}

$reviewPayload = [
    'review' => $reviewIsCurrent ? $review : null,
    'stale' => $review !== null && !$reviewIsCurrent
];

$chatStmt = $pdo->prepare(
    'SELECT role, message, created_at
     FROM personal_task_ai_chats
     WHERE task_id = ?
     ORDER BY id DESC
     LIMIT 40'
);
$chatStmt->execute([(int) $task['id']]);
$chatMessages = array_reverse($chatStmt->fetchAll(PDO::FETCH_ASSOC));

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$title = (string) $task['title'];
require_once dirname(__DIR__) . '/includes/header.php';
?>
<div class="assignment-workspace">
    <a class="text-link" href="personal-planner.php">← All assignments</a>
    <section class="card assignment-details">
        <div class="assignment-heading">
            <div>
                <p class="eyebrow">PERSONAL ASSIGNMENT</p>
                <h1><?= e($task['title']) ?></h1>
            </div>
            <span class="status-badge"><?= e(ucfirst(str_replace('_', ' ', $task['status']))) ?></span>
        </div>
        <?php if (!empty($task['due_at'])): ?>
            <p><strong>Due:</strong> <?= e(date('M j, Y g:i A', strtotime($task['due_at']))) ?></p>
        <?php endif; ?>
        <?php if (!empty($task['details'])): ?>
            <div class="assignment-instructions"><strong>Instructions or notes</strong><p class="preline"><?= e($task['details']) ?></p></div>
        <?php else: ?>
            <p class="muted">Add assignment instructions or notes in your work plan to give feedback more context.</p>
        <?php endif; ?>
    </section>

    <section class="card writing-review-workspace" aria-labelledby="writing-review-title">
        <div class="dashboard-section-heading">
            <div>
                <p class="eyebrow dashboard-eyebrow">DRAFT REVIEW</p>
                <h2 id="writing-review-title">Review your writing</h2>
                <p>Write in your draft, then review specific suggestions for grammar, punctuation, clarity, word choice, and academic tone. Click a highlighted phrase to see its note.</p>
            </div>
            <span id="draft-save-status" class="draft-save-status" role="status"><?= $draft !== '' ? 'Draft saved' : 'Draft not saved' ?></span>
        </div>

        <form id="writing-review-form" action="personal-assignment.php?id=<?= (int) $task['id'] ?>" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
            <input type="hidden" name="action" value="review">
            <label for="writing-draft">Your draft</label>
            <div class="draft-editor" id="draft-editor">
                <div class="draft-highlight-layer" aria-hidden="true">
                    <div class="draft-highlight-content" id="draft-highlight-content"></div>
                </div>
                <textarea id="writing-draft" name="message" rows="16" maxlength="6000" placeholder="Write or paste your draft here. Your work is saved with this assignment." aria-describedby="draft-help draft-count" required><?= e($draft) ?></textarea>
            </div>
            <div class="draft-editor-meta">
                <p id="draft-help" class="muted">Up to 6,000 characters. Your draft is saved to this assignment. It is sent to the AI service only when you request a review.</p>
                <span id="draft-count" class="muted" aria-live="polite"><?= number_format(mb_strlen($draft)) ?> / 6,000</span>
            </div>
            <div class="coach-form-actions">
                <button id="review-draft-button" class="button" type="submit">Review draft</button>
                <button id="save-draft-button" class="button secondary" type="button">Save draft</button>
            </div>
        </form>

        <p id="writing-review-status" class="coach-status" role="alert" hidden></p>
        <section id="writing-review-results" class="writing-review-results is-empty" aria-live="polite" aria-label="Writing review"></section>
        <div id="draft-popover" class="draft-popover" role="dialog" aria-modal="false" aria-labelledby="draft-popover-category" hidden></div>
        <script type="application/json" id="writing-review-data"><?= json_encode($reviewPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
    </section>

    <details class="card assignment-question-panel">
        <summary>Ask a follow-up question</summary>
        <p class="muted">For example, ask why a suggestion was made or how to strengthen a particular point. The draft review above is the main feedback tool.</p>
        <div id="assignment-chat" class="coach-chat assignment-chat" aria-label="Chat for <?= e($task['title']) ?>" aria-live="polite" aria-relevant="additions">
            <?php if (!$chatMessages): ?>
                <div class="coach-message coach-message-assistant coach-message-welcome">
                    <span class="coach-message-label">InsQuire coach</span>
                    <p>Ask a specific question about your draft or this assignment.</p>
                </div>
            <?php else: ?>
                <?php foreach ($chatMessages as $chatMessage): ?>
                    <div class="coach-message <?= $chatMessage['role'] === 'learner' ? 'coach-message-learner' : 'coach-message-assistant' ?>">
                        <span class="coach-message-label"><?= $chatMessage['role'] === 'learner' ? 'You' : 'InsQuire coach' ?></span>
                        <p><?= e($chatMessage['message']) ?></p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <form id="assignment-chat-form" action="personal-assignment.php?id=<?= (int) $task['id'] ?>" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
            <input type="hidden" name="action" value="question">
            <label for="assignment-chat-message">Your question</label>
            <textarea id="assignment-chat-message" name="message" rows="3" maxlength="3000" placeholder="Ask about a suggestion, your assignment, or what to revise next…" required></textarea>
            <div class="coach-form-actions"><button class="button secondary" type="submit">Ask question</button></div>
        </form>
        <p id="assignment-chat-status" class="coach-status" role="alert" hidden></p>
    </details>
</div>
<script>
(function () {
    const reviewForm = document.getElementById('writing-review-form');
    const draft = document.getElementById('writing-draft');
    const editor = document.getElementById('draft-editor');
    const highlightContent = document.getElementById('draft-highlight-content');
    const popover = document.getElementById('draft-popover');
    const results = document.getElementById('writing-review-results');
    const status = document.getElementById('writing-review-status');
    const saveStatus = document.getElementById('draft-save-status');
    const charCount = document.getElementById('draft-count');
    const reviewButton = document.getElementById('review-draft-button');
    const saveButton = document.getElementById('save-draft-button');
    const dataElement = document.getElementById('writing-review-data');

    let issues = [];
    let highlights = [];
    let activeEntry = null;
    let staleNote = null;
    let saveTimer;
    let saveInProgress = Promise.resolve();

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function setSaveStatus(text) {
        saveStatus.textContent = text;
    }

    function updateDraftCount() {
        charCount.textContent = draft.value.length.toLocaleString() + ' / 6,000';
    }

    function findRange(excerpt) {
        if (!excerpt) return null;
        const first = draft.value.indexOf(excerpt);
        if (first < 0) return null;
        if (draft.value.indexOf(excerpt, first + 1) !== -1) return null;
        return { start: first, end: first + excerpt.length };
    }

    function syncScroll() {
        highlightContent.style.transform = 'translate(' + (-draft.scrollLeft) + 'px,' + (-draft.scrollTop) + 'px)';
    }

    function renderHighlights() {
        highlights = [];
        highlightContent.replaceChildren();
        const text = draft.value;
        if (!issues.length || !text) {
            syncScroll();
            return;
        }
        const ranges = [];
        issues.forEach(function (issue, index) {
            const range = findRange(issue.excerpt);
            if (range) ranges.push({ start: range.start, end: range.end, issue: issue, index: index });
        });
        ranges.sort(function (a, b) { return a.start - b.start; });
        let cursor = 0;
        ranges.forEach(function (entry) {
            if (entry.start < cursor) return;
            if (entry.start > cursor) {
                highlightContent.append(document.createTextNode(text.slice(cursor, entry.start)));
            }
            const mark = el('mark', 'draft-highlight priority-' + entry.issue.priority, text.slice(entry.start, entry.end));
            mark.dataset.issueIndex = String(entry.index);
            highlightContent.append(mark);
            cursor = entry.end;
            highlights.push(entry);
        });
        highlightContent.append(document.createTextNode(text.slice(cursor)));
        if (text.endsWith('\n')) {
            highlightContent.append(document.createTextNode('\u200b'));
        }
        syncScroll();
    }

    function caretRect(position) {
        const computed = getComputedStyle(draft);
        const mirror = document.createElement('div');
        [
            'fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'lineHeight', 'letterSpacing',
            'wordSpacing', 'textTransform', 'paddingTop', 'paddingRight', 'paddingBottom',
            'paddingLeft', 'borderTopWidth', 'borderRightWidth', 'borderBottomWidth',
            'borderLeftWidth', 'boxSizing', 'tabSize'
        ].forEach(function (property) {
            mirror.style[property] = computed[property];
        });
        mirror.style.position = 'absolute';
        mirror.style.top = '0';
        mirror.style.left = '0';
        mirror.style.visibility = 'hidden';
        mirror.style.height = 'auto';
        mirror.style.width = computed.width;
        mirror.style.whiteSpace = 'pre-wrap';
        mirror.style.overflowWrap = 'break-word';
        mirror.textContent = draft.value.slice(0, position);
        const marker = document.createElement('span');
        marker.textContent = draft.value.slice(position) || '\u200b';
        mirror.append(marker);
        document.body.append(mirror);
        const mirrorRect = mirror.getBoundingClientRect();
        const markerRect = marker.getBoundingClientRect();
        const boxRect = draft.getBoundingClientRect();
        const fallbackHeight = parseFloat(computed.lineHeight) || 22;
        const rect = {
            left: boxRect.left + (markerRect.left - mirrorRect.left) - draft.scrollLeft,
            top: boxRect.top + (markerRect.top - mirrorRect.top) - draft.scrollTop,
            height: markerRect.height || fallbackHeight
        };
        mirror.remove();
        return rect;
    }

    function closePopover() {
        if (popover.hidden) return;
        popover.hidden = true;
        activeEntry = null;
    }

    function positionPopover(rect) {
        const margin = 12;
        const width = popover.offsetWidth;
        const height = popover.offsetHeight;
        const maxLeft = Math.max(margin, window.innerWidth - width - margin);
        const maxTop = Math.max(margin, window.innerHeight - height - margin);
        const left = Math.min(Math.max(margin, rect.left), maxLeft);
        let top = rect.top + rect.height + 8;
        if (top > maxTop) {
            top = rect.top - height - 8;
        }
        top = Math.min(Math.max(margin, top), maxTop);
        popover.style.left = left + 'px';
        popover.style.top = top + 'px';
    }

    function openPopover(entry, rect) {
        const issue = entry.issue;
        activeEntry = entry;
        popover.replaceChildren();
        popover.setAttribute('aria-label', issue.category + ' suggestion');

        const heading = el('div', 'draft-popover-heading');
        const category = el('span', 'draft-popover-category', issue.category);
        category.id = 'draft-popover-category';
        const priority = el(
            'span',
            'writing-issue-priority priority-' + issue.priority,
            issue.priority.charAt(0).toUpperCase() + issue.priority.slice(1) + ' priority'
        );
        heading.append(category, priority);

        const excerpt = el('p', 'draft-popover-excerpt');
        excerpt.append(el('strong', '', 'In your draft'), document.createElement('br'), el('q', '', issue.excerpt));

        const suggestion = el('p', 'draft-popover-suggestion');
        suggestion.append(el('strong', '', 'Suggestion'), document.createElement('br'), document.createTextNode(issue.suggestion));

        const actions = el('div', 'draft-popover-actions');
        const closeButton = el('button', 'button secondary', 'Close');
        closeButton.type = 'button';
        closeButton.addEventListener('click', function () {
            closePopover();
            draft.focus();
        });
        actions.append(closeButton);

        popover.append(heading, excerpt, suggestion, el('p', 'draft-popover-explanation', issue.explanation), actions);
        popover.hidden = false;
        positionPopover(rect || caretRectVisible(draft.selectionStart));
        closeButton.focus();
    }

    function caretRectVisible(position) {
        draft.scrollIntoView({ block: 'nearest' });
        return caretRect(position);
    }

    function entryAtCaret() {
        const position = draft.selectionStart;
        if (draft.selectionEnd !== position) return null;
        return highlights.find(function (entry) {
            return position >= entry.start && position < entry.end;
        }) || null;
    }

    function openPopoverAtCaret() {
        const entry = entryAtCaret();
        if (!entry) {
            closePopover();
            return;
        }
        openPopover(entry, caretRect(draft.selectionStart));
    }

    draft.addEventListener('scroll', function () {
        syncScroll();
        closePopover();
    });
    window.addEventListener('resize', closePopover);
    draft.addEventListener('click', openPopoverAtCaret);
    draft.addEventListener('keyup', function (event) {
        if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'].indexOf(event.key) === -1) return;
        openPopoverAtCaret();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !popover.hidden) {
            closePopover();
            draft.focus();
        }
    });
    document.addEventListener('pointerdown', function (event) {
        if (popover.hidden) return;
        if (popover.contains(event.target) || editor.contains(event.target)) return;
        closePopover();
    });

    function setStale(isStale) {
        if (isStale && issues.length && !staleNote) {
            staleNote = el('p', 'writing-review-stale-note', 'Your draft changed since this review. Run Review draft to refresh these suggestions.');
            results.prepend(staleNote);
        } else if (!isStale && staleNote) {
            staleNote.remove();
            staleNote = null;
        }
    }

    function placeholder() {
        const wrapper = el('div', 'writing-review-placeholder');
        wrapper.append(
            el('h3', '', 'Suggestions will appear here'),
            el('p', '', 'Review your draft to see highlighted phrases and clickable notes. InsQuire only points things out; you make every change yourself.')
        );
        return wrapper;
    }

    function renderReview(review) {
        issues = Array.isArray(review.issues) ? review.issues.slice() : [];
        staleNote = null;
        results.replaceChildren();
        results.classList.remove('is-empty');

        const summary = el('div', 'writing-review-summary');
        summary.append(el('p', 'eyebrow', 'REVIEW SUMMARY'), el('p', '', review.summary));
        results.append(summary);

        if (Array.isArray(review.strengths) && review.strengths.length) {
            const strengths = el('section', 'writing-review-strengths');
            const list = el('ul');
            review.strengths.forEach(function (strength) {
                list.append(el('li', '', strength));
            });
            strengths.append(el('h3', '', 'What’s working'), list);
            results.append(strengths);
        }

        const heading = el('div', 'writing-review-issues-heading');
        heading.append(el('h3', '', 'Suggestions'), el('span', '', issues.length + ' item(s)'));
        results.append(heading);

        if (!issues.length) {
            results.append(el('p', 'muted', 'No clear corrections to suggest in this draft. You can still revise and review again.'));
        } else {
            const list = el('div', 'writing-issue-list');
            issues.forEach(function (issue) {
                list.append(createIssueCard(issue));
            });
            results.append(list);
        }

        results.append(el('p', 'writing-review-disclaimer', 'These are suggestions only. InsQuire will not change your writing. Make any revisions yourself in your own words.'));
        renderHighlights();
        closePopover();
    }

    function createIssueCard(issue) {
        const card = el('article', 'writing-issue');
        const heading = el('div', 'writing-issue-heading');
        heading.append(
            el('span', 'writing-issue-category', issue.category),
            el('span', 'writing-issue-priority priority-' + issue.priority, issue.priority.charAt(0).toUpperCase() + issue.priority.slice(1) + ' priority')
        );

        const excerpt = el('p', 'writing-issue-excerpt');
        excerpt.append(el('strong', '', 'In your draft'), document.createElement('br'), el('q', '', issue.excerpt));

        const suggestion = el('p', 'writing-issue-suggestion');
        suggestion.append(el('strong', '', 'Suggestion'), document.createElement('br'), document.createTextNode(issue.suggestion));

        const actions = el('div', 'writing-issue-actions');
        const findButton = el('button', 'button secondary', 'Show in draft');
        findButton.type = 'button';
        findButton.addEventListener('click', function () {
            const range = findRange(issue.excerpt);
            if (!range) {
                findButton.disabled = true;
                return;
            }
            draft.focus();
            draft.setSelectionRange(range.start, range.end);
            const entry = highlights.find(function (item) { return item.start === range.start; });
            if (entry) {
                openPopover(entry);
            }
        });
        actions.append(findButton);

        card.append(heading, excerpt, suggestion, el('p', 'writing-issue-explanation', issue.explanation), actions);
        return card;
    }

    async function readJson(response) {
        const contentType = (response.headers.get('content-type') || '').toLowerCase();
        if (!contentType.includes('application/json')) {
            throw new Error(
                response.redirected
                    ? 'Your session may have expired. Sign in again, then reopen this assignment.'
                    : 'The server did not return a JSON response (HTTP ' + response.status + '). Refresh this page and try again.'
            );
        }
        return response.json();
    }

    function formUrl(form) {
        return new URL(form.getAttribute('action') || window.location.href, window.location.href);
    }

    function saveDraft() {
        const payload = new FormData(reviewForm);
        payload.set('action', 'save_draft');
        payload.set('message', draft.value);
        setSaveStatus('Saving…');
        saveInProgress = saveInProgress.catch(function () {}).then(function () {
            return fetch(formUrl(reviewForm), {
                method: 'POST',
                body: payload,
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            });
        }).then(async function (response) {
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'The draft could not be saved.');
            }
            setSaveStatus('Draft saved');
            return true;
        }).catch(function (error) {
            setSaveStatus('Could not save');
            status.textContent = error instanceof Error ? error.message : 'The draft could not be saved.';
            status.hidden = false;
            return false;
        });
        return saveInProgress;
    }

    draft.addEventListener('input', function () {
        updateDraftCount();
        setSaveStatus('Unsaved changes');
        renderHighlights();
        setStale(true);
        clearTimeout(saveTimer);
        saveTimer = window.setTimeout(saveDraft, 800);
    });

    saveButton.addEventListener('click', function () {
        clearTimeout(saveTimer);
        saveDraft();
    });

    reviewForm.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (!draft.value.trim()) {
            draft.reportValidity();
            return;
        }
        clearTimeout(saveTimer);
        status.hidden = true;
        reviewButton.disabled = true;
        reviewButton.textContent = 'Reviewing…';
        results.classList.add('is-loading');
        closePopover();
        try {
            if (!(await saveDraft())) {
                throw new Error('Your draft could not be saved. Please try again before requesting a review.');
            }
            const payload = new FormData(reviewForm);
            payload.set('action', 'review');
            payload.set('message', draft.value);
            const response = await fetch(formUrl(reviewForm), {
                method: 'POST',
                body: payload,
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            });
            const data = await readJson(response);
            if (!data.success) {
                const retry = Number.isInteger(data.retry_after)
                    ? ' Try again in about ' + Math.max(1, Math.ceil(data.retry_after / 60)) + ' minute(s).'
                    : '';
                throw new Error((data.error || 'Your draft could not be reviewed.') + retry);
            }
            renderReview(data.review);
            setStale(false);
            setSaveStatus('Draft saved');
        } catch (error) {
            status.textContent = error instanceof Error ? error.message : 'Your draft could not be reviewed.';
            status.hidden = false;
        } finally {
            results.classList.remove('is-loading');
            reviewButton.disabled = false;
            reviewButton.textContent = 'Review draft';
        }
    });

    const chatForm = document.getElementById('assignment-chat-form');
    const chatInput = document.getElementById('assignment-chat-message');
    const chat = document.getElementById('assignment-chat');
    const chatStatus = document.getElementById('assignment-chat-status');
    const chatButton = chatForm.querySelector('button[type="submit"]');

    function addChatMessage(role, content, label) {
        const bubble = el('div', 'coach-message ' + (role === 'learner' ? 'coach-message-learner' : 'coach-message-assistant'));
        bubble.append(el('span', 'coach-message-label', label || (role === 'learner' ? 'You' : 'InsQuire coach')), el('p', '', content));
        chat.append(bubble);
        return bubble;
    }

    chatForm.addEventListener('submit', async function (event) {
        event.preventDefault();
        const message = chatInput.value.trim();
        if (!message) {
            chatInput.reportValidity();
            return;
        }
        const payload = new FormData(chatForm);
        payload.set('message', message);
        chatStatus.hidden = true;
        chat.querySelector('.coach-message-welcome')?.remove();
        const learner = addChatMessage('learner', message);
        const pending = addChatMessage('coach', 'Thinking about your question…', 'InsQuire coach · responding');
        chatInput.disabled = true;
        chatButton.disabled = true;
        try {
            const response = await fetch(formUrl(chatForm), {
                method: 'POST',
                body: payload,
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            });
            const data = await readJson(response);
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'The coach could not respond. Please try again.');
            }
            pending.remove();
            addChatMessage('coach', data.reply);
            chatInput.value = '';
        } catch (error) {
            pending.remove();
            learner.remove();
            chatStatus.textContent = error instanceof Error ? error.message : 'The coach could not respond. Please try again.';
            chatStatus.hidden = false;
        } finally {
            chatInput.disabled = false;
            chatButton.disabled = false;
            chatInput.focus();
        }
    });

    updateDraftCount();
    renderHighlights();

    let initialPayload = { review: null, stale: false };
    try {
        initialPayload = JSON.parse(dataElement.textContent) || initialPayload;
    } catch (error) {
        initialPayload = { review: null, stale: false };
    }
    if (initialPayload.review) {
        renderReview(initialPayload.review);
    } else {
        results.replaceChildren(placeholder());
        results.classList.add('is-empty');
        if (initialPayload.stale) {
            results.classList.remove('is-empty');
            results.replaceChildren(el('p', 'writing-review-stale-note', 'Your draft changed since your last review. Run Review draft to refresh these suggestions.'));
        }
    }
}());
</script>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
